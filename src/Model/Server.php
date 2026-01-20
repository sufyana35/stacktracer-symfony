<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * Server/runtime environment information.
 *
 * Captures details about the server, operating system, runtime, and framework
 * to help identify environment-specific issues.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class Server implements \JsonSerializable
{
    /**
     * Cached auto-detected server instance (computed once per process).
     */
    private static ?self $cached = null;

    /**
     * @param string|null $name Server hostname
     * @param string|null $os Operating system: linux, darwin, windows
     * @param string|null $osVersion OS version
     * @param string|null $arch Architecture: amd64, arm64, 386
     * @param string|null $runtime Runtime: php, node, go, python, ruby, java, dotnet
     * @param string|null $runtimeVersion Runtime version
     * @param string|null $framework Framework: symfony, laravel, rails, express, gin
     * @param string|null $frameworkVersion Framework version
     */
    public function __construct(
        private ?string $name = null,
        private ?string $os = null,
        private ?string $osVersion = null,
        private ?string $arch = null,
        private ?string $runtime = null,
        private ?string $runtimeVersion = null,
        private ?string $framework = null,
        private ?string $frameworkVersion = null
    ) {
    }

    /**
     * Create with auto-detected PHP/Symfony values.
     *
     * Results are cached per process since server info never changes.
     * If frameworkVersion differs from cached version, a new instance is created.
     *
     * @param string|null $frameworkVersion Symfony version
     */
    public static function autoDetect(?string $frameworkVersion = null): self
    {
        // Return cached instance if available and version matches
        if (self::$cached !== null && self::$cached->frameworkVersion === $frameworkVersion) {
            return self::$cached;
        }

        self::$cached = new self(
            name: gethostname() ?: null,
            os: PHP_OS_FAMILY === 'Windows' ? 'windows' : (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux'),
            osVersion: php_uname('r'),
            arch: php_uname('m') === 'x86_64' ? 'amd64' : (str_contains(php_uname('m'), 'arm') ? 'arm64' : php_uname('m')),
            runtime: 'php',
            runtimeVersion: PHP_VERSION,
            framework: 'symfony',
            frameworkVersion: $frameworkVersion
        );

        return self::$cached;
    }

    /**
     * Clear the cached instance (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$cached = null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getOs(): ?string
    {
        return $this->os;
    }

    public function setOs(?string $os): self
    {
        $this->os = $os;

        return $this;
    }

    public function getOsVersion(): ?string
    {
        return $this->osVersion;
    }

    public function setOsVersion(?string $osVersion): self
    {
        $this->osVersion = $osVersion;

        return $this;
    }

    public function getArch(): ?string
    {
        return $this->arch;
    }

    public function setArch(?string $arch): self
    {
        $this->arch = $arch;

        return $this;
    }

    public function getRuntime(): ?string
    {
        return $this->runtime;
    }

    public function setRuntime(?string $runtime): self
    {
        $this->runtime = $runtime;

        return $this;
    }

    public function getRuntimeVersion(): ?string
    {
        return $this->runtimeVersion;
    }

    public function setRuntimeVersion(?string $runtimeVersion): self
    {
        $this->runtimeVersion = $runtimeVersion;

        return $this;
    }

    public function getFramework(): ?string
    {
        return $this->framework;
    }

    public function setFramework(?string $framework): self
    {
        $this->framework = $framework;

        return $this;
    }

    public function getFrameworkVersion(): ?string
    {
        return $this->frameworkVersion;
    }

    public function setFrameworkVersion(?string $frameworkVersion): self
    {
        $this->frameworkVersion = $frameworkVersion;

        return $this;
    }

    /**
     * Serialize server info to JSON format.
     *
     * @return array<string, string> Serialized server data
     */
    public function jsonSerialize(): array
    {
        $result = [];

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }
        if ($this->os !== null) {
            $result['os'] = $this->os;
        }
        if ($this->osVersion !== null) {
            $result['os_ver'] = $this->osVersion;
        }
        if ($this->arch !== null) {
            $result['arch'] = $this->arch;
        }
        if ($this->runtime !== null) {
            $result['runtime'] = $this->runtime;
        }
        if ($this->runtimeVersion !== null) {
            $result['runtime_ver'] = $this->runtimeVersion;
        }
        if ($this->framework !== null) {
            $result['framework'] = $this->framework;
        }
        if ($this->frameworkVersion !== null) {
            $result['framework_ver'] = $this->frameworkVersion;
        }

        return $result;
    }
}
