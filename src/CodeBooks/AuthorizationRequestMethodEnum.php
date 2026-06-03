<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\CodeBooks;

/**
 * Specifies the method used by the Relying Party (RP)
 * to deliver the authorization request TO the OpenID
 * Provider (OP).
 *
 * This is orthogonal to the OIDC 'response_mode' parameter:
 * - AuthorizationRequestMethodEnum controls the REQUEST
 *   delivery: sending it via HTTP GET (Query redirect)
 *   or via HTTP POST (FormPost auto-submit form).
 * - The 'response_mode' parameter (ResponseModesEnum)
 *   controls the RESPONSE delivery: how the OP returns
 *   the authorization response back to the RP
 *   (e.g., via 'query' parameters or via 'form_post'
 *   POST body).
 */
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
