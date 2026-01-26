<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Request;

use Symfony\Component\HttpFoundation\Request;

/**
 * Extracts and sanitizes request data for tracing.
 *
 * Handles header filtering, body capture, and IP detection with
 * proper sanitization of sensitive data.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class RequestExtractor
{
    /**
     * Essential headers to capture (allowlist for optimization).
     * Authorization will be redacted by sensitive key filtering.
     */
    private const ESSENTIAL_HEADERS = [
        'host',
        'user-agent',
        'content-type',
        'content-length',
        'accept',
        'accept-language',
        'authorization',
        'x-request-id',
        'x-correlation-id',
        'x-forwarded-for',
        'x-real-ip',
        'referer',
        'origin',
    ];

    /**
     * Headers that should never be captured.
     */
    private const BLOCKED_HEADERS = [
        'cookie',
        'set-cookie',
        'x-csrf-token',
    ];

    /**
     * Extract essential headers from a request.
     *
     * Only captures headers from the allowlist for performance and privacy.
     *
     * @param Request $request The Symfony request
     *
     * @return array<string, string|array> The filtered headers
     */
    public static function extractHeaders(Request $request): array
    {
        return self::compactHeaders($request->headers->all());
    }

    /**
     * Compact and filter headers from a raw headers array.
     *
     * @param array<string, array<string>> $headers Raw headers array
     *
     * @return array<string, string|array> The filtered headers
     */
    public static function compactHeaders(array $headers): array
    {
        $compacted = [];

        foreach ($headers as $name => $values) {
            if (empty($values)) {
                continue;
            }

            $lowerName = strtolower($name);

            // Skip blocked headers
            if (in_array($lowerName, self::BLOCKED_HEADERS, true)) {
                continue;
            }

            // Only include essential headers
            if (!in_array($lowerName, self::ESSENTIAL_HEADERS, true)) {
                continue;
            }

            $compacted[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $compacted;
    }

    /**
     * Extract basic request data suitable for tracing.
     *
     * @param Request $request The Symfony request
     *
     * @return array<string, mixed> The request data
     */
    public static function extractBasicData(Request $request): array
    {
        $data = [
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'path' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent'),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ];

        if ($qs = $request->getQueryString()) {
            $data['qs'] = $qs;
        }

        if ($route = $request->attributes->get('_route')) {
            $data['route'] = $route;
        }

        return $data;
    }

    /**
     * Detect the real client IP address.
     *
     * Handles X-Forwarded-For, X-Real-IP, and direct connection.
     *
     * @param Request $request      The Symfony request
     * @param bool    $trustProxies Whether to trust proxy headers
     *
     * @return string|null The client IP address
     */
    public static function getClientIp(Request $request, bool $trustProxies = true): ?string
    {
        if ($trustProxies) {
            // X-Forwarded-For can contain multiple IPs
            $xff = $request->headers->get('X-Forwarded-For');
            if ($xff) {
                $ips = array_map('trim', explode(',', $xff));

                return $ips[0] ?? null;
            }

            // X-Real-IP is typically a single IP
            $realIp = $request->headers->get('X-Real-IP');
            if ($realIp) {
                return $realIp;
            }
        }

        return $request->getClientIp();
    }

    /**
     * Get the essential headers list.
     *
     * @return string[] The list of essential header names
     */
    public static function getEssentialHeaders(): array
    {
        return self::ESSENTIAL_HEADERS;
    }

    /**
     * Sensitive keys that should be redacted from request body.
     */
    private const SENSITIVE_BODY_KEYS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'access_token',
        'refresh_token',
        'credit_card',
        'creditcard',
        'card_number',
        'cvv',
        'cvc',
        'ssn',
        'social_security',
        'private_key',
        'privatekey',
    ];

    /**
     * Extract and sanitize request body/POST parameters.
     *
     * Captures form data, JSON body, and query params with sanitization
     * of sensitive fields and size limiting.
     *
     * @param Request $request      The Symfony request
     * @param int     $maxBodySize  Maximum body size in bytes (0 = unlimited)
     * @param bool    $captureFiles Whether to capture file upload info
     *
     * @return array<string, mixed>|null The sanitized body data, or null if empty/too large
     */
    public static function extractBody(Request $request, int $maxBodySize = 10240, bool $captureFiles = true): ?array
    {
        $method = $request->getMethod();
        
        // Only capture body for methods that typically have one
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $body = [];
        $contentType = $request->headers->get('Content-Type', '');

        // Check raw content size first
        $rawContent = $request->getContent();
        if ($maxBodySize > 0 && strlen($rawContent) > $maxBodySize) {
            return [
                '_truncated' => true,
                '_original_size' => strlen($rawContent),
                '_max_size' => $maxBodySize,
            ];
        }

        // Handle JSON content
        if (str_contains($contentType, 'application/json')) {
            $jsonData = json_decode($rawContent, true);
            if (is_array($jsonData)) {
                $body = self::sanitizeBodyData($jsonData);
                $body['_content_type'] = 'json';
            }
        }
        // Handle form data (application/x-www-form-urlencoded or multipart/form-data)
        elseif (str_contains($contentType, 'form')) {
            // Get POST parameters
            $postData = $request->request->all();
            if (!empty($postData)) {
                $body = self::sanitizeBodyData($postData);
                $body['_content_type'] = 'form';
            }
        }
        // Handle other content types - just note the type and size
        elseif (!empty($rawContent)) {
            $body = [
                '_content_type' => $contentType ?: 'unknown',
                '_size' => strlen($rawContent),
                '_raw_preview' => substr($rawContent, 0, 200),
            ];
        }

        // Capture file upload info (just metadata, not content)
        if ($captureFiles) {
            $files = $request->files->all();
            if (!empty($files)) {
                $body['_files'] = self::extractFileInfo($files);
            }
        }

        return empty($body) ? null : $body;
    }

    /**
     * Recursively sanitize body data by redacting sensitive fields.
     *
     * @param array<string, mixed> $data The data to sanitize
     * @param int                  $depth Current recursion depth
     *
     * @return array<string, mixed> The sanitized data
     */
    private static function sanitizeBodyData(array $data, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 10) {
            return ['_truncated' => 'max depth reached'];
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if this is a sensitive field
            $isSensitive = false;
            foreach (self::SENSITIVE_BODY_KEYS as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeBodyData($value, $depth + 1);
            } elseif (is_string($value) && strlen($value) > 1000) {
                // Truncate very long string values
                $sanitized[$key] = substr($value, 0, 1000) . '... [truncated]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Extract metadata about uploaded files (not the content).
     *
     * @param array $files The files from the request
     *
     * @return array<string, array> File metadata
     */
    private static function extractFileInfo(array $files): array
    {
        $info = [];

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $info[$key] = self::extractFileInfo($file);
            } elseif ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $info[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'error' => $file->getError(),
                ];
            }
        }

        return $info;
    }
}
