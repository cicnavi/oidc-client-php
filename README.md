# OIDC Client - PHP

A PHP OpenID Connect Relying Party client for applications that need to
authenticate users through an OpenID Provider using Authorization Code Flow.

It is built for practical OIDC integrations: provider discovery, JWKS key
rotation, ID token validation, user claims extraction, `userinfo` fetching,
PKCE, Pushed Authorization Requests, RP-Initiated and Back-Channel Logout,
and cache-backed metadata/key handling are included.

## What It Supports

* Pre-registered OIDC clients with an existing client ID and secret
* Federated clients using OpenID Federation automatic client registration
* Dynamic Client Registration for providers that support it
* Authorization Code Flow with state, nonce, PKCE, ID token validation, and
  user data fetching
* JWKS discovery, public key rollover, and file-based caching by default
* RP-Initiated and Back-Channel Logout

## Installation

```bash
composer require cicnavi/oidc-client-php
```

## Getting Started

Choose the setup that matches your OpenID Provider:

* [Pre-registered Client](docs/2-Pre-Registered-Client.md) - use this when your
  client is already registered with the provider.
* [Federated Client](docs/3-Federated-Client.md) - use this in OpenID
  Federation environments.
* [Dynamically Registered Client](docs/4-Dynamically-Registered-Client.md) -
  use this when the provider supports Dynamic Client Registration.

For prerequisites, examples, SameSite cookie notes, and testing instructions,
see the [full documentation](docs/1-Index.md).
