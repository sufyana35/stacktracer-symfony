<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Transport;

use Stacktracer\SymfonyBundle\Model\Trace;

/**
 * Interface for sending traces to a remote API endpoint.
 *
 * Defines the contract for transport implementations that send trace data
 * to the Stacktracer backend. Implementations should handle batching,
 * compression, retries, and error handling.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
interface TransportInterface
{
    /**
     * Send a single trace.
     *
     * @param Trace $trace The trace to send
     *
     * @return bool True if sent successfully, false otherwise
     */
    public function send(Trace $trace): bool;

    /**
     * Flush any queued traces immediately.
     *
     * Forces immediate sending of all queued traces without waiting
     * for the batch size or flush interval thresholds.
     */
    public function flush(): void;
}
