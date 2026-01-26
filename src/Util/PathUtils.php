<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Util;

/**
 * Utility functions for path manipulation and normalization.
 *
 * Provides consistent path handling across the SDK for fingerprinting,
 * vendor detection, and display purposes.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class PathUtils
{
    /**
     * Check if a file path is within a vendor directory.
     *
     * @param string $path The file path to check
     *
     * @return bool True if the path is in a vendor directory
     */
    public static function isVendor(string $path): bool
    {
        return str_contains($path, '/vendor/') || str_contains($path, '\\vendor\\');
    }

    /**
     * Normalize a file path for consistent fingerprinting.
     *
     * Extracts the relevant portion of the path (from src/app/lib/vendor onwards)
     * to ensure consistent fingerprints across different environments.
     *
     * @param string $path The file path to normalize
     *
     * @return string The normalized path
     */
    public static function normalize(string $path): string
    {
        // Keep only the relevant part (from src/app/vendor onwards)
        if (preg_match('#/(src|app|lib|vendor)/.*$#', $path, $matches)) {
            return $matches[0];
        }

        // Fallback to basename
        return basename($path);
    }

    /**
     * Get a short display path suitable for UI.
     *
     * @param string      $path       The full file path
     * @param string|null $projectDir Optional project directory to strip
     *
     * @return string The shortened path
     */
    public static function shorten(string $path, ?string $projectDir = null): string
    {
        if ($projectDir !== null && str_starts_with($path, $projectDir)) {
            return ltrim(substr($path, strlen($projectDir)), '/\\');
        }

        return self::normalize($path);
    }

    /**
     * Check if a path matches any of the given patterns.
     *
     * @param string   $path     The path to check
     * @param string[] $patterns Array of regex patterns
     *
     * @return bool True if the path matches any pattern
     */
    public static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the relative path from a full path.
     *
     * @param string $fullPath    The full file path
     * @param string $basePath    The base path to strip
     * @param bool   $keepLeading Whether to keep leading slash
     *
     * @return string The relative path
     */
    public static function relative(string $fullPath, string $basePath, bool $keepLeading = false): string
    {
        $basePath = rtrim($basePath, '/\\');

        if (str_starts_with($fullPath, $basePath)) {
            $relative = substr($fullPath, strlen($basePath));

            return $keepLeading ? $relative : ltrim($relative, '/\\');
        }

        return $fullPath;
    }
}
