<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Stacktracer\SymfonyBundle\Model\LogEntry;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Stacktracer\SymfonyBundle\Util\Fingerprint;

/**
 * Monolog handler that captures logs and links them to the current span.
 *
 * Integrates with Monolog to automatically capture log entries and attach them
 * to the current trace/span for unified observability. Supports filtering by
 * log level and channel.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class Handler extends AbstractProcessingHandler
{
    private TracingService $tracingService;

    private bool $captureContext;

    private array $excludeChannels;

    public function __construct(
        TracingService $tracingService,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        bool $captureContext = true,
        array $excludeChannels = []
    ) {
        parent::__construct($level, $bubble);
        $this->tracingService = $tracingService;
        $this->captureContext = $captureContext;
        $this->excludeChannels = $excludeChannels;
    }

    protected function write(LogRecord $record): void
    {
        // Skip if tracing is disabled
        if (!$this->tracingService->isEnabled()) {
            return;
        }

        // Skip excluded channels
        if (in_array($record->channel, $this->excludeChannels, true)) {
            return;
        }

        // Handle exception context - check if there's already an exception on the current trace
        $context = $this->captureContext ? $record->context : [];
        $exceptionInfo = null;
        
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            
            // Try to get the fingerprint from the current trace if already captured
            $currentTrace = $this->tracingService->getCurrentTrace();
            $traceException = $currentTrace?->getException();
            
            // Reuse fingerprint if same exception class and already on trace
            if ($traceException !== null && ($traceException['cls'] ?? '') === get_class($exception)) {
                $exceptionInfo = [
                    'class' => $traceException['cls'],
                    'message' => $traceException['msg'] ?? $exception->getMessage(),
                    'file' => $traceException['file'] ?? $exception->getFile(),
                    'line' => $traceException['line'] ?? $exception->getLine(),
                    'fp' => $traceException['fp'] ?? null,
                ];
            } else {
                // Exception not yet on trace (timing) or different exception - compute fingerprint
                $exceptionInfo = [
                    'class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'fp' => Fingerprint::exception($exception),
                ];
            }
            
            // Remove the Throwable from context (not serializable)
            unset($context['exception']);
        }

        // Create log entry with filtered context
        $entry = new LogEntry(
            $record->message,
            $record->level->getName(),
            $context
        );

        $entry->setChannel($record->channel);

        // Attach exception info if present
        if ($exceptionInfo !== null) {
            $entry->setAttribute('exception', $exceptionInfo);
        }

        // Add extra fields
        if (!empty($record->extra)) {
            foreach ($record->extra as $key => $value) {
                $entry->setAttribute('extra.' . $key, $value);
            }
        }

        // Capture source location from extra if available
        if (isset($record->extra['file'], $record->extra['line'])) {
            $entry->setSource(
                $record->extra['file'],
                $record->extra['line'],
                $record->extra['class'] ?? null
            );
        }

        // Add to current span
        $this->tracingService->addLogEntry($entry);
    }
}
