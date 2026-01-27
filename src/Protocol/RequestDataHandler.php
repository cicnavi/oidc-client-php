<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\ClientAssertionTypesEnum;
use SimpleSAML\OpenID\Codebooks\GrantTypesEnum;
use SimpleSAML\OpenID\Codebooks\HttpMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\JwsException;
use Throwable;

class RequestDataHandler
{
    protected StateNonceDataHandlerInterface $stateNonceDataHandler;

    protected PkceDataHandlerInterface $pkceDataHandler;

    public function __construct(
        protected readonly SessionStoreInterface $sessionStore,
        protected readonly Core $core,
        protected readonly CacheInterface $cache,
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        protected readonly GuzzleBridge $guzzleBridge = new GuzzleBridge(),
        protected readonly ClientInterface $httpClient = new \GuzzleHttp\Client(),
        ?StateNonceDataHandlerInterface $stateNonceDataHandler = null,
        ?PkceDataHandlerInterface $pkceDataHandler = null,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly \DateInterval $maxCacheDuration = new \DateInterval('PT6H'),
    ) {
        $this->stateNonceDataHandler = $stateNonceDataHandler ?? new StateNonce($this->sessionStore);
        $this->pkceDataHandler = $pkceDataHandler ?? new Pkce($this->sessionStore);
    }

    /**
     * @throws OidcClientException
     */
    public function getState(): string
    {
        return $this->stateNonceDataHandler->get(StateNonce::STATE_KEY);
    }

    /**
     * @throws OidcClientException
     */
    public function getNonce(): string
    {
        return $this->stateNonceDataHandler->get(StateNonce::NONCE_KEY);
    }

    /**
     * @throws OidcClientException
     */
    public function getCodeVerifier(): string
    {
        return $this->pkceDataHandler->getCodeVerifier();
    }

    /**
     * @throws OidcClientException
     */
    public function generateCodeChallengeFromCodeVerifier(
        string $codeVerifier,
        string $method = 'S256',
    ): string {
        return $this->pkceDataHandler->generateCodeChallengeFromCodeVerifier($codeVerifier, $method);
    }



    /**
     * Get user data by performing an HTTP request to a token endpoint first
     *  and then to the userinfo endpoint using tokens to get user data.
     *
     * @return mixed[] User data.
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     */
    public function getUserData(
        ClientAuthenticationEnum $clientAuthentication,
        string $tokenEndpoint,
        string $clientId,
        string $redirectUri,
        string $jwksUri,
        ?string $userInfoEndpoint = null,
        ?string $clientSecret = null, // For client_secret_basic client authentication
        ?string $clientAssertion = null, // For private_key_jwt client authentication
        bool $usePkce = true,
        bool $fetchUserInfoClaims = true,
        ?ServerRequestInterface $request = null,
    ): array {
        $params = $this->validateAuthorizationCallbackResponse($request);

        $authorizationCode = $params[ParamsEnum::Code->value];

        $tokenData = $this->requestTokenData(
            $clientAuthentication,
            $tokenEndpoint,
            $authorizationCode,
            $clientId,
            $redirectUri,
            $clientSecret,
            $clientAssertion,
            $usePkce,
        );

        $tokenData = $this->validateTokenDataArray($tokenData);

        if ($usePkce) {
            // Since we got tokens, we can remove the code verifier (it was validated on auth server).
            $this->pkceDataHandler->removeCodeVerifier();
        }

        return $this->getClaims(
            $tokenData,
            $jwksUri,
            $userInfoEndpoint,
            $fetchUserInfoClaims
        );
    }

    /**
     * @return array{
     *     code: non-empty-string,
     *     state: ($useState is true ? non-empty-string : null)
     * }
     * @throws OidcClientException
     */
    public function validateAuthorizationCallbackResponse(
        ?ServerRequestInterface $request = null,
        bool $useState = true,
    ): array {
        $params = $request?->getQueryParams() ?? $_GET;

        $error = $params[ParamsEnum::Error->value] ?? null;
        $errorDescription = $params[ParamsEnum::ErrorDescription->value] ?? null;
        $hint = $params[ParamsEnum::Hint->value] ?? null;

        if (is_string($error)) {
            $description = is_string($errorDescription) ? $errorDescription : '(description not provided)';
            $hint = is_string($hint) ? ' (' . $hint . ').' : '.';
            $message = sprintf('Authorization server returned error "%s" - %s%s', $error, $description, $hint);
            throw new OidcClientException($message);
        }

        $code = $params[ParamsEnum::Code->value] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OidcClientException('Not all required parameters were provided (code).');
        }

