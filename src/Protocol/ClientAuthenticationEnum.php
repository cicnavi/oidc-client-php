<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

enum ClientAuthenticationEnum: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case ClientSecretPost = 'client_secret_post';
    case ClientSecretJwt = 'client_secret_jwt';
    case PrivateKeyJwt = 'private_key_jwt';

    case None = 'none';
}
