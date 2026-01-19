<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Service\TracingService;
use Twig\Extension\AbstractExtension;
use Twig\Profiler\Profile;

/**
 * Twig profiler extension for tracking template rendering.
 *
 * Creates spans with template.* semantic conventions for template rendering.
 * Tracks render times and captures template errors.
 *
 * Note: This requires the Twig profiler to be enabled. Register this
 * extension as a service and tag with `twig.extension`.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TwigTracingExtension extends AbstractExtension
{
    private TracingService $tracing;

    private float $slowThreshold;

    /** @var array<string, float> */
    private array $startTimes = [];

    public function __construct(TracingService $tracing, float $slowThreshold = 50.0)
    {
        $this->tracing = $tracing;
        $this->slowThreshold = $slowThreshold;
    }

    public function enter(Profile $profile): void
    {
        if ($profile->isRoot()) {
            return;
        }

        $key = $this->getProfileKey($profile);
        $this->startTimes[$key] = microtime(true);
    }

    public function leave(Profile $profile): void
    {
        if ($profile->isRoot()) {
            return;
        }

        $key = $this->getProfileKey($profile);
        $duration = 0.0;

        if (isset($this->startTimes[$key])) {
            $duration = (microtime(true) - $this->startTimes[$key]) * 1000;
            unset($this->startTimes[$key]);
        }

        // Only track templates (not blocks, macros, etc.) and slow renders
        if ($profile->isTemplate()) {
            $templateName = $profile->getName();

            $data = [
                'template.name' => $templateName,
                'template.type' => $profile->getType(),
                'template.duration_ms' => round($duration, 2),
            ];

            // Add breadcrumb for slow templates
            if ($duration >= $this->slowThreshold) {
                $data['template.slow'] = true;

                $this->tracing->addBreadcrumb(
                    sprintf('Slow template render: %s', $templateName),
                    'template',
                    'warning',
                    $data
                );
            }

            // Create span for template render
            $span = $this->tracing->startSpan(sprintf('template.%s', $this->getShortTemplateName($templateName)), 'template');
            $span->setAttributes($data);
            $span->setStatus('ok');
            $this->tracing->finishSpan($span);
        }
    }

    private function getProfileKey(Profile $profile): string
    {
        return sprintf('%s_%s_%s', $profile->getType(), $profile->getName(), spl_object_id($profile));
    }

    private function getShortTemplateName(string $name): string
    {
        // Remove common prefixes and path separators
        $name = str_replace(['@', '/', '\\'], ['', '.', '.'], $name);

        // Remove .html.twig extension
        $name = preg_replace('/\.(html|twig|xml|txt)\.twig$/', '', $name) ?? $name;
        $name = preg_replace('/\.twig$/', '', $name) ?? $name;

        return $name;
    }
}
