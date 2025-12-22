<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Jwk\JwkDecorator;

class SignatureKeyPair
{
    public function __construct(
        protected readonly SignatureAlgorithmEnum $signatureAlgorithm,
        protected readonly KeyPair $keyPair,
    ) {
    }

    public function getSignatureAlgorithm(): SignatureAlgorithmEnum
    {
        return $this->signatureAlgorithm;
    }

    public function getKeyPair(): KeyPair
    {
        return $this->keyPair;
    }
}
