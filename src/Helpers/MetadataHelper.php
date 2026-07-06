<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\MetadataInterface;

/**
 * Helper for reading optional values from OpenID Provider (OP) metadata, used
 * by the client implementations so the coercion logic lives in one place.
 *
 * @see \Cicnavi\Tests\Oidc\Helpers\MetadataHelperTest
 */
class MetadataHelper
{
    /**
     * Read an optional non-empty string value for the given key from OP
     * metadata, returning null when the key is not advertised or its value
     * is not a non-empty string.
     */
    public static function optionalString(MetadataInterface $metadata, string $key): ?string
    {
        try {
            $value = $metadata->get($key);
        } catch (OidcClientException) {
            return null;
        }

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
