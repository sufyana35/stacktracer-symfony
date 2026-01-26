<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

use Stacktracer\SymfonyBundle\StacktracerBundle;
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

    /** @var FeatureFlag[] */
    private array $featureFlags;

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

    // User context
    private ?User $user;

    // Server info
    private ?Server $server;

    // Release tracking
    private ?string $release;

    private ?string $environment;

    // Installed packages with fingerprint
    private ?array $packages = null;

    private ?string $packagesFingerprint = null;

    // SDK info
    private string $sdkName;

    private string $sdkVersion;

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
        $this->featureFlags = [];
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
        $this->user = null;
        $this->server = null;
        $this->release = null;
        $this->environment = null;
        $this->packages = null;
        $this->packagesFingerprint = null;
        $this->sdkName = StacktracerBundle::SDK_NAME;
        $this->sdkVersion = StacktracerBundle::SDK_VERSION;
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

    /**
     * Override the timestamp for traces that need backdating.
     * Useful for aligning trace start with actual PHP request start.
     */
    public function setTimestamp(float $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
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

    // --- Feature Flags ---

    /**
     * Add a feature flag or experiment.
     */
    public function addFeatureFlag(FeatureFlag $flag): self
    {
        // Update existing flag with same name or add new one
        foreach ($this->featureFlags as $i => $existing) {
            if ($existing->getName() === $flag->getName()) {
                $this->featureFlags[$i] = $flag;

                return $this;
            }
        }
        $this->featureFlags[] = $flag;

        return $this;
    }

    /**
     * Add multiple feature flags.
     */
    public function addFeatureFlags(array $flags): self
    {
        foreach ($flags as $flag) {
            $this->addFeatureFlag($flag);
        }

        return $this;
    }

    /**
     * Remove a feature flag by name.
     */
    public function clearFeatureFlag(string $name): self
    {
        $this->featureFlags = array_values(array_filter(
            $this->featureFlags,
            fn ($f) => $f->getName() !== $name
        ));

        return $this;
    }

    /**
     * Remove all feature flags.
     */
    public function clearFeatureFlags(): self
    {
        $this->featureFlags = [];

        return $this;
    }

    /**
     * Get all feature flags.
     *
     * @return FeatureFlag[]
     */
    public function getFeatureFlags(): array
    {
        return $this->featureFlags;
    }

    /**
     * Set feature flags (replaces existing).
     *
     * @param FeatureFlag[] $flags
     */
    public function setFeatureFlags(array $flags): self
    {
        $this->featureFlags = $flags;

        return $this;
    }

    // --- User Context ---

    /**
     * Get user context.
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Set user context.
     */
    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    // --- Server Info ---

    /**
     * Get server information.
     */
    public function getServer(): ?Server
    {
        return $this->server;
    }

    /**
     * Set server information.
     */
    public function setServer(?Server $server): self
    {
        $this->server = $server;

        return $this;
    }

    // --- Release Tracking ---

    /**
     * Get release/version.
     */
    public function getRelease(): ?string
    {
        return $this->release;
    }

    /**
     * Set release/version.
     */
    public function setRelease(?string $release): self
    {
        $this->release = $release;

        return $this;
    }

    /**
     * Get environment.
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Set environment.
     */
    public function setEnvironment(?string $environment): self
    {
        $this->environment = $environment;

        return $this;
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

    /**
     * Set installed packages with automatic fingerprint generation.
     *
     * @param array $packages Array of ['name' => 'version', ...]
     */
    public function setPackages(array $packages): self
    {
        $this->packages = $packages;
        // Generate fingerprint from sorted package list
        ksort($packages);
        $this->packagesFingerprint = substr(md5(json_encode($packages)), 0, 16);

        return $this;
    }

    public function getPackages(): ?array
    {
        return $this->packages;
    }

    public function getPackagesFingerprint(): ?string
    {
        return $this->packagesFingerprint;
    }

    public function getSdkName(): string
    {
        return $this->sdkName;
    }

    public function getSdkVersion(): string
    {
        return $this->sdkVersion;
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

        // Build base data with only non-empty fields
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'level' => $this->level,
            'message' => $this->message,

            // Single timestamp format (unix nano) - receiver can derive others
            'ts' => (int) ($this->timestamp * 1e9),

            // OTEL Trace Context
            'trace_id' => $this->traceId,

            // Fingerprinting for deduplication and grouping
            'fp' => $this->fingerprint,
            'gk' => $this->groupKey,
        ];

        // Only include non-empty arrays/values
        if (!empty($this->context)) {
            $data['context'] = $this->context;
        }
        if (!empty($this->tags)) {
            $data['tags'] = $this->tags;
        }
        if ($this->request !== null) {
            $data['request'] = $this->request;
        }
        if ($this->exception !== null) {
            $data['exception'] = $this->exception;
        }
        if ($this->performance !== null) {
            $data['performance'] = $this->performance;
        }
        if ($this->duration !== null) {
            $data['duration_ms'] = round($this->duration * 1000, 3);
        }
        if ($this->spanId !== null) {
            $data['span_id'] = $this->spanId;
        }
        if ($this->parentSpanId !== null) {
            $data['parent_span_id'] = $this->parentSpanId;
        }

        // Only include breadcrumbs if present
        if (!empty($this->breadcrumbs)) {
            $data['breadcrumbs'] = array_map(fn ($b) => $b instanceof Breadcrumb ? $b->jsonSerialize() : $b, $this->breadcrumbs);
            $data['bc_fp'] = Fingerprint::breadcrumbTrail($this->breadcrumbs);
        }

        // Only include logs if present
        if (!empty($this->logs)) {
            $data['logs'] = array_map(fn ($l) => $l instanceof LogEntry ? $l->jsonSerialize() : $l, $this->logs);
        }

        // Only include spans if present - with deduplication flag
        if (!empty($this->spans)) {
            $data['spans'] = array_map(fn ($s) => $s instanceof Span ? $s->jsonSerialize(true) : $s, $this->spans);
        }

        // Only include feature flags if present
        if (!empty($this->featureFlags)) {
            $data['flags'] = array_map(fn ($f) => $f->jsonSerialize(), $this->featureFlags);
        }

        // User context
        if ($this->user !== null) {
            $userData = $this->user->jsonSerialize();
            if (!empty($userData)) {
                $data['user'] = $userData;
            }
        }

        // Server info
        if ($this->server !== null) {
            $serverData = $this->server->jsonSerialize();
            if (!empty($serverData)) {
                $data['server'] = $serverData;
            }
        }

        // Release tracking
        if ($this->release !== null) {
            $data['release'] = $this->release;
        }
        if ($this->environment !== null) {
            $data['env'] = $this->environment;
        }

        // Installed packages (only fingerprint by default, full list on demand)
        if ($this->packages !== null && !empty($this->packages)) {
            $data['packages'] = $this->packages;
            $data['packages_fp'] = $this->packagesFingerprint;
        }

        // SDK info
        $data['sdk'] = [
            'name' => $this->sdkName,
            'version' => $this->sdkVersion,
        ];

        return $data;
    }
}
