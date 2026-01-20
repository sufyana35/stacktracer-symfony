<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Doctrine;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Doctrine connection wrapper that traces all SQL queries.
 *
 * Creates OTEL-compatible spans with db.* semantic conventions.
 * Detects slow queries based on configurable threshold.
 *
 * @author Stacktracer <hello@stacktracer.io>
 *
 * @internal
 */
final class TracingConnection extends AbstractConnectionMiddleware
{
    private TracingService $tracing;

    private float $slowQueryThreshold;

    /** @var array<string, mixed> */
    private array $connectionParams;

    private int $queryCount = 0;

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function __construct(
        ConnectionInterface $connection,
        TracingService $tracing,
        float $slowQueryThreshold,
        array $connectionParams
    ) {
        parent::__construct($connection);
        $this->tracing = $tracing;
        $this->slowQueryThreshold = $slowQueryThreshold;
        $this->connectionParams = $connectionParams;
    }

    public function prepare(string $sql): Statement
    {
        $statement = parent::prepare($sql);

        return new TracingStatement(
            $statement,
            $this->tracing,
            $sql,
            $this->slowQueryThreshold,
            $this->buildDbAttributes()
        );
    }

    public function query(string $sql): Result
    {
        return $this->traceQuery($sql, fn () => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->traceQuery($sql, fn () => parent::exec($sql));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function traceQuery(string $sql, callable $callback): mixed
    {
        ++$this->queryCount;

        $span = $this->tracing->startSpan($this->getSpanName($sql), Span::KIND_CLIENT);
        $span->setAttributes($this->buildDbAttributes());
        $span->setAttribute('db.type', 'sql');
        $span->setAttribute('db.statement', $sql);
        $span->setAttribute('db.query_count', $this->queryCount);

        $startTime = microtime(true);

        try {
            $result = $callback();

            $duration = (microtime(true) - $startTime) * 1000;

            if ($duration >= $this->slowQueryThreshold) {
                $span->setAttribute('db.slow_query', true);
                $span->setAttribute('db.duration_ms', round($duration, 2));
            }

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
     * @return array<string, mixed>
     */
    private function buildDbAttributes(): array
    {
        $attrs = [
            'db.system' => $this->detectDbSystem(),
        ];

        if (isset($this->connectionParams['dbname'])) {
            $attrs['db.name'] = $this->connectionParams['dbname'];
        }

        if (isset($this->connectionParams['host'])) {
            $attrs['db.server.address'] = $this->connectionParams['host'];
        }

        if (isset($this->connectionParams['port'])) {
            $attrs['db.server.port'] = $this->connectionParams['port'];
        }

        if (isset($this->connectionParams['user'])) {
            $attrs['db.user'] = $this->connectionParams['user'];
        }

        return $attrs;
    }

    private function detectDbSystem(): string
    {
        $driver = $this->connectionParams['driver'] ?? '';

        return match (true) {
            str_contains($driver, 'mysql') => 'mysql',
            str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => 'postgresql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'sqlsrv'), str_contains($driver, 'mssql') => 'mssql',
            str_contains($driver, 'oci'), str_contains($driver, 'oracle') => 'oracle',
            default => 'unknown',
        };
    }

    private function getSpanName(string $sql): string
    {
        $sql = trim($sql);
        $operation = strtoupper(strtok($sql, " \t\n\r") ?: 'QUERY');
        $dbName = $this->connectionParams['dbname'] ?? 'db';

        return sprintf('%s %s', $operation, $dbName);
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }
}
