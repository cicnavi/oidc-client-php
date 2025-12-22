<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

interface KeyPairConfigInterface
{
    /**
     * @return non-empty-string
     */
    public function getPrivateKeyString(): string;

    /**
     * @return non-empty-string
     */
    public function getPublicKeyString(): string;

    /**
     * @return non-empty-string|null
     */
    public function getPrivateKeyPassword(): ?string;

    /**
     * @return non-empty-string|null
     */
    public function getKeyId(): ?string;
}
