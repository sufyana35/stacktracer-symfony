<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Util;

use Symfony\Component\ErrorHandler\Error\OutOfMemoryError;

/**
 * Handles Out of Memory (OOM) error detection and recovery.
 *
 * When an OOM error is detected, this handler temporarily increases the memory
 * limit to allow error reporting to complete before the process terminates.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class OomHandler
{
    /**
     * Regex pattern to detect OOM error messages and extract current limit.
     */
    private const OOM_REGEX = '/Allowed memory size of (\d+) bytes exhausted/';

    /**
     * Default memory increase (5MB) - just enough to serialize and send error report.
     */
    public const DEFAULT_MEMORY_INCREASE = 5242880;

    /**
     * Check if the throwable is an Out of Memory error.
     *
     * Handles:
     * - Symfony 4.4+ OutOfMemoryError
     * - Legacy Symfony 2.x/3.x OutOfMemoryException
     * - Detection by message pattern for wrapped exceptions
     *
     * @param \Throwable $throwable The exception to check
     *
     * @return bool True if this is an OOM error
     */
    public static function isOom(\Throwable $throwable): bool
    {
        // Symfony 4.4+ uses OutOfMemoryError
        if ($throwable instanceof OutOfMemoryError) {
            return true;
        }

        // Legacy Symfony 2.x/3.x used OutOfMemoryException (check by class name for BC)
        if (str_contains(get_class($throwable), 'OutOfMemory')) {
            return true;
        }

        // Also detect by message pattern in case it's wrapped
        return preg_match(self::OOM_REGEX, $throwable->getMessage()) === 1;
    }

    /**
     * Handle Out of Memory errors by temporarily increasing memory limit.
     *
     * This allows error reporting to complete before the process terminates.
     * The memory increase is intentionally small - just enough to serialize
     * and send the error report.
     *
     * @param \Throwable $throwable       The OOM exception
     * @param int        $memoryIncrease  Additional bytes to allocate (default: 5MB)
     *
     * @return bool True if memory was successfully increased
     */
    public static function handle(\Throwable $throwable, int $memoryIncrease = self::DEFAULT_MEMORY_INCREASE): bool
    {
        if ($memoryIncrease <= 0) {
            return false;
        }

        // Extract current memory limit from error message
        if (preg_match(self::OOM_REGEX, $throwable->getMessage(), $matches)) {
            $currentLimit = (int) $matches[1];
            $newLimit = $currentLimit + $memoryIncrease;

            // Temporarily increase memory limit
            $result = @ini_set('memory_limit', (string) $newLimit);

            return $result !== false;
        }

        return false;
    }

    /**
     * Extract the memory limit from an OOM error message.
     *
     * @param \Throwable $throwable The OOM exception
     *
     * @return int|null The memory limit in bytes, or null if not found
     */
    public static function extractLimit(\Throwable $throwable): ?int
    {
        if (preg_match(self::OOM_REGEX, $throwable->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
