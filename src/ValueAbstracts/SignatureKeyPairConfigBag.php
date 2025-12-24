<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

class SignatureKeyPairConfigBag
{
    /**
     * @var SignatureKeyPairConfig[]
     */
    protected array $signatureKeyPairConfigs = [];

    public function __construct(
        SignatureKeyPairConfig ...$signatureKeyPairConfigs,
    ) {
        $this->add(...$signatureKeyPairConfigs);
    }

    public function add(SignatureKeyPairConfig ...$signatureKeyPairConfigs): void
    {
        foreach ($signatureKeyPairConfigs as $signatureKeyPairConfig) {
            $keyId = $signatureKeyPairConfig->getKeyPairConfig()->getKeyId();
            if ($keyId === null) {
                $this->signatureKeyPairConfigs[] = $signatureKeyPairConfig;
            } else {
                $this->signatureKeyPairConfigs[$keyId] = $signatureKeyPairConfig;
            }
        }
    }

    public function getByKeyId(string $keyId): ?SignatureKeyPairConfig
    {
        return $this->signatureKeyPairConfigs[$keyId] ?? null;
    }

    /**
     * @return SignatureKeyPairConfig[]
     */
    public function getAll(): array
    {
        return $this->signatureKeyPairConfigs;
    }
}
