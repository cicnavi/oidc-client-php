<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;

/**
 * Interface MetadataInterface OIDC Provider (OP) metadata, contains items from OIDC configuration URL.
 * @package Cicnavi\Oidc\Interfaces
 */
interface MetadataInterface
{
    /**
     * Get a metadata value for provided key.
     *
     * @param string $key Metadata key.
     * @throws OidcClientException If key does not exist.
     * @return mixed Metadata value
     */
    public function get(string $key): mixed;
}
