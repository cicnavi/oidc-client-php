# Upgrading

## Unreleased

### Added

- New `getIdTokenClaims()` method on `PreRegisteredClient`,
`DynamicallyRegisteredClient` and `FederatedClient`, returning the claims of the
ID token received at the last successful login, or `null` when not available.
These are the claims as validated at login, without the UserInfo claims that
`getUserData()` combines them with - use it to assert something against the
signed ID token itself (its `iss` or `aud`, say) rather than against the
combined set. The claims are derived from the raw ID token already persisted in
the login data, so nothing extra is stored, and they become unavailable at the
same moment the login does (logout, session expiry, or a back-channel logout
revocation).

- New `Cicnavi\Oidc\Interfaces\OidcClientInterface`, implemented by all three
clients, covering everything a client can do once the authorization request has
been sent: `getUserData()`, `logout()`, `validateLogoutCallback()`,
`handleBackchannelLogoutRequest()`, `getIdToken()`, `getIdTokenClaims()`,
`getLoginData()` and `getParMode()`. Application code that only completes and
ends logins - a callback endpoint, a logout controller, a back-channel logout
endpoint - can now type against the interface rather than against a concrete
client. Sending the authorization request is deliberately not part of the
contract: `PreRegisteredClient` and `DynamicallyRegisteredClient` send it with
`authorize()`, while `FederatedClient` has no single pre-configured OP and must
be told which one to use, so its entry point takes the OP entity ID
(`autoRegisterAndAuthenticate()`). That difference is essential rather than
incidental, so no common login signature is imposed.

- New `Cicnavi\Oidc\DataStore\ArraySessionStore`, a `SessionStoreInterface`
implementation backed by a plain array for the lifetime of the object. Inject it
wherever a PHP session is unavailable or unwanted - a CLI entry point, a worker,
or a test suite that must not leak state between cases. Nothing it holds
outlives the object, so it is not a stand-in for `PhpSessionStore` in a web
application: a login persisted there is gone by the next request. Its semantics
match `PhpSessionStore`'s exactly, including that a value stored as `null` reads
back as absent.

### Changed

- **Potentially breaking**: `PreRegisteredClient`, `DynamicallyRegisteredClient`
and `FederatedClient` now extend the new abstract
`Cicnavi\Oidc\AbstractOidcClient`, which holds the operations that all three
implemented identically - `validateLogoutCallback()`, `getIdToken()`,
`getIdTokenClaims()`, `getLoginData()`, and `logout()` (see the next entry).
Behaviour is unchanged, and so are the signatures, so ordinary use needs no
change. It matters if you subclass one of the clients: two protected methods
now exist on the hierarchy,
`resolveRequestDataHandler(): RequestDataHandler` and `usesState(): bool`, and a
subclass declaring members of its own under either name must be reconciled with
them. `DynamicallyRegisteredClient::resolveRequestDataHandler()` already
existed and keeps its behaviour; it now also satisfies the base class.
`logout()` (see below) adds three more: `logger(): ?LoggerInterface`,
`fallbackEndSessionEndpoint(): ?string` and `fallbackLogoutClientId(): ?string`.

