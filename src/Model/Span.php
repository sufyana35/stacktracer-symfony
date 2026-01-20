<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * OpenTelemetry-compatible Span representing a unit of work.
 *
 * @see https://opentelemetry.io/docs/concepts/signals/traces/#spans
 */
class Span implements \JsonSerializable
{
    // OTEL Span Kinds
    public const KIND_INTERNAL = 'INTERNAL';
    public const KIND_SERVER = 'SERVER';
    public const KIND_CLIENT = 'CLIENT';
    public const KIND_PRODUCER = 'PRODUCER';
    public const KIND_CONSUMER = 'CONSUMER';

    // OTEL Span Status Codes
    public const STATUS_UNSET = 'UNSET';
    public const STATUS_OK = 'OK';
    public const STATUS_ERROR = 'ERROR';

    private SpanContext $context;

    private ?string $parentSpanId;

    private string $name;

    private string $kind;

    private float $startTime;

    private ?float $endTime;

    private string $status;

    private ?string $statusMessage;

    /** @var array<string, mixed> OTEL attributes */
    private array $attributes;

    /** @var SpanEvent[] */
    private array $events;

    /** @var SpanLink[] */
    private array $links;

    /** @var Breadcrumb[] Linked breadcrumbs */
    private array $breadcrumbs;

    /** @var LogEntry[] Linked log entries */
    private array $logs;

    /** @var StackFrame[]|null Stack trace if applicable */
    private ?array $stackTrace;

    /** @var string|null Fingerprint for deduplication */
    private ?string $fingerprint;

    private string $serviceName;

    private string $serviceVersion;

    private array $resource;

    public function __construct(
        string $name,
        string $kind = self::KIND_INTERNAL,
        ?SpanContext $context = null,
        ?string $parentSpanId = null,
        string $serviceName = 'unknown',
        string $serviceVersion = '0.0.0'
    ) {
        $this->context = $context ?? new SpanContext();
        $this->parentSpanId = $parentSpanId;
        $this->name = $name;
        $this->kind = $kind;
        $this->startTime = microtime(true);
        $this->endTime = null;
        $this->status = self::STATUS_UNSET;
        $this->statusMessage = null;
        $this->attributes = [];
        $this->events = [];
        $this->links = [];
        $this->breadcrumbs = [];
        $this->logs = [];
        $this->stackTrace = null;
        $this->fingerprint = null;
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->resource = [];
    }

    public function getContext(): SpanContext
    {
        return $this->context;
    }

    public function getTraceId(): string
    {
        return $this->context->getTraceId();
    }

