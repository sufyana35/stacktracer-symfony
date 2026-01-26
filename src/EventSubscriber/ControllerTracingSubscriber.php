<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Stacktracer\SymfonyBundle\Util\ControllerResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Automatically traces controller execution.
 *
 * Creates spans for controller method execution with timing and metadata.
 * This is optional - developers can also manually wrap controller logic
 * using TracingService::withSpan() for finer control.
 *
 * Enable/disable via configuration:
 *   stacktracer:
 *     integrations:
 *       controller:
 *         enabled: true
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class ControllerTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;
    private bool $enabled;
    private ?Span $controllerSpan = null;
    private ?Span $initSpan = null;

    public function __construct(TracingService $tracing, bool $enabled = true)
    {
        $this->tracing = $tracing;
        $this->enabled = $enabled;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
            KernelEvents::RESPONSE => ['onResponse', 100],
            KernelEvents::EXCEPTION => ['onException', 100],
        ];
    }

    /**
     * Start a span when controller is resolved.
     */
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->enabled || !$this->tracing->isEnabled()) {
            return;
        }

        $controller = $event->getController();
        $controllerName = ControllerResolver::getName($controller);
        
        if ($controllerName === null) {
            return;
        }

        // Create an initialization span covering time from request start to now
        // This captures routing, firewall, security, and other Symfony overhead
        $request = $event->getRequest();
        $requestStartTime = $request->server->get('REQUEST_TIME_FLOAT');
        
        if ($requestStartTime) {
            $currentTime = microtime(true);
            $initDurationMs = ($currentTime - $requestStartTime) * 1000;
            
            // Only create init span if there's meaningful initialization time (> 1ms)
            if ($initDurationMs > 1) {
                $this->initSpan = $this->tracing->startSpan(
                    'symfony.kernel.init',
                    Span::KIND_INTERNAL
                );
                $this->initSpan->setOrigin('auto.http.server');
                $this->initSpan->setAttributes([
                    'symfony.init_duration_ms' => round($initDurationMs, 2),
                    'symfony.route' => $request->attributes->get('_route') ?? 'unknown',
                    'symfony.phase' => 'routing, security, firewall',
                ]);
                // Backdate the start time and immediately end
                $this->initSpan->setStartTime($requestStartTime);
                $this->initSpan->end($currentTime);
            }
        }

        $this->controllerSpan = $this->tracing->startSpan(
            sprintf('controller %s', $controllerName),
            Span::KIND_INTERNAL
        );
        $this->controllerSpan->setOrigin('auto.http.server');

        $this->controllerSpan->setAttributes([
            'code.function' => $controllerName,
            'code.namespace' => ControllerResolver::getNamespace($controller),
        ]);

        // Add route info if available
        $route = $request->attributes->get('_route');
        if ($route) {
            $this->controllerSpan->setAttribute('http.route', $route);
        }
    }

    /**
     * End controller span on response.
     */
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->controllerSpan === null) {
            return;
        }

        $this->controllerSpan->setStatus('OK');
        $this->tracing->endSpan($this->controllerSpan);
        $this->controllerSpan = null;
    }

    /**
     * End controller span on exception.
     */
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || $this->controllerSpan === null) {
            return;
        }

        $exception = $event->getThrowable();
        $this->controllerSpan->setStatus('ERROR', $exception->getMessage());
        $this->controllerSpan->setAttribute('exception.type', get_class($exception));
        $this->tracing->endSpan($this->controllerSpan);
        $this->controllerSpan = null;
    }
}
