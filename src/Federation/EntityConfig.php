<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

use Cicnavi\Oidc\Entities\ClaimBag;
use Cicnavi\Oidc\Entities\KeyedStringBag;
use Cicnavi\Oidc\Entities\KeyPairConfig;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfig;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfigBag;
use Cicnavi\Oidc\Entities\UniqueStringBag;

class EntityConfig
{
    /**
     * @param string $entityId ID of this federation entity. Will be published
     * in the Issuer and Subject claims in the Entity Configuration statement.
     * @param TrustAnchorConfigBag $trustAnchors Trust Anchors which are valid
     * for this federation entity. This is a collection (bag) of key-value
     * pairs. The key represents the Trust Anchor Entity ID, while the
     * value can be the Trust Anchor's JWKS JSON object string value, or null.
     * @param UniqueStringBag $authorityHints Authority Hints which are valid
     * for this entity. Authority Hints are Entity Identifiers of Intermediate
     * Entities (or Trust Anchors), that is - the superiors of this entity.
     * @param RpConfig $rpConfig Configuration related to the Relying Party
     * (RP) role of this federation entity.
     * @param SignatureKeyPairConfig $defaultSignatureKeyPair Default signing
     * key pair for the federation entity. Used, for example, to sign an Entity
     * Configuration statement. Will be published in JWKS claim in the Entity
     * Configuration statement.
     * @param SignatureKeyPairConfigBag $additionalSignatureKeyPairs Additional
     * signing key pairs for the federation entity. Can be used to advertise
     * additional keys, for example, for key-rollover scenarios. Will be
     * published in JWKS claim in the Entity Configuration statement.
     * @param UniqueStringBag $staticTrustMarks Trust Mark token strings
     * (signed JWTs), each representing a Trust Mark issued for this entity.
     * This option is intended for long-lasting or non-expiring tokens, so it
     * is not necessary to dynamically fetch / refresh them.
     * @param KeyedStringBag $dynamicTrustMarks Key-value pairs representing
     * Trust Marks for dynamic fetching, each representing a Trust Mark issued
     * to this entity. The key represents the Trust Mark Type, while the value
     * is the Trust Mark Issuer ID. Each Trust Mark Type in this collection will
     * be dynamically fetched from the noted Trust Mark Issuer as necessary.
     * Fetched Trust Marks will also be cached until their expiry.
     * @param ClaimBag $additionalClaims Any additional claims to publish in the
     * Entity Configuration statement. Make sure to use the correct format for
     * the particular claim, as they will be published in the Entity
     * Configuration statement as provided.
     */
    public function __construct(
        protected readonly string $entityId,
        protected readonly TrustAnchorConfigBag $trustAnchors,
        protected readonly UniqueStringBag $authorityHints,
        protected readonly RpConfig $rpConfig,
        protected readonly SignatureKeyPairConfig $defaultSignatureKeyPair,
        protected readonly SignatureKeyPairConfigBag $additionalSignatureKeyPairs = new SignatureKeyPairConfigBag(),
        protected readonly UniqueStringBag $staticTrustMarks = new UniqueStringBag(),
        protected readonly KeyedStringBag $dynamicTrustMarks = new KeyedStringBag(),
        protected readonly ClaimBag $additionalClaims = new ClaimBag(),
    ) {
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getDefaultSignatureKeyPair(): SignatureKeyPairConfig
    {
        return $this->defaultSignatureKeyPair;
    }

    public function getAdditionalSignatureKeyPairs(): SignatureKeyPairConfigBag
    {
        return $this->additionalSignatureKeyPairs;
    }

    public function getAdditionalClaims(): ClaimBag
    {
        return $this->additionalClaims;
    }

    public function getRpConfig(): RpConfig
    {
        return $this->rpConfig;
    }
}
