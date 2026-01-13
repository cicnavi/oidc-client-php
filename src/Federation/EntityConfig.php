<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

use SimpleSAML\OpenID\ValueAbstracts\ClaimBag;
use SimpleSAML\OpenID\ValueAbstracts\KeyedStringBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag;

class EntityConfig
{
    /**
     * @param string $entityId ID of this federation entity. Will be published
     * in the Issuer and Subject claims in the Entity Configuration statement.
     * @param TrustAnchorConfigBag $trustAnchorBag Trust Anchors which are valid
     * for this federation entity. This is a collection (bag) of key-value
     * pairs. The key represents the Trust Anchor Entity ID, while the
     * value can be the Trust Anchor's JWKS JSON object string value, or null.
     * @param UniqueStringBag $authorityHintBag Authority Hints which are valid
     * for this entity. Authority Hints are Entity Identifiers of Intermediate
     * Entities (or Trust Anchors), that is - the superiors of this entity.
     * @param SignatureKeyPairConfigBag $federationSignatureKeyPairConfigBag
     * Additional signing key pairs for the federation entity. Can be used to
     * advertise additional keys, for example, for key-rollover scenarios. Will
     * be published in JWKS claim in the Entity Configuration statement.
     * @param UniqueStringBag $staticTrustMarkBag Trust Mark token strings
     * (signed JWTs), each representing a Trust Mark issued for this entity.
     * This option is intended for long-lasting or non-expiring tokens, so it
     * is not necessary to dynamically fetch / refresh them.
     * @param KeyedStringBag $dynamicTrustMarkBag Key-value pairs representing
     * Trust Marks for dynamic fetching, each representing a Trust Mark issued
     * to this entity. The key represents the Trust Mark Type, while the value
     * is the Trust Mark Issuer ID. Each Trust Mark Type in this collection will
     * be dynamically fetched from the noted Trust Mark Issuer as necessary.
     * Fetched Trust Marks will also be cached until their expiry.
     * @param ClaimBag $additionalClaimBag Any additional claims to publish in
     * the Entity Configuration statement. Make sure to use the correct format
     * for the particular claim, as they will be published in the Entity
     * Configuration statement as provided.
     */
    public function __construct(
        protected readonly string $entityId,
        protected readonly TrustAnchorConfigBag $trustAnchorBag,
        protected readonly UniqueStringBag $authorityHintBag,
        // phpcs:ignore
        protected readonly SignatureKeyPairConfigBag $federationSignatureKeyPairConfigBag = new SignatureKeyPairConfigBag(),
        protected readonly UniqueStringBag $staticTrustMarkBag = new UniqueStringBag(),
        protected readonly KeyedStringBag $dynamicTrustMarkBag = new KeyedStringBag(),
        protected readonly ClaimBag $additionalClaimBag = new ClaimBag(),
    ) {
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getTrustAnchorBag(): TrustAnchorConfigBag
    {
        return $this->trustAnchorBag;
    }

    public function getAuthorityHintBag(): UniqueStringBag
    {
        return $this->authorityHintBag;
    }

    public function getFederationSignatureKeyPairConfigBag(): SignatureKeyPairConfigBag
    {
        return $this->federationSignatureKeyPairConfigBag;
    }

    public function getAdditionalClaimBag(): ClaimBag
    {
        return $this->additionalClaimBag;
    }

    public function getStaticTrustMarkBag(): UniqueStringBag
    {
        return $this->staticTrustMarkBag;
    }

    public function getDynamicTrustMarkBag(): KeyedStringBag
    {
        return $this->dynamicTrustMarkBag;
    }
}
