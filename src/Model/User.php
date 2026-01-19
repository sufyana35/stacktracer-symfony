<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Model;

/**
 * User context for understanding who was affected by an error.
 *
 * Captures user information to help identify patterns and prioritize fixes
 * based on affected users. At least one of id, email, or username should be provided.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class User implements \JsonSerializable
{
    /**
     * @param string|null $id Unique user identifier
     * @param string|null $email Email address (can be hashed for privacy)
     * @param string|null $username Username or handle
     * @param string|null $name Display name
     * @param string|null $ip User's IP address
     * @param array<string, mixed>|null $data Arbitrary user data (subscription, org, etc.)
     */
    public function __construct(
        private ?string $id = null,
        private ?string $email = null,
        private ?string $username = null,
        private ?string $name = null,
        private ?string $ip = null,
        private ?array $data = null
    ) {
    }

    /**
     * Create from an array of user data.
     *
     * @param array<string, mixed> $data User data array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            email: $data['email'] ?? null,
            username: $data['username'] ?? null,
            name: $data['name'] ?? null,
            ip: $data['ip'] ?? null,
            data: $data['data'] ?? null
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function setData(?array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Add a single data field.
     */
    public function addData(string $key, mixed $value): self
    {
        if ($this->data === null) {
            $this->data = [];
        }
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Check if user has any identifying information.
     */
    public function hasIdentity(): bool
    {
        return $this->id !== null || $this->email !== null || $this->username !== null;
    }

    /**
     * Serialize user to JSON format.
     *
     * @return array<string, mixed> Serialized user data
     */
    public function jsonSerialize(): array
    {
        $result = [];

        if ($this->id !== null) {
            $result['id'] = $this->id;
        }
        if ($this->email !== null) {
            $result['email'] = $this->email;
        }
        if ($this->username !== null) {
            $result['username'] = $this->username;
        }
        if ($this->name !== null) {
            $result['name'] = $this->name;
        }
        if ($this->ip !== null) {
            $result['ip'] = $this->ip;
        }
        if (!empty($this->data)) {
            $result['data'] = $this->data;
        }

        return $result;
    }
}
