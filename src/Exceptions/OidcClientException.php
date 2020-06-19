<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Exceptions;

use Cicnavi\Oidc\Exceptions\Interfaces\OidcClientExceptionInterface;
use Exception;

class OidcClientException extends Exception implements OidcClientExceptionInterface
{
}
