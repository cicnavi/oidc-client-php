<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Registration;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use JsonSerializable;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;

/**
 * Value object representing a client registration issued by an OpenID
 * Provider, as per OpenID Connect Dynamic Client Registration 1.0 / RFC 7591.
 * Wraps the (stored) client information response claims and exposes commonly
 * used values.
 * @see \Cicnavi\Tests\Oidc\Registration\ClientRegistrationDataTest
 */
class ClientRegistrationData implements JsonSerializable
{
    /**
     * @param mixed[] $claims Client information response claims, as returned
     * by the OP from the client registration endpoint (or loaded from the
     * client registration store).
     * @throws OidcClientException If mandatory claims are missing or invalid.
     */
    public function __construct(
        protected readonly array $claims,
    ) {
        $this->validate();
    }

    /**
     * @throws OidcClientException
     */
    protected function validate(): void
    {
        $clientId = $this->claims[ClaimsEnum::ClientId->value] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new OidcClientException(
                'Client registration data does not contain a valid "client_id" claim.',
            );
        }

        $clientSecret = $this->claims[ClaimsEnum::ClientSecret->value] ?? null;
        if ($clientSecret !== null && (!is_string($clientSecret) || $clientSecret === '')) {
            throw new OidcClientException(
                'Client registration data contains an invalid "client_secret" claim.',
            );
        }
    }

    /**
     * @return non-empty-string
     */
    public function getClientId(): string
    {
        /** @var non-empty-string $clientId Validated in constructor. */
        $clientId = $this->claims[ClaimsEnum::ClientId->value];

        return $clientId;
    }

    /**
     * @return ?non-empty-string
     */
    public function getClientSecret(): ?string
    {
        /** @var ?non-empty-string $clientSecret Validated in constructor. */
        $clientSecret = $this->claims[ClaimsEnum::ClientSecret->value] ?? null;

        return $clientSecret;
    }

    public function getClientIdIssuedAt(): ?int
    {
        $clientIdIssuedAt = $this->claims[ClaimsEnum::ClientIdIssuedAt->value] ?? null;

        return is_numeric($clientIdIssuedAt) ? (int) $clientIdIssuedAt : null;
    }

    /**
     * Time at which the client secret expires, as a Unix timestamp. Value 0
     * means the client secret does not expire.
     */
    public function getClientSecretExpiresAt(): int
    {
        $clientSecretExpiresAt = $this->claims[ClaimsEnum::ClientSecretExpiresAt->value] ?? null;

        return is_numeric($clientSecretExpiresAt) ? (int) $clientSecretExpiresAt : 0;
    }

    /**
     * Check if the client secret has expired. If no client secret was issued,
     * or the "client_secret_expires_at" claim is 0 (or not provided), the
     * client secret is considered non-expiring.
     *
     * @param ?int $atTime Unix timestamp to check expiry against. Defaults to
     * the current time.
     */
    public function isClientSecretExpired(?int $atTime = null): bool
    {
        if ($this->getClientSecret() === null) {
            return false;
        }

        $clientSecretExpiresAt = $this->getClientSecretExpiresAt();
        if ($clientSecretExpiresAt === 0) {
            return false;
        }

        return ($atTime ?? time()) >= $clientSecretExpiresAt;
    }

    /**
     * @return ?non-empty-string
     */
    public function getRegistrationAccessToken(): ?string
    {
        $registrationAccessToken = $this->claims[ClaimsEnum::RegistrationAccessToken->value] ?? null;

        return (is_string($registrationAccessToken) && $registrationAccessToken !== '') ?
        $registrationAccessToken :
        null;
    }

    /**
     * @return ?non-empty-string
     */
    public function getRegistrationClientUri(): ?string
    {
        $registrationClientUri = $this->claims[ClaimsEnum::RegistrationClientUri->value] ?? null;

        return (is_string($registrationClientUri) && $registrationClientUri !== '') ?
        $registrationClientUri :
        null;
    }

    /**
     * @return mixed[] All client information response claims.
     */
    public function getClaims(): array
    {
        return $this->claims;
    }

    /**
     * @return mixed[]
     */
    public function jsonSerialize(): array
    {
        return $this->claims;
    }
}
