<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

class KeyedStringBag implements \JsonSerializable
{
    /**
     * @var array<string,KeyedString>
     */
    protected array $keyedStrings;

    public function __construct(
        KeyedString ...$keyedStrings
    ) {
        $this->add(...$keyedStrings);
    }

    public function add(KeyedString ...$keyedStrings): void
    {
        foreach ($keyedStrings as $keyedString) {
            $this->keyedStrings[$keyedString->getKey()] = $keyedString;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->keyedStrings[$key]);
    }

    public function get(string $key): ?KeyedString
    {
        return $this->keyedStrings[$key] ?? null;
    }

    /**
     * @return array<string,KeyedString>
     */
    public function getAll(): array
    {
        return $this->keyedStrings;
    }

    /**
     * @return array<string,string>
     */
    public function jsonSerialize(): array
    {
        $arr = [];
        foreach ($this->keyedStrings as $keyedString) {
            $arr[$keyedString->getKey()] = $keyedString->getValue();
        }

        return $arr;
    }
}
