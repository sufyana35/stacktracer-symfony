<?php

namespace Stacktracer\SymfonyBundle\Model;

/**
 * StackFrame - represents a single frame in a stack trace with fingerprinting.
 */
class StackFrame implements \JsonSerializable
{
    private string $file;
    private int $line;
    private ?string $function;
    private ?string $class;
    private ?string $type;
    private bool $isVendor;
    private ?array $codeContext;
    private ?int $collapsedCount;
    private string $fingerprint;
    private string $fileHash;

    public function __construct(
        string $file,
        int $line,
        ?string $function = null,
        ?string $class = null,
        ?string $type = null,
        bool $isVendor = false,
        ?array $codeContext = null,
        ?int $collapsedCount = null
    ) {
        $this->file = $file;
        $this->line = $line;
        $this->function = $function;
        $this->class = $class;
        $this->type = $type;
        $this->isVendor = $isVendor;
        $this->codeContext = $codeContext;
        $this->collapsedCount = $collapsedCount;
        
        $this->fingerprint = $this->computeFingerprint();
        $this->fileHash = $this->computeFileHash();
    }

    /**
     * Create from PHP debug_backtrace frame.
     */
    public static function fromBacktrace(array $frame, bool $isVendor = false): self
    {
        return new self(
            $frame['file'] ?? '[internal]',
            $frame['line'] ?? 0,
            $frame['function'] ?? null,
            $frame['class'] ?? null,
            $frame['type'] ?? null,
            $isVendor
        );
    }

    /**
     * Create from exception trace.
     */
    public static function fromException(\Throwable $exception, bool $filterVendor = true, int $maxFrames = 50): array
    {
        $frames = [];
        $trace = $exception->getTrace();
        
        // Add exception location as first frame
        $frames[] = new self(
            $exception->getFile(),
            $exception->getLine(),
            null,
            get_class($exception),
            '::',
            self::isVendorPath($exception->getFile())
        );

        $count = 0;
        foreach ($trace as $frame) {
            if ($count >= $maxFrames) {
                break;
            }

            $file = $frame['file'] ?? '[internal]';
            $isVendor = self::isVendorPath($file);

            $frames[] = new self(
                $file,
                $frame['line'] ?? 0,
                $frame['function'] ?? null,
                $frame['class'] ?? null,
                $frame['type'] ?? null,
                $isVendor
            );

            $count++;
        }

        return $frames;
    }

    private static function isVendorPath(string $file): bool
    {
        return str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');
    }

    /**
     * Compute a fingerprint for this frame (location-based).
     * Uses xxh3 for speed and low collision rate.
     */
    private function computeFingerprint(): string
    {
        // Normalize the fingerprint components
        $normalized = implode(':', [
            $this->normalizeFilePath($this->file),
            $this->line,
            $this->class ?? '',
            $this->function ?? '',
        ]);

        return hash('xxh3', $normalized);
    }

    /**
     * Hash the file path for grouping.
     */
    private function computeFileHash(): string
    {
        return hash('xxh3', $this->normalizeFilePath($this->file));
    }

    /**
     * Normalize file path for consistent fingerprinting.
     */
    private function normalizeFilePath(string $path): string
    {
        // Remove project-specific prefix
        if (preg_match('#/(?:src|app|vendor)/.*$#', $path, $matches)) {
            return $matches[0];
        }
        return basename($path);
    }

    /**
     * Compute a fingerprint for an entire stack trace.
     * This is used for grouping similar errors.
     */
    public static function computeStackFingerprint(array $frames, int $maxFrames = 10): string
    {
        $fingerprints = [];
        $count = 0;
        
        foreach ($frames as $frame) {
            if (!$frame instanceof self) {
                continue;
            }
            
            // Skip vendor frames for fingerprint
            if ($frame->isVendor()) {
                continue;
            }
            
            $fingerprints[] = $frame->getFingerprint();
            $count++;
            
            if ($count >= $maxFrames) {
                break;
            }
        }

        if (empty($fingerprints)) {
            // Fallback: use all frames if no non-vendor frames
            foreach ($frames as $frame) {
                if ($frame instanceof self) {
                    $fingerprints[] = $frame->getFingerprint();
                    if (count($fingerprints) >= $maxFrames) {
                        break;
                    }
                }
            }
        }

        return hash('xxh3', implode('|', $fingerprints));
    }

    /**
     * Compute a group key for similar stack traces.
     * Less strict than fingerprint - groups similar errors.
     */
    public static function computeStackGroupKey(array $frames): string
    {
        $components = [];
        
        foreach ($frames as $frame) {
            if (!$frame instanceof self || $frame->isVendor()) {
                continue;
            }
            
            // Use only file and function for grouping
            $components[] = $frame->getFileHash() . ':' . ($frame->getFunction() ?? '');
            
            if (count($components) >= 5) {
                break;
            }
        }

        return hash('xxh3', implode('|', $components));
    }

    // --- Getters ---

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getFunction(): ?string
    {
        return $this->function;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function isVendor(): bool
    {
        return $this->isVendor;
    }

    public function getCodeContext(): ?array
    {
        return $this->codeContext;
    }

    public function setCodeContext(array $context): self
    {
        $this->codeContext = $context;
        return $this;
    }

    public function getCollapsedCount(): ?int
    {
        return $this->collapsedCount;
    }

    public function setCollapsedCount(int $count): self
    {
        $this->collapsedCount = $count;
        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    /**
     * Get the full method signature.
     */
    public function getMethodSignature(): string
    {
        if ($this->class && $this->function) {
            return $this->class . ($this->type ?? '::') . $this->function . '()';
        }
        
        if ($this->function) {
            return $this->function . '()';
        }
        
        return '[main]';
    }

    public function jsonSerialize(): array
    {
        $data = [
            'file' => $this->file,
            'line' => $this->line,
        ];
        
        if ($this->function !== null) {
            $data['fn'] = $this->function;
        }
        
        if ($this->class !== null) {
            $data['cls'] = $this->class;
            if ($this->type !== null) {
                $data['type'] = $this->type;
            }
        }
        
        // Only include vendor flag if true (default assumption is user code)
        if ($this->isVendor) {
            $data['vendor'] = true;
        }
        
        if ($this->codeContext !== null) {
            $data['ctx'] = $this->codeContext;
        }
        
        if ($this->collapsedCount !== null && $this->collapsedCount > 1) {
            $data['collapsed'] = $this->collapsedCount;
        }
        
        return $data;
    }
}
