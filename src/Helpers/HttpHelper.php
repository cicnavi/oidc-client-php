<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Helpers;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * @see \Cicnavi\Tests\Oidc\Helpers\HttpHelperTest
 */
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
     * @param ?LoggerInterface $logger Where to report a setting that had to be
     * overridden. Without one nothing is reported: these used to go to
     * error_log(), which ignored whatever logging the application had
     * configured.
     * @return mixed[]
     */
    public static function normalizeSessionCookieParams(array $cookieParams, ?LoggerInterface $logger = null): array
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
        } elseif (! in_array($cookieParams['samesite'], $validSameSiteValues, true)) {
            $logger?->warning(sprintf(
                'Invalid SameSite session cookie attribute value in php.ini. Using "%s" instead.',
                $defaultSameSiteValue,
            ));
            $cookieParams['samesite'] = $defaultSameSiteValue;
        } elseif (strcasecmp($cookieParams['samesite'], 'None') === 0) {
            if (! self::isRequestHttpSecure()) {
                $logger?->warning(sprintf(
                    'Session cookie SameSite=None requires HTTPS, and this request is not secure. ' .
                    'Using "%s" without Secure instead.',
                    $defaultSameSiteValue,
                ));
                $cookieParams['secure'] = false;
                $cookieParams['samesite'] = $defaultSameSiteValue;
            } elseif (! ($cookieParams['secure'] ?? false)) {
                $logger?->warning(
                    'Session cookie SameSite=None requires the Secure attribute, which is not set. Setting it.',
                );
                $cookieParams['secure'] = true;
            }
        } elseif (strcasecmp($cookieParams['samesite'], 'Strict') === 0) {
            // Strict is a perfectly valid attribute value, and a stronger one -
            // it is simply incompatible with this flow. The browser would not
            // send the cookie on the redirect back from the OP, so the session
            // carrying the state and nonce would be missing exactly when the
            // authorization response needs checking against it.
            $logger?->warning(sprintf(
                'Session cookie SameSite=Strict would not be sent on the redirect back from the OpenID ' .
                'Provider, losing the session at the callback. Using "%s" instead.',
                $defaultSameSiteValue,
            ));
            $cookieParams['samesite'] = $defaultSameSiteValue;
        }

        return $cookieParams;
    }

    /**
     * Generate an HTML form that automatically submits via POST to the given URL.
     *
     * @param string $url The URL to submit the form to.
     * @param array<string, string> $parameters The parameters to include in the form as hidden inputs.
     * @return string The HTML code for the auto-submitting form.
     */
    public static function generateAutoSubmitPostForm(string $url, array $parameters): string
    {
        $inputs = '';
        foreach ($parameters as $name => $value) {
            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s" />' . PHP_EOL,
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<!DOCTYPE html>
<html>
<head>
    <title>Redirecting...</title>
</head>
<body onload="document.forms[0].submit()">
    <form action="%s" method="post">
        %s
        <noscript>
            <p>Your browser does not support JavaScript or it is disabled. Please click the button below to proceed.</p>
            <input type="submit" value="Continue" />
        </noscript>
    </form>
</body>
</html>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            $inputs
        );
    }

    /**
     * Deliver a front-channel request (authorization request, RP-Initiated
     * Logout request) to the given endpoint, either as an auto-submitting
     * POST form or as a redirect with parameters in the query string,
     * depending on the method.
     *
     * If a PSR-7 response instance is provided, it is populated with the
     * proper headers / body and returned. Otherwise, output is emitted
     * directly and the script is terminated.
     *
     * @param array<string,string> $parameters
     */
    public static function dispatchFrontChannelRequest(
        string $endpoint,
        array $parameters,
        AuthorizationRequestMethodEnum $requestMethod,
        ?ResponseInterface $response = null,
        ?LoggerInterface $logger = null,
    ): ?ResponseInterface {
        if ($requestMethod === AuthorizationRequestMethodEnum::FormPost) {
            $formHtml = self::generateAutoSubmitPostForm($endpoint, $parameters);
            if ($response instanceof ResponseInterface) {
                $logger?->debug('Returning FormPost HTML in response body.');
                $response->getBody()->write($formHtml);
                return $response->withHeader('Content-Type', 'text/html');
            }

            echo $formHtml;
            exit;
        }

        $uri = $endpoint . '?' . http_build_query($parameters);

        if ($response instanceof ResponseInterface) {
            $logger?->debug('Redirecting.', ['endpoint' => $endpoint]);
            return $response->withHeader('Location', $uri);
        }

        header('Location: ' . $uri);
        exit;
    }

    /**
     * Deliver the HTTP response for an OIDC Back-Channel Logout request:
     * HTTP 200 when the logout was performed, HTTP 400 with a JSON error
     * body when the logout request or token was invalid (per specification
     * sections 2.8 and 2.9), with 'Cache-Control: no-store' in both cases.
     *
     * If a PSR-7 response instance is provided, it is populated and
     * returned. Otherwise, the response is emitted directly and the script
     * is terminated.
     *
     * @param ?string $error Error description when the logout failed, null
     * when the logout was performed.
     */
    public static function dispatchBackchannelLogoutResponse(
        ?ResponseInterface $response = null,
        ?string $error = null,
        ?LoggerInterface $logger = null,
    ): ?ResponseInterface {
        $statusCode = $error === null ? 200 : 400;

        $body = null;
        if (is_string($error)) {
            $logger?->debug('Returning back-channel logout error response.', ['error' => $error]);
            $encodedBody = json_encode(
                ['error' => 'invalid_request', 'error_description' => $error],
                JSON_UNESCAPED_SLASHES,
            );
            $body = is_string($encodedBody) ? $encodedBody : '{"error": "invalid_request"}';
        }

        if ($response instanceof ResponseInterface) {
            $response = $response->withStatus($statusCode)->withHeader('Cache-Control', 'no-store');

            if (is_string($body)) {
                $response->getBody()->write($body);
                $response = $response->withHeader('Content-Type', 'application/json');
            }

            return $response;
        }

        http_response_code($statusCode);
        header('Cache-Control: no-store');

        if (is_string($body)) {
            header('Content-Type: application/json');
            echo $body;
        }

        exit;
    }
}
