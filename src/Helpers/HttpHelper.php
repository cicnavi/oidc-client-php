<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

class HttpHelper
{
    /**
     * Check if current request is using HTTPS or not
     * @return bool
     */
    public static function isRequestHttpSecure(): bool
    {
        if (
            (isset($_SERVER['HTTPS']) && (strcasecmp($_SERVER['HTTPS'], 'on') === 0)) ||
            (
                isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
                (strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
            ) ||
            (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && (strcasecmp($_SERVER['HTTP_X_FORWARDED_SSL'], 'on') === 0)) ||
            (isset($_SERVER['REQUEST_SCHEME']) && (strcasecmp($_SERVER['REQUEST_SCHEME'], 'https') === 0)) ||
            (isset($_SERVER['SERVER_PORT']) && ((int) $_SERVER['SERVER_PORT'] === 443)) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PORT']) && ((int) $_SERVER['HTTP_X_FORWARDED_PORT'] === 443))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Normalize session cookie regarding SameSite and Secure attributes.
     *
     * @param array $cookieParams Result of session_get_cookie_params()
     * @return array
     */
    public static function normalizeSessionCookieParams(array $cookieParams): array
    {
        $validSameSiteValues = ['Lax', 'None', 'Strict'];
        $defaultSameSiteValue = $validSameSiteValues[0];

        // SameSite session cookie attribute handling
        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
        if (! isset($cookieParams['samesite']) || empty($cookieParams['samesite'])) {
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
