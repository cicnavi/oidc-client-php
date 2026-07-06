# Dynamically Registered Client

Dynamically Registered Client can be used when the OpenID Provider supports
[OpenID Connect Dynamic Client Registration 1.0](https://openid.net/specs/openid-connect-registration-1_0.html)
(RFC 7591), meaning the OP advertises a `registration_endpoint` in its
metadata. Instead of providing a pre-issued client ID and client secret, the
client registers itself on the OP and uses the issued client credentials.
After registration, it behaves exactly like a
[Pre-Registered Client](2-Pre-Registered-Client.md), using the
`client_secret_basic` client authentication method.

To instantiate a client, provide configuration parameters to the
`\Cicnavi\Oidc\DynamicallyRegisteredClient` constructor. Here's a basic
example with required parameters:

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
);
```

Registration is performed lazily on first use (first call to `authorize()`,
`getUserData()`, or any method which needs client credentials), or you can
trigger it explicitly:

```php
$registrationData = $oidcClient->register();

echo $registrationData->getClientId();
```

## Client Registration Persistence

The client registration (client credentials, Registration Access Token,
client configuration endpoint URI) is persisted using a client registration
store, so registration is performed only once, not on every request. On
subsequent instantiations, the persisted registration is used.

By default, a simple file-based store
(`Cicnavi\Oidc\Registration\FileClientRegistrationStore`) is used, which
stores registrations as JSON files in a dedicated directory in the system
`tmp` folder. Note that this is a *default for convenience*: the system `tmp`
folder is typically subject to periodic cleanup, and losing a stored
registration means losing the issued client credentials (forcing a new
registration on the OP). For production, provide a durable storage directory
(make sure it exists, is writable by the web server, and is properly
protected, since it contains client credentials):

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;
use Cicnavi\Oidc\Registration\FileClientRegistrationStore;

$registrationStore = new FileClientRegistrationStore(__DIR__ . '/../storage/registrations');

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    registrationStore: $registrationStore,
);
```

Alternatively, implement
`Cicnavi\Oidc\Registration\Interfaces\ClientRegistrationStoreInterface` to
store registrations in a database or other durable storage.

Registrations are keyed per OP configuration URL and redirect URI, so one
store instance can be shared between clients for different OPs.

If the OP issues a client secret with an expiration time
(`client_secret_expires_at`), the client will automatically perform a new
registration once the persisted client secret expires.

## Initial Access Token

The OP can protect its registration endpoint, requiring an Initial Access
Token (an OAuth 2.0 Bearer token) for registration requests. Provide it with
the `initialAccessToken` parameter:

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    initialAccessToken: 'some-initial-access-token',
);
```

## Registered Client Metadata

During registration, the client sends the following client metadata claims,
prepared from the constructor parameters:

* `redirect_uris` - containing the provided redirect URI,
* `grant_types` - `["authorization_code"]`,
* `response_types` - `["code"]`,
* `token_endpoint_auth_method` - `client_secret_basic`,
* `scope` - the provided scope,
* `client_name` - if provided using the `clientName` parameter,
* `software_id` - by default (can be disabled using the `includeSoftwareId`
parameter),
* `post_logout_redirect_uris` - if provided using the
`postLogoutRedirectUris` parameter (used for RP-Initiated Logout, see
below),
* `backchannel_logout_uri` (and optionally
`backchannel_logout_session_required`) - if provided using the
`backchannelLogoutUri` / `backchannelLogoutSessionRequired` parameters
(used for Back-Channel Logout, see below).

Any additional client metadata claims can be provided using the
`additionalClientMetadata` parameter. Claims provided here override the
prepared claims, so make sure to use the correct format for the particular
claim:

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    clientName: 'My Application',
    additionalClientMetadata: [
        'contacts' => ['admin@your-example.org'],
        'client_uri' => 'https://your-example.org',
    ],
);
```

## Client Configuration Management (RFC 7592)

