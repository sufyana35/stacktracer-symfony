<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * Represents a feature flag or experiment that was active when an error occurred.
 *
 * Used to correlate errors with feature rollouts and A/B tests. This helps identify
 * whether specific features or experiment variants are causing issues.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class FeatureFlag implements \JsonSerializable
{
    /**
     * @param string $name Feature flag or experiment name
     * @param string|null $variant Optional variant value (e.g., 'Blue', 'control', 'v2')
     */
    public function __construct(
        private string $name,
        private ?string $variant = null
    ) {
        $this->name = $name;
        $this->variant = $variant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the variant value if present.
     *
     * @return string|null Variant value or null
     */
    public function getVariant(): ?string
    {
        return $this->variant;
    }

    /**
     * Set or update the variant value.
     *
     * @param string|null $variant Variant value or null to clear
     */
    public function setVariant(?string $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    /**
     * Serialize feature flag to JSON format.
     *
     * @return array<string, string> Serialized feature flag data
     */
    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];

        if ($this->variant !== null) {
            $data['var'] = $this->variant;
        }

        return $data;
    }
}
