<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\Interfaces;

interface SessionStoreInterface
{
    /**
     * Check if key exists in data store.
     *
     * @param string $key Key which was used to store the value.
     * @return bool True if exists, else false
     */
    public function exists(string $key): bool;

    /**
     * Get value from the data store for a provided key.
     *
     * @param string $key Key which was used to store the value.
     * @return mixed|null value of the item or null if the value was never saved
     */
    public function get(string $key): mixed;

    /**
     * Put a value in the data store for the provided key.
     *
     * @param string $key Key under which the value will be available.
     * @param mixed $value The value to store in a cache
     */
    public function put(string $key, mixed $value): void;

    /**
     * Delete the value from the data store for a provided key.
     */
    public function delete(string $key): void;

    /**
     * Rotate the identifier this session is stored under, keeping its
     * contents.
     *
     * Called when a login is established. Without it, an identifier an
     * attacker managed to fix on the victim's browser before authentication
     * would still address the session afterwards, once it is authenticated -
     * which is the whole of session fixation.
     *
     * An implementation with no identifier of its own to rotate, or one whose
     * identifier belongs to a framework that already rotates it, should do
     * nothing here.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException If the identifier
     * could not be rotated. Refusing to continue is deliberate: carrying on
     * would establish an authenticated session under an identifier which may
     * already be known to somebody else, which is the outcome this exists to
     * prevent.
     */
    public function regenerateId(): void;
}
