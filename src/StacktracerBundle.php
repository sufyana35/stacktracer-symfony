<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Stacktracer Symfony Bundle.
 *
 * Lightweight error tracking and tracing SDK for Symfony applications.
 * Captures exceptions, request traces, spans, logs, breadcrumbs, and performance data
 * with OpenTelemetry-compatible distributed tracing.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class StacktracerBundle extends Bundle
{
    /**
     * Returns the bundle root path.
     *
     * @return string The bundle root path
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
