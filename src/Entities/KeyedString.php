<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

class KeyedString implements \JsonSerializable
{
    public function __construct(
        protected string $key,
        protected string $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return array<string,string>
     */
    public function jsonSerialize(): array
    {
        return [$this->key => $this->value];
    }
}
