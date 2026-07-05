<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Cicnavi\Oidc\DynamicallyRegisteredClient;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use GuzzleHttp\Client as GuzzleClient;

// Enable error reporting for debuggability during tests
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Read configurations from environment variables or use default test values matching the JSON config
$opConfigurationUrl = getenv('OP_DISCOVERY_URL') ?: 'https://localhost.emobix.co.uk:8443/test/a/oidc-client-php/.well-known/openid-configuration';
$clientRegistration = getenv('CLIENT_REGISTRATION') ?: 'static_client';
$clientId = getenv('CLIENT_ID') ?: 'oidc-client-php-test';
$clientSecret = getenv('CLIENT_SECRET') ?: 'oidc-client-php-test-secret';
$rpBaseUri = getenv('RP_BASE_URI') ?: 'https://rp.local.conformance.test';
$redirectUri = getenv('REDIRECT_URI') ?: $rpBaseUri . '/callback';
$scope = getenv('SCOPE') ?: 'openid';
// 'none' runs plain login flows; 'rp_initiated' continues each login with an
// RP-Initiated Logout flow (for the RP-Initiated Logout conformance plan).
$logoutFlow = getenv('LOGOUT_FLOW') ?: 'none';
$postLogoutRedirectUri = getenv('POST_LOGOUT_REDIRECT_URI') ?: $rpBaseUri . '/logout-callback';

try {
    // Disable SSL verification for internal Guzzle client because conformance-suite uses a self-signed cert
    $httpClient = new GuzzleClient(['verify' => false]);

    if ($clientRegistration === 'dynamic_client') {
        $client = new DynamicallyRegisteredClient(
            opConfigurationUrl: $opConfigurationUrl,
            redirectUri: $redirectUri,
            scope: $scope,
            clientName: 'oidc-client-php',
            httpClient: $httpClient,
            defaultAuthorizationRequestMethod: AuthorizationRequestMethodEnum::Query,
            postLogoutRedirectUris: $logoutFlow === 'rp_initiated' ? [$postLogoutRedirectUri] : [],
        );
    } else {
        $client = new PreRegisteredClient(
            opConfigurationUrl: $opConfigurationUrl,
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $redirectUri,
            scope: $scope,
            httpClient: $httpClient,
            defaultAuthorizationRequestMethod: AuthorizationRequestMethodEnum::Query
        );
    }

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($path === '/callback') {
        // Exchange authorization code for token and fetch user data
        $userData = $client->getUserData();

        if ($logoutFlow === 'rp_initiated') {
            // Continue with RP-Initiated Logout in a separate request, so the
            // persisted login data (ID token for 'id_token_hint') is read
            // from the session store the way a real application would.
            header('Location: ' . $rpBaseUri . '/logout', true, 302);
            exit;
        }

        // Print success div for automated browser/curl matching
        echo '<html><head><title>OIDC RP Test Completion</title></head><body>';
        echo '<div id="submission_complete">OIDC Flow Successful!</div>';
        echo '<h1>User Data</h1><pre>' . htmlspecialchars(json_encode($userData, JSON_PRETTY_PRINT)) . '</pre>';
        echo '</body></html>';
    } elseif ($path === '/logout') {
        // Redirects the user agent to the OP's end_session_endpoint with
        // id_token_hint, client_id, post_logout_redirect_uri and state.
        $client->logout(postLogoutRedirectUri: $postLogoutRedirectUri);
    } elseif ($path === '/logout-callback') {
        try {
            $client->validateLogoutCallback();

            echo '<html><head><title>OIDC RP Logout Completion</title></head><body>';
            echo '<div id="submission_complete">RP-Initiated Logout Successful!</div>';
            echo '<p>Login data cleared: ' . ($client->getLoginData() === null ? 'yes' : 'NO') . '</p>';
            echo '</body></html>';
        } catch (OidcClientException $exception) {
            // Negative test modules (state omitted or changed by the OP) end
            // up here: the RP must not treat the logout as confirmed.
            http_response_code(400);
            echo '<html><head><title>OIDC RP Logout Rejected</title></head><body>';
            echo '<div id="logout_rejected">Post logout callback rejected, logout is NOT confirmed: '
                . htmlspecialchars($exception->getMessage()) . '</div>';
            echo '</body></html>';
        }
    } else {
        if ($client instanceof DynamicallyRegisteredClient) {
            // Each conformance test module is a fresh OP instance served on the
            // same issuer URL, so a registration persisted during a previous
            // test module is stale — always register anew when starting a flow.
            // The callback request then reuses the persisted registration.
            $client->register(forceNewRegistration: true);
        }

        // Initiate OIDC authorization flow
        $client->authorize();
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<html><head><title>OIDC RP Test Error</title></head><body>';
    echo '<h1>Error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</body></html>';
    exit;
}
