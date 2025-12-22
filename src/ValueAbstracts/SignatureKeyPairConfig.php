<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;

class SignatureKeyPairConfig
{
    /**
     * @param SignatureAlgorithmEnum $signatureAlgorithm Signature algorithm
     * to use for signing JWTs.
     * @param KeyPairFilenameConfig $keyPairConfig Key pair configuration to use for
     * signing JWTs.
     */
    public function __construct(
        protected readonly SignatureAlgorithmEnum $signatureAlgorithm,
        protected readonly KeyPairConfigInterface $keyPairConfig,
    ) {
    }

    public function getSignatureAlgorithm(): SignatureAlgorithmEnum
    {
        return $this->signatureAlgorithm;
    }

    public function getKeyPairConfig(): KeyPairFilenameConfig
    {
        return $this->keyPairConfig;
    }
}
