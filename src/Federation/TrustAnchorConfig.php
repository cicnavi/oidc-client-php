<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Federation;

class TrustAnchorConfig
{
    /**
     * @param string $entityId Trust Anchor Entity ID
     * @param string|null $jwks Trust Anchor's JWKS JSON object string value, or
     * null. If JWKS is provided, it will be used to validate Trust Anchor
     * Configuration Statement in addition to using JWKS acquired during Trust
     * Chain resolution. If JWKS is not provided, the validity of Trust Anchor
     * Configuration Statement will "only" be validated by the JWKS acquired
     * during Trust Chain resolution. This means that security will rely "only"
     * on protection implied from using TLS on endpoints used during
     * Trust Chain resolution.
     */
    public function __construct(
        protected readonly string $entityId,
        protected readonly ?string $jwks = null,
    ) {
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getJwks(): ?string
    {
        return $this->jwks;
    }
}
