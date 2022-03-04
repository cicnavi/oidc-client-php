# OIDC Client - PHP

OIDC client written in PHP. It uses OIDC authorization code flow
to perform authentication. It implements JWKS public 
key usage and automatic key rollover, caching mechanism (file based by default), 
ID token verification and claims extraction, as well as 'userinfo' user data fetching.
It can also be used to simulate authorization code flow using PKCE parameters
intended for public clients.

## Prerequisites
PHP environment:
* Please check composer.json for environment requirements.
* ODIC client uses PHP session to handle 'state', 'nonce' and 'code_verifier' parameters storage and validation.
  If the session is not already started, OIDC client will try to start it using session config from php.ini.

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
To instantiate a client you will have to prepare a Config instance.
First, prepare an array with the following OIDC configuration values,
for example:
```
use Cicnavi\Oidc\Config;
use Cicnavi\Oidc\Client;

$config = [
    // Mandatory config items
    // OpenID Provider (OP) well-known configuration URL
    Config::OPTION_OP_CONFIGURATION_URL => 'https://example.org/oidc/.well-known/openid-configuration',
    // Client ID - obtained durign client registration at OP
    Config::OPTION_CLIENT_ID => 'some-client-id',
    // Client Secret - obtained durign client registration at OP
    Config::OPTION_CLIENT_SECRET => 'some-client-secret',
    // Redirect URI to which the authorization server will return auth code
    Config::OPTION_REDIRECT_URI => 'https://your-example.org/callback',
    // Scopes
    Config::OPTION_SCOPE => 'openid profile',
    
    // Optional config items with default values (override them as necessary)
    // Additional time for which the claim 'exp' is considered valid. If false, the check will be skipped.
    //Config::OPTION_ID_TOKEN_VALIDATION_EXP_LEEWAY => 0,
    // Additional time for which the claim 'iat' is considered valid. If false, the check will be skipped.
    //Config::OPTION_ID_TOKEN_VALIDATION_IAT_LEEWAY => 0,
    // Additional time for which the claim 'nbf' is considered valid. If false, the check will be skipped.
    //Config::OPTION_ID_TOKEN_VALIDATION_NBF_LEEWAY => 0,
    // Enable or disable State check
    //Config::OPTION_IS_STATE_CHECK_ENABLED => true,
    // Enable or disable Nonce check
    //Config::OPTION_IS_NONCE_CHECK_ENABLED => true,
    // Set allowed signature algorithms
    //Config::OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS =>
    //    Config::getIdTokenValidationSupportedSignatureAlgs(), // Or set your own like ['RS256', 'RS512',]
    // Set allowed encryption algorithms
    //Config::OPTION_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS =>
    //    Config::getIdTokenValidationSupportedEncryptionAlgs(),
    // Should client fetch userinfo claims
    //Config::OPTION_SHOULD_FETCH_USERINFO_CLAIMS => true,
    // Choose if client should act as confidential client or public client
    //Config::OPTION_IS_CONFIDENTIAL_CLIENT => true,
    // If public client, set PKCE code challenge method to use
    //Config::OPTION_PKCE_CODE_CHALLENGE_METHOD => 'S256',
<<<<<<< HEAD
    // Default cache time-to-live in seconds
    //Config::OPTION_DEFAULT_CACHE_TTL => 60 * 60 * 24,
=======
>>>>>>> master
];
```
Make sure to include 'openid' scope in order to use ID token for user
claims extraction. Other scopes are optional 
(refer to the documentation for your OpenID Provider).

Next, create a Cicnavi\Oidc\Config instance using the previously 
prepared config array:
```
$oidcConfig = new Config($config);
```

OIDC client can now be instantiated using config instance as parameter:
```
$oidcClient = new Client($oidcConfig);
```

## Client usage
To initiate authorization (authorization code flow),
that is, to initiate a login process, you can use
authorize() method:
```
// File: authorize.php
try {
    $oidcClient->authorize();
} catch (\Throwable $exception) {
    // In real app log the error, redirect user and show error message.
    throw $exception;
}
```  
This will initiate a browser redirection to the authorization server,
where the user will log in. If the login is successful, authorization
server will initiate a browser redirection to the 'redirect_uri'
which was registered with the client (this is your callback).

On the callback URI, you'll receive authorization code and state
(if state check is enabled) as GET parameters.
To use that authorization code, you can use getUserData() method.
This method will validate state (if state check is enabled) and send 
an HTTP request to token endpoint using the
provided authorization code in order to retrieve tokens (access and
ID token). After that it will try to extract claims from ID token
(if it was returned, that is if 'openid' scope was used in client configuration),
and will fetch user data from 'userinfo' endpoint using access token
 for authentication.
```
// File: callback.php
try {
    $userData = $oidcClient->getUserData();

    // Log in the user locally, for example:
    if (isset($userData['preferred_username'])) {
        $_SESSION['user'] = $userData['preferred_username'];
        // In real app redirect to another page, show success message...
    } else {
        // In real app redirect to another page, show error message...
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
    // In real app log the error, redirect user and show error message.
    throw $exception;
}
```
The returned user data will be in a form of array, for example:
```
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
Note that some OpenID providers (for example, AAI@EduHr Federation), will send
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
OIDC client uses caching to avoid sending HTTP requests to fetch OIDC configuration content and 
JWKS content on every client usage.

Default cache TTL (time-to-live) is set in configuration, so you can modify it as needed. 
If you need to bust cache, use reinitializeCache() client instance before making any 
authentication calls.

```
// ... 
$oidcClient = new Client($oidcConfig);
$oidcClient->reinitializeCache();
// ...
```

By default, OIDC client uses file based caching. This means that it uses a folder on your system
to store files with cached data. 
For your convenience, class Cicnavi\Oidc\Cache\FileCache is used to instantiate a Cache instance 
which will store files in the default system 'tmp' folder.
In the background, this class will use the cicnavi/simple-file-cache-php package.
If you want, you can utilize other caching techniques (memcached, redis...) by installing the corresponding
package which provides
[psr/simple-cache-implementation](https://packagist.org/providers/psr/simple-cache-implementation),
and use it for OIDC client instantiation.

Example below demonstrates how to initialize default 
FileCache instance using custom cache name and folder path (make sure the folder exists 
and is writable by the web server).
```
use Cicnavi\Oidc\Cache\FileCache;
// ... other imports

$storagePath = __DIR__ . '/../storage';
$oidcCache = new FileCache($storagePath);

// ... prepare $oidcConfig

// Create client instance using config and cache instances.
$oidcClient = new Client($oidcConfig, $oidcCache);
```

## Note on SameSite Cookie Attribute
[SameSite Cookie](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite)
attribute plays an important role in Single Sign-On (SSO) environments
because it determines how cookies are delivered in third party contexts.
During OIDC authorization code flow (the authentication flow this OIDC client uses),
a series of HTTP redirects between RP and OP is performed.

By default, the authorization code will be delivered to the RP using HTTP Redirect meaning that
the User Agent will do a GET request to the RP callback.
This means that the SameSite Cookie attribute can be set to 'Lax' or 'None', but not 'Strict'
(if the value is 'None', the attribute 'Secure' must also be set).

## Run tests
All tests are available as Composer scripts, so you can simply run them like this:
```bash
$ composer run-script test
```
