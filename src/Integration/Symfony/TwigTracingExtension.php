<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Service\TracingService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that provides tracing functions for template rendering.
 *
 * This extension adds `stacktracer_span_start` and `stacktracer_span_end` functions
 * that can be used to manually trace template sections.
 *
 * It also integrates with TwigTracingRuntime for automatic template tracing.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TwigTracingExtension extends AbstractExtension
{
    private TracingService $tracing;

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('stacktracer_span_start', [$this, 'startSpan']),
            new TwigFunction('stacktracer_span_end', [$this, 'endSpan']),
            new TwigFunction('stacktracer_breadcrumb', [$this, 'addBreadcrumb']),
        ];
    }

    /**
     * Start a span for a template section.
     *
     * @param string $name The span name
     * @param array<string, mixed> $attributes Additional attributes
     * @return string The span ID for ending it later
     */
    public function startSpan(string $name, array $attributes = []): string
    {
        if (!$this->tracing->isEnabled()) {
            return '';
        }

        $span = $this->tracing->startSpan(sprintf('twig.%s', $name), 'template');
        $span->setAttributes(array_merge([
            'template.section' => $name,
        ], $attributes));

        return $span->getSpanId();
    }

    /**
     * End a span by its ID.
     *
     * @param string $spanId The span ID returned by startSpan
     */
    public function endSpan(string $spanId): void
    {
        if (!$this->tracing->isEnabled() || empty($spanId)) {
            return;
        }

        $span = $this->tracing->getCurrentSpan();
        if ($span && $span->getSpanId() === $spanId) {
            $span->setStatus('ok');
            $this->tracing->endSpan($span);
        }
    }

    /**
     * Add a breadcrumb from a template.
     *
     * @param string $message The breadcrumb message
     * @param string $category The category (default: template)
     * @param array<string, mixed> $data Additional data
     */
    public function addBreadcrumb(string $message, string $category = 'template', array $data = []): void
    {
        if (!$this->tracing->isEnabled()) {
            return;
        }

        $this->tracing->addBreadcrumb($message, $category, 'info', $data);
    }
}
