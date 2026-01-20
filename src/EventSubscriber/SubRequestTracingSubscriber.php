<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks Symfony subrequests (ESI, fragment rendering, forward requests).
 *
 * Creates child spans for each subrequest within a main request, providing
 * visibility into nested request processing within Symfony's HttpKernel.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class SubRequestTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    /** @var array<int, Span> Maps request hash to span */
    private array $activeSpans = [];

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 3],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            return;
        }

        if (!$this->tracing->isEnabled() || $this->tracing->getCurrentTrace() === null) {
            return;
        }

        $request = $event->getRequest();
        $requestHash = spl_object_id($request);

        $span = $this->tracing->startSpan(
            sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()),
            Span::KIND_INTERNAL
        );

        $span->setAttribute('http.request.method', $request->getMethod());
        $span->setAttribute('http.url', $request->getUri());
        $span->setAttribute('http.route', $this->getRouteName($request));
        $span->setAttribute('subrequest', true);

        // Add controller info if available
        $controller = $request->attributes->get('_controller');
        if ($controller !== null) {
            $span->setAttribute('code.function', $this->formatController($controller));
        }

        $this->activeSpans[$requestHash] = $span;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            return;
        }

        $requestHash = spl_object_id($event->getRequest());

        if (!isset($this->activeSpans[$requestHash])) {
            return;
        }

        $span = $this->activeSpans[$requestHash];
        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();

        $span->setAttribute('http.response.status_code', $statusCode);

        if ($statusCode >= 400) {
            $span->setStatus('error');
        } else {
            $span->setStatus('ok');
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            return;
        }

        $requestHash = spl_object_id($event->getRequest());

        if (!isset($this->activeSpans[$requestHash])) {
            return;
        }

        $span = $this->activeSpans[$requestHash];
        $this->tracing->endSpan($span);

        unset($this->activeSpans[$requestHash]);
    }

    private function getRouteName(mixed $request): string
    {
        $routeName = $request->attributes->get('_route');
        if ($routeName !== null && is_string($routeName)) {
            return $routeName;
        }

        $controller = $request->attributes->get('_controller');
        if ($controller !== null) {
            return $this->formatController($controller);
        }

        return '<unknown>';
    }

    private function formatController(mixed $controller): string
    {
        if (is_string($controller)) {
            return $controller;
        }

        if (is_array($controller) && count($controller) === 2) {
            if (is_object($controller[0])) {
                return get_class($controller[0]) . '::' . $controller[1];
            }
            if (is_string($controller[0])) {
                return $controller[0] . '::' . $controller[1];
            }
        }

        if (is_callable($controller) && is_object($controller)) {
            return get_class($controller) . '::__invoke';
        }

        return '<unknown>';
    }
}
