# Pre-Registered Client

Pre-Registered Client can be used if the client is already registered with the
OpenID Provider, meaning you already have the client ID and client secret.

To instantiate a client, provide configuration parameters to the
`\Cicnavi\Oidc\PreRegisteredClient` constructor. Here's a basic example
with required parameters:

```php
use Cicnavi\Oidc\PreRegisteredClient;

// Create a client with required parameters
$oidcClient = new PreRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    clientId: 'some-client-id',
    clientSecret: 'some-client-secret',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile'
);
```

Make sure to include the `openid` scope to use ID token for user claims
extraction. Other scopes are optional (refer to the documentation for
your OpenID Provider).

### Optional Parameters

You can also customize the client behavior with optional parameters:

```php
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;

$oidcClient = new PreRegisteredClient(
    // Required parameters
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    clientId: 'some-client-id',
    clientSecret: 'some-client-secret',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',

    // Optional parameters with default values
    usePkce: true,  // Determines if PKCE should be used in authorization flow. True by default.
    pkceCodeChallengeMethod: PkceCodeChallengeMethodEnum::S256, // If PKCE is used, which Code Challenge Method should be used.
    timestampValidationLeeway: new \DateInterval('PT1M'),  // Leeway used for timestamp (exp, iat, nbf...) validation.
    useState: true,  // Enable / disable state check
    useNonce: true,  // Enable / disable nonce check
    fetchUserinfoClaims: true,  // Fetch claims from the userinfo endpoint
    maxCacheDuration: new \DateInterval('PT6H'),  // Cache max TTL
    logger: null,  // \Psr\Log\LoggerInterface instance
    defaultAuthorizationRequestMethod: AuthorizationRequestMethodEnum::FormPost, // Determines the default authorization request method.
    responseMode: null, // Determines the OIDC response mode (e.g., ResponseModesEnum::Query or ResponseModesEnum::FormPost. Fragment is not supported). Null by default.
    parMode: ParModeEnum::Auto, // Pushed Authorization Requests (RFC 9126) mode. See below.
);
```

### Pushed Authorization Requests (PAR, RFC 9126)

With PAR, the authorization request parameters are first POSTed directly to the
OP's `pushed_authorization_request_endpoint` (a back-channel, client-authenticated
call). The OP returns a short-lived, one-time `request_uri`, and the browser is
then sent to the authorization endpoint carrying only `client_id` and
`request_uri`. Client authentication uses the same `client_secret_basic`
credentials as the token endpoint, and PKCE / state / nonce are unchanged — only
the *delivery* of the request differs. PAR is orthogonal to
`AuthorizationRequestMethodEnum` (Query / FormPost).

The `parMode` option (`ParModeEnum`) controls when PAR is used:

- `ParModeEnum::Off` — never use PAR. Note: if the OP requires PAR
  (`require_pushed_authorization_requests = true`), it will reject the request.
- `ParModeEnum::Auto` (default) — use PAR only when the OP requires it. Otherwise
  the authorization request is delivered as usual.
- `ParModeEnum::Required` — always use PAR; an exception is thrown if the OP does
  not advertise a `pushed_authorization_request_endpoint`.

The mode can also be overridden per call: `$oidcClient->authorize(parMode: ParModeEnum::Required)`.

## Client usage

To initiate authorization (Authorization Code Flow), that is, to initiate a
login process, you can use the `authorize()` method:

```php
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
/** @var PreRegisteredClient $oidcClient */

// File: authorize.php
try {
    // You can also explicitly pass custom authorization request method and response mode:
    $oidcClient->authorize(
        authorizationRequestMethod: AuthorizationRequestMethodEnum::Query,
        responseMode: ResponseModesEnum::Query
    );
} catch (\Throwable $exception) {
    // In real app log the error, redirect user and show error message.
    throw $exception;
}
```
This will initiate a browser request (GET or POST, depending on
`AuthorizationRequestMethodEnum`) to the authorization server,
where the user will log in. If the login is successful, the authorization
server will initiate a browser redirection to the `redirect_uri`
which was registered with the client (this is your callback).

On the callback URI, you'll receive authorization `code` and `state`
(if state check is enabled) as GET (for `query` response mode) or POST
(for `form_post` response mode) parameters. The `getUserData()` method
automatically handles both types of callbacks.
This method will validate `state` (if `state` check is enabled) and send
an HTTP request to token endpoint using the provided authorization `code`
to retrieve tokens (access and ID token). After that it will try to
extract claims from ID token (if it was returned, that is if the `openid`
scope was used in client configuration), and will fetch user data from
`userinfo` endpoint using access token for authentication.

