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
```

### 2. Initiating Authentication

In your login controller, use the `autoRegisterAndAuthenticate` method. This
method takes the Entity ID of the OpenID Provider the user wants to log in with.

```php

public function login(string $opEntityId) {
    /** @var \Cicnavi\Oidc\FederatedClient $client */
    // This will resolve the trust chain, register the client if needed,
    // and initiate the authentication flow.
    $client->autoRegisterAndAuthenticate($opEntityId);
}
```

### 3. Handling the Callback

After the user authenticates at the OP, they are redirected back to your
`redirect_uri`. Use the `getUserData` method to complete the flow and
collect user information.

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