<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;

interface StateNonceDataHandlerInterface
{
    /**
     * Get existing value for given key from data store, or generate a new one and store it.
     *
     * @param string $key Key under which the value will be available in data store.
     * @param int $length Desired length when generating a new value.
     * @return string Existing value from data store, or newly generated value.
     * @throws OidcClientException If key is not valid
     */
    public function get(string $key, int $length = 40): string;

    /**
     * Verify that the value under given key in data store is the same as the given value.
     *
     * @param string $key Key under which the existing value is available in data store.
     * @param string $value Value to compare to the existing value from data store.
     * @throws OidcClientException If the values do not match, or the key is not valid.
     */
    public function verify(string $key, string $value): void;

    /**
     * Remove current value for given key from data store.
     * @param string $key
     * @throws OidcClientException If the given key is not valid.
     */
    public function remove(string $key): void;
}
