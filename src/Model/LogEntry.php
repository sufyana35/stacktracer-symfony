<?php

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
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'time_unix_nano' => (int)($this->timestamp * 1e9),
            'severity_number' => $this->severityNumber,
            'severity_text' => $this->severityText,
            'body' => $this->body,
            'attributes' => $this->attributes,
            'resource' => $this->resource,
            
            // Linking
            'span_id' => $this->spanId,
            'trace_id' => $this->traceId,
            
            // Source
            'source' => $this->sourceFile ? [
                'file' => $this->sourceFile,
                'line' => $this->sourceLine,
                'function' => $this->sourceFunction,
            ] : null,
            
            // Logger
            'logger_name' => $this->loggerName,
            'channel' => $this->channel,
            
            'fingerprint' => $this->getFingerprint(),
        ];
    }
}
