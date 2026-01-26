<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Twig;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Twig\Extension\AbstractExtension;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

/**
 * Twig tracing extension that hooks into Twig's profiler.
 * 
 * This extension creates spans for each template, block, and macro render,
 * similar to Sentry's TwigTracingExtension approach.
 *
 * The key difference from our ProfilerSubscriber is that this hooks directly
 * into Twig's profiling mechanism via enter() and leave() callbacks, giving
 * us precise timing for each template operation.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TwigTracingExtension extends AbstractExtension
{
    private TracingService $tracing;
    
    /** @var \SplObjectStorage<Profile, Span> Maps profiles to their spans */
    private \SplObjectStorage $spans;
    
    /** @var float Slow threshold in ms */
    private float $slowThreshold;

    public function __construct(TracingService $tracing, float $slowThreshold = 50.0)
    {
        $this->tracing = $tracing;
        $this->spans = new \SplObjectStorage();
        $this->slowThreshold = $slowThreshold;
    }

    /**
     * Called before the execution of a block, macro, or template.
     *
     * @param Profile $profile The profiling data
     */
    public function enter(Profile $profile): void
    {
        if (!$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }
        
        $span = $this->tracing->startSpan(
            $this->getSpanDescription($profile),
            Span::KIND_INTERNAL
        );
        
        $span->setOrigin('auto.view');
        $span->setAttributes([
            'template.name' => $this->getTemplateName($profile),
            'template.type' => $this->getProfileType($profile),
            'template.engine' => 'twig',
        ]);
        
        // Store span keyed by profile object
        $this->spans[$profile] = $span;
    }

    /**
     * Called when the execution of a block, macro, or template is finished.
     *
     * @param Profile $profile The profiling data
     */
    public function leave(Profile $profile): void
    {
        if (!isset($this->spans[$profile])) {
            return;
        }

        /** @var Span $span */
        $span = $this->spans[$profile];
        
        // Calculate duration for slow template detection
        $durationMs = $span->getDurationMs() ?? 0;
        
        // Mark slow templates
        if ($durationMs >= $this->slowThreshold) {
            $span->setAttribute('template.slow', true);
            $this->tracing->addBreadcrumb(
                'template',
                sprintf('Slow template render: %s (%.2fms)', $this->getTemplateName($profile), $durationMs),
                ['template.name' => $this->getTemplateName($profile), 'duration_ms' => $durationMs],
                'warning'
            );
        }
        
        $span->setOk();
        $this->tracing->endSpan($span);

        unset($this->spans[$profile]);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodeVisitors(): array
    {
        return [
            new ProfilerNodeVisitor(self::class),
        ];
    }

    /**
     * Get a short description for the span.
     */
    private function getSpanDescription(Profile $profile): string
    {
        switch (true) {
            case $profile->isRoot():
                return 'twig.render ' . $profile->getName();

            case $profile->isTemplate():
                return 'twig.render ' . $this->getShortTemplateName($profile->getTemplate());

            case $profile->isBlock():
                return sprintf('twig.block %s::%s', 
                    $this->getShortTemplateName($profile->getTemplate()),
                    $profile->getName()
                );

            case $profile->isMacro():
                return sprintf('twig.macro %s::%s', 
                    $this->getShortTemplateName($profile->getTemplate()),
                    $profile->getName()
                );

            default:
                return sprintf('twig.%s %s', 
                    $profile->getType(),
                    $profile->getName()
                );
        }
    }

    private function getTemplateName(Profile $profile): string
    {
        if ($profile->isRoot()) {
            return $profile->getName();
        }
        
        return $profile->getTemplate();
    }

    private function getProfileType(Profile $profile): string
    {
        if ($profile->isRoot()) {
            return 'root';
        }
        if ($profile->isTemplate()) {
            return 'template';
        }
        if ($profile->isBlock()) {
            return 'block';
        }
        if ($profile->isMacro()) {
            return 'macro';
        }
        
        return $profile->getType();
    }

    private function getShortTemplateName(string $name): string
    {
        // Remove @ prefix from bundle namespaces
        $name = ltrim($name, '@');
        
        // Convert slashes to dots for readability
        $name = str_replace(['/', '\\'], '.', $name);
        
        // Remove common extensions
        $name = preg_replace('/\.(html|twig|xml|txt)\.twig$/', '', $name) ?? $name;
        $name = preg_replace('/\.twig$/', '', $name) ?? $name;
        
        return $name;
    }
}
