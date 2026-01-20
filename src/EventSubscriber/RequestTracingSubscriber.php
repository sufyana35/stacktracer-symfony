<?php

declare(strict_types=1);

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

    /** @var array<string, array> User-Agent parsing cache (LRU with max size) */
    private static array $uaCache = [];

    /** @var int Maximum cached User-Agent entries */
    private const UA_CACHE_MAX_SIZE = 100;

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

        // Basic HTTP tags
        $trace->addTag('http.method', $request->getMethod());
        $trace->addTag('http.path', $path);
        $trace->addTag('http.scheme', $request->getScheme());
        $trace->addTag('http.host', $request->getHost());
        
        // Full URL
        $trace->addTag('url', $request->getUri());
        
        // Server info
        $trace->addTag('server_name', $request->getHost());
        
        // Runtime info
        $trace->addTag('runtime', 'php');
        $trace->addTag('runtime.version', PHP_VERSION);
        
        // Environment
        if ($environment = $this->tracing->getEnvironment()) {
            $trace->addTag('environment', $environment);
        }
        
        // Parse User-Agent for browser and OS info
        $userAgent = $request->headers->get('User-Agent', '');
        if ($userAgent) {
            $browserInfo = $this->parseUserAgent($userAgent);
            if ($browserInfo['browser']) {
                $trace->addTag('browser', $browserInfo['browser']);
                $trace->addTag('browser.name', $browserInfo['browser_name']);
                if ($browserInfo['browser_version']) {
                    $trace->addTag('browser.version', $browserInfo['browser_version']);
                }
            }
            if ($browserInfo['os']) {
                $trace->addTag('os', $browserInfo['os']);
                $trace->addTag('os.name', $browserInfo['os_name']);
            }
            if ($browserInfo['device']) {
                $trace->addTag('device', $browserInfo['device']);
            }
        }
        
        // Client IP
        if ($clientIp = $request->getClientIp()) {
            $trace->addTag('client_ip', $clientIp);
        }
        
        // Request type (ajax vs regular)
        if ($request->isXmlHttpRequest()) {
            $trace->addTag('request.type', 'ajax');
        } else {
            $trace->addTag('request.type', 'http');
        }
        
        // Locale
        if ($locale = $request->getLocale()) {
            $trace->addTag('locale', $locale);
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
        
        // Status category tag
        if ($statusCode >= 200 && $statusCode < 300) {
            $trace->addTag('http.status_class', '2xx');
            $trace->addTag('level', 'info');
        } elseif ($statusCode >= 300 && $statusCode < 400) {
            $trace->addTag('http.status_class', '3xx');
            $trace->addTag('level', 'info');
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $trace->addTag('http.status_class', '4xx');
            $trace->addTag('level', 'warning');
        } elseif ($statusCode >= 500) {
            $trace->addTag('http.status_class', '5xx');
            $trace->addTag('level', 'error');
        }
        
        // Response content type
        if ($contentType = $response->headers->get('Content-Type')) {
            // Extract just the mime type without charset
            $mimeType = explode(';', $contentType)[0];
            $trace->addTag('http.content_type', trim($mimeType));
        }
        
        // Route/transaction (resolved after routing)
        $request = $event->getRequest();
        if ($route = $request->attributes->get('_route')) {
            $trace->addTag('route', $route);
            $trace->addTag('transaction', $route);
        }
        
        // Controller info (resolved after routing)
        if ($controller = $request->attributes->get('_controller')) {
            if (is_string($controller)) {
                $trace->addTag('controller', $controller);
            } elseif (is_array($controller) && isset($controller[0], $controller[1])) {
                // Array callable format [class, method]
                $trace->addTag('controller', $controller[0] . '::' . $controller[1]);
            }
        }

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

    /**
     * Essential headers to capture (allowlist for optimization).
     */
    private const ESSENTIAL_HEADERS = [
        'host',
        'user-agent',
        'content-type',
        'content-length',
        'accept',
        'accept-language',
        'authorization',  // Will be redacted by sensitive keys
        'x-request-id',
        'x-correlation-id',
        'x-forwarded-for',
        'x-real-ip',
        'referer',
        'origin',
    ];

    private function compactHeaders(array $headers): array
    {
        $compacted = [];
        foreach ($headers as $name => $values) {
            if (empty($values)) {
                continue;
            }
            // Only include essential headers
            $lowerName = strtolower($name);
            if (!in_array($lowerName, self::ESSENTIAL_HEADERS, true)) {
                continue;
            }
            $compacted[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $compacted;
    }

    /**
     * Parse User-Agent string to extract browser, OS and device info.
     * Results are cached for performance since same UAs repeat frequently.
     *
     * @return array{browser: ?string, browser_name: ?string, browser_version: ?string, os: ?string, os_name: ?string, device: ?string}
     */
    private function parseUserAgent(string $userAgent): array
    {
        if (empty($userAgent)) {
            return [
                'browser' => null,
                'browser_name' => null,
                'browser_version' => null,
                'os' => null,
                'os_name' => null,
                'device' => null,
            ];
        }

        // Check cache first (same User-Agents repeat frequently)
        $cacheKey = hash('xxh3', $userAgent);
        if (isset(self::$uaCache[$cacheKey])) {
            return self::$uaCache[$cacheKey];
        }

        $result = [
            'browser' => null,
            'browser_name' => null,
            'browser_version' => null,
            'os' => null,
            'os_name' => null,
            'device' => null,
        ];

        // Combined browser regex - order matters (Edge before Chrome, etc.)
        // Using a single preg_match with alternation is faster than multiple preg_match calls
        if (preg_match('/(?:Edg(?:e|A|iOS)?|OPR|Opera|Chrome|Safari|Firefox|(?:MSIE |rv:))\/?([\d.]+)?/', $userAgent, $browserMatch)) {
            $fullMatch = $browserMatch[0];
            $version = $browserMatch[1] ?? null;
            
            // Determine browser from matched pattern
            if (str_contains($fullMatch, 'Edg')) {
                $result['browser_name'] = 'Edge';
            } elseif (str_contains($fullMatch, 'OPR') || str_contains($fullMatch, 'Opera')) {
                $result['browser_name'] = 'Opera';
            } elseif (str_contains($fullMatch, 'Chrome')) {
                $result['browser_name'] = 'Chrome';
            } elseif (str_contains($fullMatch, 'Safari')) {
                $result['browser_name'] = 'Safari';
            } elseif (str_contains($fullMatch, 'Firefox')) {
                $result['browser_name'] = 'Firefox';
            } elseif (str_contains($fullMatch, 'MSIE') || str_contains($fullMatch, 'rv:')) {
                $result['browser_name'] = 'IE';
            }

            if ($result['browser_name']) {
                $result['browser_version'] = $version;
                $result['browser'] = $version ? "{$result['browser_name']} $version" : $result['browser_name'];
            }
        }

        // Combined OS detection - single pattern with groups
        if (preg_match('/Windows NT ([\d.]+)|Mac OS X ([\d._]+)|(?:iPhone|iPad|iPod).*OS ([\d_]+)|Android ([\d.]+)|Linux|Ubuntu|CrOS/', $userAgent, $osMatch)) {
            $fullMatch = $osMatch[0];
            
            if (str_starts_with($fullMatch, 'Windows NT')) {
                $ntVersion = $osMatch[1] ?? '';
                $result['os_name'] = match ($ntVersion) {
                    '10.0' => str_contains($userAgent, 'Win64') ? 'Windows 11' : 'Windows 10',
                    '6.3' => 'Windows 8.1',
                    '6.2' => 'Windows 8',
                    '6.1' => 'Windows 7',
                    '6.0' => 'Windows Vista',
                    '5.1', '5.2' => 'Windows XP',
                    default => 'Windows',
                };
                $result['os'] = $result['os_name'];
            } elseif (str_contains($fullMatch, 'Mac OS X')) {
                $result['os_name'] = 'macOS';
                $version = str_replace('_', '.', $osMatch[2] ?? '');
                $result['os'] = $version ? "macOS $version" : 'macOS';
            } elseif (preg_match('/iPhone|iPad|iPod/', $fullMatch)) {
                $result['os_name'] = 'iOS';
                $version = str_replace('_', '.', $osMatch[3] ?? '');
                $result['os'] = $version ? "iOS $version" : 'iOS';
            } elseif (str_contains($fullMatch, 'Android')) {
                $result['os_name'] = 'Android';
                $version = $osMatch[4] ?? '';
                $result['os'] = $version ? "Android $version" : 'Android';
            } elseif ($fullMatch === 'Ubuntu') {
                $result['os_name'] = 'Ubuntu';
                $result['os'] = 'Ubuntu';
            } elseif ($fullMatch === 'CrOS') {
                $result['os_name'] = 'Chrome OS';
                $result['os'] = 'Chrome OS';
            } elseif ($fullMatch === 'Linux') {
                $result['os_name'] = 'Linux';
                $result['os'] = 'Linux';
            }
        }

        // Device detection - single combined pattern
        if (preg_match('/iPhone|iPod|iPad|Android.*Mobile|Android|Mobile/', $userAgent, $deviceMatch)) {
            $match = $deviceMatch[0];
            if ($match === 'iPhone' || $match === 'iPod') {
                $result['device'] = 'iPhone';
            } elseif ($match === 'iPad') {
                $result['device'] = 'iPad';
            } elseif (str_contains($match, 'Mobile') && str_contains($userAgent, 'Android')) {
                $result['device'] = 'Android Phone';
            } elseif ($match === 'Android') {
                $result['device'] = 'Android Tablet';
            } elseif ($match === 'Mobile') {
                $result['device'] = 'Mobile';
            }
        } elseif (preg_match('/Windows|Macintosh|Linux/', $userAgent)) {
            $result['device'] = 'Desktop';
        }

        // Cache result with LRU eviction
        if (count(self::$uaCache) >= self::UA_CACHE_MAX_SIZE) {
            // Remove oldest entry (first key)
            array_shift(self::$uaCache);
        }
        self::$uaCache[$cacheKey] = $result;

        return $result;
    }
}
