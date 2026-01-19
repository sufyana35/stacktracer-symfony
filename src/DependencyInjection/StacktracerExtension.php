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

        // Integration settings
        $container->setParameter('stacktracer.integrations.doctrine.enabled', $config['integrations']['doctrine']['enabled']);
        $container->setParameter('stacktracer.integrations.doctrine.slow_query_threshold', $config['integrations']['doctrine']['slow_query_threshold']);
        $container->setParameter('stacktracer.integrations.http_client.enabled', $config['integrations']['http_client']['enabled']);
        $container->setParameter('stacktracer.integrations.http_client.propagate_context', $config['integrations']['http_client']['propagate_context']);
        $container->setParameter('stacktracer.integrations.messenger.enabled', $config['integrations']['messenger']['enabled']);
        $container->setParameter('stacktracer.integrations.cache.enabled', $config['integrations']['cache']['enabled']);
        $container->setParameter('stacktracer.integrations.console.enabled', $config['integrations']['console']['enabled']);
        $container->setParameter('stacktracer.integrations.form.enabled', $config['integrations']['form']['enabled']);
        $container->setParameter('stacktracer.integrations.security.enabled', $config['integrations']['security']['enabled']);
        $container->setParameter('stacktracer.integrations.mailer.enabled', $config['integrations']['mailer']['enabled']);
        $container->setParameter('stacktracer.integrations.twig.enabled', $config['integrations']['twig']['enabled']);
        $container->setParameter('stacktracer.integrations.twig.slow_threshold', $config['integrations']['twig']['slow_threshold']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Conditionally remove integration services based on configuration
        $this->configureIntegrations($container, $config);
    }

    /**
     * Configures integration services based on availability and configuration.
     *
     * @param ContainerBuilder $container Service container
     * @param array<string, mixed> $config Processed configuration
     */
    private function configureIntegrations(ContainerBuilder $container, array $config): void
    {
        $integrationNs = 'Stacktracer\\SymfonyBundle\\Integration\\Symfony\\';

        // Remove Messenger subscriber if disabled or Messenger not available
        if (!$config['integrations']['messenger']['enabled'] || !class_exists('Symfony\\Component\\Messenger\\MessageBusInterface')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'MessengerTracingSubscriber');
        }

        // Remove Console subscriber if disabled
        if (!$config['integrations']['console']['enabled']) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'ConsoleTracingSubscriber');
        }

        // Remove HTTP Client decorator if disabled or HttpClient not available
        if (!$config['integrations']['http_client']['enabled'] || !interface_exists('Symfony\\Contracts\\HttpClient\\HttpClientInterface')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'TracingHttpClient');
        }

        // Remove Doctrine middleware if disabled or Doctrine not available
        if (!$config['integrations']['doctrine']['enabled'] || !interface_exists('Doctrine\\DBAL\\Driver\\Middleware')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'DoctrineTracingMiddleware');
        }

        // Remove Form subscriber if disabled or Form component not available
        if (!$config['integrations']['form']['enabled'] || !class_exists('Symfony\\Component\\Form\\FormEvents')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'FormTracingSubscriber');
        }

        // Remove Security subscriber if disabled or Security component not available
        if (!$config['integrations']['security']['enabled'] || !class_exists('Symfony\\Component\\Security\\Http\\Event\\LoginFailureEvent')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'SecurityTracingSubscriber');
        }

        // Remove Mailer subscriber if disabled or Mailer component not available
        if (!$config['integrations']['mailer']['enabled'] || !class_exists('Symfony\\Component\\Mailer\\Event\\MessageEvent')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'MailerTracingSubscriber');
        }

        // Remove Twig subscribers if disabled or Twig not available
        if (!$config['integrations']['twig']['enabled'] || !class_exists('Twig\\Environment')) {
            $this->removeDefinitionIfExists($container, $integrationNs . 'TwigTracingSubscriber');
            $this->removeDefinitionIfExists($container, $integrationNs . 'TwigTracingExtension');
        }
    }

    private function removeDefinitionIfExists(ContainerBuilder $container, string $id): void
    {
        if ($container->hasDefinition($id)) {
            $container->removeDefinition($id);
        }
    }

    public function getAlias(): string
    {
        return 'stacktracer';
    }
}
