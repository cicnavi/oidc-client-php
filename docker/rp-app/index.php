<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use GuzzleHttp\Client as GuzzleClient;

// Enable error reporting for debuggability during tests
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Read configurations from environment variables or use default test values matching the JSON config
$opConfigurationUrl = getenv('OP_DISCOVERY_URL') ?: 'https://localhost.emobix.co.uk:8443/test/a/oidc-client-php/.well-known/openid-configuration';
$clientId = getenv('CLIENT_ID') ?: 'oidc-client-php-test';
$clientSecret = getenv('CLIENT_SECRET') ?: 'oidc-client-php-test-secret';
$redirectUri = getenv('REDIRECT_URI') ?: 'https://rp.local.conformance.test/callback';
$scope = getenv('SCOPE') ?: 'openid';

try {
    // Disable SSL verification for internal Guzzle client because conformance-suite uses a self-signed cert
    $httpClient = new GuzzleClient(['verify' => false]);

    $client = new PreRegisteredClient(
        opConfigurationUrl: $opConfigurationUrl,
        clientId: $clientId,
        clientSecret: $clientSecret,
        redirectUri: $redirectUri,
        scope: $scope,
        httpClient: $httpClient,
        defaultAuthorizationRequestMethod: AuthorizationRequestMethodEnum::Query
    );

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($path === '/callback') {
        // Exchange authorization code for token and fetch user data
        $userData = $client->getUserData();
        
        // Print success div for automated browser/curl matching
        echo '<html><head><title>OIDC RP Test Completion</title></head><body>';
        echo '<div id="submission_complete">OIDC Flow Successful!</div>';
        echo '<h1>User Data</h1><pre>' . htmlspecialchars(json_encode($userData, JSON_PRETTY_PRINT)) . '</pre>';
        echo '</body></html>';
    } else {
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
