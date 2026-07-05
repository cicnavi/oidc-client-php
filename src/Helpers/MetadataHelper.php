<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\MetadataInterface;

/**
 * Helpers for reading optional values from OpenID Provider (OP) metadata,
 * used by the client implementations so the coercion logic lives in one place.
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

    /**
     * Read an optional list of non-empty strings for the given key from OP
     * metadata (e.g. 'id_token_signing_alg_values_supported'), returning null
     * when the key is not advertised or does not hold a non-empty list of
     * strings.
     *
     * @return ?non-empty-list<non-empty-string>
     */
    public static function optionalStringList(MetadataInterface $metadata, string $key): ?array
    {
        try {
            $value = $metadata->get($key);
        } catch (OidcClientException) {
            return null;
        }

        return self::toNonEmptyStringListOrNull($value);
    }

    /**
     * Coerce a value to a non-empty list of non-empty strings, or null when
     * it is not an array or holds no non-empty strings. Useful for reading a
     * string-list claim from already-resolved metadata (e.g. a federation
     * Trust Chain's resolved OP metadata array).
     *
     * @return ?non-empty-list<non-empty-string>
     */
    public static function toNonEmptyStringListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter(
            $value,
            static fn(mixed $item): bool => is_string($item) && $item !== '',
        ));

        return $strings === [] ? null : $strings;
    }
}
