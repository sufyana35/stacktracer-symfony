<?php

namespace Stacktracer\SymfonyBundle\Service;

use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Transport\TransportInterface;

/**
 * Main tracing service - the Stacktracer SDK.
 * Captures traces and sends them to the Stacktracer API.
 *
 * @example
 * ```php
 * // Capture an exception
 * $stacktracer->captureException($exception);
 *
 * // Capture a custom message
 * $stacktracer->captureMessage('User signed up', Trace::LEVEL_INFO, ['user_id' => 123]);
 *
 * // Add breadcrumbs during request lifecycle
 * $stacktracer->addBreadcrumb('auth', 'User logged in', ['user_id' => 123]);
 * ```
 */
class TracingService
{
    private TransportInterface $transport;
    private ?Trace $currentTrace = null;
    private array $globalTags = [];
    private array $globalContext = [];
    private bool $enabled;
    private int $exceptionContextLines;
    private int $stacktraceContextLines;
    private float $sampleRate;
    private int $maxStackFrames;
    private bool $captureCodeContext;
    private bool $filterVendorFrames;
    private bool $captureRequestHeaders;
    private array $sensitiveKeys;
    private ?string $projectDir = null;

    public function __construct(
        TransportInterface $transport,
        bool $enabled = true,
        int $exceptionContextLines = 5,
        int $stacktraceContextLines = 5,
        float $sampleRate = 1.0,
        int $maxStackFrames = 50,
        bool $captureCodeContext = true,
        bool $filterVendorFrames = true,
        bool $captureRequestHeaders = true,
        array $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'authorization', 'cookie']
    ) {
        $this->transport = $transport;
        $this->enabled = $enabled;
        $this->exceptionContextLines = $exceptionContextLines;
        $this->stacktraceContextLines = $stacktraceContextLines;
        $this->sampleRate = $sampleRate;
        $this->maxStackFrames = $maxStackFrames;
        $this->captureCodeContext = $captureCodeContext;
        $this->filterVendorFrames = $filterVendorFrames;
        $this->captureRequestHeaders = $captureRequestHeaders;
        $this->sensitiveKeys = array_map('strtolower', $sensitiveKeys);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function shouldSample(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $this->sampleRate;
    }

    public function shouldCaptureHeaders(): bool
    {
        return $this->captureRequestHeaders;
    }

    public function redactSensitiveData(array $data): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach ($this->sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactSensitiveData($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }

    public function startTrace(string $type = Trace::TYPE_REQUEST, string $message = ''): Trace
    {
        $this->currentTrace = new Trace($type, Trace::LEVEL_INFO, $message, $this->globalContext);

        foreach ($this->globalTags as $key => $value) {
            $this->currentTrace->addTag($key, $value);
        }

        return $this->currentTrace;
    }

    public function getCurrentTrace(): ?Trace
    {
        return $this->currentTrace;
    }

    public function endTrace(): void
    {
        if ($this->currentTrace === null || !$this->enabled) {
            return;
        }

        $duration = microtime(true) - $this->currentTrace->getTimestamp();
        $this->currentTrace->setDuration($duration);

        $this->transport->send($this->currentTrace);
        $this->currentTrace = null;
    }

    public function captureException(\Throwable $exception, array $context = []): Trace
    {
        $trace = new Trace(
            Trace::TYPE_EXCEPTION,
            Trace::LEVEL_ERROR,
            $exception->getMessage(),
            array_merge($this->globalContext, $context)
        );

        foreach ($this->globalTags as $key => $value) {
            $trace->addTag($key, $value);
        }

        $codeContext = [];
        if ($this->captureCodeContext && is_file($exception->getFile())) {
            $codeContext = $this->extractCodeContext(
                $exception->getFile(),
                $exception->getLine(),
                $this->exceptionContextLines
            );
        }

        $exceptionData = [
            'cls' => get_class($exception),
            'msg' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $this->shortenPath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $this->formatStackTrace($exception),
        ];

        if (!empty($codeContext)) {
            $exceptionData['code_context'] = $codeContext;
        }

        if ($exception->getPrevious()) {
            $exceptionData['prev'] = [
                'cls' => get_class($exception->getPrevious()),
                'msg' => $exception->getPrevious()->getMessage(),
            ];
        }

        $trace->setException($exceptionData);

        if ($this->currentTrace !== null) {
            $reflection = new \ReflectionClass($trace);
            $bcProp = $reflection->getProperty('breadcrumbs');
            $bcProp->setValue($trace, $this->currentTrace->getBreadcrumbs());

            if ($this->currentTrace->getRequest()) {
                $trace->setRequest($this->currentTrace->getRequest());
            }
        }

        if ($this->enabled) {
            $this->transport->send($trace);
        }

        return $trace;
    }

    public function captureMessage(string $message, string $level = Trace::LEVEL_INFO, array $context = []): Trace
    {
        $trace = new Trace(
            Trace::TYPE_CUSTOM,
            $level,
            $message,
            array_merge($this->globalContext, $context)
        );

        foreach ($this->globalTags as $key => $value) {
            $trace->addTag($key, $value);
        }

        if ($this->currentTrace !== null) {
            $reflection = new \ReflectionClass($trace);
            $bcProp = $reflection->getProperty('breadcrumbs');
            $bcProp->setValue($trace, $this->currentTrace->getBreadcrumbs());
        }

        if ($this->enabled) {
            $this->transport->send($trace);
        }

        return $trace;
    }

    public function addBreadcrumb(string $category, string $message, array $data = []): void
    {
        if ($this->currentTrace !== null) {
            $this->currentTrace->addBreadcrumb($category, $message, $data);
        }
    }

    public function setTag(string $key, string $value): void
    {
        $this->globalTags[$key] = $value;

        if ($this->currentTrace !== null) {
            $this->currentTrace->addTag($key, $value);
        }
    }

    public function setContext(string $key, mixed $value): void
    {
        $this->globalContext[$key] = $value;

        if ($this->currentTrace !== null) {
            $this->currentTrace->setContext([$key => $value]);
        }
    }

    public function flush(): void
    {
        $this->transport->flush();
    }

    private function formatStackTrace(\Throwable $exception): array
    {
        $frames = [];
        $traceData = $exception->getTrace();
        $frameCount = 0;
        $lastWasVendor = false;
        $collapsedVendorCount = 0;

        foreach ($traceData as $frame) {
            if ($frameCount >= $this->maxStackFrames) {
                $frames[] = [
                    'file' => '[truncated]',
                    'line' => 0,
                    'function' => sprintf('... and %d more frames', count($traceData) - $frameCount),
                    'class' => '',
                    'type' => '',
                    'is_vendor' => false,
                ];
                break;
            }

            $file = $frame['file'] ?? '[internal]';
            $isVendor = $this->isVendorFrame($file);

            if ($this->filterVendorFrames && $isVendor) {
                if ($lastWasVendor) {
                    $collapsedVendorCount++;
                    if (!empty($frames)) {
                        $frames[count($frames) - 1]['collapsed_count'] = $collapsedVendorCount;
                    }
                    continue;
                }
                $collapsedVendorCount = 0;
            } else {
                $collapsedVendorCount = 0;
            }

            $lastWasVendor = $isVendor;

            $frameData = [
                'file' => $this->shortenPath($file),
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? '',
                'class' => $frame['class'] ?? '',
                'type' => $frame['type'] ?? '',
                'is_vendor' => $isVendor,
            ];

            if ($this->captureCodeContext && !$isVendor) {
                if (isset($frame['file']) && isset($frame['line']) && is_file($frame['file'])) {
                    $frameData['code_context'] = $this->extractCodeContext(
                        $frame['file'],
                        $frame['line'],
                        $this->stacktraceContextLines
                    );
                }
            }

            $frames[] = $frameData;
            $frameCount++;
        }

        return $frames;
    }

    private function isVendorFrame(string $file): bool
    {
        return str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');
    }

    private function shortenPath(string $file): string
    {
        if ($this->projectDir === null) {
            if (preg_match('#^(.+?)/vendor/#', $file, $matches)) {
                $this->projectDir = $matches[1];
            }
        }

        if ($this->projectDir !== null && str_starts_with($file, $this->projectDir)) {
            return substr($file, strlen($this->projectDir) + 1);
        }

        return $file;
    }

    public function extractCodeContext(string $file, int $line, ?int $contextLines = null): array
    {
        if (!is_readable($file)) {
            return [];
        }

        $lines = @file($file);
        if ($lines === false) {
            return [];
        }

        $numLines = $contextLines ?? $this->exceptionContextLines;
        $start = max(0, $line - $numLines - 1);
        $end = min(count($lines), $line + $numLines);

        $context = [];
        for ($i = $start; $i < $end; $i++) {
            $context[] = [
                'ln' => $i + 1,
                'c' => rtrim($lines[$i]),
                'e' => ($i + 1) === $line,
            ];
        }

        return $context;
    }

    public function getExceptionContextLines(): int
    {
        return $this->exceptionContextLines;
    }

    public function getStacktraceContextLines(): int
    {
        return $this->stacktraceContextLines;
    }
}
