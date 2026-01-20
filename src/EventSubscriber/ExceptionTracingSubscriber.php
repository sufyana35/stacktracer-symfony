<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;
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

    /**
     * Regex pattern to detect OOM error messages.
     */
    private const OOM_REGEX = '/Allowed memory size of (\d+) bytes exhausted/';

    public function __construct(TracingService $tracing, int $oomMemoryIncrease = 5242880)
    {
        $this->tracing = $tracing;
        $this->oomMemoryIncrease = $oomMemoryIncrease; // Default 5MB
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
        if ($this->isOutOfMemoryError($exception)) {
            $this->handleOutOfMemory($exception);
        }

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

        // Record exception in current span ONLY if not already recorded
        // (SpanManager::withSpan already records exceptions when they bubble up)
        $currentSpan = $this->tracing->getCurrentSpan();
        if ($currentSpan && !$this->tracing->isExceptionRecorded($exception)) {
            $currentSpan->recordException($exception, [
                'http.url' => $request->getUri(),
                'http.route' => $request->attributes->get('_route'),
            ]);
            $this->tracing->markExceptionRecorded($exception, $currentSpan->getSpanId());
        }
    }

    /**
     * Check if the throwable is an Out of Memory error.
     *
     * Handles both Symfony 4.4+ OutOfMemoryError and legacy OutOfMemoryException,
     * as well as detecting OOM from the error message pattern.
     */
    private function isOutOfMemoryError(\Throwable $throwable): bool
    {
        // Symfony 4.4+ uses OutOfMemoryError
        if ($throwable instanceof OutOfMemoryError) {
            return true;
        }

        // Legacy Symfony 2.x/3.x used OutOfMemoryException (check by class name for BC)
        if (str_contains(get_class($throwable), 'OutOfMemory')) {
            return true;
        }

        // Also detect by message pattern in case it's wrapped
        return preg_match(self::OOM_REGEX, $throwable->getMessage()) === 1;
    }

    /**
     * Handle Out of Memory errors by temporarily increasing memory limit.
     *
     * This allows error reporting to complete before the process terminates.
     * The memory increase is intentionally small (default 5MB) - just enough
     * to serialize and send the error report.
     */
    private function handleOutOfMemory(\Throwable $throwable): void
    {
        if ($this->oomMemoryIncrease <= 0) {
            return;
        }

        // Extract current memory limit from error message
        if (preg_match(self::OOM_REGEX, $throwable->getMessage(), $matches)) {
            $currentLimit = (int) $matches[1];
            $newLimit = $currentLimit + $this->oomMemoryIncrease;

            // Temporarily increase memory limit
            @ini_set('memory_limit', (string) $newLimit);
        }
    }
}
