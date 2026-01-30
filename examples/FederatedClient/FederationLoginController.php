<?php

declare(strict_types=1);


namespace FederatedClient;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\FederatedClient;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;

class FederationLoginController
{
    public function __construct(
        protected readonly FederatedClient $federatedClient,
        protected readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @param ServerRequestInterface $request
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     * @throws \Throwable
     */
    public function login(ServerRequestInterface $request): void
    {
        // In a federation, you will typically have an OP Discovery endpoint,
        // where a user will select an OP and then redirect here.
        // Extract issuer (iss) and login_hint from the request.
        // In OIDC Federation, the issuer is the Entity ID of the OpenID
        // Provider.
        $queryParams = $request->getQueryParams();
        $iss = $queryParams[ParamsEnum::Iss->value] ?? null;
        $loginHint = $queryParams[ParamsEnum::LoginHint->value] ?? null;

        if (!is_string($iss) || empty($iss)) {
            throw new OidcClientException('Missing OpenID Provider Entity ID (iss).');
        }

        $this->logger->info('Login initiated for provider: ' . $iss);

        try {
            // The autoRegisterAndAuthenticate method handles:
            // 1. Trust chain resolution and validation for the provider.
            // 2. Automatic client registration at the provider.
            // 3. Returning a redirect response to the provider's authorization endpoint.
            $this->federatedClient->autoRegisterAndAuthenticate(
                $iss,
                $loginHint
            );

            throw new OidcClientException('Failed to initiate authorization request.');
        } catch (\Throwable $t) {
            $this->logger->error('Login failed: ' . $t->getMessage());
            throw $t;
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return array User data
     * @throws OidcClientException
     */
    public function callback(ServerRequestInterface $request): array
    {
        try {
            // The getUserData method handles:
            // 1. Validating the authorization response.
            // 2. Exchanging the authorization code for tokens.
            // 3. Validating the ID Token.
            // 4. Optionally fetching user information from the UserInfo endpoint.
            $userData = $this->federatedClient->getUserData($request);
            $this->logger->info('User logged in successfully.');
            return $userData;
        } catch (OidcClientException $e) {
            $this->logger->error('Callback failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
