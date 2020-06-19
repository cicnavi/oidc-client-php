<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Throwable;

class StringHelper
{
    /**
     * Get random string with desired length.
     *
     * @param int $length desired length of random string
     * @param string $randomBytesFunc Optional function to use to generate random bytes. Default is random_bytes.
     * @return string Random string
     * @throws OidcClientException If random bytes generation fails.
     */
    public static function random(int $length = 16, $randomBytesFunc = 'random_bytes'): string
    {
        if (! is_callable($randomBytesFunc)) {
            throw new OidcClientException('Provided random bytes function is not callable.');
        }

        $string = '';
        try {
            while (($len = strlen($string)) < $length) {
                $size = $length - $len;
                $bytes = call_user_func($randomBytesFunc, $size);
                $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
            }
        } catch (Throwable $exception) {
            throw new OidcClientException($exception->getMessage());
        }

        return $string;
    }
}
