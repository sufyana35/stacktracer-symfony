<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Doctrine driver wrapper that creates traced connections.
 *
 * @author Stacktracer <hello@stacktracer.io>
 *
 * @internal
 */
final class DoctrineTracingDriver extends AbstractDriverMiddleware
{
    private TracingService $tracing;

    private float $slowQueryThreshold;

    public function __construct(DriverInterface $driver, TracingService $tracing, float $slowQueryThreshold)
    {
        parent::__construct($driver);
        $this->tracing = $tracing;
        $this->slowQueryThreshold = $slowQueryThreshold;
    }

    public function connect(array $params): DriverConnection
    {
        $connection = parent::connect($params);

        return new DoctrineTracingConnection(
            $connection,
            $this->tracing,
            $this->slowQueryThreshold,
            $params
        );
    }
}
