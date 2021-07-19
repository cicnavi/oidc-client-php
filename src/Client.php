<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Base64Url\Base64Url;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionDataStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Cicnavi\Oidc\Interfaces\{ConfigInterface, MetadataInterface};
use GuzzleHttp\Psr7\Utils;
use Jose\Component\Core\JWKSet;
use Jose\Easy\AbstractLoader;
use Jose\Easy\Load;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;
use Throwable;

class Client
{
    /**
     * @var ConfigInterface $config Instance which holds all OIDC configuration values.
     */
    protected ConfigInterface $config;

    /**
     * @var CacheInterface $cache Cache instance which can be used to fetch existing values instead of
     * sending HTTP requests to auth server each time.
     */
    protected CacheInterface $cache;

    /**
     * @var MetadataInterface $metadata OIDC Provider (OP) metadata. Contains items from OIDC configuration URL.
     */
    protected MetadataInterface $metadata;

    /**
     * @var ClientInterface $httpClient Helper HTTP client instance used to easily send HTTP requests.
     */
    protected ClientInterface $httpClient;

    /**
     * @var RequestFactoryInterface $httpRequestFactory Used to prepare HTTP requests
     */
    protected RequestFactoryInterface $httpRequestFactory;

    /**
     * @var string Key used to store JWKS URI content in cache.
     */
    protected const CACHE_KEY_JWKS_URI_CONTENT = 'OIDC_JWKS_URI_CONTENT';

    /**
     * @var string Key used to store OIDC configuration URL in cache.
     */
    protected const CACHE_KEY_OIDC_CONFIGURATION_URL = 'OIDC_CONFIGURATION_URL';

    /**
     * @var DataStoreInterface $dataStore Data store for State, Nonce, and PKCE parameter handling.
     */
    protected DataStoreInterface $dataStore;

    /**
     * @var PkceDataHandlerInterface $pkceDataHandler
     */
    protected PkceDataHandlerInterface $pkceDataHandler;

    /**
     * @var StateNonceDataHandlerInterface $stateNonceDataHandler
     */
    protected StateNonceDataHandlerInterface $stateNonceDataHandler;

