<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Traces individual event listeners using Symfony's Stopwatch and TraceableEventDispatcher.
 * 
 * This provides detailed timing for every listener that runs during
 * the request lifecycle, similar to the Symfony Profiler's execution timeline.
 */
final class ListenerTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;
    private ?Stopwatch $stopwatch;
    private ?EventDispatcherInterface $dispatcher;
    
    /** @var array<string, array> Captured listener timings */
    private array $listenerTimings = [];
    
    /** @var bool Whether we're actively collecting */
    private bool $collecting = false;
    
    /** @var float Request start time in microseconds */
    private float $requestStartTime = 0;

    public function __construct(
        TracingService $tracing, 
        ?Stopwatch $stopwatch = null, 
        ?EventDispatcherInterface $dispatcher = null
    ) {
        $this->tracing = $tracing;
        $this->stopwatch = $stopwatch;
        $this->dispatcher = $dispatcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequestStart', 800],    // After trace starts (1000)
            KernelEvents::TERMINATE => ['onTerminate', -800],    // Before trace ends (-1000)
        ];
    }

    public function onRequestStart(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->collecting = true;
        $this->listenerTimings = [];
        $this->requestStartTime = microtime(true) * 1000; // Convert to milliseconds
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$this->collecting || !$this->tracing->hasActiveTrace()) {
            return;
        }

        // Collect listener timings from Stopwatch
        $this->collectFromStopwatch();
        
        // Also collect from TraceableEventDispatcher if available
        $this->collectFromTraceableDispatcher();
        
        // Create spans for all collected listeners
        $this->createListenerSpans();
        
        $this->collecting = false;
        $this->listenerTimings = [];
    }

    private function collectFromStopwatch(): void
    {
        if ($this->stopwatch === null) {
            return;
        }

        try {
            // Get events from the __root__ section (default section)
            $events = $this->stopwatch->getSectionEvents('__root__');
            
            foreach ($events as $name => $event) {
                // Skip the main 'request' event - that's covered by our main span
                if ($name === 'request') {
                    continue;
                }
                
                $periods = $event->getPeriods();
                if (empty($periods)) {
                    continue;
                }
                
                // Get the first period for timing
                $period = $periods[0];
                $startTime = $period->getStartTime(); // in milliseconds relative to stopwatch start
                $duration = $period->getDuration();   // in milliseconds
                $memory = $period->getMemory();       // in bytes
                
                $this->listenerTimings[$name] = [
                    'name' => $name,
                    'start_offset_ms' => $startTime,
                    'duration_ms' => $duration,
                    'memory_bytes' => $memory,
                    'category' => $event->getCategory(),
                ];
            }
        } catch (\Throwable $e) {
            // Silently fail if we can't get stopwatch data
        }
    }

    private function collectFromTraceableDispatcher(): void
    {
        if (!$this->dispatcher instanceof TraceableEventDispatcher) {
            return;
        }

        try {
            $calledListeners = $this->dispatcher->getCalledListeners();
            
            foreach ($calledListeners as $listener) {
                $key = $listener['pretty'] ?? ($listener['class'] ?? 'unknown');
                
                // Skip if we already have this from stopwatch with timing
                if (isset($this->listenerTimings[$key]) && $this->listenerTimings[$key]['duration_ms'] > 0) {
                    continue;
                }
                
                // Add listener info (may not have precise timing)
                $this->listenerTimings[$key] = array_merge(
                    $this->listenerTimings[$key] ?? [],
                    [
                        'name' => $key,
                        'event' => $listener['event'] ?? 'unknown',
                        'class' => $listener['class'] ?? null,
                        'method' => $listener['method'] ?? null,
                        'priority' => $listener['priority'] ?? 0,
                        'duration_ms' => $this->listenerTimings[$key]['duration_ms'] ?? 0,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    private function createListenerSpans(): void
    {
        if (!$this->tracing->hasActiveTrace()) {
            return;
        }

        // Sort by start time if available
        uasort($this->listenerTimings, function ($a, $b) {
            return ($a['start_offset_ms'] ?? 0) <=> ($b['start_offset_ms'] ?? 0);
        });

        foreach ($this->listenerTimings as $timing) {
            $name = $timing['name'];
            $durationMs = $timing['duration_ms'] ?? 0;
            
            // Skip very short spans (less than 0.1ms) to reduce noise
            if ($durationMs < 0.1) {
                continue;
            }
            
            // Determine category for coloring
            $category = $timing['category'] ?? $this->categorizeListener($name);
            
            $span = $this->tracing->startSpan($name, Span::KIND_INTERNAL);
            
            $attributes = [
                'listener.name' => $name,
                'listener.category' => $category,
                'duration_ms' => round($durationMs, 3),
            ];
            
            if (isset($timing['event'])) {
                $attributes['listener.event'] = $timing['event'];
            }
            if (isset($timing['class'])) {
                $attributes['code.namespace'] = $timing['class'];
            }
            if (isset($timing['method'])) {
                $attributes['code.function'] = $timing['method'];
            }
            if (isset($timing['priority'])) {
                $attributes['listener.priority'] = $timing['priority'];
            }
            if (isset($timing['memory_bytes'])) {
                $attributes['memory_mb'] = round($timing['memory_bytes'] / 1024 / 1024, 2);
            }
            
            $span->setAttributes($attributes);
            
            // End span immediately since we're creating historical spans
            $this->tracing->endSpan($span);
        }
    }

    private function categorizeListener(string $name): string
    {
        $nameLower = strtolower($name);
        
        if (str_contains($nameLower, 'firewall') || str_contains($nameLower, 'security') || str_contains($nameLower, 'authenticat')) {
            return 'security';
        }
        if (str_contains($nameLower, 'router') || str_contains($nameLower, 'routing')) {
            return 'router';
        }
        if (str_contains($nameLower, 'profiler') || str_contains($nameLower, 'debug') || str_contains($nameLower, 'toolbar')) {
            return 'debug';
        }
        if (str_contains($nameLower, 'session')) {
            return 'session';
        }
        if (str_contains($nameLower, 'locale') || str_contains($nameLower, 'translation')) {
            return 'locale';
        }
        if (str_contains($nameLower, 'validator') || str_contains($nameLower, 'validation')) {
            return 'validation';
        }
        if (str_contains($nameLower, 'controller')) {
            return 'controller';
        }
        if (str_contains($nameLower, 'twig') || str_contains($nameLower, 'template')) {
            return 'template';
        }
        if (str_contains($nameLower, 'doctrine') || str_contains($nameLower, 'database') || str_contains($nameLower, 'entity')) {
            return 'database';
        }
        if (str_contains($nameLower, 'kernel')) {
            return 'kernel';
        }
        
        return 'event_listener';
    }
}
