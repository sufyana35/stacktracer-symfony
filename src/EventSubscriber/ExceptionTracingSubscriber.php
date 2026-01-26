<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Stacktracer\SymfonyBundle\Util\OomHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to exception events to capture them as traces.
 *
 * Automatically captures unhandled exceptions, records them in the current span
 * for distributed tracing, and generates fingerprints for deduplication.
 *
 * Includes special handling for Out of Memory (OOM) errors - temporarily increases
 * memory limit to allow error reporting before the process dies.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class ExceptionTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    /**
     * Amount of memory (in bytes) to add when OOM is detected.
     * This allows error reporting to complete before the process dies.
     */
    private int $oomMemoryIncrease;

    public function __construct(TracingService $tracing, int $oomMemoryIncrease = OomHandler::DEFAULT_MEMORY_INCREASE)
    {
        $this->tracing = $tracing;
        $this->oomMemoryIncrease = $oomMemoryIncrease;
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

        // Check if this exception should be ignored
        if ($this->tracing->shouldIgnoreException($exception)) {
            return;
        }

        // Handle OOM errors specially - increase memory limit to allow reporting
        if (OomHandler::isOom($exception)) {
            OomHandler::handle($exception, $this->oomMemoryIncrease);
        }

        $request = $event->getRequest();

        // Add breadcrumb for the exception
        $this->tracing->addBreadcrumb('exception', 'Exception thrown', [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
        ], Breadcrumb::LEVEL_ERROR);

        // Capture the exception with full context
        $trace = $this->tracing->captureException($exception, [
            'route' => $request->attributes->get('_route'),
        ]);

        // Set request data (consolidated in request object, not duplicated in tags)
        $requestData = [
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'path' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent'),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
        ];
        if ($request->getQueryString()) {
            $requestData['qs'] = $request->getQueryString();
        }
        if ($route = $request->attributes->get('_route')) {
            $requestData['route'] = $route;
        }
        $trace->setRequest($requestData);

        // Minimal tags for filtering only
        if (method_exists($exception, 'getStatusCode')) {
            $trace->addTag('http.status_class', '5xx');
        }

        // Record exception in current span ONLY if not already recorded
        // (SpanManager::withSpan already records exceptions when they bubble up)
        $currentSpan = $this->tracing->getCurrentSpan();
        if ($currentSpan && !$this->tracing->isExceptionRecorded($exception)) {
            $currentSpan->recordException($exception);
            $this->tracing->markExceptionRecorded($exception, $currentSpan->getSpanId());
        }
    }
}
