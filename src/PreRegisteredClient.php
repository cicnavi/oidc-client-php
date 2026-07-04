<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Cicnavi\Oidc\Protocol\OpMetadata;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use DateInterval;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\ClientAuthenticationMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
use SimpleSAML\OpenID\Codebooks\ResponseTypesEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use Throwable;

/**
 * @see \Cicnavi\Tests\Oidc\PreRegisteredClientTest
 */
class PreRegisteredClient
{
    /**
     * @var CacheInterface $cache Cache instance, which can be used to fetch
     * existing values instead of sending HTTP requests to the auth server
     * each time.
     */
    protected CacheInterface $cache;

    /**
     * @var MetadataInterface $metadata OIDC Provider (OP) metadata. Contains
     * items from OIDC configuration URL.
     */
    protected MetadataInterface $metadata;

    /**
     * @var string Key used to store OIDC configuration URL in cache.
     */
    protected const CACHE_KEY_OP_CONFIGURATION_URL = 'OIDC_OP_CONFIGURATION_URL';

    protected Core $core;

    protected Jwks $jwks;

    protected RequestDataHandler $requestDataHandler;

    /**
     * Client constructor.
     * @param string $opConfigurationUrl URL where the OP configuration can be
     * fetched.
     * @param string $clientId Client ID issued by the OP.
     * @param string $clientSecret Client Secret issued by the OP.
     * @param string $redirectUri Client Redirect URI to which the OP will send
     * the authorization code.
     * @param string $scope Scopes to use in the authorization request
     * @param bool $usePkce Determines if PKCE should be used in authorization
     * flow. True by default.
     * @param PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod If PKCE is
     * used, which Code Challenge Method should be used. Default is 'S256'.
     * @param DateInterval $timestampValidationLeeway Leeway used for timestamp
     * (exp, iat, nbf...) validation. Default is 'PT1M' (1 minute).
     * @param SupportedAlgorithms $supportedAlgorithms Algorithms that the
     * client will support. Default for signatures are: EdDSA, ES256, ES384,
     * ES512, PS256, PS384, PS512, RS256, RS384, RS512.
     * @param CacheInterface|null $cache Cache instance to use for caching.
     * Default is a simple file-based cache.
     * @param SessionStoreInterface $sessionStore Data store for State, Nonce,
     * and PKCE parameter handling.
     * @param Client $httpClient Helper HTTP client instance used to easily
     * send HTTP requests.
     * @param Core|null $core Core library instance. If not provided, a new one
     * will be built using provided options.
     * @throws CacheException If cache could not be initialized.
     * @throws OidcClientException If cache could not be reinitialized.
     */
    public function __construct(
        protected string $opConfigurationUrl,
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUri,
        protected string $scope,
        protected bool $usePkce = true,
        protected PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod = PkceCodeChallengeMethodEnum::S256,
        protected readonly DateInterval $timestampValidationLeeway = new DateInterval('PT1M'),
        protected bool $useState = true,
        protected bool $useNonce = true,
        protected bool $fetchUserinfoClaims = true,
        protected readonly SupportedAlgorithms $supportedAlgorithms = new SupportedAlgorithms(
            new SignatureAlgorithmBag(
                SignatureAlgorithmEnum::EdDSA,
                SignatureAlgorithmEnum::ES256,
                SignatureAlgorithmEnum::ES384,
                SignatureAlgorithmEnum::ES512,
                SignatureAlgorithmEnum::PS256,
                SignatureAlgorithmEnum::PS384,
                SignatureAlgorithmEnum::PS512,
                SignatureAlgorithmEnum::RS256,
                SignatureAlgorithmEnum::RS384,
                SignatureAlgorithmEnum::RS512,
            ),
        ),
        protected readonly SupportedSerializers $supportedSerializers = new SupportedSerializers(),
        protected readonly ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        protected readonly SessionStoreInterface $sessionStore = new PhpSessionStore(),
        protected readonly Client $httpClient = new Client(),
        ?MetadataInterface $metadata = null,
        ?Core $core = null,
        ?Jwks $jwks = null,
        protected readonly DateInterval $maxCacheDuration = new DateInterval('PT6H'),
        // phpcs:ignore
        protected readonly AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = AuthorizationRequestMethodEnum::FormPost,
        protected readonly ?ResponseModesEnum $responseMode = null,
        ?RequestDataHandler $requestDataHandler = null,
        protected readonly ParModeEnum $parMode = ParModeEnum::Auto,
    ) {
        $this->validateResponseMode($this->responseMode);

        $this->cache = $cache ?? new FileCache('oprcpc-' . md5($this->clientId));

        $this->validateCache();

        $this->metadata = $metadata ?? new OpMetadata($this->opConfigurationUrl, $this->cache, $this->httpClient);

        $this->core = $core ?? new Core(
            $this->supportedAlgorithms,
            $this->supportedSerializers,
            $this->timestampValidationLeeway,
            $this->logger,
        );

        $this->jwks = $jwks ?? new Jwks(
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            maxCacheDuration: $this->maxCacheDuration,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            cache: $this->cache,
            logger: $this->logger,
            httpClient: $this->httpClient,
        );

        $this->requestDataHandler = $requestDataHandler ?? new RequestDataHandler(
            sessionStore: $this->sessionStore,
            core: $this->core,
            cache: $this->cache,
            jwks: $this->jwks,
            httpClient: $this->httpClient,
            logger: $this->logger,
            maxCacheDuration: $this->maxCacheDuration,
        );
    }

