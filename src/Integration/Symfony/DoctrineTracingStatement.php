<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Doctrine statement wrapper that traces prepared statement execution.
 *
 * Creates OTEL-compatible spans for prepared statement executions.
 *
 * @author Stacktracer <hello@stacktracer.io>
 *
 * @internal
 */
final class DoctrineTracingStatement extends AbstractStatementMiddleware
{
    private TracingService $tracing;

    private string $sql;

    private float $slowQueryThreshold;

    /** @var array<string, mixed> */
    private array $dbAttributes;

    /** @var array<int|string, mixed> */
    private array $params = [];

    /**
     * @param array<string, mixed> $dbAttributes
     */
    public function __construct(
        Statement $statement,
        TracingService $tracing,
        string $sql,
        float $slowQueryThreshold,
        array $dbAttributes
    ) {
        parent::__construct($statement);
        $this->tracing = $tracing;
        $this->sql = $sql;
        $this->slowQueryThreshold = $slowQueryThreshold;
        $this->dbAttributes = $dbAttributes;
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->params[$param] = $this->sanitizeParamValue($value, $type);
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $span = $this->tracing->startSpan($this->getSpanName(), 'db');
        $span->setAttributes($this->dbAttributes);
        $span->setAttribute('db.statement', $this->sql);

        if (!empty($this->params)) {
            $span->setAttribute('db.params_count', count($this->params));
        }

        $startTime = microtime(true);

        try {
            $result = parent::execute();

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

    private function getSpanName(): string
    {
        $sql = trim($this->sql);
        $operation = strtoupper(strtok($sql, " \t\n\r") ?: 'QUERY');
        $dbName = $this->dbAttributes['db.name'] ?? 'db';

        return sprintf('%s %s', $operation, $dbName);
    }

    private function sanitizeParamValue(mixed $value, ParameterType $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT) {
            return '[BINARY]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $str = (string) $value;

            return strlen($str) > 100 ? substr($str, 0, 100) . '...' : $str;
        }

        return '[COMPLEX]';
    }
}
