<?php

namespace Stacktracer\SymfonyBundle\Model;

use Stacktracer\SymfonyBundle\Util\Fingerprint;

/**
 * Represents a single trace entry with OTEL-compatible span data.
 * 
 * @see https://opentelemetry.io/docs/concepts/signals/traces/
 */
class Trace implements \JsonSerializable
{
    public const TYPE_REQUEST = 'request';
    public const TYPE_EXCEPTION = 'exception';
    public const TYPE_PERFORMANCE = 'performance';
    public const TYPE_CUSTOM = 'custom';

    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_FATAL = 'fatal';

    private string $id;
    private string $type;
    private string $level;
    private string $message;
    private array $context;
    private array $tags;
    
    /** @var Breadcrumb[] */
    private array $breadcrumbs;
    
    /** @var LogEntry[] */
    private array $logs;
    
    /** @var Span[] */
    private array $spans;
    
    private ?array $request;
    private ?array $exception;
    private ?array $performance;
    private float $timestamp;
    private ?float $duration;
    
    // OTEL Trace Context
    private ?string $traceId;
    private ?string $spanId;
    private ?string $parentSpanId;
    
    // Fingerprinting for deduplication
    private ?string $fingerprint;
    private ?string $groupKey;

    public function __construct(
        string $type = self::TYPE_CUSTOM,
        string $level = self::LEVEL_INFO,
        string $message = '',
        array $context = []
    ) {
        $this->id = $this->generateId();
        $this->type = $type;
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->tags = [];
        $this->breadcrumbs = [];
        $this->logs = [];
        $this->spans = [];
        $this->request = null;
        $this->exception = null;
        $this->performance = null;
        $this->timestamp = microtime(true);
        $this->duration = null;
        $this->traceId = SpanContext::generateTraceId();
        $this->spanId = null;
        $this->parentSpanId = null;
        $this->fingerprint = null;
        $this->groupKey = null;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function addTag(string $key, string $value): self
    {
        $this->tags[$key] = $value;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function addBreadcrumb(string $category, string $message, array $data = [], string $level = Breadcrumb::LEVEL_INFO): self
    {
        $breadcrumb = new Breadcrumb($category, $message, $level, $data);
        $breadcrumb->setTraceId($this->traceId);
        $breadcrumb->setSpanId($this->spanId);
        $breadcrumb->captureSource(2);
        $this->breadcrumbs[] = $breadcrumb;
        return $this;
    }

    public function addBreadcrumbObject(Breadcrumb $breadcrumb): self
    {
        $breadcrumb->setTraceId($this->traceId);
        if ($this->spanId) {
            $breadcrumb->setSpanId($this->spanId);
        }
        $this->breadcrumbs[] = $breadcrumb;
        return $this;
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    // --- Logs ---

    public function addLog(LogEntry $log): self
    {
        $log->setTraceId($this->traceId);
        if ($this->spanId) {
            $log->setSpanId($this->spanId);
        }
        $this->logs[] = $log;
        return $this;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    // --- Spans ---

    public function addSpan(Span $span): self
    {
        $this->spans[] = $span;
        return $this;
    }

    public function setSpans(array $spans): self
    {
        $this->spans = $spans;
        return $this;
    }

    public function getSpans(): array
    {
        return $this->spans;
    }

    // --- OTEL Context ---

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }

    public function getSpanId(): ?string
    {
        return $this->spanId;
    }

    public function setSpanId(?string $spanId): self
    {
        $this->spanId = $spanId;
        return $this;
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function setParentSpanId(?string $parentSpanId): self
    {
        $this->parentSpanId = $parentSpanId;
        return $this;
    }

    // --- Fingerprinting ---

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    public function getGroupKey(): ?string
    {
        return $this->groupKey;
    }

    public function setGroupKey(string $groupKey): self
    {
        $this->groupKey = $groupKey;
        return $this;
    }

    /**
     * Compute fingerprint based on exception data.
     */
    public function computeFingerprint(): string
    {
        if ($this->exception) {
            $this->fingerprint = Fingerprint::composite([
                $this->exception['cls'] ?? '',
                Fingerprint::normalizeMessage($this->exception['msg'] ?? ''),
                $this->exception['file'] ?? '',
                $this->exception['line'] ?? '',
            ]);
        } else {
            $this->fingerprint = Fingerprint::composite([
                $this->type,
                $this->level,
                Fingerprint::normalizeMessage($this->message),
            ]);
        }
        
        return $this->fingerprint;
    }

    /**
     * Compute group key for aggregation.
     */
    public function computeGroupKey(): string
    {
        if ($this->exception) {
            $this->groupKey = Fingerprint::composite([
                $this->exception['cls'] ?? '',
                $this->exception['file'] ?? '',
            ]);
        } else {
            $this->groupKey = Fingerprint::composite([
                $this->type,
                $this->level,
            ]);
        }
        
        return $this->groupKey;
    }

    public function setRequest(array $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getRequest(): ?array
    {
        return $this->request;
    }

    public function setException(array $exception): self
    {
        $this->exception = $exception;
        return $this;
    }

    public function getException(): ?array
    {
        return $this->exception;
    }

    public function setPerformance(array $performance): self
    {
        $this->performance = $performance;
        return $this;
    }

    public function getPerformance(): ?array
    {
        return $this->performance;
    }

    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function jsonSerialize(): array
    {
        // Auto-compute fingerprint if not set
        if ($this->fingerprint === null) {
            $this->computeFingerprint();
        }
        if ($this->groupKey === null) {
            $this->computeGroupKey();
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'tags' => $this->tags,
            
            // Request/Exception/Performance data
            'request' => $this->request,
            'exception' => $this->exception,
            'performance' => $this->performance,
            
            // Timestamps
            'timestamp' => $this->timestamp,
            'timestamp_unix_nano' => (int)($this->timestamp * 1e9),
            'datetime' => date('Y-m-d\TH:i:s.vP', (int) $this->timestamp),
            'duration_ms' => $this->duration !== null ? round($this->duration * 1000, 3) : null,
            
            // OTEL Trace Context
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            
            // Linked data with counts for frontend
            'breadcrumbs' => array_map(fn($b) => $b instanceof Breadcrumb ? $b->jsonSerialize() : $b, $this->breadcrumbs),
            'breadcrumb_count' => count($this->breadcrumbs),
            
            'logs' => array_map(fn($l) => $l instanceof LogEntry ? $l->jsonSerialize() : $l, $this->logs),
            'log_count' => count($this->logs),
            
            'spans' => array_map(fn($s) => $s instanceof Span ? $s->jsonSerialize() : $s, $this->spans),
            'span_count' => count($this->spans),
            
            // Fingerprinting for deduplication and grouping
            'fingerprint' => $this->fingerprint,
            'group_key' => $this->groupKey,
            
            // Breadcrumb trail fingerprint for pattern detection
            'breadcrumb_fingerprint' => count($this->breadcrumbs) > 0 
                ? Fingerprint::breadcrumbTrail($this->breadcrumbs) 
                : null,
        ];
    }
}
