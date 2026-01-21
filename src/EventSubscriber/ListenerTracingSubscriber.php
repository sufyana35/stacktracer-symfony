<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

/**
 * Traces individual event listeners using Symfony's Stopwatch.
 * 
 * This provides detailed timing for every listener that runs during
 * the request lifecycle, similar to the Symfony Profiler's execution timeline.
 */
final class ListenerTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;
    private ?Stopwatch $stopwatch = null;
    private ?EventDispatcherInterface $dispatcher = null;
    
    /** @var array<string, array> Captured listener timings */
    private array $listenerTimings = [];
    
    /** @var bool Whether we're actively collecting */
    private bool $collecting = false;

    public function __construct(TracingService $tracing, ?Stopwatch $stopwatch = null, ?EventDispatcherInterface $dispatcher = null)
    {
        $this->tracing = $tracing;
        $this->stopwatch = $stopwatch ?? new Stopwatch(true);
        $this->dispatcher = $dispatcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequestStart', 4096],   // Very early
            KernelEvents::RESPONSE => ['onResponse', -4096],     // Very late
            KernelEvents::TERMINATE => ['onTerminate', -4096],   // Very late
        ];
    }

    public function onRequestStart(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->collecting = true;
        $this->listenerTimings = [];
        
        // Start the stopwatch for the main request
        $this->stopwatch->start('request');
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->collecting) {
            return;
        }
        
        // Collect listener timings from TraceableEventDispatcher if available
        $this->collectListenerTimings();
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$this->collecting) {
            return;
        }

        // Stop the main stopwatch
        if ($this->stopwatch->isStarted('request')) {
            $this->stopwatch->stop('request');
        }
        
        // Collect final listener timings
        $this->collectListenerTimings();
        
        // Create spans for all collected listeners
        $this->createListenerSpans();
        
        $this->collecting = false;
        $this->listenerTimings = [];
    }

    private function collectListenerTimings(): void
    {
        if (!$this->dispatcher instanceof TraceableEventDispatcher) {
            // Try to get timings from stopwatch sections
            $this->collectFromStopwatch();
            return;
        }

        // Get called listeners from TraceableEventDispatcher
        try {
            $calledListeners = $this->dispatcher->getCalledListeners();
            
            foreach ($calledListeners as $listener) {
                $key = $listener['pretty'] ?? ($listener['class'] ?? 'unknown');
                
                if (!isset($this->listenerTimings[$key])) {
                    $this->listenerTimings[$key] = [
                        'name' => $key,
                        'event' => $listener['event'] ?? 'unknown',
                        'class' => $listener['class'] ?? null,
                        'method' => $listener['method'] ?? null,
                        'priority' => $listener['priority'] ?? 0,
                        'duration_ms' => 0,
                        'memory_mb' => 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail if we can't get listener info
        }
    }

    private function collectFromStopwatch(): void
    {
        // Get all stopwatch events and extract listener timings
        try {
            $events = $this->stopwatch->getSectionEvents('__root__');
            
            foreach ($events as $name => $event) {
                if ($event instanceof StopwatchEvent) {
                    $this->listenerTimings[$name] = [
                        'name' => $name,
                        'event' => $this->guessEventFromName($name),
                        'duration_ms' => $event->getDuration(),
                        'memory_mb' => round($event->getMemory() / 1024 / 1024, 2),
                        'category' => $event->getCategory(),
                    ];
                }
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

        foreach ($this->listenerTimings as $timing) {
            $name = $timing['name'];
            
            // Determine category for coloring
            $category = $timing['category'] ?? $this->categorizeListener($name);
            
            $span = $this->tracing->startSpan($name, Span::KIND_INTERNAL);
            
            $attributes = [
                'listener.name' => $name,
                'listener.event' => $timing['event'] ?? 'unknown',
                'listener.category' => $category,
            ];
            
            if (isset($timing['class'])) {
                $attributes['code.namespace'] = $timing['class'];
            }
            if (isset($timing['method'])) {
                $attributes['code.function'] = $timing['method'];
            }
            if (isset($timing['priority'])) {
                $attributes['listener.priority'] = $timing['priority'];
            }
            if (isset($timing['duration_ms'])) {
                $attributes['duration_ms'] = $timing['duration_ms'];
            }
            if (isset($timing['memory_mb'])) {
                $attributes['memory_mb'] = $timing['memory_mb'];
            }
            
            $span->setAttributes($attributes);
            
            // End span immediately since we're creating historical spans
            $this->tracing->endSpan($span);
        }
    }

    private function categorizeListener(string $name): string
    {
        $name = strtolower($name);
        
        if (str_contains($name, 'firewall') || str_contains($name, 'security') || str_contains($name, 'authenticat')) {
            return 'security';
        }
        if (str_contains($name, 'router') || str_contains($name, 'routing')) {
            return 'router';
        }
        if (str_contains($name, 'profiler') || str_contains($name, 'debug')) {
            return 'debug';
        }
        if (str_contains($name, 'session')) {
            return 'session';
        }
        if (str_contains($name, 'locale') || str_contains($name, 'translation')) {
            return 'locale';
        }
        if (str_contains($name, 'validator') || str_contains($name, 'validation')) {
            return 'validation';
        }
        if (str_contains($name, 'controller')) {
            return 'controller';
        }
        if (str_contains($name, 'twig') || str_contains($name, 'template')) {
            return 'template';
        }
        if (str_contains($name, 'doctrine') || str_contains($name, 'database') || str_contains($name, 'entity')) {
            return 'database';
        }
        
        return 'event_listener';
    }

    private function guessEventFromName(string $name): string
    {
        $name = strtolower($name);
        
        if (str_contains($name, 'request')) {
            return 'kernel.request';
        }
        if (str_contains($name, 'controller')) {
            return 'kernel.controller';
        }
        if (str_contains($name, 'response')) {
            return 'kernel.response';
        }
        if (str_contains($name, 'terminate')) {
            return 'kernel.terminate';
        }
        if (str_contains($name, 'exception')) {
            return 'kernel.exception';
        }
        
        return 'unknown';
    }
}
