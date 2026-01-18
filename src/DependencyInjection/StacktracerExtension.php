<?php

namespace Stacktracer\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class StacktracerExtension extends Extension
{
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
        $container->setParameter('stacktracer.exception_context_lines', $config['capture']['exception_context_lines']);
        $container->setParameter('stacktracer.stacktrace_context_lines', $config['capture']['stacktrace_context_lines']);

        // Performance settings
        $container->setParameter('stacktracer.sample_rate', $config['performance']['sample_rate']);
        $container->setParameter('stacktracer.max_stack_frames', $config['performance']['max_stack_frames']);
        $container->setParameter('stacktracer.capture_code_context', $config['performance']['capture_code_context']);
        $container->setParameter('stacktracer.filter_vendor_frames', $config['performance']['filter_vendor_frames']);
        $container->setParameter('stacktracer.capture_request_headers', $config['performance']['capture_request_headers']);
        $container->setParameter('stacktracer.sensitive_keys', $config['performance']['sensitive_keys']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'stacktracer';
    }
}