    /**
     * Check if the current cache state is considered valid. Cache is valid if
     * the cache contains metadata for OIDC Configuration URL, which is
     * available in current OIDC settings. If the cache is not valid
     * (OIDC Configuration URL was changed), the cache will be reinitialized.
     *
     * @throws OidcClientException If cache could not be reinitialized.
     */
    protected function validateCache(): void
    {
        try {
            if (
                $this->opConfigurationUrl !=
                $this->cache->get(self::CACHE_KEY_OP_CONFIGURATION_URL)
            ) {
                $this->reinitializeCache();
            }
        } catch (Throwable $throwable) {
            $error = 'Cache validation error. ' . $throwable->getMessage();
            $this->logger?->error($error);
            throw new OidcClientException($error, $throwable->getCode(), $throwable);
        }
    }

    /**
     * Send an authorization request to the authorization server using authorization code grant flow.
     *
     * @throws OidcClientException If something goes wrong :)
     */
    public function authorize(
        ?AuthorizationRequestMethodEnum $authorizationRequestMethod = null,
        ?ResponseInterface $response = null,
        ?ResponseModesEnum $responseMode = null,
        ?ParModeEnum $parMode = null,
    ): ?ResponseInterface {
        $authorizationRequestMethod ??= $this->defaultAuthorizationRequestMethod;
        $responseMode ??= $this->responseMode;
        $parMode ??= $this->parMode;

        $this->validateResponseMode($responseMode);

        $state = $this->useState ? $this->requestDataHandler->getState() : null;
        $nonce = $this->useNonce ? $this->requestDataHandler->getNonce() : null;
        $pkceCodeChallenge = $this->usePkce ?
        $this->requestDataHandler->generateCodeChallengeFromCodeVerifier(
            $this->requestDataHandler->getCodeVerifier(),
            $this->pkceCodeChallengeMethod,
        ) :
        null;
        $pkceCodeChallengeMethod = $this->usePkce ? $this->pkceCodeChallengeMethod->value : null;

        $parameters = array_filter([
            // indicate authorization code grant
            ParamsEnum::ResponseType->value => ResponseTypesEnum::Code->value,
            ParamsEnum::ClientId->value => $this->clientId,
            ParamsEnum::RedirectUri->value => $this->redirectUri,
            ParamsEnum::Scope->value => $this->scope,
            ParamsEnum::ResponseMode->value => $responseMode?->value,

            ParamsEnum::State->value => $state,
            ParamsEnum::Nonce->value => $nonce,
            ParamsEnum::CodeChallenge->value => $pkceCodeChallenge,
            ParamsEnum::CodeChallengeMethod->value => $pkceCodeChallengeMethod,
        ]);

        $this->logger?->debug('Authorization request parameters', $parameters);

        if (!is_string($authorizationEndpoint = $this->metadata->get('authorization_endpoint'))) {
            throw new OidcClientException('Authorization endpoint not found in OP metadata.');
        }

        $parEndpoint = $this->requestDataHandler->resolvePushedAuthorizationRequestEndpoint(
            $this->collectParRelatedOpMetadata(),
            $parMode,
        );

        if (is_string($parEndpoint)) {
            $this->logger?->debug('Delivering authorization request via PAR.', ['parEndpoint' => $parEndpoint]);

            $parResponse = $this->requestDataHandler->pushAuthorizationRequest(
                clientAuthenticationMethod: ClientAuthenticationMethodsEnum::ClientSecretBasic,
                pushedAuthorizationRequestEndpoint: $parEndpoint,
                parameters: $parameters,
                clientId: $this->clientId,
                clientSecret: $this->clientSecret,
            );

            // The front-channel request now carries only client_id + request_uri.
            $parameters = [
                ParamsEnum::ClientId->value => $this->clientId,
                ParamsEnum::RequestUri->value => $parResponse[ParamsEnum::RequestUri->value],
            ];
        }

        return $this->dispatchFrontChannelRequest(
            $authorizationEndpoint,
            $parameters,
            $authorizationRequestMethod,
            $response,
        );
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
        if ($requestMethod === AuthorizationRequestMethodEnum::FormPost) {
            $formHtml = HttpHelper::generateAutoSubmitPostForm($endpoint, $parameters);
            if ($response instanceof ResponseInterface) {
                $this->logger?->debug('Returning FormPost HTML in response body.');
                $response->getBody()->write($formHtml);
                return $response->withHeader('Content-Type', 'text/html');
            }

            echo $formHtml;
            exit;
        }

        $redirectUri = $endpoint . '?' . http_build_query($parameters);

        if ($response instanceof ResponseInterface) {
            $this->logger?->debug('Redirecting.', ['endpoint' => $endpoint]);
            return $response->withHeader('Location', $redirectUri);
        }

        header('Location: ' . $redirectUri);
        exit;
    }

