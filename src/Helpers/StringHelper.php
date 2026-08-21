<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Closure;
use Throwable;

/**
 * @see \Cicnavi\Tests\Oidc\Helpers\StringHelperTest
 */
class StringHelper
{
    /**
     * Get random string with desired length.
     *
     * @param int $length Desired length of the random string.
     * @param ?Closure $randomBytes Source of raw bytes, given the number of
     * bytes wanted. Defaults to random_bytes(). It exists so the failure paths
     * below can be exercised, and is a Closure rather than what it used to be:
     * the *name* of a function, which this method then called through
     * call_user_func(). That put a caller-chosen function call behind a public
     * static API, and behind the one guarding this library's randomness at
     * that.
     * @return string Random string
     * @throws OidcClientException If random bytes generation fails.
     */
    public static function random(int $length = 16, ?Closure $randomBytes = null): string
    {
        $string = '';

        try {
            // Fully qualified, and resolved in here rather than above. An
            // unqualified name would resolve to a random_bytes() declared in
            // this namespace ahead of the global one, quietly swapping out the
            // CSPRNG; and building the callable at all throws when the function
            // has been disabled, which should surface as this library's own
            // exception rather than a bare Error. The string callable this
            // replaced had neither problem, so neither is acceptable to inherit.
            $randomBytes ??= \random_bytes(...);

            while (($len = strlen($string)) < $length) {
                $size = $length - $len;

                if (!is_string($bytes = $randomBytes($size))) {
                    throw new OidcClientException('Could not generate random bytes.');
                }

                // Characters which are not safe unescaped in a URL are dropped
                // rather than translated, so the result is alphanumeric. That
                // leaves the string short of the requested length, which is
                // what the loop is for.
                $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
            }
        } catch (Throwable $throwable) {
            throw new OidcClientException($throwable->getMessage(), $throwable->getCode(), $throwable);
        }

        return $string;
    }
}
