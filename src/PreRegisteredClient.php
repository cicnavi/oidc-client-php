<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
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
use SimpleSAML\OpenID\Codebooks\ResponseTypesEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use Throwable;

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
        ?RequestDataHandler $requestDataHandler = null,
    ) {
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
            throw new OidcClientException($error);
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
    ): ?ResponseInterface {
        $authorizationRequestMethod ??= $this->defaultAuthorizationRequestMethod;

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

            ParamsEnum::State->value => $state,
            ParamsEnum::Nonce->value => $nonce,
            ParamsEnum::CodeChallenge->value => $pkceCodeChallenge,
            ParamsEnum::CodeChallengeMethod->value => $pkceCodeChallengeMethod,
        ]);

        $this->logger?->debug('Authorization request parameters', $parameters);

        if (!is_string($authorizationEndpoint = $this->metadata->get('authorization_endpoint'))) {
            throw new OidcClientException('Authorization endpoint not found in OP metadata.');
        }

        if ($authorizationRequestMethod === AuthorizationRequestMethodEnum::FormPost) {
            $formHtml = HttpHelper::generateAutoSubmitPostForm($authorizationEndpoint, $parameters);
            if ($response instanceof ResponseInterface) {
                $this->logger?->debug('Returning FormPost HTML in response body.');
                $response->getBody()->write($formHtml);
                return $response->withHeader('Content-Type', 'text/html');
            }

            echo $formHtml;
            exit;
        }

        $redirectUri = $authorizationEndpoint . '?' . http_build_query($parameters);

        if ($response instanceof ResponseInterface) {
            $this->logger?->debug('Redirecting to authorization endpoint.');
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


        return $this->requestDataHandler->getUserData(
            clientAuthenticationMethod: ClientAuthenticationMethodsEnum::ClientSecretBasic,
            authorizationCode: $authorizationCode,
            clientId: $this->clientId,
            clientRedirectUri: $this->redirectUri,
            opJwksUri: $opJwksUri,
            opTokenEndpoint: $opTokenEndpoint,
            opUserinfoEndpoint: $opUserinfoEndpoint,
            clientSecret: $this->clientSecret,
            clientAssertion: null,
            usePkce: $this->usePkce,
            useNonce: $this->useNonce,
            fetchUserinfoClaims: $this->fetchUserinfoClaims
        );
    }

    /**
     * @return MetadataInterface OIDC Configuration URL content (OIDC metadata).
     */
    public function getMetadata(): MetadataInterface
    {
        return $this->metadata;
    }

    /**
     * @throws CacheException
     */
    public function reinitializeCache(): void
    {
        $this->cache->clear();
        $this->cache->set(self::CACHE_KEY_OP_CONFIGURATION_URL, $this->opConfigurationUrl);
    }
}
