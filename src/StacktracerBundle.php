<?php

namespace Stacktracer\SymfonyBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Stacktracer Symfony Bundle
 * 
 * Lightweight error tracking and tracing SDK for Symfony applications.
 * Captures exceptions, request traces, and performance data.
 */
class StacktracerBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
