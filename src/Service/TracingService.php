<?php

namespace Stacktracer\SymfonyBundle\Service;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Model\LogEntry;
use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Model\SpanContext;
use Stacktracer\SymfonyBundle\Model\StackFrame;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Transport\TransportInterface;
use Stacktracer\SymfonyBundle\Util\Fingerprint;

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
    private SpanManager $spanManager;
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
    private string $serviceName;
    private string $serviceVersion;

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
        array $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'authorization', 'cookie'],
        string $serviceName = 'unknown',
        string $serviceVersion = '0.0.0'
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
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->spanManager = new SpanManager($serviceName, $serviceVersion);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ========================================
    // SPAN MANAGEMENT (OTEL-compatible)
    // ========================================

    /**
     * Get the span manager for advanced span operations.
     */
    public function getSpanManager(): SpanManager
    {
        return $this->spanManager;
    }

    /**
     * Start a new span within the current trace.
     */
    public function startSpan(string $name, string $kind = Span::KIND_INTERNAL): Span
    {
        $span = $this->spanManager->startSpan($name, $kind);
        
        // Sync trace context
        if ($this->currentTrace) {
            $this->currentTrace->setSpanId($span->getSpanId());
        }
        
        return $span;
    }

    /**
     * End the current span.
     */
    public function endSpan(?Span $span = null): ?Span
    {
        return $this->spanManager->endSpan($span);
    }

    /**
     * Get current span.
     */
    public function getCurrentSpan(): ?Span
    {
        return $this->spanManager->getCurrentSpan();
    }

    /**
     * Get current trace ID.
     */
    public function getCurrentTraceId(): ?string
    {
        if ($this->currentTrace) {
            return $this->currentTrace->getTraceId();
        }
        return $this->spanManager->getCurrentTraceId();
    }

    /**
     * Get current span ID.
     */
    public function getCurrentSpanId(): ?string
    {
        return $this->spanManager->getCurrentSpanId();
    }

    /**
     * Check if an exception has already been recorded.
     */
    public function isExceptionRecorded(\Throwable $e): bool
    {
        return $this->spanManager->isExceptionRecorded($e);
    }

    /**
     * Mark an exception as recorded.
     */
    public function markExceptionRecorded(\Throwable $e, string $spanId): void
    {
        $this->spanManager->markExceptionRecorded($e, $spanId);
    }

    /**
     * Execute a callback within a span.
     */
    public function withSpan(string $name, callable $callback, string $kind = Span::KIND_INTERNAL): mixed
    {
        return $this->spanManager->withSpan($name, $callback, $kind);
    }

    /**
     * Set root context from incoming traceparent header.
     */
    public function setIncomingContext(string $traceparent): void
    {
        $context = SpanContext::fromTraceparent($traceparent);
        if ($context) {
            $this->spanManager->setRootContext($context);
            if ($this->currentTrace) {
                $this->currentTrace->setTraceId($context->getTraceId());
                $this->currentTrace->setParentSpanId($context->getSpanId());
            }
        }
    }

    /**
     * Get traceparent header for outgoing requests.
     */
    public function getTraceparent(): ?string
    {
        return $this->spanManager->getTraceparent();
    }

    /**
     * Add a log entry to the current span/trace.
     */
    public function addLogEntry(LogEntry $entry): void
    {
        $span = $this->spanManager->getCurrentSpan();
        
        if ($span) {
            $span->addLog($entry);
        }
        
        if ($this->currentTrace) {
            $this->currentTrace->addLog($entry);
        }
    }

    /**
     * Create and add a log entry.
     */
    public function log(string $message, string $level = 'info', array $context = []): LogEntry
    {
        $entry = new LogEntry($message, $level, $context);
        $this->addLogEntry($entry);
        return $entry;
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

        // Sync trace ID with span manager
        $rootContext = $this->spanManager->getRootContext();
        if ($rootContext) {
            $this->currentTrace->setTraceId($rootContext->getTraceId());
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
            $this->spanManager->clear();
            return;
        }

        $duration = microtime(true) - $this->currentTrace->getTimestamp();
        $this->currentTrace->setDuration($duration);

        // Attach all spans to the trace
        $this->currentTrace->setSpans($this->spanManager->getSpans());

        // Compute fingerprints for deduplication
        $this->currentTrace->computeFingerprint();
        $this->currentTrace->computeGroupKey();

        $this->transport->send($this->currentTrace);
        $this->transport->flush();
        
        // Clear state
        $this->spanManager->clear();
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

        // Capture code context at exception location
        $codeContext = [];
        if ($this->captureCodeContext && is_file($exception->getFile())) {
            $codeContext = $this->extractCodeContext(
                $exception->getFile(),
                $exception->getLine(),
                $this->exceptionContextLines
            );
        }

        // Build stack frames with fingerprinting
        $stackFrames = StackFrame::fromException($exception, $this->filterVendorFrames, $this->maxStackFrames);
        $stackFingerprint = StackFrame::computeStackFingerprint($stackFrames);
        $stackGroupKey = StackFrame::computeStackGroupKey($stackFrames);

        // Add code context to frames
        if ($this->captureCodeContext) {
            foreach ($stackFrames as $frame) {
                if (!$frame->isVendor() && $frame->getFile() !== '[internal]' && is_file($frame->getFile())) {
                    $frame->setCodeContext(
                        $this->extractCodeContext($frame->getFile(), $frame->getLine(), $this->stacktraceContextLines)
                    );
                }
            }
        }

        $exceptionData = [
            'cls' => get_class($exception),
            'msg' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $this->shortenPath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => array_map(fn($f) => $f->jsonSerialize(), $stackFrames),
            'stack_fp' => $stackFingerprint,
            'stack_gk' => $stackGroupKey,
        ];

        if (!empty($codeContext)) {
            $exceptionData['ctx'] = $codeContext;
        }

        if ($exception->getPrevious()) {
            $exceptionData['prev'] = [
                'cls' => get_class($exception->getPrevious()),
                'msg' => $exception->getPrevious()->getMessage(),
            ];
        }

        // Compute exception fingerprint for deduplication
        $exceptionData['fp'] = Fingerprint::exception($exception);
        $exceptionData['gk'] = Fingerprint::exceptionGroup($exception);

        $trace->setException($exceptionData);
        $trace->setFingerprint($exceptionData['fp']);
        $trace->setGroupKey($exceptionData['gk']);

        // Sync trace ID with span manager
        if ($this->spanManager->getCurrentTraceId()) {
            $trace->setTraceId($this->spanManager->getCurrentTraceId());
        }
        if ($this->spanManager->getCurrentSpanId()) {
            $trace->setSpanId($this->spanManager->getCurrentSpanId());
        }

        // Record exception in current span if exists
        $currentSpan = $this->spanManager->getCurrentSpan();
        if ($currentSpan) {
            $currentSpan->recordException($exception);
        }

        if ($this->currentTrace !== null) {
            // Copy breadcrumbs from current trace
            foreach ($this->currentTrace->getBreadcrumbs() as $bc) {
                $trace->addBreadcrumbObject($bc instanceof Breadcrumb ? $bc : new Breadcrumb(
                    $bc['category'] ?? 'default',
                    $bc['message'] ?? '',
                    $bc['level'] ?? Breadcrumb::LEVEL_INFO,
                    $bc['data'] ?? []
                ));
            }

            // Copy logs from current trace
            foreach ($this->currentTrace->getLogs() as $log) {
                $trace->addLog($log);
            }

            if ($this->currentTrace->getRequest()) {
                $trace->setRequest($this->currentTrace->getRequest());
            }
        }

        // Attach spans to exception trace
        $trace->setSpans($this->spanManager->getSpans());

        // If we're in an active request trace, attach exception to it instead of sending separately
        if ($this->currentTrace !== null) {
            // Attach exception data to the current request trace
            $this->currentTrace->setException($exceptionData);
            $this->currentTrace->setLevel(Trace::LEVEL_ERROR);
            $this->currentTrace->setFingerprint($exceptionData['fp']);
            $this->currentTrace->setGroupKey($exceptionData['gk']);
            // Don't send - will be sent with endTrace()
            return $trace;
        }

        // Only send standalone exception trace if no request trace is active
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

        // Sync trace ID with span manager
        if ($this->spanManager->getCurrentTraceId()) {
            $trace->setTraceId($this->spanManager->getCurrentTraceId());
        }
        if ($this->spanManager->getCurrentSpanId()) {
            $trace->setSpanId($this->spanManager->getCurrentSpanId());
        }

        // Compute fingerprint
        $trace->setFingerprint(Fingerprint::logMessage($message, null, $level));
        $trace->computeGroupKey();

        if ($this->currentTrace !== null) {
            // Copy breadcrumbs from current trace
            foreach ($this->currentTrace->getBreadcrumbs() as $bc) {
                $trace->addBreadcrumbObject($bc instanceof Breadcrumb ? $bc : new Breadcrumb(
                    $bc['category'] ?? 'default',
                    $bc['message'] ?? '',
                    $bc['level'] ?? Breadcrumb::LEVEL_INFO,
                    $bc['data'] ?? []
                ));
            }

            // Copy logs from current trace
            foreach ($this->currentTrace->getLogs() as $log) {
                $trace->addLog($log);
            }
        }

        // Attach spans
        $trace->setSpans($this->spanManager->getSpans());

        if ($this->enabled) {
            $this->transport->send($trace);
        }

        return $trace;
    }

    public function addBreadcrumb(string $category, string $message, array $data = [], string $level = Breadcrumb::LEVEL_INFO): void
    {
        $breadcrumb = new Breadcrumb($category, $message, $level, $data);
        $breadcrumb->captureSource(2);

        // Add to current span
        $currentSpan = $this->spanManager->getCurrentSpan();
        if ($currentSpan) {
            $currentSpan->addBreadcrumb($breadcrumb);
        }

        // Add to current trace
        if ($this->currentTrace !== null) {
            $this->currentTrace->addBreadcrumbObject($breadcrumb);
        }
    }

    /**
     * Create a breadcrumb linked to the current span.
     */
    public function breadcrumb(string $category, string $message, string $level = Breadcrumb::LEVEL_INFO, array $data = []): Breadcrumb
    {
        $breadcrumb = new Breadcrumb($category, $message, $level, $data);
        $breadcrumb->captureSource(2);
        
        $currentSpan = $this->spanManager->getCurrentSpan();
        if ($currentSpan) {
            $currentSpan->addBreadcrumb($breadcrumb);
        }
        
        if ($this->currentTrace !== null) {
            $this->currentTrace->addBreadcrumbObject($breadcrumb);
        }
        
        return $breadcrumb;
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

        $codeLines = [];
        $errorIdx = null;
        for ($i = $start; $i < $end; $i++) {
            $codeLines[] = rtrim($lines[$i]);
            if (($i + 1) === $line) {
                $errorIdx = count($codeLines) - 1;
            }
        }

        // Ultra-compact format: [startLine, [code...], errorIndex]
        // Frontend calculates line numbers: startLine + index
        // Saves ~70% space vs verbose format
        return [
            $start + 1,      // Starting line number
            $codeLines,      // Array of code strings
            $errorIdx        // Index of error line (0-based)
        ];
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
