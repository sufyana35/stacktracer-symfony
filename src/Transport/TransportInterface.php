<?php

namespace Stacktracer\SymfonyBundle\Transport;

use Stacktracer\SymfonyBundle\Model\Trace;

/**
 * Interface for sending traces to a remote API endpoint.
 */
interface TransportInterface
{
    /**
     * Send a single trace.
     */
    public function send(Trace $trace): bool;

    /**
     * Flush any queued traces immediately.
     */
    public function flush(): void;
}
