<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Cache pool decorator that traces cache operations.
 *
 * Creates OTEL-compatible spans with cache.* semantic conventions for all
 * cache operations. Tracks hit/miss ratios and operation timing.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TracingCachePool implements CacheItemPoolInterface
{
    private CacheItemPoolInterface $pool;

    private TracingService $tracing;

    private string $poolName;

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(
        CacheItemPoolInterface $pool,
        TracingService $tracing,
        string $poolName = 'cache'
    ) {
        $this->pool = $pool;
        $this->tracing = $tracing;
        $this->poolName = $poolName;
    }

    public function getItem(string $key): CacheItemInterface
    {
        $span = $this->tracing->startSpan(sprintf('CACHE GET %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'get');
        $span->setAttribute('cache.key', $this->sanitizeKey($key));

        $startTime = microtime(true);

        try {
            $item = $this->pool->getItem($key);

            $duration = (microtime(true) - $startTime) * 1000;
            $span->setAttribute('cache.duration_ms', round($duration, 2));

            if ($item->isHit()) {
                ++$this->hits;
                $span->setAttribute('cache.hit', true);
            } else {
                ++$this->misses;
                $span->setAttribute('cache.hit', false);
            }

            $span->setStatus('ok');
            $this->tracing->endSpan($span);

            return $item;
        } catch (\Throwable $e) {
            ++$this->misses;
            $span->setStatus('error');
            $span->setAttribute('cache.hit', false);
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    /**
     * @param string[] $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $span = $this->tracing->startSpan(sprintf('CACHE MGET %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'mget');
        $span->setAttribute('cache.keys_count', count($keys));

        $startTime = microtime(true);

        try {
            $items = $this->pool->getItems($keys);

            $hitCount = 0;
            $missCount = 0;
            $result = [];

            foreach ($items as $key => $item) {
                if ($item->isHit()) {
                    ++$hitCount;
                } else {
                    ++$missCount;
                }
                $result[$key] = $item;
            }

            $this->hits += $hitCount;
            $this->misses += $missCount;

            $duration = (microtime(true) - $startTime) * 1000;
            $span->setAttribute('cache.duration_ms', round($duration, 2));
            $span->setAttribute('cache.hits', $hitCount);
            $span->setAttribute('cache.misses', $missCount);
            $span->setStatus('ok');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $this->misses += count($keys);
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function hasItem(string $key): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE HAS %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'has');
        $span->setAttribute('cache.key', $this->sanitizeKey($key));

        try {
            $result = $this->pool->hasItem($key);
            $span->setAttribute('cache.exists', $result);
            $span->setStatus('ok');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function clear(): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE CLEAR %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'clear');

        try {
            $result = $this->pool->clear();
            $span->setAttribute('cache.cleared', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            // Reset counters
            $this->hits = 0;
            $this->misses = 0;

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function deleteItem(string $key): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE DEL %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'delete');
        $span->setAttribute('cache.key', $this->sanitizeKey($key));

        try {
            $result = $this->pool->deleteItem($key);
            $span->setAttribute('cache.deleted', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    /**
     * @param string[] $keys
     */
    public function deleteItems(array $keys): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE MDEL %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'mdelete');
        $span->setAttribute('cache.keys_count', count($keys));

        try {
            $result = $this->pool->deleteItems($keys);
            $span->setAttribute('cache.deleted', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function save(CacheItemInterface $item): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE SET %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'set');
        $span->setAttribute('cache.key', $this->sanitizeKey($item->getKey()));

        try {
            $result = $this->pool->save($item);
            $span->setAttribute('cache.saved', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE SET_DEFERRED %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'set_deferred');
        $span->setAttribute('cache.key', $this->sanitizeKey($item->getKey()));

        try {
            $result = $this->pool->saveDeferred($item);
            $span->setAttribute('cache.deferred', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function commit(): bool
    {
        $span = $this->tracing->startSpan(sprintf('CACHE COMMIT %s', $this->poolName), 'cache');
        $span->setAttribute('cache.system', $this->poolName);
        $span->setAttribute('cache.operation', 'commit');

        try {
            $result = $this->pool->commit();
            $span->setAttribute('cache.committed', $result);
            $span->setStatus($result ? 'ok' : 'error');
            $this->tracing->endSpan($span);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setAttribute('error.type', get_class($e));
            $span->setAttribute('error.message', $e->getMessage());
            $this->tracing->endSpan($span);

            throw $e;
        }
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getMisses(): int
    {
        return $this->misses;
    }

    public function getHitRatio(): float
    {
        $total = $this->hits + $this->misses;

        return $total > 0 ? $this->hits / $total : 0.0;
    }

    private function sanitizeKey(string $key): string
    {
        // Truncate very long keys
        return strlen($key) > 100 ? substr($key, 0, 100) . '...' : $key;
    }
}
