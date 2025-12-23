<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

class KeyedString implements \JsonSerializable
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string $value
     */
    public function __construct(
        protected string $key,
        protected string $value,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return non-empty-string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return array<non-empty-string,non-empty-string>
     */
    public function jsonSerialize(): array
    {
        return [$this->key => $this->value];
    }
}
