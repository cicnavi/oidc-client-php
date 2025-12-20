<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

class ScopeBag extends UniqueStringBag implements \Stringable
{
    /**
     * @param non-empty-string $value
     * @param non-empty-string ...$values
     */
    public function __construct(string $value = 'openid', string ...$values)
    {
        parent::__construct($value, ...$values);
    }

    public function toString(): string
    {
        return implode(' ', $this->values);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
