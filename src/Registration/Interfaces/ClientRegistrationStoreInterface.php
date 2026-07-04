<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Registration\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;

/**
 * Durable storage for client registrations obtained using OpenID Connect
 * Dynamic Client Registration 1.0. Unlike a cache, entries must not be
 * subject to eviction or expiry: losing a stored registration means losing
 * the client credentials (client_id, client_secret) and the Registration
 * Access Token, forcing a new registration on the OpenID Provider.
 */
interface ClientRegistrationStoreInterface
{
    /**
     * Get stored client registration claims for the given key.
     *
     * @param string $key Key under which the client registration is stored.
     * @return ?mixed[] Client registration claims, or null if not present.
     * @throws OidcClientException On storage errors.
     */
    public function get(string $key): ?array;

    /**
     * Store client registration claims under the given key, overwriting any
     * existing entry.
     *
     * @param string $key Key under which to store the client registration.
     * @param mixed[] $claims Client registration claims (client information
     * response) to store.
     * @throws OidcClientException On storage errors.
     */
    public function set(string $key, array $claims): void;

    /**
     * Delete the stored client registration for the given key. Deleting a
     * non-existent entry is not an error.
     *
     * @param string $key Key under which the client registration is stored.
     * @throws OidcClientException On storage errors.
     */
    public function delete(string $key): void;
}
