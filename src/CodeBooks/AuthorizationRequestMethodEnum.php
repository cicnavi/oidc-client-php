<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\CodeBooks;

enum AuthorizationRequestMethodEnum
{
    /**
     * HTTP redirect to authorization endpoint, with parameters in the query
     * string.
     */
    case Query;

    /**
     * HTTP POST to authorization endpoint, with parameters in the request body.
     */
    case FormPost;
}
