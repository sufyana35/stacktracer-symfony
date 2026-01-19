<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * Represents a feature flag or experiment that was active when an error occurred.
 * Used to correlate errors with feature rollouts and A/B tests.
 */
class FeatureFlag implements \JsonSerializable
{
    private string $name;
    private ?string $variant;

    public function __construct(string $name, ?string $variant = null)
    {
        $this->name = $name;
        $this->variant = $variant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function setVariant(?string $variant): self
    {
        $this->variant = $variant;
        return $this;
    }

    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];
        
        if ($this->variant !== null) {
            $data['var'] = $this->variant;
        }
        
        return $data;
    }
}
