<?php

namespace Stacktracer\SymfonyBundle\Util;

/**
 * Fingerprinting utility for deduplication and grouping of traces.
 * 
 * Provides consistent hashing for:
 * - Stack traces (group similar errors)
 * - Exception messages (normalize variable data)
 * - Request paths (group similar routes)
 * - Log messages (deduplicate logs)
 */
class Fingerprint
{
    /**
     * Compute a fingerprint for an exception.
     * Combines exception type, normalized message, and stack trace.
     */
    public static function exception(\Throwable $exception, int $stackDepth = 5): string
    {
        $components = [
            get_class($exception),
            self::normalizeMessage($exception->getMessage()),
            self::exceptionLocation($exception),
        ];

        // Add top stack frames
        $trace = $exception->getTrace();
        for ($i = 0; $i < min($stackDepth, count($trace)); $i++) {
            $frame = $trace[$i];
            $components[] = self::frameSignature($frame);
        }

        return self::hash(implode('|', $components));
    }

    /**
     * Compute a grouping key for exceptions (less specific).
     * Groups exceptions of same type at same location.
     */
    public static function exceptionGroup(\Throwable $exception): string
    {
        return self::hash(implode('|', [
            get_class($exception),
            self::exceptionLocation($exception),
        ]));
    }

    /**
     * Get exception location signature.
     */
    private static function exceptionLocation(\Throwable $exception): string
    {
        return self::normalizePath($exception->getFile()) . ':' . $exception->getLine();
    }

    /**
     * Compute fingerprint for a stack frame.
     */
    public static function frameSignature(array $frame): string
    {
        $file = self::normalizePath($frame['file'] ?? '[internal]');
        $line = $frame['line'] ?? 0;
        $class = $frame['class'] ?? '';
        $function = $frame['function'] ?? '';

        return "{$file}:{$line}:{$class}:{$function}";
    }

    /**
     * Compute fingerprint for an entire stack trace.
     */
    public static function stackTrace(array $trace, int $maxFrames = 10): string
    {
        $signatures = [];
        $count = 0;

        foreach ($trace as $frame) {
            // Skip vendor frames
            $file = $frame['file'] ?? '';
            if (self::isVendorPath($file)) {
                continue;
            }

            $signatures[] = self::frameSignature($frame);
            $count++;

            if ($count >= $maxFrames) {
                break;
            }
        }

        // Fallback to all frames if none are non-vendor
        if (empty($signatures)) {
            foreach ($trace as $frame) {
                $signatures[] = self::frameSignature($frame);
                if (count($signatures) >= $maxFrames) {
                    break;
                }
            }
        }

        return self::hash(implode('|', $signatures));
    }

    /**
     * Normalize an exception message by replacing variable data.
     */
    public static function normalizeMessage(string $message): string
    {
        // Replace UUIDs
        $message = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '<UUID>',
            $message
        );

        // Replace hex IDs (32+ chars)
        $message = preg_replace('/[0-9a-f]{32,}/i', '<HEX>', $message);

        // Replace numbers
        $message = preg_replace('/\b\d+\b/', '<N>', $message);

        // Replace quoted strings
        $message = preg_replace('/"[^"]*"/', '"<STR>"', $message);
        $message = preg_replace("/'[^']*'/", "'<STR>'", $message);

        // Replace email addresses
        $message = preg_replace('/[\w\.-]+@[\w\.-]+\.\w+/', '<EMAIL>', $message);

        // Replace IP addresses
        $message = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '<IP>', $message);

        // Replace URLs
        $message = preg_replace('#https?://[^\s]+#', '<URL>', $message);

        return $message;
    }

    /**
     * Normalize a file path for consistent fingerprinting.
     */
    public static function normalizePath(string $path): string
    {
        // Keep only the relevant part (from src/app/vendor onwards)
        if (preg_match('#/(src|app|lib|vendor)/.*$#', $path, $matches)) {
            return $matches[0];
        }

        // Fallback to basename
        return basename($path);
    }

    /**
     * Fingerprint a request for grouping.
     */
    public static function request(string $method, string $path, ?int $statusCode = null): string
    {
        $normalizedPath = self::normalizePath($path);
        
        $components = [
            strtoupper($method),
            $normalizedPath,
        ];

        if ($statusCode !== null) {
            $components[] = (string)$statusCode;
        }

        return self::hash(implode('|', $components));
    }

    /**
     * Normalize a URL path by replacing variable segments.
     */
    public static function normalizeUrlPath(string $path): string
    {
        // Replace UUIDs in path
        $path = preg_replace(
            '/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '/<UUID>',
            $path
        );

        // Replace numeric IDs
        $path = preg_replace('/\/\d+/', '/<ID>', $path);

        // Replace hex strings (8+ chars)
        $path = preg_replace('/\/[0-9a-f]{8,}/i', '/<HEX>', $path);

        return $path;
    }

    /**
     * Fingerprint a log message for deduplication.
     */
    public static function logMessage(string $message, ?string $channel = null, ?string $level = null): string
    {
        $normalized = self::normalizeMessage($message);
        
        $components = [$normalized];
        
        if ($channel) {
            $components[] = $channel;
        }
        
        if ($level) {
            $components[] = $level;
        }

        return self::hash(implode('|', $components));
    }

    /**
     * Fingerprint a breadcrumb trail for grouping.
     */
    public static function breadcrumbTrail(array $breadcrumbs, int $lastN = 5): string
    {
        $trail = array_slice($breadcrumbs, -$lastN);
        $components = [];

        foreach ($trail as $bc) {
            $category = is_array($bc) ? ($bc['category'] ?? '') : $bc->getCategory();
            $message = is_array($bc) ? ($bc['message'] ?? '') : $bc->getMessage();
            $components[] = $category . ':' . self::normalizeMessage($message);
        }

        return self::hash(implode('|', $components));
    }

    /**
     * Compute hash using xxh3 (fast, low collision).
     */
    public static function hash(string $data): string
    {
        return hash('xxh3', $data);
    }

    /**
     * Compute a short hash (8 chars) for display.
     */
    public static function shortHash(string $data): string
    {
        return substr(self::hash($data), 0, 8);
    }

    /**
     * Check if path is in vendor directory.
     */
    private static function isVendorPath(string $path): bool
    {
        return str_contains($path, '/vendor/') || str_contains($path, '\\vendor\\');
    }

    /**
     * Generate a content-based hash for deduplication.
     * Combines multiple fields into a single fingerprint.
     */
    public static function composite(array $fields): string
    {
        $normalized = array_map(function ($value) {
            if (is_string($value)) {
                return $value;
            }
            if (is_array($value)) {
                return json_encode($value);
            }
            return (string)$value;
        }, $fields);

        return self::hash(implode('|', $normalized));
    }

    /**
     * Compute similarity score between two fingerprints.
     * Returns 0.0 to 1.0 (1.0 = identical).
     */
    public static function similarity(string $fp1, string $fp2): float
    {
        if ($fp1 === $fp2) {
            return 1.0;
        }

        // Simple byte comparison for hash similarity
        $same = 0;
        $len = min(strlen($fp1), strlen($fp2));
        
        for ($i = 0; $i < $len; $i++) {
            if ($fp1[$i] === $fp2[$i]) {
                $same++;
            }
        }

        return $same / max(strlen($fp1), strlen($fp2));
    }
}
