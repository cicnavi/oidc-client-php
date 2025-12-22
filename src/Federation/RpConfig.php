<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

use Cicnavi\Oidc\ValueAbstracts\ClaimBag;
use Cicnavi\Oidc\ValueAbstracts\ScopeBag;
use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPairConfig;
use Cicnavi\Oidc\ValueAbstracts\SignatureKeyPairConfigBag;
use Cicnavi\Oidc\ValueAbstracts\RedirectUriBag;

class RpConfig
{
    /**
     * @param RedirectUriBag $redirectUriBag Collection of redirect URIs for
     * this Relying Party. At least one redirect URI must be provided.
     * @param SignatureKeyPairConfig $defaultSignatureKeyPairConfig Default signing key pair
     * for this Relying Party. Used, for example, to sign the Request Object.
     * Will be published in JWKS claim in RP metadata.
     * @param SignatureKeyPairConfigBag $additionalSignatureKeyPairBag Additional signing
     * key pairs for this Relying Party. Can be used to advertise additional
     * keys, for example, for key-rollover scenarios. Will be published in JWKS
     * claim in RP metadata.
     * @param ClaimBag $additionalClaimBag Any additional claims to publish in the
     * RP metadata. Make sure to use the correct format for the particular
     * claim, as they will be published in RP metadata as provided.
     */
    public function __construct(
        protected readonly RedirectUriBag $redirectUriBag,
        protected readonly SignatureKeyPairConfig $defaultSignatureKeyPairConfig,
        protected readonly SignatureKeyPairConfigBag $additionalSignatureKeyPairBag = new SignatureKeyPairConfigBag(),
        protected readonly ScopeBag $scopeBag = new ScopeBag(),
        protected readonly ClaimBag $additionalClaimBag = new ClaimBag(),
    ) {
    }

    public function getRedirectUriBag(): RedirectUriBag
    {
        return $this->redirectUriBag;
    }

    public function getAdditionalClaimBag(): ClaimBag
    {
        return $this->additionalClaimBag;
    }

    public function getDefaultSignatureKeyPairConfig(): SignatureKeyPairConfig
    {
        return $this->defaultSignatureKeyPairConfig;
    }

    public function getAdditionalSignatureKeyPairBag(): SignatureKeyPairConfigBag
    {
        return $this->additionalSignatureKeyPairBag;
    }
}