If the OP returns a Registration Access Token
(`registration_access_token`) and a client configuration endpoint URI
(`registration_client_uri`) in the registration response, the client can
also read, update, and delete its registration on the OP, as per
[RFC 7592](https://www.rfc-editor.org/rfc/rfc7592.html):

```php
// Read the current client registration from the OP (also persists the
// returned client information locally):
$registrationData = $oidcClient->readRegistration();

// Update the client registration on the OP. The whole client metadata set is
// sent (as prepared from constructor parameters), with provided claims taking
// precedence:
$registrationData = $oidcClient->updateRegistration([
    'client_name' => 'My Renamed Application',
]);

// Delete (deprovision) the client registration on the OP, and remove it from
// the local client registration store:
$oidcClient->deleteRegistration();
```

## Client Usage

After registration, client usage is the same as for the
[Pre-Registered Client](2-Pre-Registered-Client.md): use `authorize()` to
initiate the authorization flow, and `getUserData()` on the callback to
exchange the authorization code for tokens and fetch user claims. All
optional parameters known from the Pre-Registered Client (PKCE, state, nonce,
authorization request method, response mode, PAR mode, caching, logging...)
are available and forwarded to the underlying client instance.

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;
/** @var DynamicallyRegisteredClient $oidcClient */

// File: authorize.php
$oidcClient->authorize();

// File: callback.php
$userData = $oidcClient->getUserData();
```

### RP-Initiated Logout

RP-Initiated Logout is also available, same as for the
[Pre-Registered Client](2-Pre-Registered-Client.md): use `logout()` to
deliver a logout request to the OP's end session endpoint, and
`validateLogoutCallback()` on the post logout redirect URI. To use a
`post_logout_redirect_uri` in the logout request, register it first using
the `postLogoutRedirectUris` constructor parameter:

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    postLogoutRedirectUris: ['https://your-example.org/logged-out'],
);

// File: logout.php
$oidcClient->logout(postLogoutRedirectUri: 'https://your-example.org/logged-out');

// File: logged-out.php
$oidcClient->validateLogoutCallback();
```

Note that providing `postLogoutRedirectUris` changes the client metadata
set, so an existing client registration will be updated (or replaced)
accordingly.

### Back-Channel Logout

Back-Channel Logout is also available, same as for the
[Pre-Registered Client](2-Pre-Registered-Client.md#back-channel-logout):
call `handleBackchannelLogoutRequest()` on the endpoint which receives
back-channel logout requests from the OP. Register that endpoint as the
`backchannel_logout_uri` client metadata using the `backchannelLogoutUri`
constructor parameter (and optionally register
`backchannel_logout_session_required` using the
`backchannelLogoutSessionRequired` parameter):

```php
use Cicnavi\Oidc\DynamicallyRegisteredClient;

$oidcClient = new DynamicallyRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    backchannelLogoutUri: 'https://your-example.org/backchannel-logout',
);

// File: backchannel-logout.php
$oidcClient->handleBackchannelLogoutRequest();
```

The logout token audience is validated against the persisted client
registrations - the current one and any retained per-client entry of a
replaced registration - so a logout for a superseded (but still persisted)
registration is still honored while old-client sessions may exist. No
client registration is performed or updated while handling back-channel
logout requests.

When the client's effective registration metadata declares
`backchannel_logout_session_required` as true - whether through the
`backchannelLogoutSessionRequired` constructor parameter or an
`additionalClientMetadata` override - a logout token that does not carry a
`sid` claim is rejected (responded to with HTTP 400), since such a client
asked the OP to always identify the exact session to terminate rather than
falling back to a subject-wide logout.
Note that providing `backchannelLogoutUri` changes the
client metadata set, so an existing client registration will be updated (or
replaced) accordingly.

## Requirements on the OpenID Provider

* OP metadata must advertise a `registration_endpoint`.
* The registration response must contain a `client_secret`, since the client
uses the `client_secret_basic` client authentication method.

As a reference, the [SimpleSAMLphp OIDC module](https://github.com/simplesamlphp/simplesamlphp-module-oidc)
OP implementation supports Dynamic Client Registration, including client
configuration management (read, update, delete) and optional Initial Access
Token protection.
