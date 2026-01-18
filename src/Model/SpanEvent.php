<?php

namespace Stacktracer\SymfonyBundle\Model;

/**
 * OTEL Span Event - a timestamped annotation within a span.
 * 
 * @see https://opentelemetry.io/docs/concepts/signals/traces/#span-events
 */
class SpanEvent implements \JsonSerializable
{
    private string $name;
    private float $timestamp;
    private array $attributes;

    public function __construct(
        string $name,
        array $attributes = [],
        ?float $timestamp = null
    ) {
        $this->name = $name;
        $this->attributes = $attributes;
        $this->timestamp = $timestamp ?? microtime(true);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'ts' => (int)($this->timestamp * 1e9),
        ];
        
        if (!empty($this->attributes)) {
            $data['attrs'] = $this->attributes;
        }
        
        return $data;
    }
}
