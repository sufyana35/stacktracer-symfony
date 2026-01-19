<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * Breadcrumb - a trail of events leading up to an issue.
 * Linked to spans for unified tracing.
 */
class Breadcrumb implements \JsonSerializable
{
    // Breadcrumb types/categories
    public const TYPE_DEFAULT = 'default';
    public const TYPE_HTTP = 'http';
    public const TYPE_NAVIGATION = 'navigation';
    public const TYPE_QUERY = 'query';
    public const TYPE_ERROR = 'error';
    public const TYPE_DEBUG = 'debug';
    public const TYPE_USER = 'user';
    public const TYPE_INFO = 'info';
    public const TYPE_WARNING = 'warning';

    // Levels
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_FATAL = 'fatal';

    private string $id;

    private string $category;

    private string $message;

    private string $level;

    private array $data;

    private float $timestamp;

    // Span linking
    private ?string $spanId;

    private ?string $traceId;

    // Stack frame reference
    private ?string $sourceFile;

    private ?int $sourceLine;

    private ?string $sourceFunction;

    public function __construct(
        string $category,
        string $message,
        string $level = self::LEVEL_INFO,
        array $data = []
    ) {
        $this->id = bin2hex(random_bytes(8));
        $this->category = $category;
        $this->message = $message;
        $this->level = $level;
        $this->data = $data;
        $this->timestamp = microtime(true);
        $this->spanId = null;
        $this->traceId = null;
        $this->sourceFile = null;
        $this->sourceLine = null;
        $this->sourceFunction = null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
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

    public function captureSource(int $skipFrames = 1): self
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $skipFrames + 1);
        $frame = $trace[$skipFrames] ?? null;

        if ($frame) {
            $this->sourceFile = $frame['file'] ?? null;
            $this->sourceLine = $frame['line'] ?? null;
            $this->sourceFunction = $frame['function'] ?? null;
        }

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

    public function getSourceFunction(): ?string
    {
        return $this->sourceFunction;
    }

    /**
     * Compute a fingerprint for this breadcrumb for deduplication.
     */
    public function getFingerprint(): string
    {
        return hash('xxh3', implode('|', [
            $this->category,
            $this->level,
            $this->message,
            $this->sourceFile ?? '',
            $this->sourceLine ?? '',
        ]));
    }

    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'cat' => $this->category,
            'msg' => $this->message,
            'lvl' => $this->level,
            'ts' => (int) ($this->timestamp * 1e9),
        ];

        // Only include data if non-empty
        if (!empty($this->data)) {
            $data['data'] = $this->data;
        }

        // Only include span_id if set
        if ($this->spanId !== null) {
            $data['span_id'] = $this->spanId;
        }

        // Only include source if it's meaningful (not framework internals)
        if ($this->sourceFile !== null && !$this->isFrameworkSource()) {
            $data['src'] = [
                'file' => $this->sourceFile,
                'line' => $this->sourceLine,
            ];
            if ($this->sourceFunction !== null) {
                $data['src']['fn'] = $this->sourceFunction;
            }
        }

        // Include fingerprint for deduplication
        $data['fp'] = $this->getFingerprint();

        return $data;
    }

    /**
     * Check if source is a framework/vendor file (not useful to show).
     */
    private function isFrameworkSource(): bool
    {
        if ($this->sourceFile === null) {
            return false;
        }
        // Skip vendor and common framework paths
        $skipPatterns = [
            '/vendor/',
            '/var/cache/',
            'EventDispatcher',
            'HttpKernel',
        ];
        foreach ($skipPatterns as $pattern) {
            if (str_contains($this->sourceFile, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
