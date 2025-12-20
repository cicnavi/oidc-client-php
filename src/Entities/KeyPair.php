<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Jwk\JwkDecorator;

class KeyPair
{
    public function __construct(
        protected JwkDecorator $privateKey,
        protected JwkDecorator $publicKey,
        protected readonly string $keyId,
        protected readonly ?string $privateKeyPassword = null,
    ) {
    }

    public function getPrivateKey(): JwkDecorator
    {
        return $this->privateKey;
    }

    public function getPublicKey(): JwkDecorator
    {
        return $this->publicKey;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function getPrivateKeyPassword(): ?string
    {
        return $this->privateKeyPassword;
    }
}
