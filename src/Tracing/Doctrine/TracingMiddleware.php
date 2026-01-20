<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Doctrine;

use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Doctrine\DBAL\Logging\Middleware;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Doctrine DBAL middleware for tracking SQL queries as spans.
 *
 * Creates OTEL-compatible spans with db.* semantic conventions for all database
 * queries. Supports slow query detection and query fingerprinting.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TracingMiddleware implements DriverMiddleware
{
    private TracingService $tracing;

    private float $slowQueryThreshold;

    public function __construct(TracingService $tracing, float $slowQueryThreshold = 100.0)
    {
        $this->tracing = $tracing;
        $this->slowQueryThreshold = $slowQueryThreshold;
    }

    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new TracingDriver($driver, $this->tracing, $this->slowQueryThreshold);
    }
}
