<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traces detailed kernel events to provide Symfony Profiler-like spans.
 * 
 * This subscriber captures granular timing information for:
 * - kernel.request phase
 * - kernel.controller resolution
 * - kernel.controller_arguments
 * - kernel.view (for non-Response returns)
 * - kernel.response phase
 * - kernel.finish_request
 * - kernel.terminate phase
 */
final class KernelEventTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;
    
    /** @var array<string, Span> Active spans keyed by event name */
    private array $spans = [];
    
    /** @var array<string, float> Start times for measuring durations */
    private array $startTimes = [];

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    public static function getSubscribedEvents(): array
    {
        // We use specific priorities to wrap around other listeners
        return [
            // kernel.request - capture the entire request handling phase
            KernelEvents::REQUEST => [
                ['onRequestStart', 2048],    // Very early - start span
                ['onRequestEnd', -2048],     // Very late - end span
            ],
            
            // kernel.controller - when controller is resolved
            KernelEvents::CONTROLLER => [
                ['onControllerStart', 2048],
                ['onControllerEnd', -2048],
            ],
            
            // kernel.controller_arguments - when controller arguments are resolved
            KernelEvents::CONTROLLER_ARGUMENTS => [
                ['onControllerArgumentsStart', 2048],
                ['onControllerArgumentsEnd', -2048],
            ],
            
            // kernel.view - when controller returns non-Response
            KernelEvents::VIEW => [
                ['onViewStart', 2048],
                ['onViewEnd', -2048],
            ],
            
            // kernel.response - response processing phase
            KernelEvents::RESPONSE => [
                ['onResponseStart', 2048],
                ['onResponseEnd', -2048],
            ],
            
            // kernel.finish_request
            KernelEvents::FINISH_REQUEST => [
                ['onFinishRequestStart', 2048],
                ['onFinishRequestEnd', -2048],
            ],
            
            // kernel.terminate - after response is sent
            KernelEvents::TERMINATE => [
                ['onTerminateStart', 2048],
                ['onTerminateEnd', -2048],
            ],
        ];
    }

    // ========================================
    // kernel.request handlers
    // ========================================
    
    public function onRequestStart(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['kernel.request'] = microtime(true);
        
        $span = $this->tracing->startSpan('kernel.request', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.request',
            'http.method' => $event->getRequest()->getMethod(),
            'http.path' => $event->getRequest()->getPathInfo(),
        ]);
        
        $this->spans['kernel.request'] = $span;
    }

    public function onRequestEnd(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $this->endSpan('kernel.request');
    }

    // ========================================
    // kernel.controller handlers
    // ========================================
    
    public function onControllerStart(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['controller.get_callable'] = microtime(true);
        
        $controller = $event->getController();
        $controllerName = $this->getControllerName($controller);
        
        $span = $this->tracing->startSpan('controller.get_callable', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.controller',
            'code.function' => $controllerName,
        ]);
        
        $this->spans['controller.get_callable'] = $span;
    }

    public function onControllerEnd(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $this->endSpan('controller.get_callable');
    }

    // ========================================
    // kernel.controller_arguments handlers
    // ========================================
    
    public function onControllerArgumentsStart(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['controller.arguments'] = microtime(true);
        
        $span = $this->tracing->startSpan('controller.arguments', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.controller_arguments',
            'argument_count' => count($event->getArguments()),
        ]);
        
        $this->spans['controller.arguments'] = $span;
    }

    public function onControllerArgumentsEnd(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $this->endSpan('controller.arguments');
    }

    // ========================================
    // kernel.view handlers
    // ========================================
    
    public function onViewStart(ViewEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['kernel.view'] = microtime(true);
        
        $span = $this->tracing->startSpan('kernel.view', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.view',
        ]);
        
        $this->spans['kernel.view'] = $span;
    }

    public function onViewEnd(ViewEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $this->endSpan('kernel.view');
    }

    // ========================================
    // kernel.response handlers
    // ========================================
    
    public function onResponseStart(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['kernel.response'] = microtime(true);
        
        $response = $event->getResponse();
        
        $span = $this->tracing->startSpan('kernel.response', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.response',
            'http.status_code' => $response->getStatusCode(),
        ]);
        
        $this->spans['kernel.response'] = $span;
    }

    public function onResponseEnd(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $this->endSpan('kernel.response');
    }

    // ========================================
    // kernel.finish_request handlers
    // ========================================
    
    public function onFinishRequestStart(FinishRequestEvent $event): void
    {
        if (!$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['kernel.finish_request'] = microtime(true);
        
        $span = $this->tracing->startSpan('kernel.finish_request', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.finish_request',
        ]);
        
        $this->spans['kernel.finish_request'] = $span;
    }

    public function onFinishRequestEnd(FinishRequestEvent $event): void
    {
        $this->endSpan('kernel.finish_request');
    }

    // ========================================
    // kernel.terminate handlers
    // ========================================
    
    public function onTerminateStart(TerminateEvent $event): void
    {
        if (!$this->tracing->isEnabled() || !$this->tracing->hasActiveTrace()) {
            return;
        }

        $this->startTimes['kernel.terminate'] = microtime(true);
        
        $span = $this->tracing->startSpan('kernel.terminate', Span::KIND_INTERNAL);
        $span->setAttributes([
            'symfony.event' => 'kernel.terminate',
        ]);
        
        $this->spans['kernel.terminate'] = $span;
    }

    public function onTerminateEnd(TerminateEvent $event): void
    {
        $this->endSpan('kernel.terminate');
    }

    // ========================================
    // Helper methods
    // ========================================

    private function endSpan(string $name): void
    {
        if (!isset($this->spans[$name])) {
            return;
        }

        $span = $this->spans[$name];
        
        // Calculate duration if we have start time
        if (isset($this->startTimes[$name])) {
            $duration = (microtime(true) - $this->startTimes[$name]) * 1000;
            $span->setAttribute('duration_ms', round($duration, 3));
            unset($this->startTimes[$name]);
        }
        
        $this->tracing->endSpan($span);
        unset($this->spans[$name]);
    }

    private function getControllerName(mixed $controller): string
    {
        if (is_array($controller)) {
            if (is_object($controller[0])) {
                return get_class($controller[0]) . '::' . $controller[1];
            }
            return $controller[0] . '::' . $controller[1];
        }

        if (is_object($controller)) {
            if (method_exists($controller, '__invoke')) {
                return get_class($controller) . '::__invoke';
            }
            return get_class($controller);
        }

        if (is_string($controller)) {
            return $controller;
        }

        return 'unknown';
    }
}
