<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

class ClaimBag
{
    /**
     * @param mixed[] $claims
     */
    public function __construct(
        protected array $claims = [],
    ) {
    }

    /**
     * @return mixed[]
     */
    public function getAll(): array
    {
        return $this->claims;
    }

    public function get(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->claims[$name]);
    }

    public function set(string $name, mixed $value): void
    {
        $this->claims[$name] = $value;
    }

    /**
     * @param mixed[] $claims
     */
    public function merge(array $claims): void
    {
        $this->claims = array_merge($this->claims, $claims);
    }

    public function remove(string $name): void
    {
        unset($this->claims[$name]);
    }

    public function getAsString(string $name): ?string
    {
        $ret = $this->get($name);

        if (is_string($ret)) {
            return $ret;
        }

        return null;
    }

    /**
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

    public function getAsInt(string $name): ?int
    {
        $ret = $this->get($name);

        if (is_int($ret)) {
            return $ret;
        }

        return null;
    }
}
