<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
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
        $controllerName = $this->getControllerName($controller);
        
        if ($controllerName === null) {
            return;
        }

        $this->controllerSpan = $this->tracing->startSpan(
            sprintf('controller %s', $controllerName),
            Span::KIND_INTERNAL
        );

        $this->controllerSpan->setAttributes([
            'code.function' => $controllerName,
            'code.namespace' => $this->getControllerNamespace($controller),
        ]);

        // Add route info if available
        $request = $event->getRequest();
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

    /**
     * Extract controller name from the controller callable.
     */
    private function getControllerName(mixed $controller): ?string
    {
        if (is_array($controller)) {
            // [ControllerClass, 'methodName']
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            $method = $controller[1];
            
            // Get short class name
            $shortClass = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
            
            return sprintf('%s::%s', $shortClass, $method);
        }

        if (is_object($controller) && method_exists($controller, '__invoke')) {
            $class = get_class($controller);
            $shortClass = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
            return sprintf('%s::__invoke', $shortClass);
        }

        if (is_string($controller)) {
            // 'Controller::method' format
            if (str_contains($controller, '::')) {
                $parts = explode('::', $controller);
                $shortClass = substr(strrchr($parts[0], '\\') ?: $parts[0], 1) ?: $parts[0];
                return sprintf('%s::%s', $shortClass, $parts[1]);
            }
            return $controller;
        }

        return null;
    }

    /**
     * Get the controller's namespace.
     */
    private function getControllerNamespace(mixed $controller): ?string
    {
        if (is_array($controller) && isset($controller[0])) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            $pos = strrpos($class, '\\');
            return $pos !== false ? substr($class, 0, $pos) : null;
        }

        if (is_object($controller)) {
            $class = get_class($controller);
            $pos = strrpos($class, '\\');
            return $pos !== false ? substr($class, 0, $pos) : null;
        }

        return null;
    }
}
