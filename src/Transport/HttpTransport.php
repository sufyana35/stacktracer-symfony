<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\StacktracerBundle;

/**
 * HTTP transport for sending traces to a remote API.
 *
 * Production-ready transport implementation with batching, compression,
 * retry logic with exponential backoff, and queue overflow protection.
 *
 * Features:
 * - Batching (reduces HTTP overhead)
 * - Compression (gzip)
 * - Retry with exponential backoff
 * - Queue overflow protection
 * - Graceful shutdown handling
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class HttpTransport implements TransportInterface
{
    private string $endpoint;

    private string $apiKey;

    private LoggerInterface $logger;

    private int $batchSize;

    private int $flushIntervalMs;

    private int $maxQueueSize;

    private array $queue = [];

    private float $lastFlush;

    private int $timeout;

    private bool $compress;

    private int $maxRetries;

    public function __construct(
        string $endpoint,
        string $apiKey,
        ?LoggerInterface $logger = null,
        int $batchSize = 50,
        int $flushIntervalMs = 5000,
        int $maxQueueSize = 500,
        int $timeout = 5,
        bool $compress = true,
        int $maxRetries = 3
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger ?? new NullLogger();
        $this->batchSize = $batchSize;
        $this->flushIntervalMs = $flushIntervalMs;
        $this->maxQueueSize = $maxQueueSize;
        $this->timeout = $timeout;
        $this->compress = $compress;
        $this->maxRetries = $maxRetries;
        $this->lastFlush = microtime(true) * 1000;
    }

    /**
     * Queue a trace for sending.
     */
    public function send(Trace $trace): bool
    {
        if (count($this->queue) >= $this->maxQueueSize) {
            array_shift($this->queue);
            $this->logger->warning('Trace queue overflow, dropping oldest trace');
        }

        $this->queue[] = $trace->jsonSerialize();

        if ($this->shouldFlush()) {
            $this->flush();
        }

        return true;
    }

    /**
     * Flush all queued traces to the API.
     */
    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $batch = $this->queue;
        $this->queue = [];
        $this->lastFlush = microtime(true) * 1000;

        $this->sendToApi($batch);
    }

    private function shouldFlush(): bool
    {
        if (count($this->queue) >= $this->batchSize) {
            return true;
        }

        $elapsed = (microtime(true) * 1000) - $this->lastFlush;

        return $elapsed >= $this->flushIntervalMs;
    }

    private function sendToApi(array $batch): bool
    {
        $payload = [
            'traces' => $batch,
            'meta' => [
                'sdk' => StacktracerBundle::SDK_NAME,
                'version' => StacktracerBundle::SDK_VERSION,
                'sent_at' => (int) (microtime(true) * 1000),
                'count' => count($batch),
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-Trace-Count: ' . count($batch),
            'User-Agent: Stacktracer-Symfony/' . StacktracerBundle::SDK_VERSION,
        ];

        if ($this->compress && strlen($body) > 1024) {
            $compressed = gzencode($body, 6);
            if ($compressed !== false && strlen($compressed) < strlen($body)) {
                $body = $compressed;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        $headers[] = 'Content-Length: ' . strlen($body);

        for ($attempt = 1; $attempt <= $this->maxRetries; ++$attempt) {
            try {
                $result = $this->doHttpRequest($body, $headers);

                if ($result['status'] >= 200 && $result['status'] < 300) {
                    $this->logger->debug('Sent {count} traces to API', ['count' => count($batch)]);

                    return true;
                }

                if ($result['status'] >= 400 && $result['status'] < 500) {
                    $this->logger->error('API rejected traces: HTTP {status}', [
                        'status' => $result['status'],
                        'response' => substr($result['body'], 0, 200),
                    ]);

                    return false;
                }

                $this->logger->warning('API error HTTP {status}, retry {attempt}/{max}', [
                    'status' => $result['status'],
                    'attempt' => $attempt,
                    'max' => $this->maxRetries,
                ]);

            } catch (\Exception $e) {
                $this->logger->warning('HTTP error: {msg}, retry {attempt}/{max}', [
                    'msg' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max' => $this->maxRetries,
                ]);
            }

            if ($attempt < $this->maxRetries) {
                usleep((int) (pow(2, $attempt) * 100000));
            }
        }

        $this->logger->error('Failed to send {count} traces after {max} retries', [
            'count' => count($batch),
            'max' => $this->maxRetries,
        ]);

        return false;
    }

    private function doHttpRequest(string $body, array $headers): array
    {
        // Use cURL if available, otherwise fall back to file_get_contents
        if (function_exists('curl_init')) {
            return $this->doHttpRequestCurl($body, $headers);
        }

        return $this->doHttpRequestStream($body, $headers);
    }

    private function doHttpRequestCurl(string $body, array $headers): array
    {
        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('cURL error: ' . $error);
        }

        return [
            'status' => $status,
            'body' => $response,
        ];
    }

    private function doHttpRequestStream(string $body, array $headers): array
    {
        $headerString = implode("\r\n", $headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerString,
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->endpoint, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException('HTTP error: ' . ($error['message'] ?? 'Unknown error'));
        }

        // Parse status from $http_response_header
        $status = 0;
        if (isset($http_response_header[0])) {
            if (preg_match('/HTTP\/[\d.]+\s+(\d+)/', $http_response_header[0], $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => $response,
        ];
    }

    public function getQueueSize(): int
    {
        return count($this->queue);
    }
}
