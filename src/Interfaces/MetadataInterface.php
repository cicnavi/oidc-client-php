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
     * Get a metadata value for a provided key.
     *
     * @param string $key OpMetadata key.
     * @throws OidcClientException If the key does not exist.
     * @return mixed OpMetadata value
     */
    public function get(string $key): mixed;
}
