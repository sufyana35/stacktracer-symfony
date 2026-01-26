<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Request\RequestExtractor;
use Stacktracer\SymfonyBundle\Request\UserAgentParser;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to request lifecycle events to create request traces.
 *
 * Automatically traces HTTP requests with support for distributed tracing
 * via W3C traceparent header. Creates spans, captures request/response data,
 * and links all data by trace/span IDs.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class RequestTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    private array $excludePatterns;

    private bool $shouldTrace = false;

    private ?Span $requestSpan = null;

    public function __construct(TracingService $tracing, array $excludePatterns = [])
    {
        $this->tracing = $tracing;
        $this->excludePatterns = array_merge([
            '#^/_profiler#',
            '#^/_wdt#',
            '#^/favicon\.ico$#',
            '#^/robots\.txt$#',
            '#^/apple-touch-icon#',
            '#\.map$#',
        ], $excludePatterns);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1000],
            KernelEvents::RESPONSE => ['onResponse', -1000],
            KernelEvents::TERMINATE => ['onTerminate', -1000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Skip prefetch/prerender requests (browser speculative loading)
        $purpose = $request->headers->get('Purpose') ?? $request->headers->get('Sec-Purpose') ?? '';
        if (in_array(strtolower($purpose), ['prefetch', 'prerender'], true)) {
            return;
        }

        // Skip fetch metadata prefetch hints
        $fetchMode = $request->headers->get('Sec-Fetch-Mode', '');
        if ($fetchMode === 'prefetch') {
            return;
        }

        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return;
            }
        }

        if (!$this->tracing->shouldSample()) {
            return;
        }

        $this->shouldTrace = true;

        // Handle incoming distributed trace context (W3C Trace Context)
        $traceparent = $request->headers->get('traceparent');
        if ($traceparent) {
            $this->tracing->setIncomingContext($traceparent);
        }

        // Start the trace
        $trace = $this->tracing->startTrace(
            Trace::TYPE_REQUEST,
            sprintf('%s %s', $request->getMethod(), $path)
        );

        // Start a span for the HTTP request (OTEL SERVER kind)
        $this->requestSpan = $this->tracing->startSpan(
            sprintf('%s %s', $request->getMethod(), $path),
            Span::KIND_SERVER
        );
        $this->requestSpan->setOrigin('auto.http.server');

        // Backdate the trace and request span to actual PHP request start time
        // This ensures timing is accurate from the actual request start, not when our subscriber runs
        $requestStartTime = $request->server->get('REQUEST_TIME_FLOAT');
        if ($requestStartTime) {
            $trace->setTimestamp((float)$requestStartTime);
            $this->requestSpan->setStartTime((float)$requestStartTime);
        }

        // Set OTEL semantic convention attributes
        $this->requestSpan->setAttributes([
            'http.method' => $request->getMethod(),
            'http.url' => $request->getUri(),
            'http.target' => $path,
            'http.host' => $request->getHost(),
            'http.scheme' => $request->getScheme(),
            'http.user_agent' => $request->headers->get('User-Agent'),
            'http.client_ip' => $request->getClientIp(),
            'http.route' => $request->attributes->get('_route'),
        ]);

        // Extract basic request data using RequestExtractor
        $requestData = RequestExtractor::extractBasicData($request);

        if ($this->tracing->shouldCaptureHeaders()) {
            $requestData['headers'] = $this->tracing->redactSensitiveData(
                RequestExtractor::extractHeaders($request)
            );
        }

        // Capture POST/PUT/PATCH request body if enabled
        if ($this->tracing->shouldCaptureRequestBody()) {
            $bodyData = RequestExtractor::extractBody(
                $request,
                $this->tracing->getMaxBodySize(),
                $this->tracing->shouldCaptureFiles()
            );
            if ($bodyData !== null) {
                $requestData['body'] = $this->tracing->redactSensitiveData($bodyData);
            }
        }
        
        // Parse User-Agent for browser and OS info
        $userAgent = $request->headers->get('User-Agent', '');
        if ($userAgent) {
            $browserInfo = UserAgentParser::parse($userAgent);
            if ($browserInfo['browser']) {
                $requestData['browser'] = $browserInfo['browser'];
                $requestData['browser_name'] = $browserInfo['browser_name'];
                if ($browserInfo['browser_version']) {
                    $requestData['browser_version'] = $browserInfo['browser_version'];
                }
            }
            if ($browserInfo['os']) {
                $requestData['os'] = $browserInfo['os'];
                $requestData['os_name'] = $browserInfo['os_name'];
            }
            if ($browserInfo['device']) {
                $requestData['device'] = $browserInfo['device'];
            }
        }
        
        // Request type (ajax vs regular)
        $requestData['type'] = $request->isXmlHttpRequest() ? 'ajax' : 'http';
        
        // Locale
        if ($locale = $request->getLocale()) {
            $requestData['locale'] = $locale;
        }

        $trace->setRequest($requestData);

        // Minimal tags for filtering/grouping only (data that's useful in WHERE clauses)
        // Full request data is in trace.request object
        $trace->addTag('http.status_class', ''); // Will be set on response
        
        // Environment (important for filtering)
        if ($environment = $this->tracing->getEnvironment()) {
            $trace->addTag('environment', $environment);
        }

        $this->tracing->addBreadcrumb('http', 'Request started', [
            'method' => $request->getMethod(),
            'path' => $path,
        ], Breadcrumb::LEVEL_INFO);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->shouldTrace) {
            return;
        }

        $trace = $this->tracing->getCurrentTrace();
        if ($trace === null) {
            return;
        }

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();

        // Update request data with response info
        $request = $event->getRequest();
        $existingRequest = $trace->getRequest() ?? [];
        $existingRequest['status_code'] = $statusCode;
        
        // Response content type
        if ($contentType = $response->headers->get('Content-Type')) {
            $existingRequest['content_type'] = explode(';', $contentType)[0];
        }
        
        // Route/transaction (resolved after routing)
        if ($route = $request->attributes->get('_route')) {
            $existingRequest['route'] = $route;
        }
        
        // Controller info (resolved after routing)
        if ($controller = $request->attributes->get('_controller')) {
            if (is_string($controller)) {
                $existingRequest['controller'] = $controller;
            } elseif (is_array($controller) && isset($controller[0], $controller[1])) {
                $existingRequest['controller'] = $controller[0] . '::' . $controller[1];
            }
        }
        
        $trace->setRequest($existingRequest);
        
        // Status class tag is useful for grouping/filtering
        if ($statusCode >= 200 && $statusCode < 300) {
            $trace->addTag('http.status_class', '2xx');
        } elseif ($statusCode >= 300 && $statusCode < 400) {
            $trace->addTag('http.status_class', '3xx');
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $trace->addTag('http.status_class', '4xx');
        } elseif ($statusCode >= 500) {
            $trace->addTag('http.status_class', '5xx');
        }

        // Update span with response info
        if ($this->requestSpan) {
            $this->requestSpan->setAttribute('http.status_code', $statusCode);
            if ($response->headers->get('Content-Length')) {
                $this->requestSpan->setAttribute('http.response_content_length', $response->headers->get('Content-Length'));
            }

            if ($statusCode >= 400) {
                $this->requestSpan->setError(sprintf('HTTP %d', $statusCode));
            } else {
                $this->requestSpan->setOk();
            }
        }

        if ($statusCode >= 500) {
            $trace->setLevel(Trace::LEVEL_ERROR);
        } elseif ($statusCode >= 400) {
            $trace->setLevel(Trace::LEVEL_WARNING);
        }

        $this->tracing->addBreadcrumb('http', 'Response sent', [
            'status_code' => $statusCode,
        ], $statusCode >= 400 ? Breadcrumb::LEVEL_WARNING : Breadcrumb::LEVEL_INFO);

        $trace->setPerformance([
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);

        // Add traceparent to response for downstream tracing
        $traceparent = $this->tracing->getTraceparent();
        if ($traceparent) {
            $response->headers->set('traceparent', $traceparent);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->shouldTrace) {
            // End the request span
            if ($this->requestSpan) {
                $this->tracing->endSpan($this->requestSpan);
                $this->requestSpan = null;
            }

            $this->tracing->endTrace();
            $this->shouldTrace = false;
        }
    }
}
