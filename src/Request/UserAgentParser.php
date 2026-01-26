<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Request;

/**
 * Parses User-Agent strings to extract browser, OS, and device information.
 *
 * Results are cached for performance since the same User-Agent strings
 * repeat frequently in web applications.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class UserAgentParser
{
    /**
     * @var array<string, array> User-Agent parsing cache (LRU with max size)
     */
    private static array $cache = [];

    /**
     * Maximum cached User-Agent entries.
     */
    private const CACHE_MAX_SIZE = 100;

    /**
     * Parse a User-Agent string to extract browser, OS, and device info.
     *
     * @param string $userAgent The User-Agent string
     *
     * @return array{
     *     browser: ?string,
     *     browser_name: ?string,
     *     browser_version: ?string,
     *     os: ?string,
     *     os_name: ?string,
     *     device: ?string
     * } The parsed information
     */
    public static function parse(string $userAgent): array
    {
        if (empty($userAgent)) {
            return self::emptyResult();
        }

        // Check cache first (same User-Agents repeat frequently)
        $cacheKey = hash('xxh3', $userAgent);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $result = self::emptyResult();

        // Parse browser
        $result = array_merge($result, self::parseBrowser($userAgent));

        // Parse OS
        $result = array_merge($result, self::parseOs($userAgent));

        // Parse device
        $result['device'] = self::parseDevice($userAgent);

        // Cache result with LRU eviction
        self::cacheResult($cacheKey, $result);

        return $result;
    }

    /**
     * Parse browser information from User-Agent.
     *
     * @param string $userAgent The User-Agent string
     *
     * @return array{browser: ?string, browser_name: ?string, browser_version: ?string}
     */
    private static function parseBrowser(string $userAgent): array
    {
        $result = [
            'browser' => null,
            'browser_name' => null,
            'browser_version' => null,
        ];

        // Combined browser regex - order matters (Edge before Chrome, etc.)
        if (preg_match('/(?:Edg(?:e|A|iOS)?|OPR|Opera|Chrome|Safari|Firefox|(?:MSIE |rv:))\/?([\d.]+)?/', $userAgent, $match)) {
            $fullMatch = $match[0];
            $version = $match[1] ?? null;

            // Determine browser from matched pattern
            $browserName = match (true) {
                str_contains($fullMatch, 'Edg') => 'Edge',
                str_contains($fullMatch, 'OPR'), str_contains($fullMatch, 'Opera') => 'Opera',
                str_contains($fullMatch, 'Chrome') => 'Chrome',
                str_contains($fullMatch, 'Safari') => 'Safari',
                str_contains($fullMatch, 'Firefox') => 'Firefox',
                str_contains($fullMatch, 'MSIE'), str_contains($fullMatch, 'rv:') => 'IE',
                default => null,
            };

            if ($browserName !== null) {
                $result['browser_name'] = $browserName;
                $result['browser_version'] = $version;
                $result['browser'] = $version ? "{$browserName} {$version}" : $browserName;
            }
        }

        return $result;
    }

    /**
     * Parse OS information from User-Agent.
     *
     * @param string $userAgent The User-Agent string
     *
     * @return array{os: ?string, os_name: ?string}
     */
    private static function parseOs(string $userAgent): array
    {
        $result = [
            'os' => null,
            'os_name' => null,
        ];

        // Combined OS detection
        if (preg_match('/Windows NT ([\d.]+)|Mac OS X ([\d._]+)|(?:iPhone|iPad|iPod).*OS ([\d_]+)|Android ([\d.]+)|Linux|Ubuntu|CrOS/', $userAgent, $match)) {
            $fullMatch = $match[0];

            if (str_starts_with($fullMatch, 'Windows NT')) {
                $ntVersion = $match[1] ?? '';
                $result['os_name'] = match ($ntVersion) {
                    '10.0' => str_contains($userAgent, 'Win64') ? 'Windows 11' : 'Windows 10',
                    '6.3' => 'Windows 8.1',
                    '6.2' => 'Windows 8',
                    '6.1' => 'Windows 7',
                    '6.0' => 'Windows Vista',
                    '5.1', '5.2' => 'Windows XP',
                    default => 'Windows',
                };
                $result['os'] = $result['os_name'];
            } elseif (str_contains($fullMatch, 'Mac OS X')) {
                $result['os_name'] = 'macOS';
                $version = str_replace('_', '.', $match[2] ?? '');
                $result['os'] = $version ? "macOS {$version}" : 'macOS';
            } elseif (preg_match('/iPhone|iPad|iPod/', $fullMatch)) {
                $result['os_name'] = 'iOS';
                $version = str_replace('_', '.', $match[3] ?? '');
                $result['os'] = $version ? "iOS {$version}" : 'iOS';
            } elseif (str_contains($fullMatch, 'Android')) {
                $result['os_name'] = 'Android';
                $version = $match[4] ?? '';
                $result['os'] = $version ? "Android {$version}" : 'Android';
            } elseif ($fullMatch === 'Ubuntu') {
                $result['os_name'] = 'Ubuntu';
                $result['os'] = 'Ubuntu';
            } elseif ($fullMatch === 'CrOS') {
                $result['os_name'] = 'Chrome OS';
                $result['os'] = 'Chrome OS';
            } elseif ($fullMatch === 'Linux') {
                $result['os_name'] = 'Linux';
                $result['os'] = 'Linux';
            }
        }

        return $result;
    }

    /**
     * Parse device type from User-Agent.
     *
     * @param string $userAgent The User-Agent string
     *
     * @return string|null The device type
     */
    private static function parseDevice(string $userAgent): ?string
    {
        if (preg_match('/iPhone|iPod|iPad|Android.*Mobile|Android|Mobile/', $userAgent, $match)) {
            $m = $match[0];

            return match (true) {
                $m === 'iPhone', $m === 'iPod' => 'iPhone',
                $m === 'iPad' => 'iPad',
                str_contains($m, 'Mobile') && str_contains($userAgent, 'Android') => 'Android Phone',
                $m === 'Android' => 'Android Tablet',
                $m === 'Mobile' => 'Mobile',
                default => null,
            };
        }

        if (preg_match('/Windows|Macintosh|Linux/', $userAgent)) {
            return 'Desktop';
        }

        return null;
    }

    /**
     * Get an empty result array.
     *
     * @return array{
     *     browser: null,
     *     browser_name: null,
     *     browser_version: null,
     *     os: null,
     *     os_name: null,
     *     device: null
     * }
     */
    private static function emptyResult(): array
    {
        return [
            'browser' => null,
            'browser_name' => null,
            'browser_version' => null,
            'os' => null,
            'os_name' => null,
            'device' => null,
        ];
    }

    /**
     * Cache a result with LRU eviction.
     *
     * @param string $key    The cache key
     * @param array  $result The result to cache
     */
    private static function cacheResult(string $key, array $result): void
    {
        if (count(self::$cache) >= self::CACHE_MAX_SIZE) {
            // Remove oldest entry (first key)
            array_shift(self::$cache);
        }

        self::$cache[$key] = $result;
    }

    /**
     * Clear the User-Agent cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
