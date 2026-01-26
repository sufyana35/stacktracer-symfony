<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Service;

use Stacktracer\SymfonyBundle\Model\Breadcrumb;
use Stacktracer\SymfonyBundle\Model\FeatureFlag;
use Stacktracer\SymfonyBundle\Model\LogEntry;
use Stacktracer\SymfonyBundle\Model\Server;
use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Model\SpanContext;
use Stacktracer\SymfonyBundle\Model\StackFrame;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Model\User;
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

    /** @var FeatureFlag[] */
    private array $featureFlags = [];

    private bool $enabled;

    private bool $spansEnabled;

    private int $exceptionContextLines;

    private int $stacktraceContextLines;

    private float $sampleRate;

    private int $maxStackFrames;

    private bool $captureCodeContext;

    private bool $filterVendorFrames;

    private bool $captureRequestHeaders;

    private bool $captureRequestBody;

    private int $maxBodySize;

    private bool $captureFiles;

    private array $sensitiveKeys;

    private ?string $projectDir = null;

    private string $serviceName;

    private string $serviceVersion;

    private ?User $globalUser = null;

    private ?Server $server = null;

    private ?string $release = null;

    /** @var array<array{tags: array, context: array, breadcrumbs: array}> */
    private array $scopeStack = [];

    /** @var string[] Exception classes to ignore */
    private array $ignoredExceptions = [];

    /** @var string[] Transaction/route names to ignore */
    private array $ignoredTransactions = [];

    /** @var callable[] Array of before-send callbacks */
    private array $beforeSendCallbacks = [];

    /** @var callable[] Array of before-send-transaction callbacks */
    private array $beforeSendTransactionCallbacks = [];

    private ?string $environment = null;

    /** @var array|null Cached installed packages */
    private ?array $cachedPackages = null;

    /** @var bool Whether to collect package info */
    private bool $collectPackages = true;

    /** @var array<string, string> Compiled regex patterns for ignored exceptions */
    private array $compiledExceptionPatterns = [];

    /** @var array<string, string> Compiled regex patterns for ignored transactions */
    private array $compiledTransactionPatterns = [];

    /** @var array<string, string> Path shortening cache */
    private array $pathCache = [];

    /** @var int Maximum path cache size */
    private const PATH_CACHE_MAX_SIZE = 200;

    /** @var array<string, array> File content cache for code context */
    private array $fileCache = [];

    /** @var int Maximum file cache entries */
    private const FILE_CACHE_MAX_SIZE = 20;

    public function __construct(
        TransportInterface $transport,
        bool $enabled = true,
        bool $spansEnabled = false,
        int $exceptionContextLines = 5,
        int $stacktraceContextLines = 5,
        float $sampleRate = 1.0,
        int $maxStackFrames = 50,
        bool $captureCodeContext = true,
        bool $filterVendorFrames = true,
        bool $captureRequestHeaders = true,
        bool $captureRequestBody = true,
        int $maxBodySize = 10240,
        bool $captureFiles = true,
        array $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'authorization', 'cookie'],
        string $serviceName = 'unknown',
        string $serviceVersion = '0.0.0',
        ?string $environment = null,
        array $ignoredExceptions = [],
        array $ignoredTransactions = []
    ) {
        $this->transport = $transport;
        $this->enabled = $enabled;
        $this->spansEnabled = $spansEnabled;
        $this->exceptionContextLines = $exceptionContextLines;
        $this->stacktraceContextLines = $stacktraceContextLines;
        $this->sampleRate = $sampleRate;
        $this->maxStackFrames = $maxStackFrames;
        $this->captureCodeContext = $captureCodeContext;
        $this->filterVendorFrames = $filterVendorFrames;
        $this->captureRequestHeaders = $captureRequestHeaders;
        $this->captureRequestBody = $captureRequestBody;
        $this->maxBodySize = $maxBodySize;
        $this->captureFiles = $captureFiles;
        $this->sensitiveKeys = array_map('strtolower', $sensitiveKeys);
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->ignoredExceptions = $ignoredExceptions;
        $this->ignoredTransactions = $ignoredTransactions;
        $this->spanManager = new SpanManager($serviceName, $serviceVersion);
        
        // Auto-detect environment from Symfony kernel if not explicitly set
        if ($environment !== null) {
            $this->environment = $environment;
        } elseif (isset($_SERVER['APP_ENV'])) {
            $this->environment = $_SERVER['APP_ENV'];
        } elseif (getenv('APP_ENV')) {
            $this->environment = getenv('APP_ENV');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if OTEL spans are enabled (paid feature).
     */
    public function isSpansEnabled(): bool
    {
        return $this->spansEnabled;
    }

    // ========================================
    // SPAN MANAGEMENT (OTEL-compatible, paid feature)
    // ========================================

    /**
     * Get the span manager for advanced span operations.
     * Note: Spans are only included in payloads if spansEnabled is true.
     */
    public function getSpanManager(): SpanManager
    {
        return $this->spanManager;
    }

    /**
     * Start a new span within the current trace.
     * Returns a no-op span if spans are disabled.
     */
    public function startSpan(string $name, string $kind = Span::KIND_INTERNAL): Span
    {
        // Always create span for internal tracking (breadcrumbs/logs link to spans)
        // but only include in output if spansEnabled
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
     * Check if there is an active trace.
     */
    public function hasActiveTrace(): bool
    {
        return $this->currentTrace !== null;
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

    public function shouldCaptureRequestBody(): bool
    {
        return $this->captureRequestBody;
    }

    public function getMaxBodySize(): int
    {
        return $this->maxBodySize;
    }

    public function shouldCaptureFiles(): bool
    {
        return $this->captureFiles;
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

        // Attach any pre-registered feature flags
        if (!empty($this->featureFlags)) {
            $this->currentTrace->setFeatureFlags($this->featureFlags);
        }

        // Attach user context
        if ($this->globalUser !== null) {
            $this->currentTrace->setUser($this->globalUser);
        }

        // Attach release and environment
        if ($this->release !== null) {
            $this->currentTrace->setRelease($this->release);
        }
        if ($this->environment !== null) {
            $this->currentTrace->setEnvironment($this->environment);
        }

        // Attach server info (lazy-initialized and cached)
        $this->currentTrace->setServer($this->getServer());

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

        // Attach spans only if spans feature is enabled (paid feature)
        if ($this->spansEnabled) {
            $this->currentTrace->setSpans($this->spanManager->getSpans());
        }

        // Compute fingerprints for deduplication
        $this->currentTrace->computeFingerprint();
        $this->currentTrace->computeGroupKey();

        // Attach installed packages
        $this->attachPackagesToTrace($this->currentTrace);

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

        // Attach feature flags
        if (!empty($this->featureFlags)) {
            $trace->setFeatureFlags($this->featureFlags);
        }

        // Attach user context
        if ($this->globalUser !== null) {
            $trace->setUser($this->globalUser);
        }

        // Attach release and environment
        if ($this->release !== null) {
            $trace->setRelease($this->release);
        }
        if ($this->environment !== null) {
            $trace->setEnvironment($this->environment);
        }

        // Attach server info (lazy-initialized and cached)
        $trace->setServer($this->getServer());

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
            'trace' => array_map(fn ($f) => $f->jsonSerialize(), $stackFrames),
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
        $fingerprint = Fingerprint::exception($exception);
        $groupKey = Fingerprint::exceptionGroup($exception);
        $exceptionData['fp'] = $fingerprint;
        $exceptionData['gk'] = $groupKey;

        $trace->setException($exceptionData);
        $trace->setFingerprint(is_array($fingerprint) ? implode(':', $fingerprint) : $fingerprint);
        $trace->setGroupKey(is_array($groupKey) ? implode(':', $groupKey) : $groupKey);

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

        // Attach spans only if spans feature is enabled (paid feature)
        if ($this->spansEnabled) {
            $trace->setSpans($this->spanManager->getSpans());
        }

        // If we're in an active request trace, attach exception to it instead of sending separately
        if ($this->currentTrace !== null) {
            // Attach exception data to the current request trace
            $this->currentTrace->setException($exceptionData);
            $this->currentTrace->setLevel(Trace::LEVEL_ERROR);
            $this->currentTrace->setFingerprint(is_array($exceptionData['fp']) ? implode(':', $exceptionData['fp']) : $exceptionData['fp']);
            $this->currentTrace->setGroupKey(is_array($exceptionData['gk']) ? implode(':', $exceptionData['gk']) : $exceptionData['gk']);

            // Don't send - will be sent with endTrace()
            return $trace;
        }

        // Only send standalone exception trace if no request trace is active
        if ($this->enabled) {
            $this->attachPackagesToTrace($trace);
            $this->transport->send($trace);
        }

        return $trace;
    }

    /**
     * Notify Stacktracer of an exception with an optional per-event callback.
     *
     * Similar to Bugsnag's notifyException() - allows modifying the trace
     * before it's sent via a callback.
     *
     * @param \Throwable    $exception The exception to report
     * @param callable|null $modifier  Optional callback to modify the trace before sending
     * @param array         $context   Additional context data
     *
     * @return Trace The captured trace
     *
     * @example
     * ```php
     * $stacktracer->notifyException($exception, function (Trace $trace) {
     *     $trace->setContext('diagnostics', [
     *         'error' => 'RuntimeException',
     *         'state' => 'Caught',
     *     ]);
     *     return $trace;
     * });
     * ```
     */
    public function notifyException(\Throwable $exception, ?callable $modifier = null, array $context = []): Trace
    {
        $trace = $this->captureException($exception, $context);

        if ($modifier !== null) {
            $modifiedTrace = $modifier($trace);
            if ($modifiedTrace instanceof Trace) {
                $trace = $modifiedTrace;
            }
        }

        return $trace;
    }

    /**
     * Notify Stacktracer of an error without an exception object.
     *
     * Similar to Bugsnag's notifyError() - useful for reporting handled errors
     * or non-exception error conditions.
     *
     * @param string        $name     The error name/type
     * @param string        $message  The error message
     * @param string        $level    The severity level (default: error)
     * @param array         $context  Additional context data
     * @param callable|null $modifier Optional callback to modify the trace before sending
     *
     * @return Trace The captured trace
     *
     * @example
     * ```php
     * $stacktracer->notifyError('PaymentFailed', 'Card declined', Trace::LEVEL_ERROR, [
     *     'card_type' => 'visa',
     *     'amount' => 99.99,
     * ]);
     * ```
     */
    public function notifyError(
        string $name,
        string $message,
        string $level = Trace::LEVEL_ERROR,
        array $context = [],
        ?callable $modifier = null
    ): Trace {
        $trace = new Trace(
            Trace::TYPE_EXCEPTION,
            $level,
            $message,
            array_merge($this->globalContext, $context)
        );

        foreach ($this->globalTags as $key => $value) {
            $trace->addTag($key, $value);
        }

        // Attach feature flags
        if (!empty($this->featureFlags)) {
            $trace->setFeatureFlags($this->featureFlags);
        }

        // Attach user context
        if ($this->globalUser !== null) {
            $trace->setUser($this->globalUser);
        }

        // Attach release and environment
        if ($this->release !== null) {
            $trace->setRelease($this->release);
        }
        if ($this->environment !== null) {
            $trace->setEnvironment($this->environment);
        }

        // Attach server info
        $trace->setServer($this->getServer());

        // Build a synthetic stack trace from current location
        $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxStackFrames);
        $stackFrames = [];
        foreach ($stackTrace as $frame) {
            $file = $frame['file'] ?? '[internal]';
            $stackFrames[] = new StackFrame(
                $file,
                $frame['line'] ?? 0,
                $frame['function'] ?? null,
                $frame['class'] ?? null,
                $frame['type'] ?? null,
                str_contains($file, '/vendor/')
            );
        }

        $stackFingerprint = StackFrame::computeStackFingerprint($stackFrames);

        // Create exception-like data structure
        $exceptionData = [
            'cls' => $name,
            'msg' => $message,
            'code' => 0,
            'file' => $stackFrames[0]->getFile() ?? '[unknown]',
            'line' => $stackFrames[0]->getLine() ?? 0,
            'trace' => array_map(fn ($f) => $f->jsonSerialize(), $stackFrames),
            'stack_fp' => $stackFingerprint,
        ];

        // Compute fingerprint
        $exceptionData['fp'] = Fingerprint::composite([$name, $message, $stackFingerprint]);
        $exceptionData['gk'] = Fingerprint::hash($name);

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

        // Apply modifier if provided
        if ($modifier !== null) {
            $modifiedTrace = $modifier($trace);
            if ($modifiedTrace instanceof Trace) {
                $trace = $modifiedTrace;
            }
        }

        // Send the trace
        if ($this->enabled) {
            $this->attachPackagesToTrace($trace);
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

        // Attach feature flags
        if (!empty($this->featureFlags)) {
            $trace->setFeatureFlags($this->featureFlags);
        }

        // Attach user context
        if ($this->globalUser !== null) {
            $trace->setUser($this->globalUser);
        }

        // Attach release and environment
        if ($this->release !== null) {
            $trace->setRelease($this->release);
        }
        if ($this->environment !== null) {
            $trace->setEnvironment($this->environment);
        }

        // Attach server info (lazy-initialized and cached)
        $trace->setServer($this->getServer());

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

        // Attach spans only if spans feature is enabled (paid feature)
        if ($this->spansEnabled) {
            $trace->setSpans($this->spanManager->getSpans());
        }

        if ($this->enabled) {
            $this->attachPackagesToTrace($trace);
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

    // ========================================
    // FEATURE FLAGS & EXPERIMENTS
    // ========================================

    /**
     * Add a single feature flag or experiment.
     *
     * @param string $name Flag name
     * @param string|null $variant Optional variant (e.g., 'Blue', 'control', 'v2')
     *
     * @example
     * ```php
     * $stacktracer->addFeatureFlag('Checkout button color', 'Blue');
     * $stacktracer->addFeatureFlag('New checkout flow');
     * ```
     */
    public function addFeatureFlag(string $name, ?string $variant = null): void
    {
        $flag = new FeatureFlag($name, $variant);

        // Update existing or add new
        foreach ($this->featureFlags as $i => $existing) {
            if ($existing->getName() === $name) {
                $this->featureFlags[$i] = $flag;
                if ($this->currentTrace !== null) {
                    $this->currentTrace->addFeatureFlag($flag);
                }

                return;
            }
        }

        $this->featureFlags[] = $flag;

        if ($this->currentTrace !== null) {
            $this->currentTrace->addFeatureFlag($flag);
        }
    }

    /**
     * Add multiple feature flags.
     * If called again, new data is merged with existing flags (newer variants take precedence).
     *
     * @param FeatureFlag[] $flags
     *
     * @example
     * ```php
     * $stacktracer->addFeatureFlags([
     *     new FeatureFlag('Checkout button color', 'Blue'),
     *     new FeatureFlag('Special offer', 'Free Coffee'),
     *     new FeatureFlag('New checkout flow'),
     * ]);
     * ```
     */
    public function addFeatureFlags(array $flags): void
    {
        foreach ($flags as $flag) {
            if ($flag instanceof FeatureFlag) {
                $this->addFeatureFlag($flag->getName(), $flag->getVariant());
            }
        }
    }

    /**
     * Remove a single feature flag.
     *
     * @param string $name Flag name to remove
     */
    public function clearFeatureFlag(string $name): void
    {
        $this->featureFlags = array_values(array_filter(
            $this->featureFlags,
            fn ($f) => $f->getName() !== $name
        ));

        if ($this->currentTrace !== null) {
            $this->currentTrace->clearFeatureFlag($name);
        }
    }

    /**
     * Remove all feature flags.
     */
    public function clearFeatureFlags(): void
    {
        $this->featureFlags = [];

        if ($this->currentTrace !== null) {
            $this->currentTrace->clearFeatureFlags();
        }
    }

    /**
     * Get all active feature flags.
     *
     * @return FeatureFlag[]
     */
    public function getFeatureFlags(): array
    {
        return $this->featureFlags;
    }

    // ========================================
    // USER CONTEXT
    // ========================================

    /**
     * Set the current user context.
     *
     * @param User|array<string, mixed> $user user object or array with id, email, username, etc
     *
     * @example
     * ```php
     * $stacktracer->setUser([
     *     'id' => $user->getId(),
     *     'email' => $user->getEmail(),
     *     'name' => $user->getName(),
     *     'data' => ['subscription' => 'premium'],
     * ]);
     * ```
     */
    public function setUser(User|array $user): void
    {
        $this->globalUser = $user instanceof User ? $user : User::fromArray($user);

        if ($this->currentTrace !== null) {
            $this->currentTrace->setUser($this->globalUser);
        }
    }

    /**
     * Get the current user context.
     */
    public function getUser(): ?User
    {
        return $this->globalUser;
    }

    /**
     * Clear the user context.
     */
    public function clearUser(): void
    {
        $this->globalUser = null;
    }

    // ========================================
    // SCOPE MANAGEMENT
    // ========================================

    /**
     * Push a new scope onto the stack.
     *
     * Scopes isolate tags, context, and breadcrumbs. Useful for isolating
     * context between different message handlers or subrequests.
     */
    public function pushScope(): void
    {
        $this->scopeStack[] = [
            'tags' => $this->globalTags,
            'context' => $this->globalContext,
            'breadcrumbs' => $this->currentTrace?->getBreadcrumbs() ?? [],
        ];
    }

    /**
     * Pop the current scope from the stack, restoring the previous scope.
     */
    public function popScope(): void
    {
        if (empty($this->scopeStack)) {
            return;
        }

        $previousScope = array_pop($this->scopeStack);
        $this->globalTags = $previousScope['tags'];
        $this->globalContext = $previousScope['context'];

        if ($this->currentTrace !== null) {
            // Clear current breadcrumbs and restore from scope
            // (Note: In a real implementation, we'd need to modify Trace to support this)
        }
    }

    /**
     * Execute a callback within an isolated scope.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withScope(callable $callback): mixed
    {
        $this->pushScope();

        try {
            return $callback();
        } finally {
            $this->popScope();
        }
    }

    // ========================================
    // IGNORED EXCEPTIONS & TRANSACTIONS
    // ========================================

    /**
     * Set exception classes to ignore.
     *
     * @param string[] $exceptions FQCN patterns (supports wildcards with *)
     */
    public function setIgnoredExceptions(array $exceptions): void
    {
        $this->ignoredExceptions = $exceptions;
    }

    /**
     * Set transaction/route names to ignore.
     *
     * @param string[] $transactions Route or transaction names
     */
    public function setIgnoredTransactions(array $transactions): void
    {
        $this->ignoredTransactions = $transactions;
    }

    /**
     * Check if an exception class should be ignored.
     * Uses compiled patterns for better performance on repeated calls.
     */
    public function shouldIgnoreException(\Throwable $exception): bool
    {
        $class = get_class($exception);

        foreach ($this->ignoredExceptions as $pattern) {
            // Exact match (fast path)
            if ($pattern === $class) {
                return true;
            }

            // Wildcard match - use compiled pattern cache
            if (str_contains($pattern, '*')) {
                if (!isset($this->compiledExceptionPatterns[$pattern])) {
                    $this->compiledExceptionPatterns[$pattern] = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
                }
                if (preg_match($this->compiledExceptionPatterns[$pattern], $class)) {
                    return true;
                }
            }

            // Parent class check (use is_a for interface/parent matching)
            if (is_a($exception, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a transaction should be ignored.
     * Uses compiled patterns for better performance on repeated calls.
     */
    public function shouldIgnoreTransaction(string $name): bool
    {
        foreach ($this->ignoredTransactions as $pattern) {
            // Exact match (fast path)
            if ($pattern === $name) {
                return true;
            }

            // Wildcard match - use compiled pattern cache
            if (str_contains($pattern, '*')) {
                if (!isset($this->compiledTransactionPatterns[$pattern])) {
                    $this->compiledTransactionPatterns[$pattern] = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
                }
                if (preg_match($this->compiledTransactionPatterns[$pattern], $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ========================================
    // BEFORE SEND CALLBACKS
    // ========================================

    /**
     * Register a callback to filter/modify events before sending.
     *
     * Multiple callbacks can be registered and will be executed in order.
     * If any callback returns null, the trace will be dropped.
     *
     * @param callable $callback Callback that receives Trace, returns Trace or null to drop
     *
     * @example
     * ```php
     * // Add metadata from your application
     * $stacktracer->registerCallback(function (Trace $trace) {
     *     $trace->setContext('team', 'backend');
     *     return $trace;
     * });
     *
     * // Drop traces for certain routes
     * $stacktracer->registerCallback(function (Trace $trace) {
     *     if (str_starts_with($trace->getRequest()['path'] ?? '', '/health')) {
     *         return null; // Drop health check traces
     *     }
     *     return $trace;
     * });
     * ```
     */
    public function registerCallback(callable $callback): void
    {
        $this->beforeSendCallbacks[] = $callback;
    }

    /**
     * Set callback to filter/modify events before sending.
     *
     * @param callable|null $callback Callback that receives Trace, returns Trace or null to drop
     *
     * @deprecated Use registerCallback() instead for multiple callbacks support
     */
    public function setBeforeSend(?callable $callback): void
    {
        if ($callback === null) {
            $this->beforeSendCallbacks = [];
        } else {
            $this->beforeSendCallbacks = [$callback];
        }
    }

    /**
     * Register a callback for transaction traces.
     *
     * @param callable $callback Callback that receives Trace, returns Trace or null to drop
     */
    public function registerTransactionCallback(callable $callback): void
    {
        $this->beforeSendTransactionCallbacks[] = $callback;
    }

    /**
     * Set callback to filter/modify transactions before sending.
     *
     * @param callable|null $callback Callback that receives Trace, returns Trace or null to drop
     *
     * @deprecated Use registerTransactionCallback() instead
     */
    public function setBeforeSendTransaction(?callable $callback): void
    {
        if ($callback === null) {
            $this->beforeSendTransactionCallbacks = [];
        } else {
            $this->beforeSendTransactionCallbacks = [$callback];
        }
    }

    /**
     * Apply all before_send callbacks to a trace.
     *
     * Callbacks are executed in registration order. If any returns null,
     * the trace is dropped and no further callbacks are executed.
     *
     * @param Trace $trace The trace to process
     *
     * @return Trace|null Returns modified trace or null to drop
     */
    public function applyBeforeSend(Trace $trace): ?Trace
    {
        foreach ($this->beforeSendCallbacks as $callback) {
            $trace = $callback($trace);
            if ($trace === null) {
                return null;
            }
        }

        return $trace;
    }

    /**
     * Apply all before_send_transaction callbacks to a trace.
     *
     * @param Trace $trace The trace to process
     *
     * @return Trace|null Returns modified trace or null to drop
     */
    public function applyBeforeSendTransaction(Trace $trace): ?Trace
    {
        foreach ($this->beforeSendTransactionCallbacks as $callback) {
            $trace = $callback($trace);
            if ($trace === null) {
                return null;
            }
        }

        return $trace;
    }

    // ========================================
    // RELEASE & ENVIRONMENT
    // ========================================

    /**
     * Set the release/version identifier.
     *
     * @param string $release Release version (e.g., 'v2.3.1', 'abc123', '2024.01.15')
     *
     * @example
     * ```php
     * $stacktracer->setRelease('v2.3.1');
     * $stacktracer->setRelease(getenv('GIT_COMMIT_SHA'));
     * ```
     */
    public function setRelease(string $release): void
    {
        $this->release = $release;

        if ($this->currentTrace !== null) {
            $this->currentTrace->setRelease($release);
        }
    }

    /**
     * Get the current release.
     */
    public function getRelease(): ?string
    {
        return $this->release;
    }

    /**
     * Set the environment name.
     *
     * @param string $environment Environment name (e.g., 'production', 'staging', 'development')
     *
     * @example
     * ```php
     * $stacktracer->setEnvironment('production');
     * ```
     */
    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;

        if ($this->currentTrace !== null) {
            $this->currentTrace->setEnvironment($environment);
        }
    }

    /**
     * Get the current environment.
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    // ========================================
    // SERVER INFO
    // ========================================

    /**
     * Set server/runtime information.
     *
     * @param Server|null $server Server info object, or null for auto-detection
     *
     * @example
     * ```php
     * // Auto-detect PHP/Symfony info
     * $stacktracer->setServer(Server::autoDetect());
     * ```
     */
    public function setServer(?Server $server = null): void
    {
        $this->server = $server ?? Server::autoDetect();

        if ($this->currentTrace !== null) {
            $this->currentTrace->setServer($this->server);
        }
    }

    /**
     * Get the server info (lazy-initialized on first access).
     *
     * Server info is auto-detected and cached since it never changes
     * during the process lifetime.
     */
    public function getServer(): Server
    {
        if ($this->server === null) {
            $this->server = Server::autoDetect();
        }

        return $this->server;
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
                    ++$collapsedVendorCount;
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
            ++$frameCount;
        }

        return $frames;
    }

    private function isVendorFrame(string $file): bool
    {
        return str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');
    }

    private function shortenPath(string $file): string
    {
        // Check path cache first
        if (isset($this->pathCache[$file])) {
            return $this->pathCache[$file];
        }

        if ($this->projectDir === null) {
            if (preg_match('#^(.+?)/vendor/#', $file, $matches)) {
                $this->projectDir = $matches[1];
            }
        }

        $shortened = $file;
        if ($this->projectDir !== null && str_starts_with($file, $this->projectDir)) {
            $shortened = substr($file, strlen($this->projectDir) + 1);
        }

        // Cache with LRU eviction
        if (count($this->pathCache) >= self::PATH_CACHE_MAX_SIZE) {
            array_shift($this->pathCache);
        }
        $this->pathCache[$file] = $shortened;

        return $shortened;
    }

    /**
     * Extract code context around a specific line.
     * Uses file caching to avoid re-reading the same file for multiple frames.
     */
    public function extractCodeContext(string $file, int $line, ?int $contextLines = null): array
    {
        if (!is_readable($file)) {
            return [];
        }

        // Use file cache for repeated reads (same file, different lines)
        if (!isset($this->fileCache[$file])) {
            $lines = @file($file);
            if ($lines === false) {
                return [];
            }
            
            // Cache with LRU eviction
            if (count($this->fileCache) >= self::FILE_CACHE_MAX_SIZE) {
                array_shift($this->fileCache);
            }
            $this->fileCache[$file] = $lines;
        }
        
        $lines = $this->fileCache[$file];

        $numLines = $contextLines ?? $this->exceptionContextLines;
        $start = max(0, $line - $numLines - 1);
        $end = min(count($lines), $line + $numLines);

        $codeLines = [];
        $errorIdx = null;
        for ($i = $start; $i < $end; ++$i) {
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
            $errorIdx,        // Index of error line (0-based)
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

    /**
     * Get installed packages from Composer.
     * Cached after first call since packages don't change during runtime.
     *
     * @return array<string, string> Package name => version
     */
    public function getInstalledPackages(): array
    {
        if ($this->cachedPackages !== null) {
            return $this->cachedPackages;
        }

        $packages = [];

        // Use Composer's InstalledVersions if available
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $installed = \Composer\InstalledVersions::getAllRawData();
                foreach ($installed as $dataset) {
                    if (!isset($dataset['versions'])) {
                        continue;
                    }
                    foreach ($dataset['versions'] as $name => $info) {
                        // Skip dev dependencies and metapackages
                        if (isset($info['type']) && in_array($info['type'], ['metapackage', 'project'], true)) {
                            continue;
                        }
                        // Get pretty version or fallback to version
                        $version = $info['pretty_version'] ?? $info['version'] ?? 'unknown';
                        $packages[$name] = $version;
                    }
                }
            } catch (\Throwable $e) {
                // Silently fail if we can't read packages
            }
        }

        // Sort for consistent fingerprinting
        ksort($packages);
        $this->cachedPackages = $packages;

        return $packages;
    }

    /**
     * Enable or disable package collection.
     */
    public function setCollectPackages(bool $collect): void
    {
        $this->collectPackages = $collect;
    }

    /**
     * Attach installed packages to a trace.
     */
    private function attachPackagesToTrace(Trace $trace): void
    {
        if (!$this->collectPackages) {
            return;
        }

        $packages = $this->getInstalledPackages();
        if (!empty($packages)) {
            $trace->setPackages($packages);
        }
    }
}
