<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * LogEntry - structured log record linked to spans.
 *
 * @see https://opentelemetry.io/docs/concepts/signals/logs/
 */
class LogEntry implements \JsonSerializable
{
    // OTEL Severity Numbers
    public const SEVERITY_TRACE = 1;
    public const SEVERITY_DEBUG = 5;
    public const SEVERITY_INFO = 9;
    public const SEVERITY_WARN = 13;
    public const SEVERITY_ERROR = 17;
    public const SEVERITY_FATAL = 21;

    // Severity text mapping
    private const SEVERITY_MAP = [
        'trace' => self::SEVERITY_TRACE,
        'debug' => self::SEVERITY_DEBUG,
        'info' => self::SEVERITY_INFO,
        'notice' => self::SEVERITY_INFO,
        'warning' => self::SEVERITY_WARN,
        'warn' => self::SEVERITY_WARN,
        'error' => self::SEVERITY_ERROR,
        'critical' => self::SEVERITY_ERROR,
        'alert' => self::SEVERITY_FATAL,
        'emergency' => self::SEVERITY_FATAL,
        'fatal' => self::SEVERITY_FATAL,
    ];

    private string $id;

    private float $timestamp;

    private int $severityNumber;

    private string $severityText;

    private string $body;

    private array $attributes;

    private array $resource;

    // Span linking
    private ?string $spanId;

    private ?string $parentSpanId;

    private ?string $traceId;

    // Source location
    private ?string $sourceFile;

    private ?int $sourceLine;

    private ?string $sourceFunction;

    // Logger info
    private ?string $loggerName;

    private ?string $channel;

    public function __construct(
        string $body,
        string $severityText = 'info',
        array $attributes = []
    ) {
        $this->id = bin2hex(random_bytes(8));
        $this->timestamp = microtime(true);
        $this->severityText = strtolower($severityText);
        $this->severityNumber = self::SEVERITY_MAP[$this->severityText] ?? self::SEVERITY_INFO;
        $this->body = $body;
        $this->attributes = $attributes;
        $this->resource = [];
        $this->spanId = null;
        $this->parentSpanId = null;
        $this->traceId = null;
        $this->sourceFile = null;
        $this->sourceLine = null;
        $this->sourceFunction = null;
        $this->loggerName = null;
        $this->channel = null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getSeverityNumber(): int
    {
        return $this->severityNumber;
    }

    public function getSeverityText(): string
    {
        return $this->severityText;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    // --- Span Linking ---

    public function setSpanId(?string $spanId): self
    {
        $this->spanId = $spanId;

        return $this;
    }

    public function getSpanId(): ?string
    {
        return $this->spanId;
    }

    public function setParentSpanId(?string $parentSpanId): self
    {
        $this->parentSpanId = $parentSpanId;

        return $this;
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function setTraceId(?string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    // --- Source Location ---

    public function setSource(string $file, int $line, ?string $function = null): self
    {
        $this->sourceFile = $file;
        $this->sourceLine = $line;
        $this->sourceFunction = $function;

        return $this;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }

    // --- Logger Info ---

    public function setLoggerName(string $name): self
    {
        $this->loggerName = $name;

        return $this;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function setResource(array $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Create from Monolog record.
     */
    public static function fromMonolog(array $record): self
    {
        $level = strtolower($record['level_name'] ?? $record['level'] ?? 'info');

        $entry = new self(
            $record['message'] ?? '',
            $level,
            $record['context'] ?? []
        );

        if (isset($record['channel'])) {
            $entry->setChannel($record['channel']);
        }

        if (isset($record['extra'])) {
            foreach ($record['extra'] as $key => $value) {
                $entry->setAttribute('extra.' . $key, $value);
            }
        }

        return $entry;
    }

    /**
     * Compute fingerprint for log deduplication.
     */
    public function getFingerprint(): string
    {
        return hash('xxh3', implode('|', [
            $this->severityText,
            $this->body,
            $this->channel ?? '',
            $this->sourceFile ?? '',
            $this->sourceLine ?? '',
        ]));
    }

    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'ts' => (int) ($this->timestamp * 1e9),
            'sev' => $this->severityNumber,
            'lvl' => $this->severityText,
            'body' => $this->body,
        ];

        // Only include non-empty attributes
        if (!empty($this->attributes)) {
            $data['attrs'] = $this->attributes;
        }

        // Only include span_id if set
        if ($this->spanId !== null) {
            $data['span_id'] = $this->spanId;
        }

        // Only include parent_span_id if set
        if ($this->parentSpanId !== null) {
            $data['parent_span_id'] = $this->parentSpanId;
        }

        // Only include source if meaningful
        if ($this->sourceFile !== null) {
            $src = ['file' => $this->sourceFile, 'line' => $this->sourceLine];
            if ($this->sourceFunction !== null) {
                $src['fn'] = $this->sourceFunction;
            }
            $data['src'] = $src;
        }

        // Only include channel if set
        if ($this->channel !== null) {
            $data['ch'] = $this->channel;
        }

        // Always include fingerprint for deduplication
        $data['fp'] = $this->getFingerprint();

        // Calculate payload size (body + serialized attributes)
        $attrSize = !empty($this->attributes) ? strlen(json_encode($this->attributes)) : 0;
        $data['payload_size'] = strlen($this->body) + $attrSize;

        return $data;
    }
}
