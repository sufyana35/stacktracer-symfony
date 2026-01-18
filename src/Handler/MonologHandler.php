<?php

namespace Stacktracer\SymfonyBundle\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;
use Stacktracer\SymfonyBundle\Model\LogEntry;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Monolog handler that captures logs and links them to the current span.
 */
class MonologHandler extends AbstractProcessingHandler
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

        // Create log entry
        $entry = new LogEntry(
            $record->message,
            $record->level->getName(),
            $this->captureContext ? $record->context : []
        );

        $entry->setChannel($record->channel);

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
