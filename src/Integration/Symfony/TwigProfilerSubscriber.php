<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

/**
 * Twig template rendering subscriber.
 *
 * Traces Twig template rendering by hooking into Twig's ProfilerExtension.
 * Creates spans with template.* semantic conventions.
 *
 * This automatically enables the Twig profiler if not already enabled.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TwigProfilerSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;
    private ?Environment $twig;
    private ?Profile $profile = null;
    private bool $profilerRegistered = false;
    
    /** @var float Threshold in ms for slow template warning */
    private float $slowThreshold;

    public function __construct(
        TracingService $tracing, 
        ?Environment $twig = null,
        float $slowThreshold = 50.0
    ) {
        $this->tracing = $tracing;
        $this->twig = $twig;
        $this->slowThreshold = $slowThreshold;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1000],
            KernelEvents::RESPONSE => ['onKernelResponse', 50],
        ];
    }

    /**
     * Called on request - register profiler extension if needed.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->twig) {
            return;
        }

        if ($this->profilerRegistered) {
            return;
        }

        // Create and register our own profile to collect template data
        $this->profile = new Profile();
        
        try {
            if (!$this->twig->hasExtension(ProfilerExtension::class)) {
                $this->twig->addExtension(new ProfilerExtension($this->profile));
                $this->profilerRegistered = true;
            } else {
                // Try to get the existing profile from the existing extension
                $existingExt = $this->twig->getExtension(ProfilerExtension::class);
                // We can't get the profile from existing extension, use our own
                // This might not capture templates rendered before we registered
            }
        } catch (\Exception $e) {
            // Extension already added or Twig is locked - that's ok
        }
    }

    /**
     * Called after response is generated - collect template profiling data.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled()) {
            return;
        }

        if (!$this->profile) {
            return;
        }

        // Process the collected profile data
        $this->processProfile($this->profile);
        
        // Reset the profile for the next request
        $this->profile->reset();
    }

    /**
     * Recursively process Twig profile and create spans.
     */
    private function processProfile(Profile $profile, int $depth = 0): void
    {
        if ($depth > 20) {
            return; // Prevent infinite recursion
        }

        // Process child profiles (templates are nested)
        foreach ($profile as $child) {
            $this->processChildProfile($child, $depth + 1);
        }
    }

    /**
     * Process a single profile entry.
     */
    private function processChildProfile(Profile $profile, int $depth): void
    {
        if ($profile->isTemplate()) {
            $templateName = $profile->getName();
            $duration = $profile->getDuration() * 1000; // Convert to ms
            
            $span = $this->tracing->startSpan(
                sprintf('twig.render %s', $this->getShortTemplateName($templateName)),
                Span::KIND_INTERNAL
            );
            
            $attributes = [
                'template.name' => $templateName,
                'template.engine' => 'twig',
                'template.duration_ms' => round($duration, 2),
            ];
            
            if ($duration >= $this->slowThreshold) {
                $attributes['template.slow'] = true;
                $this->tracing->addBreadcrumb(
                    sprintf('Slow template render: %s (%.2fms)', $templateName, $duration),
                    'template',
                    'warning',
                    $attributes
                );
            }
            
            $span->setAttributes($attributes);
            $span->setStatus('OK');
            $this->tracing->endSpan($span);
        }

        // Process nested profiles
        foreach ($profile as $child) {
            $this->processChildProfile($child, $depth + 1);
        }
    }

    private function getShortTemplateName(string $name): string
    {
        $name = str_replace(['@', '/', '\\'], ['', '.', '.'], $name);
        $name = preg_replace('/\.(html|twig|xml|txt)\.twig$/', '', $name) ?? $name;
        $name = preg_replace('/\.twig$/', '', $name) ?? $name;
        return $name;
    }
}
