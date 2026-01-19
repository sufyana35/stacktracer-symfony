<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP Client decorator that traces outgoing HTTP requests.
 *
 * Creates OTEL-compatible spans with http.* semantic conventions for all
 * outgoing HTTP requests. Automatically propagates W3C trace context headers.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TracingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    private TracingService $tracing;

    private bool $propagateContext;

    public function __construct(
        HttpClientInterface $client,
        TracingService $tracing,
        bool $propagateContext = true
    ) {
        $this->client = $client;
        $this->tracing = $tracing;
        $this->propagateContext = $propagateContext;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';
        $scheme = $parsedUrl['scheme'] ?? 'http';
        $port = $parsedUrl['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parsedUrl['path'] ?? '/';

        $span = $this->tracing->startSpan(sprintf('%s %s', $method, $host), 'http');

        // Set OTEL http.* semantic attributes
        $span->setAttribute('http.request.method', $method);
        $span->setAttribute('url.full', $this->sanitizeUrl($url));
        $span->setAttribute('url.scheme', $scheme);
        $span->setAttribute('server.address', $host);
        $span->setAttribute('server.port', $port);
        $span->setAttribute('url.path', $path);

        if (isset($parsedUrl['query'])) {
            $span->setAttribute('url.query', $parsedUrl['query']);
        }

        // Propagate W3C trace context if enabled
        if ($this->propagateContext) {
            $options = $this->injectTraceContext($options, $span);
        }

        // Track request body size if available
        if (isset($options['body'])) {
            $bodySize = is_string($options['body']) ? strlen($options['body']) : null;
            if ($bodySize !== null) {
                $span->setAttribute('http.request.body.size', $bodySize);
            }
        }

        if (isset($options['headers']['Content-Type'])) {
            $span->setAttribute('http.request.header.content-type', $options['headers']['Content-Type']);
        }

        try {
            $response = $this->client->request($method, $url, $options);

            // Wrap response to capture status and finish span on completion
            return new TracingResponse($response, $span, $this->tracing);
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->finishSpan($span);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function injectTraceContext(array $options, Span $span): array
    {
        $options['headers'] = $options['headers'] ?? [];

        // W3C Trace Context propagation
        $context = $span->getContext();
        if ($context !== null) {
            $traceparent = sprintf(
                '00-%s-%s-%s',
                $context->getTraceId(),
                $context->getSpanId(),
                $context->isSampled() ? '01' : '00'
            );
            $options['headers']['traceparent'] = $traceparent;

            if ($context->getTraceState() !== null) {
                $options['headers']['tracestate'] = $context->getTraceState();
            }
        }

        return $options;
    }

    private function sanitizeUrl(string $url): string
    {
        // Remove password from URLs
        $parsed = parse_url($url);

        if (!isset($parsed['pass'])) {
            return $url;
        }

        $parsed['pass'] = '[REDACTED]';

        $result = '';
        if (isset($parsed['scheme'])) {
            $result .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['user'])) {
            $result .= $parsed['user'];
            if (isset($parsed['pass'])) {
                $result .= ':' . $parsed['pass'];
            }
            $result .= '@';
        }
        if (isset($parsed['host'])) {
            $result .= $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $result .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $result .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $result .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $result .= '#' . $parsed['fragment'];
        }

        return $result;
    }
}
