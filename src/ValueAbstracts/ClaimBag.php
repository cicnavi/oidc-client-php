<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

class ClaimBag
{
    /**
     * @param array<non-empty-string,mixed> $claims
     */
    public function __construct(
        protected array $claims = [],
    ) {
    }

    /**
     * @return array<non-empty-string,mixed>
     */
    public function getAll(): array
    {
        return $this->claims;
    }

    /**
     * @param non-empty-string $name
     */
    public function get(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * @param non-empty-string $name
     */
    public function has(string $name): bool
    {
        return isset($this->claims[$name]);
    }

    /**
     * @param non-empty-string $name
     */
    public function set(string $name, mixed $value): void
    {
        $this->claims[$name] = $value;
    }

    /**
     * @param array<non-empty-string,mixed> $claims
     */
    public function merge(array $claims): void
    {
        $this->claims = array_merge($this->claims, $claims);
    }

    /**
     * @param non-empty-string $name
     */
    public function remove(string $name): void
    {
        unset($this->claims[$name]);
    }

    /**
     * @param non-empty-string $name
     */
    public function getAsString(string $name): ?string
    {
        $ret = $this->get($name);

        if (is_string($ret)) {
            return $ret;
        }

        return null;
    }

    /**
     * @param non-empty-string $name
     * @return mixed[]|null
     */
    public function getAsArray(string $name): ?array
    {
        $ret = $this->get($name);

        if (is_array($ret)) {
            return $ret;
        }

        return null;
    }

    /**
     * @param non-empty-string $name
     */
    public function getAsInt(string $name): ?int
    {
        $ret = $this->get($name);

        if (is_int($ret)) {
            return $ret;
        }

        return null;
    }
}
