<?php

namespace Stacktracer\SymfonyBundle\Model;

/**
 * OpenTelemetry-compatible Span Context for distributed tracing.
 * 
 * Follows W3C Trace Context specification:
 * @see https://www.w3.org/TR/trace-context/
 * @see https://opentelemetry.io/docs/concepts/signals/traces/#span-context
 */
class SpanContext implements \JsonSerializable
{
    public const TRACE_FLAG_SAMPLED = 0x01;
    public const TRACE_FLAG_RANDOM = 0x02;

    private string $traceId;
    private string $spanId;
    private int $traceFlags;
    private string $traceState;
    private bool $isRemote;

    public function __construct(
        ?string $traceId = null,
        ?string $spanId = null,
        int $traceFlags = self::TRACE_FLAG_SAMPLED,
        string $traceState = '',
        bool $isRemote = false
    ) {
        $this->traceId = $traceId ?? self::generateTraceId();
        $this->spanId = $spanId ?? self::generateSpanId();
        $this->traceFlags = $traceFlags;
        $this->traceState = $traceState;
        $this->isRemote = $isRemote;
    }

    /**
     * Generate a 128-bit trace ID (32 hex chars) per OTEL spec.
     */
    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a 64-bit span ID (16 hex chars) per OTEL spec.
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Create context from W3C traceparent header.
     * Format: version-traceId-parentSpanId-traceFlags
     */
    public static function fromTraceparent(string $traceparent): ?self
    {
        $parts = explode('-', $traceparent);
        
        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if ($version !== '00' || strlen($traceId) !== 32 || strlen($spanId) !== 16) {
            return null;
        }

        return new self(
            $traceId,
            $spanId,
            hexdec($flags),
            '',
            true
        );
    }

    /**
     * Create child context with new span ID but same trace ID.
     */
    public function createChild(): self
    {
        return new self(
            $this->traceId,
            self::generateSpanId(),
            $this->traceFlags,
            $this->traceState,
            false
        );
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getTraceFlags(): int
    {
        return $this->traceFlags;
    }

    public function isSampled(): bool
    {
        return ($this->traceFlags & self::TRACE_FLAG_SAMPLED) !== 0;
    }

    public function isRemote(): bool
    {
        return $this->isRemote;
    }

    public function getTraceState(): string
    {
        return $this->traceState;
    }

    /**
     * Generate W3C traceparent header value.
     */
    public function toTraceparent(): string
    {
        return sprintf(
            '00-%s-%s-%02x',
            $this->traceId,
            $this->spanId,
            $this->traceFlags
        );
    }

    /**
     * Check if this is a valid context.
     */
    public function isValid(): bool
    {
        return strlen($this->traceId) === 32 
            && strlen($this->spanId) === 16
            && $this->traceId !== str_repeat('0', 32)
            && $this->spanId !== str_repeat('0', 16);
    }

    public function jsonSerialize(): array
    {
        $data = [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
        ];
        
        // Only include non-default flags
        if ($this->traceFlags !== self::TRACE_FLAG_SAMPLED) {
            $data['flags'] = $this->traceFlags;
        }
        
        if ($this->traceState !== '') {
            $data['state'] = $this->traceState;
        }
        
        if ($this->isRemote) {
            $data['remote'] = true;
        }
        
        return $data;
    }
}
