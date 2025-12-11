<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

class HttpHelper
{
    /**
     * Check if the current request is using HTTPS or not
     */
    public static function isRequestHttpSecure(): bool
    {
        return (
            isset($_SERVER['HTTPS']) &&
            is_string($_SERVER['HTTPS']) &&
            (strcasecmp($_SERVER['HTTPS'], 'on') === 0)
        ) ||
        (
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        is_string($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        (strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
        ) ||
        (
        isset($_SERVER['HTTP_X_FORWARDED_SSL']) &&
        is_string($_SERVER['HTTP_X_FORWARDED_SSL']) &&
        (strcasecmp($_SERVER['HTTP_X_FORWARDED_SSL'], 'on') === 0)
        ) ||
        (
        isset($_SERVER['REQUEST_SCHEME']) &&
        is_string($_SERVER['REQUEST_SCHEME']) &&
        (strcasecmp($_SERVER['REQUEST_SCHEME'], 'https') === 0)
        ) ||
        (
        isset($_SERVER['SERVER_PORT']) &&
        is_numeric($_SERVER['SERVER_PORT']) &&
        ((int) $_SERVER['SERVER_PORT'] === 443)
        ) ||
        (
        isset($_SERVER['HTTP_X_FORWARDED_PORT']) &&
        is_numeric($_SERVER['HTTP_X_FORWARDED_PORT']) &&
        ((int) $_SERVER['HTTP_X_FORWARDED_PORT'] === 443)
        );
    }

    /**
     * Normalize session cookie regarding SameSite and Secure attributes.
     *
     * @param mixed[] $cookieParams Result of session_get_cookie_params()
     * @return mixed[]
     */
    public static function normalizeSessionCookieParams(array $cookieParams): array
    {
        $validSameSiteValues = ['Lax', 'lax', 'None', 'none', 'Strict', 'strict'];
        $defaultSameSiteValue = $validSameSiteValues[0];

        // SameSite session cookie attribute handling
        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
        if (
            (!isset($cookieParams['samesite'])) ||
            (!is_string($cookieParams['samesite']))
        ) {
            $cookieParams['samesite'] = $defaultSameSiteValue;
        } elseif (! in_array($cookieParams['samesite'], $validSameSiteValues)) {
            error_log('Invalid SameSite session cookie attribute value in php.ini. Reverting to default value.');
            $cookieParams['samesite'] = $defaultSameSiteValue;
        } elseif (strcasecmp($cookieParams['samesite'], 'None') === 0) {
            if (! self::isRequestHttpSecure()) {
                error_log('Invalid session cookie parameters for requests using HTTP (not using HTTPS).' .
                          'Reverting to default values.');
                $cookieParams['secure'] = false;
                $cookieParams['samesite'] = $defaultSameSiteValue;
            } elseif (! $cookieParams['secure']) {
                error_log('Invalid parameter Secure for session cookie. ' .
                          'When using SameSite=None, parameter Secure must be set. Reverting to Secure.');
                $cookieParams['secure'] = true;
            }
        } elseif (strcasecmp($cookieParams['samesite'], 'Strict') === 0) {
            error_log('Invalid SameSite session cookie attribute value (Strict) in php.ini.' .
                      'Reverting to default value.');
            $cookieParams['samesite'] = $defaultSameSiteValue;
        }

        return $cookieParams;
    }
}
