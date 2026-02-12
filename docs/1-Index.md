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

There are two ways to instantiate an OIDC client:
* Pre-registered Client (`Cicnavi\Oidc\PreRegisteredClient`) - can be used if 
the client is already registered with the OpenID Provider.
* Federated Client (`Cicnavi\Oidc\FederatedClient`) - can be used in federated
environments (as per OpenID Federation specification). This client type
currently supports Automatic Client Registration flow using Request Object
passed by value.

Check the dedicated sections below for more details about each client type:
* [Pre-registered Client](2-Pre-Registered-Client.md)
* [Federated Client](3-Federated-Client.md)


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
