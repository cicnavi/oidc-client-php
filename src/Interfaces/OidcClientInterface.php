<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Interfaces;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Everything an OpenID Connect Relying Party (RP) client can do once the
 * authorization request has been sent: completing the login at the redirect
 * URI, reading what that login produced, and terminating it again.
 *
 * Sending the authorization request itself is deliberately not part of this
 * contract. A pre-registered or dynamically registered client sends it with
 * authorize(), while a federated client has no single pre-configured OpenID
 * Provider (OP) and must be told which one to use, so its entry point takes
 * the OP entity ID: FederatedClient::autoRegisterAndAuthenticate(). That
 * difference is essential rather than incidental, so the login entry point is
 * left to the concrete client and only the part that genuinely is common is
 * expressed here.
 *
 * Application code that only completes and ends logins - a callback endpoint,
 * a logout controller, a back-channel logout endpoint - can therefore type
 * against this interface and stay indifferent to how the client is
 * registered with the OP.
 *
 * @package Cicnavi\Oidc\Interfaces
 */
interface OidcClientInterface
{
    /**
     * Complete the login: validate the authorization callback, exchange the
     * authorization code for tokens, validate the ID token, and return the
     * End-User claims (the ID token claims, combined with the UserInfo claims
     * when the client is configured to fetch them).
     *
     * @param ?ServerRequestInterface $request Authorization callback request.
     * If not provided, it is read from PHP globals.
     * @return mixed[] User data.
     * @throws OidcClientException If the callback or any of the tokens it
     * leads to can not be validated.
     */
    public function getUserData(?ServerRequestInterface $request = null): array;

    /**
     * Perform RP-Initiated Logout: remove the login data persisted in the
     * session store (local logout) and deliver a logout request to the OP's
     * end session endpoint, carrying the ID token received at login as
     * 'id_token_hint'.
     *
     * This does not destroy the application session itself - the application
     * should do that as part of its own logout handling. With the default
     * PhpSessionStore, though, the persisted login data lives in the same PHP
     * session as the application data, so do not destroy that session before
     * calling this method: the ID token would be gone and the logout request
     * would go out without 'id_token_hint', a weaker request which the OP may
     * refuse or answer with a user confirmation prompt (a warning is logged in
     * that case). Destroy the session on the post logout redirect page
     * instead, or - when using the $response variant - after this method
     * returns.
     *
     * @param ?string $postLogoutRedirectUri URI to which the OP should
     * redirect the user agent after logout. Must be registered on the OP as
     * one of this client's 'post_logout_redirect_uris' - how depends on the
     * client: registered manually for a pre-registered one, sent during
     * client registration for a dynamically registered one (see its
     * $postLogoutRedirectUris constructor parameter), or published as a
     * Relying Party metadata claim for a federated one (see the Relying Party
     * configuration additional claims). Validate the redirected request using
     * validateLogoutCallback().
     * @param ?string $logoutHint Hint about the End-User that is logging out,
     * analogous to 'login_hint' (e.g., e-mail address or phone number).
     * @param ?string $uiLocales Preferred languages for the OP's logout user
     * interface (space-separated language tags).
     * @param AuthorizationRequestMethodEnum $logoutRequestMethod How to
     * deliver the logout request to the OP. Defaults to Query (HTTP GET
     * redirect), which every OP supporting RP-Initiated Logout accepts.
     * @param ?ResponseInterface $response Optional HTTP response which will
     * be populated with proper headers and returned. If not provided, an
     * immediate redirect (or form output) is performed.
     * @throws OidcClientException If no end session endpoint is available.
     */
    public function logout(
        ?string $postLogoutRedirectUri = null,
        ?string $logoutHint = null,
        ?string $uiLocales = null,
        AuthorizationRequestMethodEnum $logoutRequestMethod = AuthorizationRequestMethodEnum::Query,
        ?ResponseInterface $response = null,
    ): ?ResponseInterface;

    /**
     * Validate the request made to the post logout redirect URI after an
     * RP-Initiated Logout (the OP must return the logout state parameter
     * unchanged). No-op when the client is configured not to use state.
     *
     * @param ?ServerRequestInterface $request Post logout redirect request.
     * If not provided, it is read from PHP globals.
     * @throws OidcClientException If the state parameter is missing or does
     * not match the one sent in the logout request.
     */
    public function validateLogoutCallback(?ServerRequestInterface $request = null): void;

    /**
     * Handle an OIDC Back-Channel Logout request from the OP: validate the
     * logout token from the request (signature against the OP JWKS, issuer,
     * audience, claim set, freshness, 'jti' replay), record the login
     * revocation it requests, and deliver the appropriate HTTP response
     * (200 when the logout was performed, 400 with a JSON error body when
     * not).
     *
     * A back-channel logout request arrives outside the context of the
     * End-User's session, so the affected login can not be removed from its
     * session store here. Instead the revocation is recorded in the login
     * revocation registry, and the affected persisted login is observed as
     * terminated on subsequent reads (see getLoginData()).
     *
     * @param ?ServerRequestInterface $request Back-channel logout request.
     * If not provided, it is read from PHP globals.
     * @param ?ResponseInterface $response Optional HTTP response which will
     * be populated with the proper status / headers / body and returned. If
     * not provided, the response is emitted directly and the script is
     * terminated.
     */
    public function handleBackchannelLogoutRequest(
        ?ServerRequestInterface $request = null,
        ?ResponseInterface $response = null,
    ): ?ResponseInterface;

    /**
     * Raw ID token received at the last successful login, or null when not
     * available (no login was performed, no ID token was issued, or the
     * session expired).
     */
    public function getIdToken(): ?string;

    /**
     * Claims of the ID token received at the last successful login, or null
     * when not available.
     *
     * These are the claims as validated at login, without the UserInfo claims
     * that getUserData() combines them with. Use this to assert something
     * against the signed ID token itself - for example its 'iss' or 'aud' -
     * rather than against the combined set, where End-User claims from the
     * UserInfo response are also present.
     *
     * @return mixed[]|null
     */
    public function getIdTokenClaims(): ?array;

    /**
     * Login data persisted at the last successful login (raw ID token, its
     * 'iss' / 'sub' / 'sid' claims, OP end session endpoint), or null when
     * not available - which includes the case of the login having been
     * terminated by a back-channel logout.
     *
     * @return mixed[]|null
     */
    public function getLoginData(): ?array;

    /**
     * Pushed Authorization Requests (PAR, RFC 9126) mode this client is
     * configured with.
     */
    public function getParMode(): ParModeEnum;
}