    /**
     * Get user data by performing an HTTP request to a token endpoint first
     * and then to the userinfo endpoint using tokens to get user data.
     *
     * @return mixed[] User data.
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     */
    public function getUserData(?ServerRequestInterface $request = null): array
    {
        $params = $this->requestDataHandler->validateAuthorizationCallbackResponse(
            $request,
            $this->useState,
        );

        $authorizationCode = $params[ParamsEnum::Code->value];

        if (!is_string($opJwksUri = $this->metadata->get(ClaimsEnum::JwksUri->value))) {
            throw new OidcClientException('JWKS URI not found in OP metadata.');
        }

        if (!is_string($opTokenEndpoint = $this->metadata->get(ClaimsEnum::TokenEndpoint->value))) {
            throw new OidcClientException('Token endpoint not found in OP metadata.');
        }

        $opUserinfoEndpoint = $this->metadata->get(ClaimsEnum::UserinfoEndpoint->value);
        $opUserinfoEndpoint = is_string($opUserinfoEndpoint) ? $opUserinfoEndpoint : null;

        $expectedIssuer = is_string($expectedIssuer = $this->metadata->get(ClaimsEnum::Issuer->value)) ?
        $expectedIssuer :
        null;


        return $this->requestDataHandler->getUserData(
            clientAuthenticationMethod: ClientAuthenticationMethodsEnum::ClientSecretBasic,
            authorizationCode: $authorizationCode,
            clientId: $this->clientId,
            clientRedirectUri: $this->redirectUri,
            opJwksUri: $opJwksUri,
            opTokenEndpoint: $opTokenEndpoint,
            opUserinfoEndpoint: $opUserinfoEndpoint,
            clientSecret: $this->clientSecret,
            usePkce: $this->usePkce,
            useNonce: $this->useNonce,
            fetchUserinfoClaims: $this->fetchUserinfoClaims,
            expectedIssuer: $expectedIssuer,
            opEndSessionEndpoint: $this->getOptionalMetadataString(ClaimsEnum::EndSessionEndpoint->value),
        );
    }

