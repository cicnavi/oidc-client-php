<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;

class KeyPairFilenameConfig implements KeyPairConfigInterface
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
     * @inheritDoc
     */
    public function getPrivateKeyPassword(): ?string
    {
        return $this->privateKeyPassword;
    }


    /**
     * @inheritDoc
     */
    public function getKeyId(): ?string
    {
        return $this->keyId;
    }

    /**
     * @inheritDoc
     */
    public function getPrivateKeyString(): string
    {
        return $this->getFileContent($this->privateKeyFilename);
    }

    /**
     * @inheritDoc
     */
    public function getPublicKeyString(): string
    {
        return $this->getFileContent($this->publicKeyFilename);
    }

    /**
     * @param non-empty-string $filename
     * @return non-empty-string
     */
    protected function getFileContent(string $filename): string
    {
        if (!file_exists($filename)) {
            throw new \RuntimeException(sprintf('File %s does not exist.', $filename));
        }

        $fileContents = file_get_contents($filename);

        if ($fileContents === false) {
            throw new \RuntimeException(sprintf('Could not read file %s.', $filename));
        }

        if ($fileContents === '') {
            throw new \RuntimeException(sprintf('File %s is empty.', $filename));
        }

        return $fileContents;
    }
}