```php
use Cicnavi\Oidc\PreRegisteredClient;
/** @var PreRegisteredClient $oidcClient */

// File: callback.php
try {
    $userData = $oidcClient->getUserData();

    // Log in the user locally, for example:
    if (isset($userData['preferred_username'])) {
        $_SESSION['user'] = $userData['preferred_username'];
        // In the real app redirect to another page, show a success message...
    } else {
        // In the real app redirect to another page, show an error message...
    }

    // This part is for demo purposes, so we can see returned user data.
    $userDataString = var_export($userData, true);

    $content = <<<EOT
        User data: <br>
        <pre>
        {$userDataString} <br>
        </pre>
        <br>
        <a href="index.php">Back to start page</a>
        EOT;

    require __DIR__ . '/../views/page.php';
} catch (\Throwable $exception) {
    // In a real app log the error, redirect the user and show an error message.
    throw $exception;
}
```
The returned user data will be in the form of an array, for example:
```php
array (
  'iss' => 'http://example.org',
  'aud' => 'f7f0a46fbd8469a6bb',
  'jti' => 'bc59a823b69945cc8e3731cedc536ed44d3',
  'nbf' => 1593006799,
  'exp' => 1595598799,
  'sub' => 'da4294fb4af275',
  'iat' => 1593006799,
  'family_name' => 'John',
  'given_name' => 'Doe',
  'nickname' => 'jdoe',
  'preferred_username' => 'jdoe@example.org',
  'name' => 'John Doe',
  'email' => 'john.doe@example.org',
  'address' => 'Some organization, Example street 123, HR-10000 Zagreb, Croatia',
  'phone_number' => '123',
  // ...
)
```
Note that some OpenID providers (for example, AAI@EduHr Federation) will send
claims that have multiple values, for example:
```
// ...
'hrEduPersonUniqueID' =>
  array (
    0 => 'jdoe@example.org',
  ),
  'uid' =>
  array (
    0 => 'jdoe',
  ),
  'cn' =>
  array (
    0 => 'John Doe',
  ),
  'sn' =>
  array (
    0 => 'Doe',
  ),
  'givenName' =>
  array (
    0 => 'John',
  ),
  'mail' =>
  array (
    0 => 'john.doe@example.org',
    1 => 'jdoe@example.org',
  ),
```

## RP-Initiated Logout

If the OpenID Provider advertises an `end_session_endpoint` in its metadata,
you can use the `logout()` method to perform
[OpenID Connect RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html).

After a successful login (`getUserData()`), the client persists the raw ID
token and related login data in the session store. On `logout()`, the client
removes that login data (local logout) and delivers a logout request to the
OP's end session endpoint, carrying the ID token as `id_token_hint`, the
`client_id`, and a `state` parameter (if state check is enabled).

Destroying the application session itself (for example, `session_destroy()`)
remains the application's responsibility - but note that with the default
`PhpSessionStore`, the persisted login data lives in the same PHP session as
your application data. **Do not destroy the PHP session before calling
`logout()`** - the ID token would be gone, and the logout request would be
sent without `id_token_hint` (a weaker request which the OP may refuse or
answer with a user confirmation prompt; the client logs a warning in that
case). Instead, remove your own application data from the session before
calling `logout()` (the client removes its own login data itself), and
destroy the session completely on the post logout redirect page.
Alternatively, use the PSR-7 `response` variant, in which case you can
destroy the session after `logout()` returns and before emitting the
response.

```php
use Cicnavi\Oidc\PreRegisteredClient;
/** @var PreRegisteredClient $oidcClient */

// File: logout.php
try {
    // Log out the user locally, but do not destroy the PHP session yet,
    // since by default it also holds the ID token needed for the logout
    // request:
    unset($_SESSION['user']);

    $oidcClient->logout(
        // Optional. Must be registered on the OP as one of the client's
        // 'post_logout_redirect_uris':
        postLogoutRedirectUri: 'https://client.example.org/logged-out.php',
    );
} catch (\Throwable $exception) {
    // In a real app log the error, redirect the user and show an error message.
    throw $exception;
}
```

If a `post_logout_redirect_uri` was provided, the OP will redirect the user
back to it after logout, returning the `state` parameter. Validate it using
`validateLogoutCallback()`:

```php
use Cicnavi\Oidc\PreRegisteredClient;
/** @var PreRegisteredClient $oidcClient */

// File: logged-out.php
try {
    $oidcClient->validateLogoutCallback();

    // Now the session can be destroyed completely.
    session_destroy();

    // Show a "logged out" page...
} catch (\Throwable $exception) {
    // In a real app log the error and show an error message.
    throw $exception;
}
```

The `logout()` method also accepts optional `logoutHint` and `uiLocales`
parameters, a `logoutRequestMethod` (HTTP GET redirect by default), and a
PSR-7 `response` instance which will be populated with proper headers and
returned (instead of performing an immediate redirect).

The raw ID token received at login is also available using the
`getIdToken()` method (and related login data using `getLoginData()`), for
example, if you need to build a custom logout request yourself.

## Note on Caching

OIDC client uses caching to avoid sending HTTP requests to fetch OIDC
configuration content and JWKS content on every client usage.

Default cache TTL (time-to-live) is set in configuration, so you can modify
it as needed. If you need to bust cache, use `reinitializeCache()` client
instance before making any authentication calls.

```php
use Cicnavi\Oidc\PreRegisteredClient;

// ...
$oidcClient = new PreRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    clientId: 'some-client-id',
    clientSecret: 'some-client-secret',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile'
);
$oidcClient->reinitializeCache();
// ...
```

By default, an OIDC client uses file-based caching. This means that it uses a
folder on your system to store files with cached data. For your convenience,
class `Cicnavi\Oidc\Cache\FileCache` is used to instantiate a Cache instance
which will store files in the default system `tmp` folder.
In the background, this class will use the `cicnavi/simple-file-cache-php`
package. If you want, you can use other caching techniques (memcached, redis...)
by installing the corresponding package which provides
[psr/simple-cache-implementation](https://packagist.org/providers/psr/simple-cache-implementation), and use it for OIDC client
instantiation.

The example below demonstrates how to initialize the default `FileCache`
instance using a custom cache name and folder path (make sure the folder exists
and is writable by the web server).

```php
use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\PreRegisteredClient;
// ... other imports

$storagePath = __DIR__ . '/../storage';
$oidcCache = new FileCache($storagePath);

// Create client instance with custom cache
$oidcClient = new PreRegisteredClient(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    clientId: 'some-client-id',
    clientSecret: 'some-client-secret',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    cache: $oidcCache  // Pass a custom cache instance
);
```
