# OIDC Client PHP

## Prerequisites

PHP environment:
* Check `composer.json` for environment requirements.
* ODIC client uses PHP session by default to handle `state`, `nonce` and
`code_verifier` parameters storage and validation. If the session is not
already started, the OIDC client will try to start it using session config
from `php.ini`.

OpenID Provider must support:
* Authorization Code Flow
* OIDC Discovery URL (`.well-known` URL with OP metadata)
* JWKS URI providing JWK key(s)

## Installation

OIDC Client is available as a Composer package. In your project you can run:

```shell script
composer require cicnavi/oidc-client-php
```

## Client Usage

There are three ways to instantiate an OIDC client:
* Pre-registered Client (`Cicnavi\Oidc\PreRegisteredClient`) - can be used if
the client is already registered with the OpenID Provider.
* Federated Client (`Cicnavi\Oidc\FederatedClient`) - can be used in federated
environments (as per OpenID Federation specification). This client type
currently supports Automatic Client Registration flow using Request Object
passed by value.
* Dynamically Registered Client (`Cicnavi\Oidc\DynamicallyRegisteredClient`) -
can be used if the OpenID Provider supports OpenID Connect Dynamic Client
Registration 1.0. The client registers itself with the OpenID Provider and
uses the issued client credentials.

### A common type for the part after login

All three implement `Cicnavi\Oidc\Interfaces\OidcClientInterface`, which
covers everything a client does once the authorization request has been sent:
`getUserData()`, `logout()`, `validateLogoutCallback()`,
`handleBackchannelLogoutRequest()`, `getIdToken()`, `getIdTokenClaims()`,
`getLoginData()` and `getParMode()`. Code that only completes and ends logins -
a callback endpoint, a logout controller, a back-channel logout endpoint - can
type against the interface and stay indifferent to how the client is registered
with the OpenID Provider:

```php
use Cicnavi\Oidc\Interfaces\OidcClientInterface;

function handleCallback(OidcClientInterface $oidcClient): array
{
    return $oidcClient->getUserData();
}
```

Sending the authorization request is deliberately not part of the interface.
The pre-registered and dynamically registered clients send it with
`authorize()`, while a federated client has no single pre-configured OpenID
Provider and must be told which one to use, so its entry point takes the OP
entity ID (`autoRegisterAndAuthenticate()`). Start the login through the
concrete client, and hold the interface everywhere after that.

Check the dedicated sections below for more details about each client type:
* [Pre-registered Client](2-Pre-Registered-Client.md)
* [Federated Client](3-Federated-Client.md)
* [Dynamically Registered Client](4-Dynamically-Registered-Client.md)
* [Conformance Testing](5-Conformance-Testing.md)


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
