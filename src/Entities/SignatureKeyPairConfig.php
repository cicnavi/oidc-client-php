<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Entities;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;

class SignatureKeyPairConfig
{
    /**
     * @param SignatureAlgorithmEnum $signatureAlgorithm Signature algorithm
     * to use for signing JWTs.
     * @param KeyPairConfig $keyPairConfig Key pair configuration to use for
     * signing JWTs.
     */
    public function __construct(
        protected readonly SignatureAlgorithmEnum $signatureAlgorithm,
        protected readonly KeyPairConfig $keyPairConfig,
    ) {
    }

    public function getSignatureAlgorithm(): SignatureAlgorithmEnum
    {
        return $this->signatureAlgorithm;
    }

    public function getKeyPairConfig(): KeyPairConfig
    {
        return $this->keyPairConfig;
    }
}
