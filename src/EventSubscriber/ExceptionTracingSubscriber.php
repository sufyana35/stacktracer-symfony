<?php

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to exception events to capture them as traces.
 * Records exceptions in the current span for distributed tracing.
 */
class ExceptionTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->tracing->isEnabled()) {
            return;
        }

        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Add breadcrumb for the exception
        $this->tracing->addBreadcrumb('exception', 'Exception thrown', [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], Breadcrumb::LEVEL_ERROR);

        // Capture the exception with full context
        $trace = $this->tracing->captureException($exception, [
            'request_uri' => $request->getUri(),
            'route' => $request->attributes->get('_route'),
        ]);

        $trace->setRequest([
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'path' => $request->getPathInfo(),
            'query_string' => $request->getQueryString(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ]);

        // Record exception in current span (if exists)
        $currentSpan = $this->tracing->getCurrentSpan();
        if ($currentSpan) {
            $currentSpan->recordException($exception, [
                'http.url' => $request->getUri(),
                'http.route' => $request->attributes->get('_route'),
            ]);
        }
    }
}
