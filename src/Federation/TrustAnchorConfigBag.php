<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

class TrustAnchorConfigBag
{
    /**
     * @var array<string,TrustAnchorConfig>
     */
    protected array $trustAnchorConfigs;

    public function __construct(
        TrustAnchorConfig ...$trustAnchorConfigs
    ) {
        $this->add(...$trustAnchorConfigs);
    }

    public function add(TrustAnchorConfig ...$trustAnchorConfigs): void
    {
        foreach ($trustAnchorConfigs as $trustAnchorConfig) {
            $this->trustAnchorConfigs[$trustAnchorConfig->getEntityId()] = $trustAnchorConfig;
        }
    }

    public function getByEntityId(string $entityId): ?TrustAnchorConfig
    {
        return $this->trustAnchorConfigs[$entityId] ?? null;
    }

    /**
     * @return array<string,TrustAnchorConfig>
     */
    public function getAll(): array
    {
        return $this->trustAnchorConfigs;
    }
}