        $returnParams = [
            ParamsEnum::Code->value => $code,
            ParamsEnum::State->value => null,
        ];

        if ($useState) {
            $state = $params[ParamsEnum::State->value] ?? null;
            if (!is_string($state) || $state === '') {
                throw new OidcClientException('Not all required parameters were provided (state).');
            }

            $this->stateNonceDataHandler->verify(StateNonce::STATE_KEY, $state);
            $returnParams[ParamsEnum::State->value] = $state;
        }

        return $returnParams;
    }

    /**
 * Send request to token endpoint using provided authorization code to
     * receive tokens.
 *
 * @return mixed[] Token data (access token, [ID token], refresh token...)
 * @throws OidcClientException
 */
    public function requestTokenData(
        ClientAuthenticationEnum $clientAuthentication,
        string $tokenEndpoint,
        string $authorizationCode,
        string $clientId,
        string $redirectUri,
        ?string $clientSecret = null, // For client_secret_basic client authentication
        ?string $clientAssertion = null, // For private_key_jwt client authentication
        bool $usePkce = true,
    ): array {
        $params = [
            ParamsEnum::GrantType->value => GrantTypesEnum::AuthorizationCode->value,
            ParamsEnum::ClientId->value => $clientId,
            ParamsEnum::Code->value => $authorizationCode,
            ParamsEnum::RedirectUri->value => $redirectUri,
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        if ($clientAuthentication === ClientAuthenticationEnum::ClientSecretBasic) {
            if (!is_string($clientSecret)) {
                throw new OidcClientException(
                    'Client secret must be provided for client authentication method "client_secret_basic".',
                );
            }

            $headers['Authorization'] = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
        }

        if ($clientAuthentication === ClientAuthenticationEnum::PrivateKeyJwt) {
            if (!is_string($clientAssertion)) {
                throw new OidcClientException(
                    'Client assertion must be provided for client authentication method "private_key_jwt".',
                );
            }

            $params[ParamsEnum::ClientAssertionType->value] = ClientAssertionTypesEnum::JwtBaerer->value;
            $params[ParamsEnum::ClientAssertion->value] = $clientAssertion;
        }

        if ($usePkce) {
            $params[ParamsEnum::CodeVerifier->value] = $this->pkceDataHandler->getCodeVerifier();
        }

        try {
            $bodyStream = $this->guzzleBridge->psr7StreamFor(http_build_query($params));

            $tokenRequest = $this->httpRequestFactory
                ->createRequest(HttpMethodsEnum::POST->value, $tokenEndpoint)
                ->withBody($bodyStream);

            foreach ($headers as $key => $value) {
                $tokenRequest = $tokenRequest->withHeader($key, $value);
            }

            $response = $this->httpClient->sendRequest($tokenRequest);

            $this->validateHttpResponseOk($response);

            return $this->getDecodedHttpResponseJson($response);
        } catch (Throwable $throwable) {
            throw new OidcClientException('Token data request error. ' . $throwable->getMessage());
        }
    }

    /**
     * Ensure that HTTP response is 200 OK
     *
     * @throws OidcClientException If response is not 200 OK
     */
    public function validateHttpResponseOk(ResponseInterface $response): void
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
     * @return mixed[] Decoded JSON
     * @throws OidcClientException If the response is not valid JSON.
     */
    public function getDecodedHttpResponseJson(ResponseInterface $response): array
    {
        try {
            $responseBody = (string) $response->getBody();
            return $this->decodeJsonOrThrow($responseBody);
        } catch (Throwable $throwable) {
            $this->logger?->error(
                'JSON response body decode error: ' . $throwable->getMessage(),
                ['responseBody' => $responseBody ?? null]
            );
            throw new OidcClientException(
                'HTTP request JSON response is not valid.'
            );
        }
    }

    /**
     * @return mixed[] Decoded JSON from the provided string.
     * @throws OidcClientException
     */
    protected function decodeJsonOrThrow(string $json): array
    {
        try {
            if (!is_array($decodedJson = json_decode($json, true, 512, JSON_THROW_ON_ERROR))) {
                throw new OidcClientException('JSON decode error.');
            }

            return $decodedJson;
        } catch (Throwable $throwable) {
            $this->logger?->error('JSON decode error: ' . $throwable->getMessage(), ['json' => $json]);
            throw new OidcClientException('JSON decode error.');
        }
    }

    /**
     * Validate $tokenData array.
     *
     * @param mixed[] $tokenData Array containing token data (access token,
     * refresh token, ID token...).
     * @return array{
     *     access_token: non-empty-string,
     *     token_type: non-empty-string,
     *     id_token: ?non-empty-string,
     * }
     * @throws OidcClientException
     */
    public function validateTokenDataArray(array $tokenData): array
    {
        $returnArray = [
            ParamsEnum::IdToken->value => null,
        ];

        $accessToken = $tokenData['access_token'] ?? null;
        if ((!is_string($accessToken)) || $accessToken === '') {
            throw new OidcClientException('Token data does not contain access token value.');
        }

        $returnArray[ParamsEnum::AccessToken->value] = $accessToken;

        $tokenType = $tokenData[ParamsEnum::TokenType->value] ?? null;
        if ((!is_string($tokenType)) || $tokenType === '') {
            throw new OidcClientException('Token data does not contain token type.');
        }

        $returnArray[ParamsEnum::TokenType->value] = $tokenType;

        $idToken = $tokenData[ParamsEnum::IdToken->value] ?? null;
        if ($idToken === '') {
            throw new OidcClientException('Token data contains invalid ID token value.');
        }

        if (is_string($idToken)) {
            $returnArray[ParamsEnum::IdToken->value] = $idToken;
        }

        return $returnArray;
    }

    /**
     * Get claims from ID token (if available) and user data 'userinfo' endpoint.
     *
     * If the 'openid' scope was present in the authorization request, the token
     * endpoint will return ID token. In that case the claims will be extracted
     * from ID token and combined with user claims fetched from the 'userinfo'
     * endpoint. To get claims from the 'userinfo' endpoint, another HTTP
     * request will be made using an access token for authentication.
     *
     * @param array{
     *      access_token: non-empty-string,
     *      token_type: non-empty-string,
     *      id_token: ?non-empty-string,
     *  } $tokenData Array containing at least access_token and optionally id_token.
     * @return mixed[] User data extracted from ID token (if available) or fetched from the 'userinfo' endpoint.
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     */
    public function getClaims(
        array $tokenData,
        string $jwksUri,
        ?string $userInfoEndpoint = null,
        bool $useNonce = true,
        bool $fetchUserInfoClaims = true,
    ): array {
        $idTokenClaims = [];
        $userInfoClaims = [];

        $idToken = $tokenData[ParamsEnum::IdToken->value] ?? null;
        if (is_string($idToken)) {
            $idTokenClaims = $this->getDataFromIDToken($idToken, $jwksUri, $useNonce);
        }

        $accessToken = $tokenData[ParamsEnum::AccessToken->value];
        if ($fetchUserInfoClaims && is_string($userInfoEndpoint)) {
            $userInfoClaims = $this->requestUserDataFromUserInfoEndpoint(
                $accessToken,
                $userInfoEndpoint,
            );

            $this->validateIdTokenAndUserInfoClaims($idTokenClaims, $userInfoClaims);
        }

        return array_merge($idTokenClaims, $userInfoClaims);
    }

    /**
     * Validate provided ID token and get claims from it.
     *
     * @param string $idToken ID token received from token endpoint.
     * @return mixed[] Claims from ID token
     * @throws JwsException
     * @throws OidcClientException
     * @throws InvalidValueException
     */
    public function getDataFromIDToken(
        string $idToken,
        string $jwksUri,
        bool $useNonce = true,
        bool $refreshCache = false,
    ): array {
        $jwks = $this->getJwksUriContent($jwksUri, $refreshCache);

        try {
            $idTokenJws = $this->core->idTokenFactory()->fromToken($idToken);
        } catch (JwsException $jwsException) {
            $error = 'Error building ID Token: ' . $jwsException->getMessage();
            $this->logger?->error($error, ['idToken' => $idToken]);
            throw new OidcClientException($error);
        }

        try {
            $idTokenJws->verifyWithKeySet($jwks);
        } catch (Throwable $throwable) {
            // If we have already refreshed our cache (we have fresh JWKS), throw...
            if ($refreshCache) {
                $this->logger?->error('ID token is not valid. ' . $throwable->getMessage());
                throw new OidcClientException('ID token is not valid. ' . $throwable->getMessage());
            }

            $this->logger?->warning('ID Token signature verification failed, but trying once more with JWKS refresh.');
            // Try once more with refreshing cache (fetch fresh JWKS).
            return $this->getDataFromIDToken(
                $idToken,
                $jwksUri,
                $useNonce,
                true,
            );
        }

        if ($useNonce) {
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
     * Get the JWKS URI content from cache or by fetching it from JWKS URI
     * (making an HTTP request).
     *
     * @param bool $refreshCache Indicate if the JWKS cache value should be refreshed.
     * @return mixed[] JWKS URI content
     * @throws OidcClientException
     */
    public function getJwksUriContent(
        string $jwksUri,
        bool $refreshCache = false
    ): array {
        if ($refreshCache) {
            return $this->requestJwksUriContent($jwksUri);
        }

        try {
            $jwksURIContent = $this->cache->get($jwksUri);
        } catch (Throwable $throwable) {
            throw new OidcClientException('JWKS URI content cache error. ' . $throwable->getMessage());
        }

        if (is_array($jwksURIContent)) {
            return $jwksURIContent;
        }

        return $this->requestJwksUriContent($jwksUri);
    }

    /**
     * Get content from JWKS URI and store it in cache for future use.
     *
     * @return mixed[] JWKS URI content
     * @throws OidcClientException
     */
    protected function requestJwksUriContent(
        string $jwksUri,
    ): array {
        $jwksRequest = $this->httpRequestFactory
            ->createRequest('GET', $jwksUri)
            ->withHeader('Accept', 'application/json');
        try {
            $response = $this->httpClient->sendRequest($jwksRequest);
            $this->validateHttpResponseOk($response);
            $jwksUriContent = $this->getDecodedHttpResponseJson($response);
            $this->validateJwksUriContentArary($jwksUriContent);
            $this->cache->set($jwksUri, $jwksUriContent, $this->maxCacheDuration);
        } catch (Throwable $throwable) {
            throw new OidcClientException('JWKS URI content request error. ' . $throwable->getMessage());
        }

        return $jwksUriContent;
    }

    /**
     * @param mixed[] $jwksUriContent
     * @throws OidcClientException If JWKS URI does not contain keys
     */
    public function validateJwksUriContentArary(array $jwksUriContent): void
    {
        if (
            (! isset($jwksUriContent['keys'])) ||
            (! is_array($jwksUriContent['keys'])) ||
            ($jwksUriContent['keys'] === [])
        ) {
            throw new OidcClientException('JWKS URI does not contain any keys.');
        }
    }

    /**
     * Get user data from 'userinfo' endpoint.
     *
     * @param string $accessToken Access token used to authenticate on 'userinfo' endpoint.
     * @return mixed[] User data
     * @throws OidcClientException
     */
    public function requestUserDataFromUserInfoEndpoint(
        string $accessToken,
        string $userInfoEndpoint,
    ): array {
        try {
            $userInfoRequest = $this->httpRequestFactory
                ->createRequest(HttpMethodsEnum::GET->value, $userInfoEndpoint)
                ->withHeader('Authorization', 'Bearer ' . $accessToken)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($userInfoRequest);
            $this->validateHttpResponseOk($response);

            $claims = $this->getDecodedHttpResponseJson($response);

            $this->validateUserInfoClaims($claims);

            return $claims;
        } catch (Throwable $throwable) {
            throw new OidcClientException('UserInfo endpoint error. ' . $throwable->getMessage());
        }
    }

    /**
     * @param mixed[] $claims
     * @throws OidcClientException
     */
    protected function validateUserInfoClaims(array $claims): void
    {
        if (! isset($claims[ClaimsEnum::Sub->value])) {
            throw new OidcClientException('UserInfo Response does not contain mandatory sub claim.');
        }
    }

    /**
     * @param mixed[] $idTokenClaims
     * @param mixed[] $userInfoClaims
     * @throws OidcClientException
     */
    protected function validateIdTokenAndUserInfoClaims(
        array $idTokenClaims,
        array $userInfoClaims,
    ): void {
        // Per https://openid.net/specs/openid-connect-core-1_0.html#UserInfoResponse
        if ($idTokenClaims[ClaimsEnum::Sub->value] !== $userInfoClaims[ClaimsEnum::Sub->value]) {
            throw new OidcClientException('ID token and UserInfo sub claim must be equal.');
        }
    }
}
