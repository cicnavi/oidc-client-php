# OIDC Client - PHP

OIDC client written in PHP. It uses OIDC authorization code flow to perform
authentication. It implements JWKS public key usage and automatic key
rollover, caching mechanism (file based by default), ID token
verification and claims extraction, as well as 'userinfo' user data fetching.

## Prerequisites

PHP environment:
* Check composer.json for environment requirements.
* ODIC client uses PHP session to handle `state`, `nonce` and `code_verifier`
parameters storage and validation. If the session is not already started, the
OIDC client will try to start it using session config from php.ini.

OpenID Provider must support:
* authorization code flow
* OIDC Discovery URL (well-known URL with OIDC metadata)
* JWKS URI providing JWK key(s)
  
## Installation

OIDC Client is available as a Composer package. In your project you can run:

```shell script
composer require cicnavi/oidc-client-php
```

## Client instantiation

To instantiate a client, provide configuration parameters to the
`\Cicnavi\Oidc\Client` constructor. Here's a basic example with required
parameters:

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
use Cicnavi\Oidc\PreRegisteredClient;

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
    timestampValidationLeeway: new DateInterval('PT1M'),  // Leeway used for timestamp (exp, iat, nbf...) validation.
    useState: true,  // Enable/disable state check
    useNonce: true,  // Enable/disable nonce check
    fetchUserInfoClaims: true,  // Fetch claims from the userinfo endpoint
    defaultCacheTtl: 86400,  // Cache TTL in seconds (24 hours)
    logger: null,  // \Psr\Log\LoggerInterface instance
);
```

## Client usage

To initiate authorization (authorization code flow), that is, to initiate a
login process, you can use the `authorize()` method:

```php 
use Cicnavi\Oidc\Client;
/** @var Client $oidcClient */

// File: authorize.php
try {
    $oidcClient->authorize();
} catch (\Throwable $exception) {
    // In real app log the error, redirect user and show error message.
    throw $exception;
}
```  
This will initiate a browser redirection to the authorization server,
where the user will log in. If the login is successful, the authorization
server will initiate a browser redirection to the `redirect_uri`
which was registered with the client (this is your callback).

On the callback URI, you'll receive authorization code and state
(if state check is enabled) as GET parameters. To use that
authorization code, you can use the `getUserData()` method.
This method will validate `state` (if state check is enabled) and send 
an HTTP request to token endpoint using the provided authorization code
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
    // In a real app log the error, redirect user and show an error message.
    throw $exception;
}
```
The returned user data will be in a form of array, for example:
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
which will store files in the default system 'tmp' folder.
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
// ... other imports

$storagePath = __DIR__ . '/../storage';
$oidcCache = new FileCache($storagePath);

// Create client instance with custom cache
$oidcClient = new Client(
    opConfigurationUrl: 'https://example.org/oidc/.well-known/openid-configuration',
    clientId: 'some-client-id',
    clientSecret: 'some-client-secret',
    redirectUri: 'https://your-example.org/callback',
    scope: 'openid profile',
    cache: $oidcCache  // Pass a custom cache instance
);
```

## Note on SameSite Cookie Attribute

[SameSite Cookie](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite)
attribute plays an important role in Single Sign-On (SSO) environments
because it determines how cookies are delivered in third party contexts.
During OIDC authorization code flow (the authentication flow this OIDC client
uses), a series of HTTP redirects between RP and OP is performed.

By default, the authorization code will be delivered to the RP using HTTP
Redirect, meaning that the User Agent will do a GET request to the RP callback.
This means that the SameSite Cookie attribute can be set to `Lax` or `None`,
but not `Strict` (if the value is `None`, the attribute `Secure` must also
be set).

## Run tests

All tests are available as Composer scripts, so you can run them like this:

```bash
$ composer run-script test
```
