<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Util;

/**
 * Fingerprinting utility for deduplication and grouping of traces.
 *
 * Provides consistent hashing using xxHash3 (xxh3) for fast, collision-resistant
 * fingerprints. Used for deduplication, grouping similar errors, and cost optimization
 * by storing fingerprints instead of full stack traces on repeats.
 *
 * Fingerprinting strategies:
 * - Stack traces (group similar errors)
 * - Exception messages (normalize variable data)
 * - Request paths (group similar routes)
 * - Log messages (deduplicate logs)
 * - Breadcrumb trails (identify unique user paths)
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class Fingerprint
{
    /**
     * Compute a fingerprint for an exception.
     * Combines exception type, normalized message, and stack trace.
     *
     * @param \Throwable $exception  The exception to fingerprint
     * @param int        $stackDepth Number of stack frames to include
     *
     * @return string The fingerprint hash (16 hex chars)
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
        for ($i = 0; $i < min($stackDepth, count($trace)); ++$i) {
            $frame = $trace[$i];
            $components[] = self::frameSignature($frame);
        }

        return self::hash(implode('|', $components));
    }

    /**
     * Compute a grouping key for exceptions (less specific).
     * Groups exceptions of same type at same location.
     *
     * @param \Throwable $exception The exception to group
     *
     * @return string The group key hash (16 hex chars)
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
     *
     * @param \Throwable $exception The exception
     *
     * @return string The location string (file:line)
     */
    private static function exceptionLocation(\Throwable $exception): string
    {
        return PathUtils::normalize($exception->getFile()) . ':' . $exception->getLine();
    }

    /**
     * Compute fingerprint for a stack frame.
     *
     * @param array $frame The stack frame array
     *
     * @return string The frame signature
     */
    public static function frameSignature(array $frame): string
    {
        $file = PathUtils::normalize($frame['file'] ?? '[internal]');
        $line = $frame['line'] ?? 0;
        $class = $frame['class'] ?? '';
        $function = $frame['function'] ?? '';

        return "{$file}:{$line}:{$class}:{$function}";
    }

    /**
     * Compute fingerprint for an entire stack trace.
     *
     * @param array $trace     The stack trace array
     * @param int   $maxFrames Maximum frames to include
     *
     * @return string The stack trace fingerprint
     */
    public static function stackTrace(array $trace, int $maxFrames = 10): string
    {
        $signatures = [];
        $count = 0;

        foreach ($trace as $frame) {
            // Skip vendor frames
            $file = $frame['file'] ?? '';
            if (PathUtils::isVendor($file)) {
                continue;
            }

            $signatures[] = self::frameSignature($frame);
            ++$count;

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
     *
     * @param string $message The message to normalize
     *
     * @return string The normalized message
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
     *
     * @param string $path The file path to normalize
     *
     * @return string The normalized path
     *
     * @deprecated Use PathUtils::normalize() instead
     */
    public static function normalizePath(string $path): string
    {
        return PathUtils::normalize($path);
    }

    /**
     * Fingerprint a request for grouping.
     *
     * @param string   $method     The HTTP method
     * @param string   $path       The request path
     * @param int|null $statusCode Optional status code
     *
     * @return string The request fingerprint
     */
    public static function request(string $method, string $path, ?int $statusCode = null): string
    {
        $normalizedPath = PathUtils::normalize($path);

        $components = [
            strtoupper($method),
            $normalizedPath,
        ];

        if ($statusCode !== null) {
            $components[] = (string) $statusCode;
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
     *
     * @param string      $message The log message
     * @param string|null $channel The log channel
     * @param string|null $level   The log level
     *
     * @return string The log fingerprint
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
     *
     * @param array $breadcrumbs Array of breadcrumbs
     * @param int   $lastN       Number of most recent breadcrumbs to include
     *
     * @return string The trail fingerprint
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
     *
     * @param string $data The data to hash
     *
     * @return string The 16-character hex hash
     */
    public static function hash(string $data): string
    {
        return hash('xxh3', $data);
    }

    /**
     * Compute a short hash (8 chars) for display.
     *
     * @param string $data The data to hash
     *
     * @return string The 8-character hex hash
     */
    public static function shortHash(string $data): string
    {
        return substr(self::hash($data), 0, 8);
    }

    /**
     * Generate a content-based hash for deduplication.
     * Combines multiple fields into a single fingerprint.
     *
     * @param array $fields Array of field values
     *
     * @return string The composite fingerprint
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

            return (string) $value;
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

        for ($i = 0; $i < $len; ++$i) {
            if ($fp1[$i] === $fp2[$i]) {
                ++$same;
            }
        }

        return $same / max(strlen($fp1), strlen($fp2));
    }
}
