<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\Interfaces;

interface DataStoreInterface
{
    /**
     * Check if key exists in data store.
     *
     * @param string $key Key which was used to store the value.
     * @return bool True if exists, else false
     */
    public function exists(string $key): bool;

    /**
     * Get value from data store for provided key.
     *
     * @param string $key Key which was used to store the value.
     * @return mixed|null value of the item or null if the value was never saved
     */
    public function get(string $key);

    /**
     * Put a value in data store for the provided key.
     *
     * @param string $key Key under which the value will be available.
     * @param mixed $value The value to store in cache
     */
    public function put(string $key, $value): void;

    /**
     * Delete the value from data store for provided key.
     *
     * @param string $key
     */
    public function delete(string $key): void;
}
