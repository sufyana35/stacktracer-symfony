<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Stacktracer Extension - Loads and manages bundle configuration.
 *
 * Processes configuration from stacktracer.yaml and registers services
 * with the dependency injection container.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class StacktracerExtension extends Extension
{
    /**
     * Loads bundle configuration and registers services.
     *
     * @param array<int, array<string, mixed>> $configs Configuration values from stacktracer.yaml
     * @param ContainerBuilder $container Service container
     *
     * @throws \Exception When configuration is invalid or services cannot be loaded
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Core settings
        $container->setParameter('stacktracer.enabled', $config['enabled']);
        $container->setParameter('stacktracer.exclude_patterns', $config['exclude_patterns']);

        // Transport settings
        $container->setParameter('stacktracer.transport.endpoint', $config['transport']['endpoint']);
        $container->setParameter('stacktracer.transport.api_key', $config['transport']['api_key']);
        $container->setParameter('stacktracer.transport.batch_size', $config['transport']['batch_size']);
        $container->setParameter('stacktracer.transport.flush_interval_ms', $config['transport']['flush_interval_ms']);
        $container->setParameter('stacktracer.transport.max_queue_size', $config['transport']['max_queue_size']);
        $container->setParameter('stacktracer.transport.timeout', $config['transport']['timeout']);
        $container->setParameter('stacktracer.transport.compress', $config['transport']['compress']);
        $container->setParameter('stacktracer.transport.max_retries', $config['transport']['max_retries']);

        // Capture settings
        $container->setParameter('stacktracer.capture_request', $config['capture']['request']);
        $container->setParameter('stacktracer.capture_exception', $config['capture']['exception']);
        $container->setParameter('stacktracer.capture_spans', $config['capture']['spans']);
        $container->setParameter('stacktracer.exception_context_lines', $config['capture']['exception_context_lines']);
        $container->setParameter('stacktracer.stacktrace_context_lines', $config['capture']['stacktrace_context_lines']);

        // Performance settings
        $container->setParameter('stacktracer.sample_rate', $config['performance']['sample_rate']);
        $container->setParameter('stacktracer.max_stack_frames', $config['performance']['max_stack_frames']);
        $container->setParameter('stacktracer.capture_code_context', $config['performance']['capture_code_context']);
        $container->setParameter('stacktracer.filter_vendor_frames', $config['performance']['filter_vendor_frames']);
        $container->setParameter('stacktracer.capture_request_headers', $config['performance']['capture_request_headers']);
        $container->setParameter('stacktracer.sensitive_keys', $config['performance']['sensitive_keys']);

        // Service identification (OTEL)
        $serviceName = $config['service']['name'] ?? 'unknown';
        $serviceVersion = $config['service']['version'] ?? '0.0.0';
        $container->setParameter('stacktracer.service_name', $serviceName ?: 'unknown');
        $container->setParameter('stacktracer.service_version', $serviceVersion ?: '0.0.0');

        // Logging settings
        $container->setParameter('stacktracer.logging.enabled', $config['logging']['enabled']);
        $container->setParameter('stacktracer.logging.level', $config['logging']['level']);
        $container->setParameter('stacktracer.logging.capture_context', $config['logging']['capture_context']);
        $container->setParameter('stacktracer.logging.exclude_channels', $config['logging']['exclude_channels']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'stacktracer';
    }
}