    /**
     * Perform RP-Initiated Logout: remove the login data persisted in the
     * session store (local logout) and deliver a logout request to the OP's
     * end session endpoint, carrying the ID token received at login as
     * 'id_token_hint'.
     *
     * Note that this does not destroy the application session itself - the
     * application should do that as part of its own logout handling. However,
     * with the default PhpSessionStore the persisted login data lives in the
     * same PHP session as the application data, so do not destroy the PHP
     * session before calling this method - otherwise the ID token is gone and
     * the logout request is sent without 'id_token_hint' (a weaker request
     * which the OP may refuse or answer with a user confirmation prompt; a
     * warning is logged in that case). Destroy the session on the post logout
     * redirect page instead, or - when using the $response variant - after
     * this method returns.
     *
     * @param ?string $postLogoutRedirectUri URI to which the OP should
     * redirect the user agent after logout. Must be registered on the OP as
     * one of this client's 'post_logout_redirect_uris'. Validate the
     * redirected request using validateLogoutCallback().
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
     * @throws OidcClientException If the OP does not advertise an
     * 'end_session_endpoint'.
     */
    public function logout(
        ?string $postLogoutRedirectUri = null,
        ?string $logoutHint = null,
        ?string $uiLocales = null,
        AuthorizationRequestMethodEnum $logoutRequestMethod = AuthorizationRequestMethodEnum::Query,
        ?ResponseInterface $response = null,
    ): ?ResponseInterface {
        $endSessionEndpoint = $this->requestDataHandler->getLoginEndSessionEndpoint() ??
        $this->getOptionalMetadataString(ClaimsEnum::EndSessionEndpoint->value);

        if (!is_string($endSessionEndpoint)) {
            throw new OidcClientException(
                'End session endpoint not found in OP metadata, so RP-Initiated Logout is not available.',
            );
        }

        $idTokenHint = $this->requestDataHandler->getLoginIdToken();

        if ($idTokenHint === null) {
            $this->logger?->warning(
                'No ID token found in persisted login data, sending RP-Initiated Logout request without ' .
                '"id_token_hint". The OpenID Provider may refuse the request or prompt the user for ' .
                'confirmation. If the application session was destroyed before calling logout(), destroy ' .
                'it after the logout request is prepared instead (see logout() documentation).',
            );
        }

        $parameters = $this->requestDataHandler->buildEndSessionParameters(
            idTokenHint: $idTokenHint,
            // Prefer the client ID the login was performed with, so it
            // matches the 'id_token_hint' even if the client registration
            // changed in the meantime (dynamically registered clients).
            clientId: $this->requestDataHandler->getLoginClientId() ?? $this->clientId,
            postLogoutRedirectUri: $postLogoutRedirectUri,
            state: $this->useState ? $this->requestDataHandler->getLogoutState() : null,
            logoutHint: $logoutHint,
            uiLocales: $uiLocales,
        );

        $this->logger?->debug('Logout request parameters', $parameters);

        // Local logout: remove persisted login data.
        $this->requestDataHandler->clearLoginData();

        return $this->dispatchFrontChannelRequest(
            $endSessionEndpoint,
            $parameters,
            $logoutRequestMethod,
            $response,
        );
    }

    /**
     * Validate the request made to the post logout redirect URI after an
     * RP-Initiated Logout (the OP must return the logout state parameter
     * unchanged). No-op when this client is configured not to use state.
     *
     * @throws OidcClientException If the state parameter is missing or does
     * not match the one sent in the logout request.
     */
    public function validateLogoutCallback(?ServerRequestInterface $request = null): void
    {
        $this->requestDataHandler->validateLogoutCallbackResponse($request, $this->useState);
    }

    /**
     * Raw ID token received at the last successful login, or null when not
     * available (no login was performed, no ID token was issued, or the
     * session expired).
     */
    public function getIdToken(): ?string
    {
        return $this->requestDataHandler->getLoginIdToken();
    }

    /**
     * Login data persisted at the last successful login (raw ID token, its
     * 'iss' / 'sub' / 'sid' claims, OP end session endpoint), or null when
     * not available.
     *
     * @return mixed[]|null
     */
    public function getLoginData(): ?array
    {
        return $this->requestDataHandler->getLoginData();
    }

    /**
     * Read an optional string value from OP metadata, returning null when the
     * key is not advertised or its value is not a non-empty string.
     */
    protected function getOptionalMetadataString(string $key): ?string
    {
        try {
            $value = $this->metadata->get($key);
        } catch (OidcClientException) {
            return null;
        }

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * @return MetadataInterface OIDC Configuration URL content (OIDC metadata).
     */
    public function getMetadata(): MetadataInterface
    {
        return $this->metadata;
    }

    public function getParMode(): ParModeEnum
    {
        return $this->parMode;
    }

    /**
     * Read the PAR-related OP metadata values used to decide whether to use PAR.
     * Missing values are simply omitted (the OP does not support / require PAR).
     *
     * @return array<string,mixed>
     */
    protected function collectParRelatedOpMetadata(): array
    {
        $parRelatedOpMetadata = [];

        foreach (
            [
            ClaimsEnum::PushedAuthorizationRequestEndpoint->value,
            ClaimsEnum::RequirePushedAuthorizationRequests->value,
            ] as $key
        ) {
            try {
                $parRelatedOpMetadata[$key] = $this->metadata->get($key);
            } catch (OidcClientException) {
                // Not advertised by this OP; leave it out.
            }
        }

        return $parRelatedOpMetadata;
    }

    /**
     * @throws CacheException
     */
    public function reinitializeCache(): void
    {
        $this->cache->clear();
        $this->cache->set(self::CACHE_KEY_OP_CONFIGURATION_URL, $this->opConfigurationUrl);
    }

    /**
     * @throws OidcClientException
     */
    protected function validateResponseMode(?ResponseModesEnum $responseMode): void
    {
        if ($responseMode === ResponseModesEnum::Fragment) {
            throw new OidcClientException(
                "The 'fragment' response mode is not supported because URLs with fragments are " .
                "not sent to the server and cannot be handled by a server-side PHP client."
            );
        }
    }
}
