<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\OidcClientInterface;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Behaviour shared by every OIDC client in this package: the operations that
 * are expressed purely in terms of the login / logout data persisted in the
 * session store, and so do not depend on how the client is registered with
 * the OpenID Provider (OP).
 *
 * Deliberately holds no state. Everything it needs is reached through the two
 * seams below, so a subclass remains free to build its request data handler
 * whenever it likes (eagerly in the constructor, or lazily once a client
 * registration exists) without the base having to know.
 *
 * The remaining OidcClientInterface methods - getUserData(), logout(),
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
     * Whether this client uses the OAuth 2.0 'state' parameter. Clients that
     * do not must not require it back on a callback, since none was ever
     * sent.
     */
    abstract protected function usesState(): bool;

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
}
