<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;

class KeyPairConfig
{
    /**
     * @param non-empty-string $privateKeyFilename Path to the PEM file containing the
     * private key used to sign the JWTs.
     * @param non-empty-string $publicKeyFilename Path to the PEM file containing the
     * public key used to verify the JWTs.
     * @param non-empty-string|null $privateKeyPassword Password for the private key, if
     * any.
     * @param non-empty-string|null $keyId Key ID to use for the key pair. If not
     * provided, a public key thumbprint will be used.
     */
    public function __construct(
        protected readonly string $privateKeyFilename,
        protected readonly string $publicKeyFilename,
        protected readonly ?string $privateKeyPassword = null,
        protected readonly ?string $keyId = null,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getPrivateKeyFilename(): string
    {
        return $this->privateKeyFilename;
    }

    /**
     * @return non-empty-string
     */
    public function getPublicKeyFilename(): string
    {
        return $this->publicKeyFilename;
    }

    /**
     * @return non-empty-string|null
     */
    public function getPrivateKeyPassword(): ?string
    {
        return $this->privateKeyPassword;
    }

    /**
     * @return non-empty-string|null
     */
    public function getKeyId(): ?string
    {
        return $this->keyId;
    }
}
