<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Factories;

use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPair;
use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPairBag;
use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPairConfig;
use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPairConfigBag;

class SignatureKeyPairBagFactory
{
    public function __construct(
        protected readonly SignatureKeyPairFactory $signatureKeyPairFactory,
    ) {
    }

    public function fromConfig(SignatureKeyPairConfigBag $signatureKeyPairConfigBag): SignatureKeyPairBag
    {
        return new SignatureKeyPairBag(
            ...array_map(
                fn(
                    SignatureKeyPairConfig $signatureKeyPairConfig,
                ): SignatureKeyPair => $this->signatureKeyPairFactory->fromConfig($signatureKeyPairConfig),
                $signatureKeyPairConfigBag->getAll(),
            ),
        );
    }
}
