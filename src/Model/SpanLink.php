<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * OTEL Span Link - connects spans across traces.
 *
 * @see https://opentelemetry.io/docs/concepts/signals/traces/#span-links
 */
class SpanLink implements \JsonSerializable
{
    private SpanContext $context;

    private array $attributes;

    public function __construct(SpanContext $context, array $attributes = [])
    {
        $this->context = $context;
        $this->attributes = $attributes;
    }

    public function getContext(): SpanContext
    {
        return $this->context;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'trace_id' => $this->context->getTraceId(),
            'span_id' => $this->context->getSpanId(),
        ];

        $traceState = $this->context->getTraceState();
        if ($traceState !== '') {
            $data['trace_state'] = $traceState;
        }

        if (!empty($this->attributes)) {
            $data['attrs'] = $this->attributes;
        }

        return $data;
    }
}
