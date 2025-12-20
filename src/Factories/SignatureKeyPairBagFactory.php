<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Factories;

use Cicnavi\Oidc\Entities\SignatureKeyPair;
use Cicnavi\Oidc\Entities\SignatureKeyPairBag;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfig;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfigBag;

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
