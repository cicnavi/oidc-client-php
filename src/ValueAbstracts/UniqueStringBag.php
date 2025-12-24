<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

class UniqueStringBag implements \JsonSerializable
{
    /**
     * @var string[]
     */
    protected array $values = [];

    public function __construct(
        string ...$values
    ) {
        $this->values = $values;
    }

    /**
     * @return string[]
     */
    public function getAll(): array
    {
        return $this->values;
    }

    public function has(string $value): bool
    {
        return in_array($value, $this->values, true);
    }

    public function add(string ...$values): void
    {
        foreach ($values as $value) {
            if (!in_array($value, $this->values, true)) {
                $this->values[] = $value;
            }
        }
    }

    /**
     * @return string[]
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
