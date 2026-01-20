<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\HttpClient;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP Response wrapper that completes span tracing on response completion.
 *
 * Captures response status, headers, and body size as OTEL-compatible attributes.
 *
 * @author Stacktracer <hello@stacktracer.io>
 *
 * @internal
 */
final class TracingResponse implements ResponseInterface
{
    private ResponseInterface $response;

    private Span $span;

    private TracingService $tracing;

    private bool $spanFinished = false;

    public function __construct(ResponseInterface $response, Span $span, TracingService $tracing)
    {
        $this->response = $response;
        $this->span = $span;
        $this->tracing = $tracing;
    }

    public function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
        $this->finishSpanWithStatus($statusCode);

        return $statusCode;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        try {
            $headers = $this->response->getHeaders($throw);

            if (isset($headers['content-type'][0])) {
                $this->span->setAttribute('http.response.header.content-type', $headers['content-type'][0]);
            }

            return $headers;
        } catch (\Throwable $e) {
            $this->finishSpanWithError($e);

            throw $e;
        }
    }

    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->response->getContent($throw);
            $this->span->setAttribute('http.response.body.size', strlen($content));
            $this->finishSpanWithStatus($this->response->getStatusCode());

            return $content;
        } catch (\Throwable $e) {
            $this->finishSpanWithError($e);

            throw $e;
        }
    }

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array
    {
        try {
            $array = $this->response->toArray($throw);
            $this->finishSpanWithStatus($this->response->getStatusCode());

            return $array;
        } catch (\Throwable $e) {
            $this->finishSpanWithError($e);

            throw $e;
        }
    }

    public function cancel(): void
    {
        $this->span->setStatus('cancelled');
        $this->span->setAttribute('http.cancelled', true);
        $this->finishSpan();
        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    private function finishSpanWithStatus(int $statusCode): void
    {
        if ($this->spanFinished) {
            return;
        }

        $this->span->setAttribute('http.response.status_code', $statusCode);

        if ($statusCode >= 400 && $statusCode < 500) {
            $this->span->setStatus('error');
            $this->span->setAttribute('http.client_error', true);
        } elseif ($statusCode >= 500) {
            $this->span->setStatus('error');
            $this->span->setAttribute('http.server_error', true);
        } else {
            $this->span->setStatus('ok');
        }

        $this->finishSpan();
    }

    private function finishSpanWithError(\Throwable $e): void
    {
        if ($this->spanFinished) {
            return;
        }

        $this->span->setStatus('error');
        $this->span->setAttribute('error.type', get_class($e));
        $this->span->setAttribute('error.message', $e->getMessage());

        $this->finishSpan();
    }

    private function finishSpan(): void
    {
        if ($this->spanFinished) {
            return;
        }

        // Add timing info if available
        $info = $this->response->getInfo();
        if (isset($info['total_time'])) {
            $this->span->setAttribute('http.duration_ms', round($info['total_time'] * 1000, 2));
        }
        if (isset($info['namelookup_time'])) {
            $this->span->setAttribute('http.dns_time_ms', round($info['namelookup_time'] * 1000, 2));
        }
        if (isset($info['connect_time'])) {
            $this->span->setAttribute('http.connect_time_ms', round($info['connect_time'] * 1000, 2));
        }

        $this->tracing->endSpan($this->span);
        $this->spanFinished = true;
    }
}
