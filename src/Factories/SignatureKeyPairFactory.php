<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Factories;

use Cicnavi\Oidc\Entities\KeyPair;
use Cicnavi\Oidc\Entities\SignatureKeyPair;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfig;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\HashAlgorithmsEnum;
use SimpleSAML\OpenID\Codebooks\PublicKeyUseEnum;
use SimpleSAML\OpenID\Jwk;

class SignatureKeyPairFactory
{
    public function __construct(
        protected readonly Jwk $jwk = new Jwk(),
    ) {
    }

    public function fromConfig(
        SignatureKeyPairConfig $signatureKeyPairConfig,
        HashAlgorithmsEnum $jwkThumbprintHashAlgo = HashAlgorithmsEnum::SHA_256,
    ): SignatureKeyPair {
        $publicKeyJwkDecorator = $this->jwk->jwkDecoratorFactory()->fromPkcs1Or8KeyFile(
            $signatureKeyPairConfig->getKeyPairConfig()->getPublicKeyFilename(),
            additionalData: [
                ClaimsEnum::Use->value => PublicKeyUseEnum::Signature->value,
                ClaimsEnum::Alg->value => $signatureKeyPairConfig->getSignatureAlgorithm()->value,
            ],
        );

        $keyId = $signatureKeyPairConfig->getKeyPairConfig()->getKeyId() ??
        $publicKeyJwkDecorator->jwk()->thumbprint($jwkThumbprintHashAlgo->phpName());

        $publicKeyJwkDecorator->addAdditionalData(ClaimsEnum::Kid->value, $keyId);

        return new SignatureKeyPair(
            $signatureKeyPairConfig->getSignatureAlgorithm(),
            new KeyPair(
                $this->jwk->jwkDecoratorFactory()->fromPkcs1Or8KeyFile(
                    $signatureKeyPairConfig->getKeyPairConfig()->getPrivateKeyFilename(),
                ),
                $publicKeyJwkDecorator,
                $keyId,
                $signatureKeyPairConfig->getKeyPairConfig()->getPrivateKeyPassword(),
            )
        );
    }
}
