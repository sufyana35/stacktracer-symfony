<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\EventDispatcher;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A decorating EventDispatcher that creates spans for each listener.
 * 
 * This works in both development AND production environments, providing
 * detailed listener timing similar to Symfony's profiler.
 */
class TracingEventDispatcher implements EventDispatcherInterface
{
    private EventDispatcherInterface $dispatcher;
    private TracingService $tracing;
    
    /** @var array<string, bool> Events to trace (kernel events by default) */
    private array $tracedEvents;
    
    /** @var bool Whether tracing is currently active */
    private bool $isTracing = false;
    
    /** @var float Minimum duration in ms to create a span (reduces noise) */
    private float $minDurationMs;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        TracingService $tracing,
        array $tracedEvents = [],
        float $minDurationMs = 0.5
    ) {
        $this->dispatcher = $dispatcher;
        $this->tracing = $tracing;
        $this->minDurationMs = $minDurationMs;
        
        // Default: trace all kernel events
        $this->tracedEvents = $tracedEvents ?: [
            'kernel.request' => true,
            'kernel.controller' => true,
            'kernel.controller_arguments' => true,
            'kernel.view' => true,
            'kernel.response' => true,
            'kernel.finish_request' => true,
            'kernel.terminate' => true,
            'kernel.exception' => true,
            'security.interactive_login' => true,
            'security.logout_on_change' => true,
            'security.switch_user' => true,
        ];
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $eventName = $eventName ?? get_class($event);
        
        // For kernel.request, we need to let RequestTracingSubscriber create the trace first
        // After that, hasActiveTrace() will return true for subsequent events
        if ($eventName === 'kernel.request') {
            // First, dispatch the event normally to let trace be created
            $result = $this->dispatcher->dispatch($event, $eventName);
            return $result;
        }
        
        // Check if we should trace this event
        if (!$this->shouldTrace($eventName)) {
            return $this->dispatcher->dispatch($event, $eventName);
        }
        
        // Get listeners for this event
        $listeners = $this->dispatcher->getListeners($eventName);
        
        if (empty($listeners)) {
            return $this->dispatcher->dispatch($event, $eventName);
        }
        
        // Dispatch with listener timing
        foreach ($listeners as $listener) {
            if ($event instanceof Event && $event->isPropagationStopped()) {
                break;
            }
            
            $listenerName = $this->getListenerName($listener);
            $startTime = hrtime(true);
            
            // Call the listener
            $listener($event, $eventName, $this);
            
            $durationNs = hrtime(true) - $startTime;
            $durationMs = $durationNs / 1_000_000;
            
            // Create a span if duration exceeds threshold
            if ($durationMs >= $this->minDurationMs && $this->tracing->hasActiveTrace()) {
                $this->createListenerSpan($listenerName, $eventName, $durationMs);
            }
        }
        
        return $event;
    }

    private function shouldTrace(string $eventName): bool
    {
        // Always trace if the event is in our list
        if (isset($this->tracedEvents[$eventName])) {
            return $this->tracing->isEnabled() && $this->tracing->hasActiveTrace();
        }
        
        // Also trace any kernel.* events
        if (str_starts_with($eventName, 'kernel.')) {
            return $this->tracing->isEnabled() && $this->tracing->hasActiveTrace();
        }
        
        return false;
    }

    private function createListenerSpan(string $listenerName, string $eventName, float $durationMs): void
    {
        $span = $this->tracing->startSpan($listenerName, Span::KIND_INTERNAL);
        
        $span->setAttributes([
            'listener.name' => $listenerName,
            'listener.event' => $eventName,
            'listener.category' => $this->categorizeListener($listenerName),
            'duration_ms' => round($durationMs, 3),
        ]);
        
        $this->tracing->endSpan($span);
    }

    private function getListenerName(callable $listener): string
    {
        if (is_array($listener)) {
            if (is_object($listener[0])) {
                $class = get_class($listener[0]);
                // Shorten the class name for readability
                $shortClass = substr($class, strrpos($class, '\\') + 1);
                // Include method name for clarity
                $method = $listener[1] ?? '__invoke';
                return $shortClass . '::' . $method;
            }
            return $listener[0] . '::' . $listener[1];
        }
        
        if (is_object($listener)) {
            $class = get_class($listener);
            $shortClass = substr($class, strrpos($class, '\\') + 1);
            
            if ($listener instanceof \Closure) {
                // Try to get info about the closure
                try {
                    $ref = new \ReflectionFunction($listener);
                    if ($closureThis = $ref->getClosureThis()) {
                        $thisClass = get_class($closureThis);
                        $thisShort = substr($thisClass, strrpos($thisClass, '\\') + 1);
                        return $thisShort . '::closure';
                    }
                    // Check for closure class attribute (Symfony's lazy loading)
                    if ($attrs = $ref->getAttributes()) {
                        foreach ($attrs as $attr) {
                            if ($attr->getName() === 'Closure') {
                                $args = $attr->getArguments();
                                if (isset($args['class'])) {
                                    $cls = $args['class'];
                                    return substr($cls, strrpos($cls, '\\') + 1);
                                }
                            }
                        }
                    }
                } catch (\ReflectionException $e) {
                    // ignore
                }
                return 'Closure';
            }
            
            return $shortClass;
        }
        
        if (is_string($listener)) {
            return $listener;
        }
        
        return 'unknown';
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
        if (str_contains($nameLower, 'controller')) {
            return 'controller';
        }
        if (str_contains($nameLower, 'twig') || str_contains($nameLower, 'template')) {
            return 'template';
        }
        if (str_contains($nameLower, 'doctrine') || str_contains($nameLower, 'database')) {
            return 'database';
        }
        if (str_contains($nameLower, 'login') || str_contains($nameLower, 'logout')) {
            return 'auth';
        }
        
        return 'event_listener';
    }

    // ========================================
    // Delegate all other methods to inner dispatcher
    // ========================================

    /**
     * @param callable|array $listener Symfony uses lazy-loaded arrays in dev mode
     */
    public function addListener(string $eventName, $listener, int $priority = 0): void
    {
        $this->dispatcher->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->dispatcher->addSubscriber($subscriber);
    }

    /**
     * @param callable|array $listener
     */
    public function removeListener(string $eventName, $listener): void
    {
        $this->dispatcher->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->dispatcher->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->dispatcher->getListeners($eventName);
    }

    public function getListenerPriority(string $eventName, callable $listener): ?int
    {
        return $this->dispatcher->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }
}
