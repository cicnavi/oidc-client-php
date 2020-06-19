# OIDC Client - PHP



## Prerequisites
* Register your OIDC client. By registering, you'll get client ID and client secret. During
the registration process, you'll have to provide a redirect URI (URL on which your app will
listen for authorization code requests from the authorization server).
* Configure your client by providing the following parameters

```
OIDC_CONFIGURATION_URL="http://pc-mivancic.srce.hr:8073/test/idp-ssp-1.18.7/module.php/oidc/openid-configuration.php"
OIDC_CLIENT_ID="some-client-id"
OIDC_CLIENT_SECRET="some-client-secret"
OIDC_AUTHORIZATION_CALLBACK_URL="redirect-uri-to-which-the-authorization-server-will-send-auth-code"
OIDC_SCOPE="openid profile email address phone"
OIDC_IS_CONFIDENTIAL_CLIENT=1
OIDC_PKCE_CODE_CHALLENGE_METHOD="S256"
```

* Folder 'storage' must be writable by the web server


  


