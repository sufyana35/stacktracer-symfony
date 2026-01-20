<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Service;

/**
 * Custom metrics collection service.
 *
 * Provides methods to record counters, gauges, and distributions
 * that can be sent alongside traces for application monitoring.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class MetricsService
{
    /** @var array<string, array{type: string, value: float|int, tags: array<string, string>, timestamp: float}> */
    private array $metrics = [];

    private TracingService $tracing;

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    /**
     * Increment a counter metric.
     *
     * @param string $name Metric name
     * @param int|float $value Value to add (default: 1)
     * @param array<string, string> $tags Optional tags
     */
    public function count(string $name, int|float $value = 1, array $tags = []): void
    {
        $this->record('counter', $name, $value, $tags);
    }

    /**
     * Set a gauge metric (point-in-time value).
     *
     * @param string $name Metric name
     * @param int|float $value Current value
     * @param array<string, string> $tags Optional tags
     */
    public function gauge(string $name, int|float $value, array $tags = []): void
    {
        $this->record('gauge', $name, $value, $tags);
    }

    /**
     * Record a distribution metric (for percentiles/histograms).
     *
     * @param string $name Metric name
     * @param int|float $value Value to record
     * @param array<string, string> $tags Optional tags
     */
    public function distribution(string $name, int|float $value, array $tags = []): void
    {
        $this->record('distribution', $name, $value, $tags);
    }

    /**
     * Record timing in milliseconds.
     *
     * @param string $name Metric name
     * @param float $milliseconds Duration in ms
     * @param array<string, string> $tags Optional tags
     */
    public function timing(string $name, float $milliseconds, array $tags = []): void
    {
        $this->distribution($name, $milliseconds, $tags);
    }

    /**
     * Time a callable and record its duration.
     *
     * @template T
     *
     * @param string $name Metric name
     * @param callable(): T $callback Callback to time
     * @param array<string, string> $tags Optional tags
     *
     * @return T
     */
    public function time(string $name, callable $callback, array $tags = []): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $duration = (microtime(true) - $start) * 1000;
            $this->timing($name, $duration, $tags);
        }
    }

    /**
     * Increment a metric by 1.
     *
     * @param string $name Metric name
     * @param array<string, string> $tags Optional tags
     */
    public function increment(string $name, array $tags = []): void
    {
        $this->count($name, 1, $tags);
    }

    /**
     * Decrement a metric by 1.
     *
     * @param string $name Metric name
     * @param array<string, string> $tags Optional tags
     */
    public function decrement(string $name, array $tags = []): void
    {
        $this->count($name, -1, $tags);
    }

    /**
     * Record a metric.
     *
     * @param string $type Metric type (counter, gauge, distribution)
     * @param string $name Metric name
     * @param int|float $value Metric value
     * @param array<string, string> $tags Optional tags
     */
    private function record(string $type, string $name, int|float $value, array $tags): void
    {
        $key = $name . ':' . $type . ':' . md5(serialize($tags));

        $this->metrics[$key] = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ];

        // Attach to current trace if available
        $trace = $this->tracing->getCurrentTrace();
        if ($trace !== null) {
            $context = $trace->getContext();
            $existingMetrics = $context['metrics'] ?? [];
            $existingMetrics[] = $this->metrics[$key];
            $context['metrics'] = $existingMetrics;
            $trace->setContext($context);
        }
    }

    /**
     * Get all recorded metrics.
     *
     * @return array<string, array{type: string, value: float|int, tags: array<string, string>, timestamp: float}>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Flush and return all metrics, clearing the internal buffer.
     *
     * @return array<string, array{type: string, value: float|int, tags: array<string, string>, timestamp: float}>
     */
    public function flush(): array
    {
        $metrics = $this->metrics;
        $this->metrics = [];

        return $metrics;
    }
}
