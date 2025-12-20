<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

use Cicnavi\Oidc\Entities\ClaimBag;
use Cicnavi\Oidc\Entities\KeyPairConfig;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfigBag;
use Cicnavi\Oidc\Entities\RedirectUriBag;

class RpConfig
{
    /**
     * @param RedirectUriBag $redirectUris Collection of redirect URIs for
     * this Relying Party. At least one redirect URI must be provided.
     * @param KeyPairConfig $defaultSigningKeyPair Default signing key pair
     * for this Relying Party. Used, for example, to sign the Request Object.
     * Will be published in JWKS claim in RP metadata.
     * @param SignatureKeyPairConfigBag $additionalSigningKeyPairs Additional signing
     * key pairs for this Relying Party. Can be used to advertise additional
     * keys, for example, for key-rollover scenarios. Will be published in JWKS
     * claim in RP metadata.
     * @param ClaimBag $additionalClaims Any additional claims to publish in the
     * RP metadata. Make sure to use the correct format for the particular
     * claim, as they will be published in RP metadata as provided.
     */
    public function __construct(
        protected readonly RedirectUriBag $redirectUris,
        protected readonly KeyPairConfig $defaultSigningKeyPair,
        protected readonly SignatureKeyPairConfigBag $additionalSigningKeyPairs = new SignatureKeyPairConfigBag(),
        protected readonly ClaimBag $additionalClaims = new ClaimBag(),
    ) {
    }

    public function getRedirectUris(): RedirectUriBag
    {
        return $this->redirectUris;
    }

    public function getAdditionalClaims(): ClaimBag
    {
        return $this->additionalClaims;
    }

    public function getDefaultSigningKeyPair(): KeyPairConfig
    {
        return $this->defaultSigningKeyPair;
    }

    public function getAdditionalSigningKeyPairs(): SignatureKeyPairConfigBag
    {
        return $this->additionalSigningKeyPairs;
    }
}