    public function getSpanId(): string
    {
        return $this->context->getSpanId();
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * Override the start time for spans created after the fact.
     * Useful for integrations that collect timing data after completion.
     */
    public function setStartTime(float $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getDurationMs(): ?float
    {
        if ($this->endTime === null) {
            return null;
        }

        return round(($this->endTime - $this->startTime) * 1000, 3);
    }

    public function end(?float $endTime = null): self
    {
        $this->endTime = $endTime ?? microtime(true);

        return $this;
    }

    public function isEnded(): bool
    {
        return $this->endTime !== null;
    }

    // --- Status ---

    public function setStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->statusMessage = $message;

        return $this;
    }

    public function setOk(): self
    {
        return $this->setStatus(self::STATUS_OK);
    }

    public function setError(string $message = ''): self
    {
        return $this->setStatus(self::STATUS_ERROR, $message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusMessage(): ?string
    {
        return $this->statusMessage;
    }

    // --- Attributes (OTEL semantic conventions) ---

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    // --- Events (timestamped annotations) ---

    public function addEvent(string $name, array $attributes = [], ?float $timestamp = null): self
    {
        $this->events[] = new SpanEvent($name, $attributes, $timestamp);

        return $this;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    // --- Links (to other spans/traces) ---

    public function addLink(SpanContext $context, array $attributes = []): self
    {
        $this->links[] = new SpanLink($context, $attributes);

        return $this;
    }

    public function getLinks(): array
    {
        return $this->links;
    }

    // --- Breadcrumbs ---

    public function addBreadcrumb(Breadcrumb $breadcrumb): self
    {
        // Link breadcrumb to this span
        $breadcrumb->setSpanId($this->getSpanId());
        $breadcrumb->setTraceId($this->getTraceId());
        $this->breadcrumbs[] = $breadcrumb;

        return $this;
    }

    public function createBreadcrumb(
        string $category,
        string $message,
        string $level = Breadcrumb::LEVEL_INFO,
        array $data = []
    ): Breadcrumb {
        $breadcrumb = new Breadcrumb($category, $message, $level, $data);
        $this->addBreadcrumb($breadcrumb);

        return $breadcrumb;
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    // --- Logs ---

    public function addLog(LogEntry $log): self
    {
        $log->setSpanId($this->getSpanId());
        $log->setTraceId($this->getTraceId());
        $this->logs[] = $log;

        return $this;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    // --- Stack Trace ---

    /**
     * @param StackFrame[] $stackTrace
     */
    public function setStackTrace(array $stackTrace): self
    {
        $this->stackTrace = $stackTrace;
        $this->fingerprint = StackFrame::computeStackFingerprint($stackTrace);

        return $this;
    }

    public function getStackTrace(): ?array
    {
        return $this->stackTrace;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    // --- Resource/Service Info ---

    public function setResource(array $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function getResource(): array
    {
        return $this->resource;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getServiceVersion(): string
    {
        return $this->serviceVersion;
    }

    // --- Child Span Creation ---

    public function createChild(string $name, string $kind = self::KIND_INTERNAL): self
    {
        $childContext = $this->context->createChild();

        return new self(
            $name,
            $kind,
            $childContext,
            $this->getSpanId(),
            $this->serviceName,
            $this->serviceVersion
        );
    }

    // --- Record Exception ---

    public function recordException(\Throwable $exception, array $additionalAttributes = []): self
    {
        $attributes = array_merge([
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ], $additionalAttributes);

        if ($exception->getCode() !== 0) {
            $attributes['exception.code'] = $exception->getCode();
        }

        $this->addEvent('exception', $attributes);
        $this->setError($exception->getMessage());

        return $this;
    }

    /**
     * @param bool $deduplicate If true, exclude attributes that duplicate trace-level request data
     */
    public function jsonSerialize(bool $deduplicate = false): array
    {
        // Core fields always present
        $data = [
            'span_id' => $this->getSpanId(),
            'name' => $this->name,
            'kind' => $this->kind,
            'start_ts' => (int) ($this->startTime * 1e9),
            'status' => $this->status,
        ];

        // Only include parent if set
        if ($this->parentSpanId !== null) {
            $data['parent_span_id'] = $this->parentSpanId;
        }

        // Only include end time and duration if ended
        if ($this->endTime !== null) {
            $data['end_ts'] = (int) ($this->endTime * 1e9);
            $data['duration_ms'] = $this->getDurationMs();
        }

        // Status message only if set
        if ($this->statusMessage !== null) {
            $data['status_msg'] = $this->statusMessage;
        }

        // Attributes - optionally deduplicate from trace-level request data
        $attrs = $this->attributes;
        if ($deduplicate) {
            // Remove attributes that are already in trace.request
            $duplicateKeys = [
                'http.url', 'http.method', 'http.target', 'http.host',
                'http.scheme', 'http.user_agent', 'http.client_ip',
            ];
            foreach ($duplicateKeys as $key) {
                unset($attrs[$key]);
            }
        }
        // Filter out null values from attributes
        $attrs = array_filter($attrs, fn ($v) => $v !== null);
        if (!empty($attrs)) {
            $data['attrs'] = $attrs;
        }

        // Events - only if present, compact format
        if (!empty($this->events)) {
            $data['events'] = array_map(function ($e) {
                $eventData = $e->jsonSerialize();
                // Remove verbose stacktrace string from attrs
                if (isset($eventData['attrs']['exception.stacktrace'])) {
                    unset($eventData['attrs']['exception.stacktrace']);
                }

                return $eventData;
            }, $this->events);
        }

        // Links - only if present
        if (!empty($this->links)) {
            $data['links'] = array_map(fn ($l) => $l->jsonSerialize(), $this->links);
        }

        // Resource - compact
        $data['svc'] = $this->serviceName;
        if ($this->serviceVersion !== '0.0.0') {
            $data['svc_ver'] = $this->serviceVersion;
        }

        // Fingerprint only if set
        if ($this->fingerprint !== null) {
            $data['fp'] = $this->fingerprint;
        }

        return $data;
    }
}
