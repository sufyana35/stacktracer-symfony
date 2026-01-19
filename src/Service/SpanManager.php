<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Service;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Model\SpanContext;

/**
 * Manages the current span context stack for distributed tracing.
 *
 * Handles span lifecycle, parent-child relationships, and trace context propagation
 * for OpenTelemetry-compatible distributed tracing. Thread-safe span context propagation.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class SpanManager
{
    /** @var Span[] Stack of active spans */
    private array $spanStack = [];

    /** @var Span[] All spans in current trace */
    private array $spans = [];

    /** @var \SplObjectStorage Tracks exceptions already recorded */
    private \SplObjectStorage $recordedExceptions;

    private ?SpanContext $rootContext = null;

    private string $serviceName;

    private string $serviceVersion;

    public function __construct(
        string $serviceName = 'unknown',
        string $serviceVersion = '0.0.0'
    ) {
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->recordedExceptions = new \SplObjectStorage();
    }

    /**
     * Set root context from incoming request (e.g., from traceparent header).
     */
    public function setRootContext(SpanContext $context): void
    {
        $this->rootContext = $context;
    }

    /**
     * Get root context.
     */
    public function getRootContext(): ?SpanContext
    {
        return $this->rootContext;
    }

    /**
     * Start a new span.
     */
    public function startSpan(
        string $name,
        string $kind = Span::KIND_INTERNAL,
        ?SpanContext $context = null
    ): Span {
        $parentSpan = $this->getCurrentSpan();

        if ($context === null) {
            if ($parentSpan) {
                // Create child of current span
                $context = $parentSpan->getContext()->createChild();
            } elseif ($this->rootContext) {
                // Create child of root context
                $context = $this->rootContext->createChild();
            } else {
                // Create new root span
                $context = new SpanContext();
                $this->rootContext = $context;
            }
        }

        $parentSpanId = $parentSpan ? $parentSpan->getSpanId() : null;

        $span = new Span(
            $name,
            $kind,
            $context,
            $parentSpanId,
            $this->serviceName,
            $this->serviceVersion
        );

        $this->spanStack[] = $span;
        $this->spans[] = $span;

        return $span;
    }

    /**
     * End the current span.
     */
    public function endSpan(?Span $span = null): ?Span
    {
        if ($span === null) {
            $span = array_pop($this->spanStack);
        } else {
            // Find and remove specific span from stack
            $index = array_search($span, $this->spanStack, true);
            if ($index !== false) {
                array_splice($this->spanStack, $index, 1);
            }
        }

        if ($span && !$span->isEnded()) {
            $span->end();
        }

        return $span;
    }

    /**
     * Get the current active span.
     */
    public function getCurrentSpan(): ?Span
    {
        return end($this->spanStack) ?: null;
    }

    /**
     * Get current trace ID.
     */
    public function getCurrentTraceId(): ?string
    {
        if ($this->rootContext) {
            return $this->rootContext->getTraceId();
        }

        $span = $this->getCurrentSpan();

        return $span ? $span->getTraceId() : null;
    }

    /**
     * Get current span ID.
     */
    public function getCurrentSpanId(): ?string
    {
        $span = $this->getCurrentSpan();

        return $span ? $span->getSpanId() : null;
    }

    /**
     * Get all spans in the current trace.
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Get completed spans only.
     */
    public function getCompletedSpans(): array
    {
        return array_filter($this->spans, fn (Span $s) => $s->isEnded());
    }

    /**
     * Clear all spans (for request end).
     */
    public function clear(): void
    {
        // End any remaining spans
        foreach ($this->spanStack as $span) {
            if (!$span->isEnded()) {
                $span->end();
            }
        }

        $this->spanStack = [];
        $this->spans = [];
        $this->rootContext = null;
        $this->recordedExceptions = new \SplObjectStorage();
    }

    /**
     * Check if an exception has already been recorded.
     */
    public function isExceptionRecorded(\Throwable $e): bool
    {
        return $this->recordedExceptions->contains($e);
    }

    /**
     * Mark an exception as recorded.
     */
    public function markExceptionRecorded(\Throwable $e, string $spanId): void
    {
        $this->recordedExceptions->attach($e, $spanId);
    }

    /**
     * Execute callback within a span context.
     */
    public function withSpan(string $name, callable $callback, string $kind = Span::KIND_INTERNAL): mixed
    {
        $span = $this->startSpan($name, $kind);

        try {
            $result = $callback($span);
            $span->setOk();

            return $result;
        } catch (\Throwable $e) {
            // Only record exception if not already recorded (prevents duplicates on bubble-up)
            if (!$this->recordedExceptions->contains($e)) {
                $span->recordException($e);
                $this->recordedExceptions->attach($e, $span->getSpanId());
            }
            throw $e;
        } finally {
            $this->endSpan($span);
        }
    }

    /**
     * Get trace data for propagation (W3C format).
     */
    public function getTraceparent(): ?string
    {
        $span = $this->getCurrentSpan();
        if ($span) {
            return $span->getContext()->toTraceparent();
        }

        if ($this->rootContext) {
            return $this->rootContext->toTraceparent();
        }

        return null;
    }

    /**
     * Build a span tree from flat list.
     */
    public function buildSpanTree(): array
    {
        $tree = [];
        $spanMap = [];

        // Index spans by ID
        foreach ($this->spans as $span) {
            $spanMap[$span->getSpanId()] = [
                'span' => $span,
                'children' => [],
            ];
        }

        // Build tree
        foreach ($this->spans as $span) {
            $parentId = $span->getParentSpanId();

            if ($parentId && isset($spanMap[$parentId])) {
                $spanMap[$parentId]['children'][] = &$spanMap[$span->getSpanId()];
            } else {
                $tree[] = &$spanMap[$span->getSpanId()];
            }
        }

        return $tree;
    }

    /**
     * Serialize all spans for transport.
     */
    public function jsonSerialize(): array
    {
        return [
            'trace_id' => $this->getCurrentTraceId(),
            'service' => [
                'name' => $this->serviceName,
                'version' => $this->serviceVersion,
            ],
            'spans' => array_map(fn (Span $s) => $s->jsonSerialize(), $this->spans),
            'span_count' => count($this->spans),
        ];
    }
}
