<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Oidc\Interfaces\OidcClientInterface;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Behaviour shared by every OIDC client in this package: the operations that
 * are carried out against the login / logout data persisted in the session
 * store, and so do not depend on how the client is registered with the
 * OpenID Provider (OP).
 *
 * Deliberately holds no state. Everything it needs is reached through the
 * abstract seams below, so a subclass remains free to build its request data
 * handler whenever it likes (eagerly in the constructor, or lazily once a
 * client registration exists), and to answer the two questions RP-Initiated
 * Logout asks of it, without the base having to know how a client is
 * configured.
 *
 * The remaining OidcClientInterface methods - getUserData(),
 * handleBackchannelLogoutRequest() and getParMode() - are left to the
 * subclass, which is why this class stays abstract.
 */
abstract class AbstractOidcClient implements OidcClientInterface
{
    /**
     * The request data handler to operate on. Called on every use rather than
     * stored, so that a subclass building its handler lazily can resolve it at
     * the moment it is needed.
     *
     * @throws OidcClientException If the handler can not be resolved.
     */
    abstract protected function resolveRequestDataHandler(): RequestDataHandler;

    /**
     * The logger this client was configured with, if any.
     */
    abstract protected function logger(): ?LoggerInterface;

    /**
     * Whether this client uses the OAuth 2.0 'state' parameter. Clients that
     * do not must not require it back on a callback, since none was ever
     * sent.
     */
    abstract protected function usesState(): bool;

    /**
     * End session endpoint to fall back on when the persisted login data does
     * not carry one - because no login was performed in this session, or the
     * OP advertised no 'end_session_endpoint' at the time it was.
     *
     * Return null when the client has no OP to ask outside of a login. The
     * cost is only that RP-Initiated Logout is refused in that case, which it
     * would be anyway.
     */
    abstract protected function fallbackEndSessionEndpoint(): ?string;

    /**
     * Client identifier to send as the 'client_id' logout parameter when the
     * persisted login data does not carry the one the login was performed
     * with. Return null when none can be determined; the parameter is then
     * omitted, and the OP has to identify the client from 'id_token_hint'
     * alone.
     */
    abstract protected function fallbackLogoutClientId(): ?string;

    /**
     * @inheritDoc
     */
    public function logout(
        ?string $postLogoutRedirectUri = null,
        ?string $logoutHint = null,
        ?string $uiLocales = null,
        AuthorizationRequestMethodEnum $logoutRequestMethod = AuthorizationRequestMethodEnum::Query,
        ?ResponseInterface $response = null,
    ): ?ResponseInterface {
        $requestDataHandler = $this->resolveRequestDataHandler();

        // Prefer the endpoint recorded at login, so the request goes to the
        // OP the login was actually performed with.
        $endSessionEndpoint = $requestDataHandler->getLoginEndSessionEndpoint() ??
        $this->fallbackEndSessionEndpoint();

        if (!is_string($endSessionEndpoint)) {
            $error = 'End session endpoint not available, so RP-Initiated Logout is not available (the ' .
            'OpenID Provider did not advertise one, or no login was performed).';
            $this->logger()?->error($error);
            throw new OidcClientException($error);
        }

        $idTokenHint = $requestDataHandler->getLoginIdToken();

        if ($idTokenHint === null) {
            $this->logger()?->warning(
                'No ID token found in persisted login data, sending RP-Initiated Logout request without ' .
                '"id_token_hint". The OpenID Provider may refuse the request or prompt the user for ' .
                'confirmation. If the application session was destroyed before calling logout(), destroy ' .
                'it after the logout request is prepared instead (see logout() documentation).',
            );
        }

        $parameters = $requestDataHandler->buildEndSessionParameters(
            idTokenHint: $idTokenHint,
            // Prefer the client ID the login was performed with, so it
            // matches the 'id_token_hint' even if the client registration
            // changed in the meantime.
            clientId: $requestDataHandler->getLoginClientId() ?? $this->fallbackLogoutClientId(),
            postLogoutRedirectUri: $postLogoutRedirectUri,
            state: $this->usesState() ? $requestDataHandler->getLogoutState() : null,
            logoutHint: $logoutHint,
            uiLocales: $uiLocales,
        );

        $this->logger()?->debug('Logout request parameters', $parameters);

        // Local logout: remove persisted login data.
        $requestDataHandler->clearLoginData();

        return $this->dispatchFrontChannelRequest(
            $endSessionEndpoint,
            $parameters,
            $logoutRequestMethod,
            $response,
        );
    }

    /**
     * @inheritDoc
     */
    public function validateLogoutCallback(?ServerRequestInterface $request = null): void
    {
        $this->resolveRequestDataHandler()->validateLogoutCallbackResponse($request, $this->usesState());
    }

    /**
     * @inheritDoc
     */
    public function getIdToken(): ?string
    {
        return $this->resolveRequestDataHandler()->getLoginIdToken();
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenClaims(): ?array
    {
        return $this->resolveRequestDataHandler()->getLoginIdTokenClaims();
    }

    /**
     * @inheritDoc
     */
    public function getLoginData(): ?array
    {
        return $this->resolveRequestDataHandler()->getLoginData();
    }

    /**
     * Deliver a front-channel request (authorization request, RP-Initiated
     * Logout request) to the OP, either as an auto-submitting POST form or as
     * a redirect with parameters in the query string.
     *
     * @param array<string,string> $parameters
     */
    protected function dispatchFrontChannelRequest(
        string $endpoint,
        array $parameters,
        AuthorizationRequestMethodEnum $requestMethod,
        ?ResponseInterface $response,
    ): ?ResponseInterface {
        return HttpHelper::dispatchFrontChannelRequest(
            $endpoint,
            $parameters,
            $requestMethod,
            $response,
            $this->logger(),
        );
    }
}
