# OIDC Client - PHP

Generic OpenID Connect (OIDC) client (RP) written in PHP.

It uses OIDC Authorization Code Flow to perform authentication. It implements
JWKS public key usage and automatic key rollover, caching mechanism
(file based by default), ID token verification and claims extraction,
'userinfo' user data fetching, OIDC RP-Initiated Logout, OIDC Back-Channel
Logout, and has support for automatic client registration for federated
environments, as well as OpenID Connect Dynamic Client Registration.

For information on how to use this client, refer to the
[documentation](docs/1-Index.md).