    /**
     * Client constructor.
     * @param ConfigInterface $config
     * @param CacheInterface $cache
     * @param DataStoreInterface|null $dataStore
     * @param ClientInterface|null $httpClient
     * @param RequestFactoryInterface|null $httpRequestFactory
     * @param StateNonceDataHandlerInterface|null $stateNonceDataHandler
     * @param PkceDataHandlerInterface|null $pkceDataHandler
     * @param MetadataInterface|null $metadata
     * @throws OidcClientException If cache could not be reinitialized.
     */
    public function __construct(
        ConfigInterface $config,
        CacheInterface $cache, // TODO mivanci make caching optional
        ?DataStoreInterface $dataStore = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpRequestFactory = null,
        ?StateNonceDataHandlerInterface $stateNonceDataHandler = null,
        ?PkceDataHandlerInterface $pkceDataHandler = null,
        ?MetadataInterface $metadata = null
    ) {
        $this->config = $config;
        $this->cache = $cache;

        $this->validateCache();

        $this->dataStore = $dataStore ?? new PhpSessionDataStore();
        $this->httpClient = $httpClient ?? new \GuzzleHttp\Client();
        $this->httpRequestFactory = $httpRequestFactory ?? new RequestFactory();
        $this->stateNonceDataHandler = $stateNonceDataHandler ?? new StateNonce($this->dataStore);
        $this->pkceDataHandler = $pkceDataHandler ?? new Pkce($this->dataStore);
        $this->metadata = $metadata ?? new Metadata($config, $this->cache, $this->httpClient);
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
                $this->config->getOidcConfigurationUrl() !=
                $this->cache->get(self::CACHE_KEY_OIDC_CONFIGURATION_URL)
            ) {
                $this->cache->clear();
                $this->cache->set(self::CACHE_KEY_OIDC_CONFIGURATION_URL, $this->config->getOidcConfigurationUrl());
            }
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            throw new OidcClientException('Cache validation error. ' . $exception->getMessage());
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
            'client_id' => $this->config->getClientId(),
            'redirect_uri' => $this->config->getRedirectUri(),
            'scope' => $this->config->getScope(),
        ];

        if ($this->config->isStateCheckEnabled()) {
            $queryParameters['state'] = $this->stateNonceDataHandler->get(StateNonce::STATE_KEY);
        }

        if ($this->config->isNonceCheckEnabled()) {
            $queryParameters['nonce'] = $this->stateNonceDataHandler->get(StateNonce::NONCE_KEY);
        }

        // Web apps with backend are considered confidential, but left this choice for testing
        if (! $this->config->isConfidentialClient()) {
            $codeChallengeMethod = $this->config->getPkceCodeChallengeMethod();

            $codeChallenge = $this->pkceDataHandler->generateCodeChallengeFromCodeVerifier(
                $this->pkceDataHandler->getCodeVerifier(),
                $codeChallengeMethod
            );

            $queryParameters['code_challenge'] = $codeChallenge;
            $queryParameters['code_challenge_method'] = $codeChallengeMethod;
        }

        $redirectUri = $this->metadata->get('authorization_endpoint') .
            '?' .
            http_build_query($queryParameters);

        header('Location: ' . $redirectUri);
    }

    /**
     * Perform HTTP requests to token endpoint first and then use tokens to get user data.
     *
     * @return array User data.
     * @throws OidcClientException
     */
    public function authenticate(): array
    {
        $this->validateAuthorizationResponse();

        $tokenData = $this->requestTokenData($_GET['code']);

        $this->validateTokenDataArray($tokenData);

        if (! $this->config->isConfidentialClient()) {
            // Since we got tokens, we can remove code verifier (it was validated on auth server).
            $this->pkceDataHandler->removeCodeVerifier();
        }

        return $this->getUserData($tokenData);
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

        if ($this->config->isStateCheckEnabled()) {
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
            'client_id' => $this->config->getClientId(),
            'code' => $authorizationCode,
            'redirect_uri' => $this->config->getRedirectUri(),
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        if ($this->config->isConfidentialClient()) {
            $headers['Authorization'] = 'Basic ' .
                base64_encode($this->config->getClientId() . ':' . $this->config->getClientSecret());
        } else {
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
     * @throws OidcClientException
     */
    public function getUserData(array $tokenData): array
    {
        $this->validateTokenDataArray($tokenData);

        $claims = [];

        if (isset($tokenData['id_token'])) {
            $claims = $this->getDataFromIDToken($tokenData['id_token']);
        }

        if ($this->config->shouldFetchUserinfoClaims()) {
            $claims = array_merge($claims, $this->requestUserDataFromUserinfoEndpoint($tokenData['access_token']));
        }

        return $claims;
    }

    /**
     * Validate provided ID token and get claims from it.
     *
     * @param string $idToken ID token received from token endpoint.
     * @return array Claims from ID token
     * @throws OidcClientException
     */
    public function getDataFromIDToken(string $idToken, bool $refreshCache = false): array
    {
        $jwtLoader = $this->resolveJwtLoader($idToken);

        $jwkSet = JWKSet::createFromKeyData($this->getJwksUriContent($refreshCache));

        // TODO mivanci implement JWE support.
        // Differentiate allowed algorithms depending if it is JWS or JWE loader
//        if ($jwtLoader instanceof Validate) {
//            $jwtLoader = $jwtLoader->algs($this->config->getIdTokenValidationAllowedSignatureAlgs());
//        } else {
//            $jwtLoader = $jwtLoader->algs($this->config->getIdTokenValidationAllowedEncryptionAlgs());
//        }

        $jwtLoader = $jwtLoader->algs($this->config->getIdTokenValidationAllowedSignatureAlgs());
        $jwtLoader = $jwtLoader
            ->mandatory(['iss', 'sub', 'aud', 'exp', 'iat'])
            ->exp($this->config->getIdTokenValidationExpLeeway()) // Check "exp" claim
            ->iat($this->config->getIdTokenValidationIatLeeway()) // Check "iat" claim
            ->nbf($this->config->getIdTokenValidationNbfLeeway()) // Check "nbf" claim
            ->aud($this->config->getClientId()) // Check allowed audience
            ->iss($this->metadata->get('issuer')) // Check allowed issuer
            ->keyset($jwkSet); // Set available keys used for token signature validation

        try {
            /**
             * Psalm reports that method run() doesn't exist, however, this works fine.
             * @psalm-suppress UndefinedMethod
             */
            $jwt = $jwtLoader->run(); // Go!
        } catch (Throwable $exception) {
            // If we have already refreshed our cache (we have fresh JWKS), throw...
            if ($refreshCache) {
                throw new OidcClientException('ID token is not valid. ' . $exception->getMessage());
            }

            // Try once more with refreshing cache (fetch fresh JWKS).
            return $this->getDataFromIDToken($idToken, true);
        }

        if ($this->config->isNonceCheckEnabled()) {
            if (! $jwt->claims->has('nonce')) {
                throw new OidcClientException('Nonce parameter is not present in ID token.');
            }

            $this->stateNonceDataHandler->verify(StateNonce::NONCE_KEY, $jwt->claims->get('nonce'));
        }

        // JWT claims...
        return $jwt->claims->all();
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
            $this->cache->set(self::CACHE_KEY_JWKS_URI_CONTENT, $jwksUriContent);
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
    public function requestUserDataFromUserinfoEndpoint(string $accessToken): array
    {
        try {
            $userinfoEndpoint = $this->metadata->get('userinfo_endpoint');

            $userinfoRequest = $this->httpRequestFactory
                ->createRequest('GET', $userinfoEndpoint)
                ->withHeader('Authorization', 'Bearer ' . $accessToken)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($userinfoRequest);
            $this->validateHttpResponseOk($response);

            return $this->getDecodedHttpResponseJson($response);
        } catch (Throwable $exception) {
            throw new OidcClientException('Userinfo endpoint error. ' . $exception->getMessage());
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
        if ((! isset($tokenData['access_token'])) || empty($tokenData['access_token'])) {
            throw new OidcClientException('Token data does not contain access token value.');
        }

        if ((! isset($tokenData['token_type'])) || empty($tokenData['token_type'])) {
            throw new OidcClientException('Token data does not token type.');
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
            return $this->decodeJsonOrThrow((string) $response->getBody());
        } catch (Throwable $exception) {
            throw new OidcClientException('HTTP request JSON response is not valid.');
        }
    }

    protected function decodeJsonOrThrow(string $json): array
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new OidcClientException('JSON decode error.');
        }
    }

    /**
     * @param string $token
     * @return \Jose\Easy\Decrypt|\Jose\Easy\Validate
     * @throws OidcClientException
     */
    protected function resolveJwtLoader(string $token): AbstractLoader
    {
        // Split the JWT string into three parts
        $jwtArray = explode('.', $token);

        if (count($jwtArray) < 3) {
            throw new OidcClientException('id_token format not supported (no three parts).');
        }

        try {
            // Extract, base64 decode, then json decode it
            $jwtHeader = $this->decodeJsonOrThrow(Base64Url::decode($jwtArray[0]));
        } catch (Throwable $exception) {
            throw new OidcClientException('JWT header error. ' . $exception->getMessage());
        }

        // Check if it is JWE (header contains enc parameter).
        if (isset($jwtHeader['enc']) && (! empty($jwtHeader['enc'])) && is_string($jwtHeader['enc'])) {
            throw new OidcClientException('JWT header error. JWE support not implemented.');
            // TODO mivanci implement JWE support.
            //return Load::jwe($token);
        }

        // It is JWS.
        return Load::jws($token);
    }
}
