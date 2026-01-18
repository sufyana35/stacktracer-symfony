<?php

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to request lifecycle events to create request traces.
 * Supports distributed tracing via W3C traceparent header.
 */
class RequestTracingSubscriber implements EventSubscriberInterface
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

        $requestData = [
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'path' => $path,
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent'),
        ];

        if ($request->getQueryString()) {
            $requestData['qs'] = $request->getQueryString();
        }

        if ($this->tracing->shouldCaptureHeaders()) {
            $requestData['headers'] = $this->tracing->redactSensitiveData(
                $this->compactHeaders($request->headers->all())
            );
        }

        $trace->setRequest($requestData);

        $trace->addTag('http.method', $request->getMethod());
        $trace->addTag('http.path', $path);

        if ($request->attributes->get('_route')) {
            $trace->addTag('route', $request->attributes->get('_route'));
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

        $trace->addTag('http.status_code', (string) $statusCode);

        // Update span with response info
        if ($this->requestSpan) {
            $this->requestSpan->setAttribute('http.status_code', $statusCode);
            $this->requestSpan->setAttribute('http.response_content_length', $response->headers->get('Content-Length'));
            
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
            'content_type' => $response->headers->get('Content-Type'),
        ], $statusCode >= 400 ? Breadcrumb::LEVEL_WARNING : Breadcrumb::LEVEL_INFO);

        $trace->setPerformance([
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
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

    private function compactHeaders(array $headers): array
    {
        $compacted = [];
        foreach ($headers as $name => $values) {
            if (empty($values)) {
                continue;
            }
            $compacted[$name] = count($values) === 1 ? $values[0] : $values;
        }
        return $compacted;
    }
}
