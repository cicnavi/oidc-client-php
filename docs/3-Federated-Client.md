# Federated Client

The `FederatedClient` class provides an implementation for an OpenID Connect
Relying Party (RP) that supports **Automatic Client Registration** as defined
in the [OpenID Federation 1.0](https://openid.net/specs/openid-federation-1_0.html) specification.

## Features

- **Trust Chain Resolution**: Automatically resolves and validates trust chains
from the OpenID Provider (OP) to a configured Trust Anchor.
- **Automatic Registration**: Dynamically registers the client at the OP during
the first authentication request.
- **Metadata Management**: Handles the generation of the RP's Entity
Configuration and metadata, including keys and trust marks.
- **Federation Discovery**: Supports discovering OPs and their metadata.
- **OIDC Flow**: Manages the authorization code flow, including PKCE,
state/nonce validation, and ID Token verification.
- **Caching**: Efficiently caches resolved trust chains and metadata to improve
performance.

## Prerequisites

### PKI

The Federated Client requires at least two sets of cryptographic keys:
1. **Federation Keys**: Used to sign the RP's Entity Configuration.
2. **Connect Keys**: Used for OIDC protocol operations (e.g., signing Request
Objects or private_key_jwt authentication).

Sample commands to generate RSA key pairs using OpenSSL:

```bash
# Generate Federation keys
openssl genrsa -out keys/federation-sig.key 3072
openssl rsa -in keys/federation-sig.key -pubout -out keys/federation-sig.pub

# Generate Connect keys
openssl genrsa -out keys/connect-sig.key 3072
openssl rsa -in keys/connect-sig.key -pubout -out keys/connect-sig.pub
```

## Configuration

The client is configured using `EntityConfig` (for federation-related settings)
and `RelyingPartyConfig` (for OIDC-related settings).

See the [Configuration Example](../examples/FederatedClient/federated-client-config-example.php)
for a detailed structure.

### Key Configuration Parameters

- `entityId`: The unique identifier for your RP (must be a URL).
- `trustAnchorBag`: A collection of trusted root entities (Trust Anchors).
- `authorityHintBag`: A list of immediate superior entities in the federation.
- `redirectUriBag`: Authorized callback URIs for your application.

## Usage

### 1. Instantiation

It is recommended to use a factory to instantiate the `FederatedClient`.

See the [FederatedClientFactory Example](../examples/FederatedClient/FederatedClientFactory.php).

```php
use FederatedClient\FederatedClientFactory;

$config = require 'path/to/config.php';
$factory = new FederatedClientFactory($config, $logger, $cache);
$client = $factory->build();

// Direct instantiation with custom response mode:
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
$client = new FederatedClient(
    entityConfig: $entityConfig,
    relyingPartyConfig: $relyingPartyConfig,
    responseMode: ResponseModesEnum::FormPost, // Optional
    parMode: ParModeEnum::Auto // Optional; Pushed Authorization Requests (RFC 9126) mode. See below.
);
```

### Pushed Authorization Requests (PAR, RFC 9126)

The Federated Client can deliver the authorization request via PAR: the
authorization parameters are POSTed directly to the OP's
`pushed_authorization_request_endpoint`, authenticated with `private_key_jwt`,
and the browser is then sent to the authorization endpoint carrying only
`client_id` and the returned one-time `request_uri`. In this PAR flow plain
authorization parameters are pushed (no signed Request Object is used).

The `parMode` option (`ParModeEnum`) controls when PAR is used:

- `ParModeEnum::Off` — never use PAR (the OP rejects the request if it requires PAR).
- `ParModeEnum::Auto` (default) — use PAR only when the OP advertises
  `require_pushed_authorization_requests = true`.
- `ParModeEnum::Required` — always use PAR; throws if the OP advertises no
  `pushed_authorization_request_endpoint`.

The mode can also be overridden per call:
`$client->autoRegisterAndAuthenticate($opEntityId, parMode: ParModeEnum::Required)`.

### 2. Initiating Authentication

In your login controller, use the `autoRegisterAndAuthenticate` method. This
method takes the Entity ID of the OpenID Provider the user wants to log in with.

```php

use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;

public function login(string $opEntityId) {
    /** @var \Cicnavi\Oidc\FederatedClient $client */
    // This will resolve the trust chain, register the client if needed,
    // and initiate the authentication flow. You can optionally specify a response mode:
    $client->autoRegisterAndAuthenticate($opEntityId, responseMode: ResponseModesEnum::FormPost);
}
```

### 3. Handling the Callback

After the user authenticates at the OP, they are redirected back to your
`redirect_uri` via GET (for query response mode) or POST (for form_post response mode).
Use the `getUserData` method to complete the flow and collect user information.
The client automatically parses and handles both GET and POST requests.

```php

public function callback(ServerRequestInterface $request) {
    /** @var \Cicnavi\Oidc\FederatedClient $client */
    try {
        // Validates the response and returns an array of user claims.
        $userData = $client->getUserData($request);
        // User is authenticated, $userData contains 'sub', 'email', etc.
    } catch (OidcClientException $e) {
        // Handle authentication error
    }
}
```

See the [LoginController Example](../examples/FederatedClient/FederationLoginController.php)
for a sample implementation.

### 4. RP-Initiated Logout

If the OP advertised an `end_session_endpoint` in its (resolved) metadata at
login time, you can use the `logout()` method to perform
[OpenID Connect RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html).
Since the OP is resolved per authorization flow, the end session endpoint is
snapshotted at login time (during `getUserData()`) together with the raw ID
token, and used later when `logout()` is called.

The client removes the persisted login data (local logout) and delivers a
logout request to the OP's end session endpoint, carrying the ID token as
`id_token_hint`, the RP entity ID as `client_id`, and a `state` parameter.
Note that destroying the application session itself remains the
application's responsibility - but do not destroy the PHP session before
calling `logout()`, since with the default `PhpSessionStore` it also holds
the persisted login data (end session endpoint, ID token). See the note in
the [Pre-Registered Client documentation](2-Pre-Registered-Client.md#rp-initiated-logout)
on session handling around logout.

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */

// Log out the user locally (but do not destroy the PHP session yet), then:
$client->logout(
    // Optional. Must be registered as one of this RP's
    // 'post_logout_redirect_uris' metadata values (which can be provided
    // using the Relying Party configuration additional claims):
    postLogoutRedirectUri: 'https://rp.example.org/logged-out',
);
```

If a `post_logout_redirect_uri` was provided, validate the redirected
request using `validateLogoutCallback()` (verifies the returned `state`):

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */
$client->validateLogoutCallback();
```

## Entity Configuration Endpoint

To participate in a federation, your RP must publish its
**Entity Configuration** at the well-known endpoint:
`/.well-known/openid-federation`

You can generate the content for this endpoint using:

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */

// Build the Entity Statement
$entityStatement = $client->buildEntityStatement();

// Send the Entity Statement as a JWT
header('Content-Type: application/entity-statement+jwt');
header('Access-Control-Allow-Origin: *');

echo $entityStatement->getToken();
exit();
```

See the [Entity Configuration Endpoint Example](../examples/FederatedClient/FederationConfigurationController.php)
for a sample implementation.

## Federation Discovery (from v3.1)

The client can discover OPs and their metadata using the `FederationDiscovery`
service.

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */

// Optionally define claim paths to sort discovered OPs by their display names
// (e.g., for user-friendly display in a login UI). The paths are relative to
// the OP's metadata structure. The method also has default paths it checks
// if not provided.
$sortClaimPaths = [
    ['metadata', 'openid_provider', 'display_name'],
    ['metadata', 'federation_entity', 'display_name'],
];
$forceRefresh = false; // Set to true to bypass cache and fetch fresh data

$openIdProvidersPerTrustAnchor = $client->discoverOpenIdProviders($sortClaimPaths, $forceRefresh);

// The result $openIdProvidersPerTrustAnchor is an associative array where each
// trust anchor ID maps to its list of discovered entities:
// [trustAnchorId => [entityId1 => entityPayload1, entityId2 => entityPayload2, ...]]
// Use it to display available OPs for users to choose from during login.

```

This operation can be time-consuming on the first run because it may require a
full traversal of the federation under each configured Trust Anchor. Results
are cached and subsequent calls are typically much faster.

To warm up discovery caches (for example, from a CLI command or scheduled job),
you can trigger discovery in advance:

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */

// Warm up OP discovery caches for all configured trust anchors.
// Keep forceRefresh=true only for explicit refresh jobs.
$client->discoverOpenIdProviders(forceRefresh: true);
```

### Advanced Discovery

If you need to discover entities other than OpenID Providers, use
`discoverEntities()` and provide criteria explicitly:

```php
/** @var \Cicnavi\Oidc\FederatedClient $client */

$entitiesPerTrustAnchor = $client->discoverEntities(
    criteria: [
        'entity_type' => ['openid_provider'],
        // Optional filters:
        // 'trust_mark_type' => ['https://example.org/trust-mark/type'],
        // 'query' => 'search text',
    ],
    sortClaimPaths: [
        ['metadata', 'openid_provider', 'display_name'],
        ['metadata', 'federation_entity', 'display_name'],
    ],
    sortOrder: 'asc',
    forceRefresh: false,
);
```

`discoverEntities()` returns the same grouped shape:
`[trustAnchorId => [entityId => entityPayload, ...]]`.