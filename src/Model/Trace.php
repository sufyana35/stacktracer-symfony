<?php

namespace Stacktracer\SymfonyBundle\Model;

/**
 * Represents a single trace entry.
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
    private array $breadcrumbs;
    private ?array $request;
    private ?array $exception;
    private ?array $performance;
    private float $timestamp;
    private ?float $duration;

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
        $this->request = null;
        $this->exception = null;
        $this->performance = null;
        $this->timestamp = microtime(true);
        $this->duration = null;
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

    public function addBreadcrumb(string $category, string $message, array $data = []): self
    {
        $this->breadcrumbs[] = [
            'timestamp' => microtime(true),
            'category' => $category,
            'message' => $message,
            'data' => $data,
        ];
        return $this;
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
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
        return [
            'id' => $this->id,
            'type' => $this->type,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'tags' => $this->tags,
            'breadcrumbs' => $this->breadcrumbs,
            'request' => $this->request,
            'exception' => $this->exception,
            'performance' => $this->performance,
            'timestamp' => $this->timestamp,
            'datetime' => date('Y-m-d H:i:s', (int) $this->timestamp),
            'duration_ms' => $this->duration !== null ? round($this->duration * 1000, 2) : null,
        ];
    }
}
