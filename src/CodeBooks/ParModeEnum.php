<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\CodeBooks;

/**
 * Controls whether the Relying Party (RP) delivers its authorization request
 * via Pushed Authorization Requests (PAR, RFC 9126): the RP first POSTs the
 * authorization parameters to the OP's PAR endpoint (a back-channel,
 * client-authenticated call), receives a one-time 'request_uri', and then
 * sends the user agent to the authorization endpoint carrying only 'client_id'
 * and 'request_uri'.
 *
 * This is orthogonal to AuthorizationRequestMethodEnum (Query / FormPost),
 * which only controls how the front-channel request is delivered.
 */
enum ParModeEnum
{
    /**
     * Never use PAR. The authorization request is delivered as usual. Note that
     * if the OP requires PAR (require_pushed_authorization_requests = true), it
     * will reject the request.
     */
    case Off;

    /**
     * Use PAR only when the OP requires it (advertises
     * require_pushed_authorization_requests = true). Otherwise the
     * authorization request is delivered as usual. This is the default.
     */
    case Auto;

    /**
     * Always use PAR. If the OP does not advertise a
     * 'pushed_authorization_request_endpoint', an exception is thrown.
     */
    case Required;
}
