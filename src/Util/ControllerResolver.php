<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Util;

/**
 * Utility for resolving controller names from Symfony controller callables.
 *
 * Handles various controller formats:
 * - Array: [ControllerClass, 'methodName']
 * - Invokable: ControllerClass (with __invoke)
 * - String: 'Controller::method'
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class ControllerResolver
{
    /**
     * Extract a short controller name for display/tracing.
     *
     * Returns format: "ShortClassName::methodName"
     *
     * @param mixed $controller The controller callable
     *
     * @return string|null The controller name, or null if unresolvable
     *
     * @example
     * ```php
     * // [App\Controller\UserController, 'show'] -> "UserController::show"
     * // App\Controller\HomeController (invokable) -> "HomeController::__invoke"
     * // "App\Controller\Api::list" -> "Api::list"
     * ```
     */
    public static function getName(mixed $controller): ?string
    {
        if (is_array($controller) && isset($controller[0], $controller[1])) {
            // [ControllerClass, 'methodName']
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            $method = $controller[1];

            $shortClass = self::getShortClassName($class);

            return sprintf('%s::%s', $shortClass, $method);
        }

        if (is_object($controller) && method_exists($controller, '__invoke')) {
            $class = get_class($controller);
            $shortClass = self::getShortClassName($class);

            return sprintf('%s::__invoke', $shortClass);
        }

        if (is_string($controller)) {
            // 'Controller::method' format
            if (str_contains($controller, '::')) {
                $parts = explode('::', $controller);
                $shortClass = self::getShortClassName($parts[0]);

                return sprintf('%s::%s', $shortClass, $parts[1]);
            }

            return $controller;
        }

        return null;
    }

    /**
     * Get the full namespace of a controller.
     *
     * @param mixed $controller The controller callable
     *
     * @return string|null The namespace, or null if unresolvable
     */
    public static function getNamespace(mixed $controller): ?string
    {
        $class = self::getFullClassName($controller);

        if ($class === null) {
            return null;
        }

        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, 0, $pos) : null;
    }

    /**
     * Get the full class name from a controller callable.
     *
     * @param mixed $controller The controller callable
     *
     * @return string|null The full class name, or null if unresolvable
     */
    public static function getFullClassName(mixed $controller): ?string
    {
        if (is_array($controller) && isset($controller[0])) {
            return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        }

        if (is_object($controller)) {
            return get_class($controller);
        }

        if (is_string($controller) && str_contains($controller, '::')) {
            return explode('::', $controller)[0];
        }

        return null;
    }

    /**
     * Get the method name from a controller callable.
     *
     * @param mixed $controller The controller callable
     *
     * @return string|null The method name, or null if unresolvable
     */
    public static function getMethod(mixed $controller): ?string
    {
        if (is_array($controller) && isset($controller[1])) {
            return $controller[1];
        }

        if (is_object($controller) && method_exists($controller, '__invoke')) {
            return '__invoke';
        }

        if (is_string($controller) && str_contains($controller, '::')) {
            return explode('::', $controller)[1];
        }

        return null;
    }

    /**
     * Extract the short class name (without namespace).
     *
     * @param string $class The full class name
     *
     * @return string The short class name
     */
    private static function getShortClassName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }
}
