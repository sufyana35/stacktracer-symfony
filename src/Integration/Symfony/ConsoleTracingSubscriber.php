<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Console command subscriber for tracing CLI command execution.
 *
 * Creates OTEL-compatible spans with cli.* semantic conventions for all
 * Symfony console commands.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class ConsoleTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    private ?Span $activeSpan = null;

    private float $startTime = 0.0;

    private int $memoryStart = 0;

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 100],
            ConsoleEvents::ERROR => ['onError', 0],
            ConsoleEvents::TERMINATE => ['onTerminate', -100],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command === null) {
            return;
        }

        $commandName = $command->getName() ?? 'unknown';

        // Start a new trace for CLI commands
        $trace = $this->tracing->startTrace(sprintf('cli %s', $commandName));
        $trace->setAttribute('trace.type', 'cli');

        $this->activeSpan = $this->tracing->startSpan(sprintf('CMD %s', $commandName), 'cli');

        // OTEL cli.* semantic conventions
        $this->activeSpan->setAttribute('cli.command', $commandName);
        $this->activeSpan->setAttribute('cli.description', $command->getDescription() ?: null);

        // Capture arguments and options
        $input = $event->getInput();

        $arguments = $input->getArguments();
        if (!empty($arguments)) {
            // Filter out the command name argument
            unset($arguments['command']);
            if (!empty($arguments)) {
                $this->activeSpan->setAttribute('cli.arguments', $this->sanitizeArgs($arguments));
            }
        }

        $options = $input->getOptions();
        if (!empty($options)) {
            // Filter out empty options and verbose flags
            $options = array_filter($options, static fn ($v) => $v !== null && $v !== false && $v !== []);
            unset($options['verbose'], $options['version'], $options['help'], $options['quiet'], $options['ansi'], $options['no-ansi'], $options['no-interaction']);
            if (!empty($options)) {
                $this->activeSpan->setAttribute('cli.options', $this->sanitizeArgs($options));
            }
        }

        // Track memory and timing
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);

        // Environment info
        $this->activeSpan->setAttribute('cli.interactive', $input->isInteractive());
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        if ($this->activeSpan === null) {
            return;
        }

        $exception = $event->getError();

        $this->activeSpan->setStatus('error');
        $this->activeSpan->setAttribute('error.type', get_class($exception));
        $this->activeSpan->setAttribute('error.message', $exception->getMessage());
        $this->activeSpan->setAttribute('cli.exit_code', $event->getExitCode());

        // Capture the exception with full context
        $this->tracing->captureException($exception);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->activeSpan === null) {
            return;
        }

        $exitCode = $event->getExitCode();

        $this->activeSpan->setAttribute('cli.exit_code', $exitCode);

        // Calculate duration
        if ($this->startTime > 0) {
            $duration = (microtime(true) - $this->startTime) * 1000;
            $this->activeSpan->setAttribute('cli.duration_ms', round($duration, 2));
        }

        // Calculate memory usage
        if ($this->memoryStart > 0) {
            $memoryEnd = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            $this->activeSpan->setAttribute('cli.memory_used_mb', round($memoryEnd / 1024 / 1024, 2));
            $this->activeSpan->setAttribute('cli.memory_peak_mb', round($memoryPeak / 1024 / 1024, 2));
        }

        // Set status based on exit code
        if ($exitCode === 0) {
            $this->activeSpan->setStatus('ok');
        } else {
            $this->activeSpan->setStatus('error');
        }

        $this->tracing->finishSpan($this->activeSpan);
        $this->tracing->finishTrace();

        $this->activeSpan = null;
        $this->startTime = 0.0;
        $this->memoryStart = 0;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function sanitizeArgs(array $args): array
    {
        $sanitized = [];

        foreach ($args as $key => $value) {
            // Skip sensitive-looking arguments
            $lowerKey = strtolower($key);
            if (str_contains($lowerKey, 'password') || str_contains($lowerKey, 'secret') || str_contains($lowerKey, 'token') || str_contains($lowerKey, 'key') || str_contains($lowerKey, 'credential')) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_string($value)) {
                // Truncate long values
                $sanitized[$key] = strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = array_map(static fn ($v) => is_string($v) && strlen($v) > 100 ? substr($v, 0, 100) . '...' : $v, $value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = '[COMPLEX]';
            }
        }

        return $sanitized;
    }
}