- **Potentially breaking**: `logout()` now lives on `AbstractOidcClient` for all
three clients, and the exception thrown when no end session endpoint can be
found carries a single message covering every case: *"End session endpoint not
available, so RP-Initiated Logout is not available (the OpenID Provider did not
advertise one, or no login was performed)."* It replaces the two messages used
before (`PreRegisteredClient` and `DynamicallyRegisteredClient` said "not found
in OP metadata"; `FederatedClient` said "not available in persisted login
data"), so anything matching on the old text needs updating. The exception is
now also logged at error level before being thrown by all three, which
previously only `FederatedClient` did. Which endpoints are considered is
unchanged: the one recorded at login first, then - for the pre-registered and
dynamically registered clients only - the configured OP's
`end_session_endpoint`. A federated client still has no fallback, because it
resolves an OP's metadata per issuer through a federation Trust Chain and has
no configured OP to ask outside of a login.

- **Potentially breaking**: `FederatedClient::dispatchAuthorizationRequest()`
has been removed. Front-channel delivery for all three clients now goes through
the inherited `AbstractOidcClient::dispatchFrontChannelRequest()`, which has the
same behaviour and the same signature but different parameter names
(`$endpoint`, `$parameters`, `$requestMethod` rather than
`$opAuthorizationEndpoint`, `$authorizationParameters`,
`$authorizationRequestMethod`). Only subclasses are affected - both methods are
protected. `PreRegisteredClient::dispatchFrontChannelRequest()` was identical
and is now inherited rather than declared, so overrides of it keep working.

- **Breaking**: the minimum PHP version is now 8.3, raised from 8.2. PHP 8.2
loses security support on 31 December 2026, so a major released now would ship
with a floor going end-of-life almost immediately. PHP 8.3 is supported until
31 December 2027. Nothing in the library requires 8.3 syntax; the floor moved
because 8.2 is about to stop receiving fixes, not to adopt new language
features.
- **Potentially breaking**: `OpMetadata` no longer fetches the OP's discovery
document in its constructor - it fetches it when a metadata value is first asked
for. Constructing an object should not make an HTTP request: it charged every
consumer a network round trip whether or not it went on to read any metadata,
and it made the object impossible to build while the OP was unreachable, which
is how a local logout - needing nothing from the OP - ended up depending on the
OP being up. Since all three clients build an `OpMetadata` in their own
constructors, constructing a client no longer reaches the network either. The
consequence to plan for is that `OidcClientException` for an unreachable or
invalid discovery document now surfaces at the first metadata access rather than
at construction, so a `try`/`catch` wrapped around client construction alone
will no longer see it. The document is still fetched at most once per object.
- **Breaking**: `SessionStoreInterface` gained a `regenerateId(): void` method,
which every implementation must now provide. `RequestDataHandler` calls it as a
login is established, before the login data is written, so that a session
identifier fixed on the victim's browser before authentication cannot address
the session afterwards - session fixation. `PhpSessionStore` implements it with
`session_regenerate_id(true)`, which also deletes the old session file;
`ArraySessionStore` does nothing, having no identifier anybody could have fixed
in advance. If you supply your own implementation and its identifier is managed
by a framework which already rotates it on authentication, implement this as a
no-op; otherwise rotate. A failure to rotate throws and the login fails, rather
than being logged and carried on from - continuing would produce exactly the
situation the rotation prevents. Note the identifier is rotated on login only,
not on logout.
- **Potentially breaking**: `PhpSessionStore` no longer starts the PHP session
in its constructor - it starts it on first access instead. Starting a session is
a process-wide side effect which sends a `Set-Cookie` header, and constructing a
store should not do that for a caller who may never touch the session. Two
consequences: constructing the store no longer throws when a session cannot be
started, and a session now starts slightly later, so an application which sends
output before its first session access will see `Session start error - headers
already sent.` where it previously succeeded. Access the store (or construct the
client and perform the authorization request) before emitting output. A subclass
whose constructor calls `parent::__construct()` still works, but that call no
longer starts the session - the first access does. The protected
`startSession()` method keeps its name and its already-running early return, so
a subclass overriding it still has its version called.
- **Breaking**: `StringHelper::random()`'s second argument changed from
`string $randomBytesFunc` - the *name* of a function, which the method then
invoked through `call_user_func()` - to `?Closure $randomBytes`. It exists to
let the failure paths be tested, but as a string it put a caller-chosen function
call behind a public static API, and behind the one guarding this library's
randomness at that. Nothing in the library ever passed it. If you did, pass a
closure instead; passing an uncallable value is now a `TypeError` rather than an
`OidcClientException`, and the `'Provided random bytes function is not
callable.'` failure no longer exists, a `Closure` always being callable.
- **Potentially breaking**: `HttpHelper::normalizeSessionCookieParams()` takes
an optional second argument, a `?LoggerInterface`, and reports overridden cookie
settings through it instead of through `error_log()`. A library writing straight
to `error_log()` ignores whatever logging the application configured. Nothing is
reported when no logger is given, so pass one to keep seeing these messages.
`PhpSessionStore` now takes an optional `?LoggerInterface` of its own and passes
it along, and all three clients build their default session store with the
logger they were given - so a client constructed with a logger reports these
without any further wiring.
- The message for `SameSite=Strict` no longer calls it invalid. It is a valid
and stronger setting; it is simply incompatible with this flow, because the
browser would not send the session cookie on the redirect back from the OpenID
Provider, losing the state and nonce exactly when the authorization response
needs checking against them. The value is still overridden to `Lax`, but the
warning now says why. Two messages which ran their sentences together
("php.ini.Reverting") are also fixed.
- **Potentially breaking**: `PhpSessionStore` no longer substitutes an in-memory
array when the SAPI is `cli`. That check silently gave console and worker
deployments throwaway storage - a login appeared to succeed and was then lost -
and, because the substitution reset `$_SESSION` unconditionally, constructing a
second store discarded whatever the first had stored. PHP sessions in fact work
under the CLI SAPI, so the check was never needed for them to function; it
existed to isolate this library's own tests. Inject an `ArraySessionStore` where
you relied on that behaviour.
- **Potentially breaking**: ID tokens are now validated against the RP's
`idTokenSignedResponseAlg` configuration option, which defaults to `RS256`.
Previously this option was only applied to back-channel logout tokens, and the
ID token's `alg` header was not checked at all. If your OP signs ID tokens with
an algorithm other than `RS256`, set `idTokenSignedResponseAlg` on the client to
match (e.g. `SignatureAlgorithmEnum::ES256->value`) - otherwise the ID token is
now rejected. This aligns ID token handling with the logout token handling, and
with OpenID Connect Dynamic Client Registration section 2, which specifies that
the OP signs a client's ID tokens with the single algorithm that client
registered.
- **Potentially breaking**: an ID token which is unsigned, or which uses the
`none` algorithm, is now explicitly rejected.
- An ID token whose `aud` claim is an empty array is now rejected. Previously an
empty audience skipped both the audience and the authorized party (`azp`)
checks.
- ID token expiration (`exp`) is now validated using the configured
`timestampValidationLeeway` instead of an additional zero-leeway comparison,
which had been rejecting tokens that the configured leeway was meant to accept.
If you relied on expired ID tokens being rejected the instant they expired,
regardless of `timestampValidationLeeway`, set that option to `PT0S`.
- When no expected issuer or no expected client ID is available, the
corresponding ID token check is skipped as before, but is now logged as a
warning rather than passing silently.
- **Potentially breaking**: the ID token's mandatory `sub` claim is now
validated on the token itself. An ID token without a `sub`, or whose `sub` is
not ASCII or exceeds 255 characters, is now rejected. Previously the claim went
unchecked until the ID token / UserInfo `sub` cross-check indexed it, which
produced an "Undefined array key" warning followed by a misleading "must be
equal" error.
- The ID token / UserInfo `sub` cross-check now reports which side is missing
the claim, instead of reporting an absent claim as an inequality.
- **Potentially breaking**: the claims returned by `getUserData()` no longer let
a UserInfo response override claims that belong to the ID token itself, rather
than describing the End-User: the JWT registered claims `iss`, `aud`, `exp`,
`nbf`, `iat` and `jti` (RFC 7519 section 4.1), the authentication claims
`auth_time`, `nonce`, `acr`, `amr` and `azp` (OpenID Connect Core section 2),
and the binding claims `at_hash`, `c_hash`, `sub_jwk` and `sid`. Previously the
UserInfo values won for every claim, so a caller re-checking `iss` or `aud` on the
returned array - or `acr` / `amr` / `auth_time` to enforce an assurance level,
multi-factor authentication or reauthentication - was checking unsigned values
rather than the ones validated on the signed token. Ordinary End-User claims
still come from the UserInfo response as before, and a claim absent from the ID
token is not affected. (`sub` was already required to be equal in both, so it is
unchanged.) None of the protected claims belong to the UserInfo response claim
set in the first place (OpenID Connect Core section 5.1), so a UserInfo response
carrying one is already anomalous; a suppressed override is logged as a warning.
If you were relying on one of these UserInfo values reaching your application,
read it from the UserInfo endpoint yourself.
- **Potentially breaking for subclasses**: `RequestDataHandler::getUserData()` no
longer calls `getClaims()`. Both now build on two new protected methods -
`resolveClaims()`, which gathers the ID token and UserInfo claims separately,
and `combineClaims()`, which merges them. This is what lets `getUserData()` reach
the unmerged ID token claims. If you subclass `RequestDataHandler` and override
`getClaims()` to customise login behaviour, override `resolveClaims()` or
`combineClaims()` instead - an override of `getClaims()` alone no longer affects
`getUserData()`. The public signature of `getClaims()` is unchanged, and calling
it directly behaves as before, as does an override of `storeLoginData()`, which
`getUserData()` still calls.
- The set of claims a UserInfo response cannot override is now composed from
three specification groups - `JWT_REGISTERED_CLAIMS` (RFC 7519 section 4.1),
`ID_TOKEN_AUTHENTICATION_CLAIMS` (OpenID Connect Core section 2) and
`ID_TOKEN_BINDING_CLAIMS` - minus `UNPROTECTED_ID_TOKEN_CLAIMS`, by a new
protected `RequestDataHandler::idTokenProtocolClaims()` method. Override that
method to defend an additional claim, such as an OP-specific one your
application relies on. Composing the set from groups that each map to a
specification section is what turned up `sub_jwk`, which the single flat list it
replaced was missing.
- The checks that OpenID Connect defines identically for ID tokens and logout
tokens - the `alg` header, the signature (including the one-time JWKS refresh
retry), and the `iss`, `aud` and `azp` claims - are now performed by a single
new `Cicnavi\Oidc\Protocol\TokenValidator` collaborator instead of by two copies
which had drifted apart. `RequestDataHandler` takes one as a new optional last
constructor argument, and builds its own when none is given. Behaviour is
unchanged apart from the two items below.
- Logout token validation now logs a warning when it is asked to validate
without an expected issuer or without an expected client ID, and so skips that
check - as ID token validation already did.
- Validation failure messages for both token types were unified, and now
consistently name the token and the claim they are about (for example
`ID token issuer (iss) claim "..." does not match expected issuer "..."`). If
you match on the text of these messages rather than catching
`OidcClientException`, revisit those matches.
- `RequestDataHandler::storeLoginData()` now reads the claims it persists out of
the ID token using the ID token *hint* factory rather than the ID token factory.
The latter rejects an expired token, which would have sent the method down its
best-effort path and persisted a login with a null `iss`, `sub` and `sid`,
leaving back-channel logout unable to correlate anything with that login.
- Logout token expiration (`exp`) is now checked once more after the signature
has been verified, rather than only while the token is being built. The two
moments are separated by a network round trip whenever the JWKS refresh retry
runs, which is long enough for a deliberately short-lived logout token to
expire in flight. Such a token is now rejected with
`Logout token is no longer valid.` instead of being accepted.
- An ID token which fails to build now surfaces as an `OidcClientException`
regardless of what the underlying library threw. Previously only `JwsException`
and its subclasses were wrapped, so an `InvalidValueException` from token
parsing escaped unwrapped. Logout token handling already wrapped everything.

## [3.1.0] - 2026-05-04

### Added

In src/FederatedClient.php added methods `discoverOpenIdProviders` and
`discoverEntities` to `FederatedClient` class, which can be used to discover
OPs and entities in federated environments.

## [3.0.0] - 2025-12-02

Major release with breaking changes to the client instantiation API.

### Added

- New `Cicnavi\Oidc\FederatedClient` class which can be used in federated 
environments. Check the
[Federated Client Documentation](docs/3-Federated-Client.md) for more
information.

### Changed 
- **Breaking**: Class `Cicnavi\Oidc\Client` (`src/Client.php`) has been
renamed to `Cicnavi\Oidc\PreRegisteredClient` (`src/PreRegisteredClient.php`).
- **Breaking**: Class `Cicnavi\Oidc\PreRegisteredClient` now accepts
configuration options as direct constructor parameters using PHP 8.2's property
promotion, instead of accepting a `Cicnavi\Oidc\Config` instance.
- **Breaking**: Class `Cicnavi\Oidc\Metadata` (`src/Metadata.php`) has been
renamed to `Cicnavi\Oidc\OpMetadata` (`src/OpMetadata.php`)
- **Breaking**: Class `Cicnavi\Oidc\OpMetadata` now accepts
configuration parameters directly instead of a `ConfigInterface` instance.
- Instead of optional options `idTokenValidationAllowedSignatureAlgs` and 
`idTokenValidationAllowedEncryptionAlgs`, you can now designate supported 
algorithms by instantiating `\SimpleSAML\OpenID\SupportedAlgorithms` and
passing it to the client.
- Instead of optional options `idTokenValidationExpLeeway`,
`idTokenValidationIatLeeway` and `idTokenValidationNbfLeeway`, you can now 
designate validation leeway for timestamps using `timestampValidationLeeway`
configuration option, which is `DateInterval` instance.
- `PreRegisteredClient` will now use PKCE by default. This can be disabled by
setting `shouldUsePkce` to `false`.
- The minimum supported PHP version is now v8.2

### Removed 
- **Breaking**: Class `Cicnavi\Oidc\Config` (`src/Config.php`) has been removed.
- **Breaking**: Interface `Cicnavi\Oidc\Interfaces\ConfigInterface`
(`src/Interfaces/ConfigInterface.php`) has been removed.
- All `Config::OPTION_*` constants are no longer available.
- Configuration option used to designate if the client is confidential or not
has been removed. Client is now always considered confidential.

### Migration Guide

#### Before (v2.x):

```php
use Cicnavi\Oidc\Config;

$config = new Config([
    Config::OPTION_OP_CONFIGURATION_URL => 'https://example.org/.well-known/openid-configuration',
    Config::OPTION_CLIENT_ID => 'client-id',
    Config::OPTION_CLIENT_SECRET => 'client-secret',
    Config::OPTION_REDIRECT_URI => 'https://your-app.org/callback',
    Config::OPTION_SCOPE => 'openid profile',
    Config::OPTION_IS_CONFIDENTIAL_CLIENT => true,
    Config::OPTION_DEFAULT_CACHE_TTL => 3600,
]);

$client = new Client($config);
```

#### After (v3.0):

```php
use Cicnavi\Oidc\PreRegisteredClient;

$client = new PreRegisteredClient(
    opConfigurationUrl: 'https://example.org/.well-known/openid-configuration',
    clientId: 'client-id',
    clientSecret: 'client-secret',
    redirectUri: 'https://your-app.org/callback',
    scope: 'openid profile',
    usePkce: true,
);
```

#### With Custom Cache (Before):
```php
$config = new Config([...]);
$cache = new FileCache('custom-cache-path');
$client = new Client($config, $cache);
```

#### With Custom Cache (After):
```php
use Cicnavi\Oidc\PreRegisteredClient;
use SimpleSAML\Cache\FileCache;

$cache = new FileCache('custom-cache-path');
$client = new PreRegisteredClient(
    opConfigurationUrl: '...',
    clientId: '...',
    clientSecret: '...',
    redirectUri: '...',
    scope: '...',
    cache: $cache
);
```
