<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionDataStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use DateInterval;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use Throwable;

class Client
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
     * @var string Key used to store JWKS URI content in cache.
     */
    protected const CACHE_KEY_JWKS_URI_CONTENT = 'OIDC_JWKS_URI_CONTENT';

    /**
     * @var string Key used to store OIDC configuration URL in cache.
     */
    protected const CACHE_KEY_OP_CONFIGURATION_URL = 'OIDC_OP_CONFIGURATION_URL';

    /**
     * @var PkceDataHandlerInterface $pkceDataHandler
     */
    protected PkceDataHandlerInterface $pkceDataHandler;

    /**
     * @var StateNonceDataHandlerInterface $stateNonceDataHandler
     */
    protected StateNonceDataHandlerInterface $stateNonceDataHandler;

    protected Core $core;

    /**
     * Client constructor.
     * @param string $opConfigurationUrl URL where the OP configuration can be fetched.
     * @param string $clientId Client ID issued by the OP.
     * @param string $clientSecret Client Secret issued by the OP.
     * @param string $redirectUri Client Redirect URI to which the OP will send the authorization code.
     * @param string $scope Scopes to use in the authorization request
     * @param bool $shouldUsePkce Determines if PKCE should be used in authorization flow. True by default.
     * @param string $pkceCodeChallengeMethod If PKCE is used, which Code Challenge Method should be used.
     * Default is 'S256'.
     * @param DateInterval $timestampValidationLeeway Leeway used for timestamp (exp, iat, nbf...) validation.
     * Default is 'PT1M' (1 minute).
     * @param bool $isStateCheckEnabled
     * @param bool $isNonceCheckEnabled
     * @param bool $shouldFetchUserInfoClaims
     * @param int|null $defaultCacheTtl
     * @param SupportedAlgorithms $supportedAlgorithms Algorithms that the client will support. Default for
     * signatures are: EdDSA, ES256, ES384, ES512, PS256, PS384, PS512, RS256, RS384, RS512.
     * @param SupportedSerializers $supportedSerializers
     * @param LoggerInterface|null $logger
     * @param CacheInterface|null $cache Cache instance to use for caching. Default is simple file-based cache.
     * @param DataStoreInterface $dataStore Data store for State, Nonce, and PKCE parameter handling.
     * @param ClientInterface $httpClient Helper HTTP client instance used to easily send HTTP requests.
     * @param RequestFactoryInterface $httpRequestFactory Used to prepare HTTP requests
     * @param StateNonceDataHandlerInterface|null $stateNonceDataHandler
     * @param PkceDataHandlerInterface|null $pkceDataHandler
     * @param MetadataInterface|null $metadata
     * @param Core|null $core Core library instance. If not provided, new one will be built using provided options.
     * @throws CacheException If cache could not be initialized.
     * @throws OidcClientException If cache could not be reinitialized.
     */
    public function __construct(
        protected string $opConfigurationUrl,
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUri,
        protected string $scope,
        protected bool $shouldUsePkce = true,
        protected string $pkceCodeChallengeMethod = 'S256',
        protected readonly DateInterval $timestampValidationLeeway = new DateInterval('PT1M'),
        protected bool $isStateCheckEnabled = true,
        protected bool $isNonceCheckEnabled = true,
        protected bool $shouldFetchUserInfoClaims = true,
        protected ?int $defaultCacheTtl = 86400,
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
        protected readonly DataStoreInterface $dataStore = new PhpSessionDataStore(),
        protected readonly ClientInterface $httpClient = new \GuzzleHttp\Client(),
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        ?StateNonceDataHandlerInterface $stateNonceDataHandler = null,
        ?PkceDataHandlerInterface $pkceDataHandler = null,
        ?MetadataInterface $metadata = null,
        ?Core $core = null,
    ) {
        $this->cache = $cache ?? new FileCache('oidc-client-php-cache-' . md5($this->clientId));

        $this->validateCache();

        $this->stateNonceDataHandler = $stateNonceDataHandler ?? new StateNonce($this->dataStore);
        $this->pkceDataHandler = $pkceDataHandler ?? new Pkce($this->dataStore);
        $this->metadata = $metadata ?? new Metadata($this->opConfigurationUrl, $this->cache, $this->httpClient);

        $this->core = $core ?? new Core(
            $this->supportedAlgorithms,
            $this->supportedSerializers,
            $this->timestampValidationLeeway,
            $this->logger,
        );
    }

    /**
     * Check if the current cache state is considered valid. Cache is valid if the cache contains metadata
     * for OIDC Configuration URL which is available in current OIDC settings.
     * If the cache is not valid (OIDC Configuration URL was changed), the cache will be reinitialized.
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
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            $error = 'Cache validation error. ' . $exception->getMessage();
            $this->logger?->error($error);
            throw new OidcClientException($error);
        }
    }

    /**
     * Send an authorization request to the authorization server using authorization code grant flow.
     *
     * @throws OidcClientException If something goes wrong :)
     */
    public function authorize(): void
    {
        $queryParameters = [
            'response_type' => 'code', // indicate authorization code grant
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
        ];

        if ($this->isStateCheckEnabled) {
            $queryParameters['state'] = $this->stateNonceDataHandler->get(StateNonce::STATE_KEY);
        }

        if ($this->isNonceCheckEnabled) {
            $queryParameters['nonce'] = $this->stateNonceDataHandler->get(StateNonce::NONCE_KEY);
        }

        if ($this->shouldUsePkce) {
            $codeChallengeMethod = $this->pkceCodeChallengeMethod;

            $codeChallenge = $this->pkceDataHandler->generateCodeChallengeFromCodeVerifier(
                $this->pkceDataHandler->getCodeVerifier(),
                $codeChallengeMethod
            );

            $queryParameters['code_challenge'] = $codeChallenge;
            $queryParameters['code_challenge_method'] = $codeChallengeMethod;
        }

        $this->logger?->debug('Authorization request', $queryParameters);

        $redirectUri = $this->metadata->get('authorization_endpoint') .
            '?' .
            http_build_query($queryParameters);

        header('Location: ' . $redirectUri);
    }

    /**
     * Get user data by performing HTTP requests to token endpoint first and
     * then using tokens to get user data.
     *
     * @return array User data.
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     */
    public function getUserData(): array
    {
        $this->validateAuthorizationResponse();

        $tokenData = $this->requestTokenData($_GET['code']);

        $this->validateTokenDataArray($tokenData);

        if ($this->shouldUsePkce) {
            // Since we got tokens, we can remove code verifier (it was validated on auth server).
            $this->pkceDataHandler->removeCodeVerifier();
        }

        return $this->getClaims($tokenData);
    }

    /**
     * @throws OidcClientException
     */
    protected function validateAuthorizationResponse(): void
    {
        if (isset($_GET['error'])) {
            $description = $_GET['error_description'] ?? '(description not provided)';
            $hint = isset($_GET['hint']) ? ' (' . $_GET['hint'] . ').' : '.';
            $message = sprintf('Authorization server returned error "%s" - %s%s', $_GET['error'], $description, $hint);
            throw new OidcClientException($message);
        }

        if ((! isset($_GET['code'])) || (! $_GET['code'])) {
            throw new OidcClientException('Not all required parameters were provided (code).');
        }

        if ($this->isStateCheckEnabled) {
            if ((! isset($_GET['state'])) || (! $_GET['state'])) {
                throw new OidcClientException('Not all required parameters were provided (state).');
            }

            $this->stateNonceDataHandler->verify(StateNonce::STATE_KEY, $_GET['state']);
        }
    }

    /**
     * Send request to token endpoint using provided authorization code, to receive tokens.
     *
     * @param string $authorizationCode
     * @return array Token data (access token, [ID token], refresh token...)
     * @throws OidcClientException
     */
    public function requestTokenData(string $authorizationCode): array
    {
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'code' => $authorizationCode,
            'redirect_uri' => $this->redirectUri,
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $headers['Authorization'] = 'Basic ' .
            base64_encode($this->clientId . ':' . $this->clientSecret);

        if ($this->shouldUsePkce) {
            $params['code_verifier'] = $this->pkceDataHandler->getCodeVerifier();
        }

        try {
            $bodyStream = Utils::streamFor(http_build_query($params));

            $tokenRequest = $this->httpRequestFactory
                ->createRequest('POST', $this->metadata->get('token_endpoint'))
                ->withBody($bodyStream);

            foreach ($headers as $key => $value) {
                $tokenRequest = $tokenRequest->withHeader($key, $value);
            }

            $response = $this->httpClient->sendRequest($tokenRequest);
            $this->validateHttpResponseOk($response);

            return $this->getDecodedHttpResponseJson($response);
        } catch (Throwable $exception) {
            throw new OidcClientException('Token data request error. ' . $exception->getMessage());
        }
    }

    /**
     * Get claims from ID token (if available) and user data 'userinfo' endpoint.
     *
     * If 'openid' scope was present in authorization request, token endpoint will return ID token.
     * In that case the claims will be extracted from ID token and combined with user claims fetched from 'userinfo'
     * endpoint. To get claims from 'userinfo' endpoint another HTTP request will be made using
     * access token for authentication.
     *
     * @param array $tokenData Array containing at least access_token, and optionally id_token.
     * @return array User data extracted from ID token (if available) or fetched from 'userinfo' endpoint.
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     */
    public function getClaims(array $tokenData): array
    {
        $this->validateTokenDataArray($tokenData);

        $idTokenClaims = [];
        $userInfoClaims = [];
        if (isset($tokenData['id_token'])) {
            $idTokenClaims = $this->getDataFromIDToken($tokenData['id_token']);
        }

        if ($this->shouldFetchUserInfoClaims) {
            $userInfoClaims = $this->requestUserDataFromUserInfoEndpoint($tokenData['access_token']);
        }

        $this->validateIdTokenAndUserInfoClaims($idTokenClaims, $userInfoClaims);

        return array_merge($idTokenClaims, $userInfoClaims);
    }

    /**
     * Validate provided ID token and get claims from it.
     *
     * @param string $idToken ID token received from token endpoint.
     * @param bool $refreshCache
     * @return array Claims from ID token
     * @throws JwsException
     * @throws OidcClientException
     * @throws InvalidValueException
     */
    public function getDataFromIDToken(string $idToken, bool $refreshCache = false): array
    {
        $jwks = $this->getJwksUriContent($refreshCache);

        try {
            $idTokenJws = $this->core->idTokenFactory()->fromToken($idToken);
        } catch (JwsException $e) {
            $error = 'Error building ID Token: ' . $e->getMessage();
            $this->logger?->error($error, ['idToken' => $idToken]);
            throw new OidcClientException($error);
        }

        try {
            $idTokenJws->verifyWithKeySet($jwks);
        } catch (Throwable $exception) {
            // If we have already refreshed our cache (we have fresh JWKS), throw...
            if ($refreshCache) {
                $this->logger?->error('ID token is not valid. ' . $exception->getMessage());
                throw new OidcClientException('ID token is not valid. ' . $exception->getMessage());
            }

            $this->logger?->warning('ID Token signature verification failed, but trying once more with JWKS refresh.');
            // Try once more with refreshing cache (fetch fresh JWKS).
            return $this->getDataFromIDToken($idToken, true);
        }

        if ($this->isNonceCheckEnabled) {
            if (($nonce = $idTokenJws->getNonce()) === null) {
                $this->logger?->error('ID token nonce not found.');
                throw new OidcClientException('Nonce parameter is not present in ID token.');
            }

            $this->stateNonceDataHandler->verify(StateNonce::NONCE_KEY, $nonce);
        }

        // JWT claims...
        return $idTokenJws->getPayload();
    }

    /**
     * @param array $jwksUriContent
     * @throws OidcClientException If JWKS URI does not contain keys
     */
    public function validateJwksUriContentArary(array $jwksUriContent): void
    {
        if (
            (! isset($jwksUriContent['keys'])) ||
            (! is_array($jwksUriContent['keys'])) ||
            (count($jwksUriContent['keys']) === 0)
        ) {
            throw new OidcClientException('JWKS URI does not contain any keys.');
        }
    }

    /**
     * Get the JWKS URI content from cache, or by fetching it from JWKS URI (making an HTTP request).
     *
     * @param bool $refreshCache Indicate if the JWKS cache value should be refreshed.
     * @return array JWKS URI content
     * @throws OidcClientException
     */
    public function getJwksUriContent(bool $refreshCache = false): array
    {
        if ($refreshCache) {
            return $this->requestJwksUriContent();
        }

        try {
            $jwksURIContent = $this->cache->get(self::CACHE_KEY_JWKS_URI_CONTENT);
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            throw new OidcClientException('JWKS URI content cache error. ' . $exception->getMessage());
        }

        if (! $jwksURIContent) {
            return $this->requestJwksUriContent();
        }

        return $jwksURIContent;
    }

    /**
     * Get content from JWKS URI and store it in cache for future use.
     *
     * @return array JWKS URI content
     * @throws OidcClientException
     */
    protected function requestJwksUriContent(): array
    {
        $jwksRequest = $this->httpRequestFactory
            ->createRequest('GET', $this->metadata->get('jwks_uri'))
            ->withHeader('Accept', 'application/json');
        try {
            $response = $this->httpClient->sendRequest($jwksRequest);
            $this->validateHttpResponseOk($response);
            $jwksUriContent = $this->getDecodedHttpResponseJson($response);
            $this->validateJwksUriContentArary($jwksUriContent);
            $this->cache->set(self::CACHE_KEY_JWKS_URI_CONTENT, $jwksUriContent, $this->defaultCacheTtl);
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            throw new OidcClientException('JWKS URI content request error. ' . $exception->getMessage());
        }

        return $jwksUriContent;
    }

    /**
     * Get user data from 'userinfo' endpoint.
     *
     * @param string $accessToken Access token used to authenticate on 'userinfo' endpoint.
     * @return array User data
     * @throws OidcClientException
     */
    public function requestUserDataFromUserInfoEndpoint(string $accessToken): array
    {
        try {
            $userInfoEndpoint = $this->metadata->get('userinfo_endpoint');

            $userInfoRequest = $this->httpRequestFactory
                ->createRequest('GET', $userInfoEndpoint)
                ->withHeader('Authorization', 'Bearer ' . $accessToken)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($userInfoRequest);
            $this->validateHttpResponseOk($response);

            $claims = $this->getDecodedHttpResponseJson($response);

            $this->validateUserInfoClaims($claims);

            return $claims;
        } catch (Throwable $exception) {
            throw new OidcClientException('UserInfo endpoint error. ' . $exception->getMessage());
        }
    }

    /**
     * @return MetadataInterface OIDC Configuration URL content (OIDC metadata).
     */
    public function getMetadata(): MetadataInterface
    {
        return $this->metadata;
    }

    /**
     * Validate $tokenData array.
     *
     * @param array $tokenData Array containing token data (access token, refresh token, ID token...).
     * @throws OidcClientException
     */
    public function validateTokenDataArray(array $tokenData): void
    {
        if (
            (! isset($tokenData['access_token'])) ||
            (!is_string($tokenData['access_token'])) ||
            $tokenData['access_token'] === ''
        ) {
            throw new OidcClientException('Token data does not contain access token value.');
        }

        if (
            (! isset($tokenData['token_type'])) ||
            (!is_string($tokenData['token_type'])) ||
            $tokenData['token_type'] === ''
        ) {
            throw new OidcClientException('Token data does not contain token type.');
        }
    }

    /**
     * Ensure that HTTP response is 200 OK
     *
     * @param ResponseInterface $response
     * @throws OidcClientException If response is not 200 OK
     */
    protected function validateHttpResponseOk(ResponseInterface $response): void
    {
        $httpStatusCode = $response->getStatusCode();
        if ($httpStatusCode !== 200) {
            throw new OidcClientException(
                sprintf('HTTP response is not valid (%s - %s)', $httpStatusCode, $response->getReasonPhrase())
            );
        }
    }

    /**
     * Get decoded JSON from the HTTP response body.
     * @param ResponseInterface $response
     * @return array Decoded JSON
     * @throws OidcClientException If the response is not valid JSON.
     */
    protected function getDecodedHttpResponseJson(ResponseInterface $response): array
    {
        try {
            $responseBody = (string) $response->getBody();
            return $this->decodeJsonOrThrow($responseBody);
        } catch (Throwable $exception) {
            $this->logger?->error(
                'JSON response body decode error: ' . $exception->getMessage(),
                ['responseBody' => $responseBody ?? null]);
            throw new OidcClientException(
                'HTTP request JSON response is not valid.'
            );
        }
    }

    /**
     * @throws OidcClientException
     */
    protected function decodeJsonOrThrow(string $json): array
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->logger?->error('JSON decode error: ' . $exception->getMessage(), ['json' => $json]);
            throw new OidcClientException('JSON decode error.');
        }
    }

    /**
     * @param array $claims
     * @throws OidcClientException
     */
    protected function validateUserInfoClaims(array $claims): void
    {
        if (! isset($claims['sub'])) {
            throw new OidcClientException('UserInfo Response does not contain mandatory sub claim.');
        }
    }

    /**
     * @param array $idTokenClaims
     * @param array $userInfoClaims
     * @throws OidcClientException
     */
    protected function validateIdTokenAndUserInfoClaims(array $idTokenClaims, array $userInfoClaims): void
    {
        if (! $this->shouldFetchUserInfoClaims) {
            return;
        }

        // Per https://openid.net/specs/openid-connect-core-1_0.html#UserInfoResponse
        if ($idTokenClaims['sub'] !== $userInfoClaims['sub']) {
            throw new OidcClientException('ID token and UserInfo sub claim must be equal.');
        }
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
