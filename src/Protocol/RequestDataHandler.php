<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\ClientAssertionTypesEnum;
use SimpleSAML\OpenID\Codebooks\ClientAuthenticationMethodsEnum;
use SimpleSAML\OpenID\Codebooks\GrantTypesEnum;
use SimpleSAML\OpenID\Codebooks\HttpMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Jwks;
use Throwable;

/**
 * @see \Cicnavi\Tests\Oidc\Protocol\RequestDataHandlerTest
 */
class RequestDataHandler
{
    public const KEY_OP_METADATA_FOR_STATE = 'op_metadata_for_state_';

    public const KEY_REDIRECT_URI_FOR_STATE_ = 'redirect_uri_for_state_';

    /**
     * Session store key under which login data needed for logout (raw ID
     * token, its iss / sub / sid claims, OP end session endpoint) is
     * persisted after a successful login.
     */
    public const KEY_LOGIN_DATA = 'oidc_login_data';

    protected StateNonceDataHandlerInterface $stateNonceDataHandler;

    protected PkceDataHandlerInterface $pkceDataHandler;

    public function __construct(
        protected readonly SessionStoreInterface $sessionStore,
        protected readonly Core $core,
        protected readonly CacheInterface $cache,
        protected readonly Jwks $jwks,
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        protected readonly GuzzleBridge $guzzleBridge = new GuzzleBridge(),
        protected readonly Client $httpClient = new Client(),
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
        PkceCodeChallengeMethodEnum $pkceCodeChallengeMethodEnum = PkceCodeChallengeMethodEnum::S256,
    ): string {
        return $this->pkceDataHandler->generateCodeChallengeFromCodeVerifier(
            $codeVerifier,
            $pkceCodeChallengeMethodEnum,
        );
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
        ClientAuthenticationMethodsEnum $clientAuthenticationMethod,
        string $authorizationCode,
        string $clientId,
        string $clientRedirectUri,
        string $opJwksUri,
        string $opTokenEndpoint,
        ?string $opUserinfoEndpoint = null,
        ?string $clientSecret = null, // For client_secret_basic client authentication
        ?string $clientAssertion = null, // For private_key_jwt client authentication
        bool $usePkce = true,
        bool $useNonce = true,
        bool $fetchUserinfoClaims = true,
        ?string $expectedIssuer = null,
        ?string $opEndSessionEndpoint = null,
    ): array {

        $tokenData = $this->requestTokenData(
            clientAuthenticationMethod: $clientAuthenticationMethod,
            tokenEndpoint: $opTokenEndpoint,
            authorizationCode: $authorizationCode,
            clientId: $clientId,
            redirectUri: $clientRedirectUri,
            clientSecret: $clientSecret,
            clientAssertion: $clientAssertion,
            usePkce: $usePkce,
        );

        $tokenData = $this->validateTokenDataArray($tokenData);

        if ($usePkce) {
            // Since we got tokens, we can remove the code verifier (it was validated on auth server).
            $this->pkceDataHandler->removeCodeVerifier();
        }

        $claims = $this->getClaims(
            tokenData: $tokenData,
            jwksUri: $opJwksUri,
            userinfoEndpoint: $opUserinfoEndpoint,
            useNonce: $useNonce,
            fetchUserinfoClaims: $fetchUserinfoClaims,
            expectedIssuer: $expectedIssuer,
            expectedClientId: $clientId,
        );

        $this->storeLoginData(
            $tokenData[ParamsEnum::IdToken->value],
            $opEndSessionEndpoint,
            $clientId,
        );

        return $claims;
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
        $queryParams = $request?->getQueryParams() ?? $_GET;
        $parsedBody = $request?->getParsedBody() ?? $_POST;
        $params = array_merge(
            $queryParams,
            is_array($parsedBody) ? $parsedBody : []
        );

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
        ClientAuthenticationMethodsEnum $clientAuthenticationMethod,
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

        $clientAuthentication = $this->buildClientAuthentication(
            $clientAuthenticationMethod,
            $clientId,
            $clientSecret,
            $clientAssertion,
        );
        $params = array_merge($params, $clientAuthentication['params']);
        $headers = array_merge($headers, $clientAuthentication['headers']);

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
            throw new OidcClientException(
                'Token data request error. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Build client authentication params/headers for a back-channel request
     * (token endpoint, PAR endpoint...). Supports the same methods as the token
     * endpoint: 'client_secret_basic' (Authorization header) and
     * 'private_key_jwt' (client_assertion params).
     *
     * @return array{params: array<string,string>, headers: array<string,string>}
     * @throws OidcClientException
     */
    protected function buildClientAuthentication(
        ClientAuthenticationMethodsEnum $clientAuthenticationMethod,
        string $clientId,
        ?string $clientSecret,
        ?string $clientAssertion,
    ): array {
        $params = [];
        $headers = [];

        if ($clientAuthenticationMethod === ClientAuthenticationMethodsEnum::ClientSecretBasic) {
            if (!is_string($clientSecret)) {
                throw new OidcClientException(
                    'Client secret must be provided for client authentication method "client_secret_basic".',
                );
            }

            // Per RFC 6749 section 2.3.1, the client ID and secret must be
            // form-urlencoded before being used as Basic auth credentials.
            $headers['Authorization'] = 'Basic ' .
            base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret));
        }

        if ($clientAuthenticationMethod === ClientAuthenticationMethodsEnum::PrivateKeyJwt) {
            if (!is_string($clientAssertion)) {
                throw new OidcClientException(
                    'Client assertion must be provided for client authentication method "private_key_jwt".',
                );
            }

            $params[ParamsEnum::ClientAssertionType->value] = ClientAssertionTypesEnum::JwtBaerer->value;
            $params[ParamsEnum::ClientAssertion->value] = $clientAssertion;
        }

        return ['params' => $params, 'headers' => $headers];
    }

    /**
     * Decide whether Pushed Authorization Requests (PAR, RFC 9126) should be
     * used for the given OP and, if so, return the PAR endpoint to push to.
     *
     * @param array<string,mixed> $opMetadata Resolved OP metadata. The keys of
     * interest are 'pushed_authorization_request_endpoint' and
     * 'require_pushed_authorization_requests'.
     * @return ?non-empty-string The PAR endpoint to push to, or null when PAR
     * should not be used for this OP under the given mode.
     * @throws OidcClientException When PAR must be used but the OP does not
     * advertise a 'pushed_authorization_request_endpoint'.
     */
    public function resolvePushedAuthorizationRequestEndpoint(
        array $opMetadata,
        ParModeEnum $parMode,
    ): ?string {
        if ($parMode === ParModeEnum::Off) {
            return null;
        }

        $endpoint = $opMetadata[ClaimsEnum::PushedAuthorizationRequestEndpoint->value] ?? null;
        $endpoint = (is_string($endpoint) && $endpoint !== '') ? $endpoint : null;

        $opRequiresPar =
        ($opMetadata[ClaimsEnum::RequirePushedAuthorizationRequests->value] ?? false) === true;

        // In 'auto' mode PAR is used only when the OP requires it.
        if ($parMode === ParModeEnum::Auto && !$opRequiresPar) {
            return null;
        }

        // From here PAR must be used (mode 'required', or 'auto' and the OP
        // requires it). A PAR endpoint is therefore mandatory.
        if ($endpoint === null) {
            throw new OidcClientException(
                'Pushed Authorization Requests must be used for this OpenID Provider, but it does not ' .
                'advertise a "pushed_authorization_request_endpoint".',
            );
        }

        return $endpoint;
    }

    /**
     * Push an authorization request (RFC 9126) to the OP's PAR endpoint and
     * return the resulting one-time 'request_uri'.
     *
     * The request is a back-channel POST (application/x-www-form-urlencoded)
     * carrying every authorization-request parameter the RP would otherwise put
     * in the front-channel redirect, plus client authentication (the same
     * methods as the token endpoint). The 'request_uri' parameter MUST NOT be
     * part of the pushed parameters.
     *
     * @param array<string,string> $parameters Authorization-request parameters
     * to push (response_type, redirect_uri, scope, state, nonce,
     * code_challenge...). 'client_id' is set from $clientId; any 'request_uri'
     * is rejected.
     * @return array{request_uri: non-empty-string, expires_in: int}
     * @throws OidcClientException
     */
    public function pushAuthorizationRequest(
        ClientAuthenticationMethodsEnum $clientAuthenticationMethod,
        string $pushedAuthorizationRequestEndpoint,
        array $parameters,
        string $clientId,
        ?string $clientSecret = null, // For client_secret_basic client authentication
        ?string $clientAssertion = null, // For private_key_jwt client authentication
    ): array {
        if (array_key_exists(ParamsEnum::RequestUri->value, $parameters)) {
            throw new OidcClientException(
                'The "request_uri" parameter must not be sent in a pushed authorization request.',
            );
        }

        // client_id is a required authorization-request parameter in the PAR body.
        $parameters[ParamsEnum::ClientId->value] = $clientId;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $clientAuthentication = $this->buildClientAuthentication(
            $clientAuthenticationMethod,
            $clientId,
            $clientSecret,
            $clientAssertion,
        );
        $parameters = array_merge($parameters, $clientAuthentication['params']);
        $headers = array_merge($headers, $clientAuthentication['headers']);

        try {
            $bodyStream = $this->guzzleBridge->psr7StreamFor(http_build_query($parameters));

            $parRequest = $this->httpRequestFactory
                ->createRequest(HttpMethodsEnum::POST->value, $pushedAuthorizationRequestEndpoint)
                ->withBody($bodyStream);

            foreach ($headers as $key => $value) {
                $parRequest = $parRequest->withHeader($key, $value);
            }

            $response = $this->httpClient->sendRequest($parRequest);

            $this->validatePushedAuthorizationResponse($response);

            return $this->validatePushedAuthorizationResponseData(
                $this->getDecodedHttpResponseJson($response),
            );
        } catch (OidcClientException $oidcClientException) {
            // Already a meaningful error (rejection, invalid response...); surface as-is.
            throw $oidcClientException;
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'Pushed authorization request error. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Ensure that the PAR endpoint response indicates success (HTTP 201).
     * Errors are returned in the token-endpoint error format (JSON), never as a
     * redirect, so surface 'error' / 'error_description' when present.
     *
     * @throws OidcClientException If the response does not indicate success.
     */
    public function validatePushedAuthorizationResponse(ResponseInterface $response): void
    {
        $httpStatusCode = $response->getStatusCode();
        if ($httpStatusCode === 201) {
            return;
        }

        $message = sprintf(
            'Pushed authorization request was not successful (HTTP %s - %s).',
            $httpStatusCode,
            $response->getReasonPhrase(),
        );

        try {
            $errorData = $this->decodeJsonOrThrow((string) $response->getBody());
            $error = $errorData[ParamsEnum::Error->value] ?? null;
            $errorDescription = $errorData[ParamsEnum::ErrorDescription->value] ?? null;
            if (is_string($error)) {
                $message = sprintf(
                    'Pushed authorization request rejected by OpenID Provider - error "%s"%s',
                    $error,
                    is_string($errorDescription) ? ': ' . $errorDescription . '.' : '.',
                );
            }
        } catch (Throwable) {
            // Body was not a JSON error object; keep the generic status message.
        }

        $this->logger?->error($message);
        throw new OidcClientException($message);
    }

    /**
     * Validate the decoded PAR success response body.
     *
     * @param mixed[] $data Decoded JSON from the PAR endpoint response.
     * @return array{request_uri: non-empty-string, expires_in: int}
     * @throws OidcClientException
     */
    public function validatePushedAuthorizationResponseData(array $data): array
    {
        $requestUri = $data[ParamsEnum::RequestUri->value] ?? null;
        if (!is_string($requestUri) || $requestUri === '') {
            throw new OidcClientException(
                'Pushed authorization response does not contain a valid "request_uri".',
            );
        }

        // expires_in is informational for the RP (the redirect is issued
        // immediately), so treat it as best-effort.
        $expiresIn = $data[ParamsEnum::ExpiresIn->value] ?? null;
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 0;

        return [
            ParamsEnum::RequestUri->value => $requestUri,
            ParamsEnum::ExpiresIn->value => $expiresIn,
        ];
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
        $responseBody = (string) $response->getBody();
        try {
            return $this->decodeJsonOrThrow($responseBody);
        } catch (Throwable $throwable) {
            $this->logger?->error(
                'JSON response body decode error: ' . $throwable->getMessage(),
                ['responseBody' => $responseBody]
            );
            throw new OidcClientException(
                'HTTP request JSON response is not valid.',
                $throwable->getCode(),
                $throwable,
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
        } catch (Throwable) {
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
        ?string $userinfoEndpoint = null,
        bool $useNonce = true,
        bool $fetchUserinfoClaims = true,
        ?string $expectedIssuer = null,
        ?string $expectedClientId = null,
    ): array {
        $idTokenClaims = [];
        $userInfoClaims = [];

        $idToken = $tokenData[ParamsEnum::IdToken->value] ?? null;
        if (is_string($idToken)) {
            $idTokenClaims = $this->getDataFromIdToken(
                idToken: $idToken,
                jwksUri: $jwksUri,
                useNonce: $useNonce,
                refreshCache: false,
                expectedIssuer: $expectedIssuer,
                expectedClientId: $expectedClientId,
            );
        }

        $accessToken = $tokenData[ParamsEnum::AccessToken->value];
        if ($fetchUserinfoClaims && is_string($userinfoEndpoint)) {
            $userInfoClaims = $this->requestUserDataFromUserInfoEndpoint(
                accessToken: $accessToken,
                userInfoEndpoint: $userinfoEndpoint,
            );

            $this->validateIdTokenAndUserinfoClaims($idTokenClaims, $userInfoClaims);
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
    public function getDataFromIdToken(
        string $idToken,
        string $jwksUri,
        bool $useNonce = true,
        bool $refreshCache = false,
        ?string $expectedIssuer = null,
        ?string $expectedClientId = null,
    ): array {
        $jwks = $this->getJwksUriContent($jwksUri, $refreshCache);

        try {
            $idTokenJws = $this->core->idTokenFactory()->fromToken($idToken);
        } catch (JwsException $jwsException) {
            $error = 'Error building ID Token: ' . $jwsException->getMessage();
            $this->logger?->error($error, ['idToken' => $idToken]);
            throw new OidcClientException($error, $jwsException->getCode(), $jwsException);
        }

        try {
            $idTokenJws->verifyWithKeySet($jwks);
        } catch (Throwable $throwable) {
            // If we have already refreshed our cache (we have fresh JWKS), throw...
            if ($refreshCache) {
                $this->logger?->error('ID token is not valid. ' . $throwable->getMessage());
                throw new OidcClientException(
                    'ID token is not valid. ' . $throwable->getMessage(),
                    $throwable->getCode(),
                    $throwable,
                );
            }

            $this->logger?->warning('ID Token signature verification failed, but trying once more with JWKS refresh.');
            // Try once more with refreshing cache (fetch fresh JWKS).
            return $this->getDataFromIdToken(
                idToken: $idToken,
                jwksUri: $jwksUri,
                useNonce: $useNonce,
                refreshCache: true,
                expectedIssuer: $expectedIssuer,
                expectedClientId: $expectedClientId,
            );
        }

        // Validate Issuer (iss)
        if ($expectedIssuer !== null) {
            $iss = $idTokenJws->getIssuer();
            if ($iss !== $expectedIssuer) {
                $error = sprintf('Issuer claim "%s" does not match expected issuer "%s".', $iss, $expectedIssuer);
                $this->logger?->error($error);
                throw new OidcClientException($error);
            }
        }

        // Validate Audience (aud) and Authorized Party (azp)
        if ($expectedClientId !== null) {
            $aud = $idTokenJws->getAudience();
            if ($aud !== [] && !in_array($expectedClientId, $aud, true)) {
                $error = sprintf('Audience claim does not contain expected client ID "%s".', $expectedClientId);
                $this->logger?->error($error);
                throw new OidcClientException($error);
            }

            if (count($aud) > 1) {
                $azp = $idTokenJws->getAuthorizedParty();
                if ($azp === null) {
                    $error = 'Authorized party claim (azp) is missing but multiple audiences are present.';
                    $this->logger?->error($error);
                    throw new OidcClientException($error);
                }

                if ($azp !== $expectedClientId) {
                    $error = sprintf(
                        'Authorized party claim "%s" does not match expected client ID "%s".',
                        $azp,
                        $expectedClientId,
                    );
                    $this->logger?->error($error);
                    throw new OidcClientException($error);
                }
            }
        }

        // Validate Expiration Time (exp)
        $exp = $idTokenJws->getExpirationTime();
        if ($exp > 0 && time() > $exp) {
            $error = 'ID Token has expired.';
            $this->logger?->error($error);
            throw new OidcClientException($error);
        }

        // Validate Issued At (iat)
        $iat = $idTokenJws->getIssuedAt();
        // Allow a small clock skew (e.g. 5 minutes or 300 seconds)
        if ($iat > 0 && time() < ($iat - 300)) {
            $error = 'ID Token was issued in the future.';
            $this->logger?->error($error);
            throw new OidcClientException($error);
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
     * @return array{keys:array<array<string,mixed>>} JWKS URI content
     * @throws OidcClientException
     */
    public function getJwksUriContent(
        string $jwksUri,
        bool $refreshCache = false
    ): array {
        if ($refreshCache) {
            $jwks = $this->jwks->jwksFetcher()->fromJwksUri($jwksUri)?->jsonSerialize();
            if (!is_array($jwks)) {
                $this->logger?->error(
                    'No JWKS content from JWKS URI.',
                    ['jwksUri' => $jwksUri, 'refreshCache' => $refreshCache],
                );
                throw new OidcClientException('Invalid JWKS URI content.');
            }
        }

        $jwks = $this->jwks->jwksFetcher()->fromCacheOrJwksUri($jwksUri)?->jsonSerialize();

        if (!is_array($jwks)) {
            $this->logger?->error(
                'No JWKS content from cache or JWKS URI.',
                ['jwksUri' => $jwksUri, 'refreshCache' => $refreshCache],
            );

            throw new OidcClientException('Invalid JWKS URI content.');
        }

        return $jwks;
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

            $this->validateUserinfoClaims($claims);

            return $claims;
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'UserInfo endpoint error. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * @param mixed[] $claims
     * @throws OidcClientException
     */
    protected function validateUserinfoClaims(array $claims): void
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
    protected function validateIdTokenAndUserinfoClaims(
        array $idTokenClaims,
        array $userInfoClaims,
    ): void {
        if ($idTokenClaims === []) {
            return;
        }

        // Per https://openid.net/specs/openid-connect-core-1_0.html#UserInfoResponse
        if ($idTokenClaims[ClaimsEnum::Sub->value] !== $userInfoClaims[ClaimsEnum::Sub->value]) {
            throw new OidcClientException('ID token and UserInfo sub claim must be equal.');
        }
    }

    /**
     * @param mixed[] $opMetadata
     */
    public function setResolvedOpMetadataForState(string $state, array $opMetadata): void
    {
        $this->sessionStore->put(self::KEY_OP_METADATA_FOR_STATE . $state, $opMetadata);
    }

    /**
     * @return mixed[]
     * @throws OidcClientException
     */
    public function getResolvedOpMetadataForState(string $state): array
    {
        $resolvedOpMetadata = $this->sessionStore->get(self::KEY_OP_METADATA_FOR_STATE . $state);

        if (is_array($resolvedOpMetadata)) {
            $this->sessionStore->delete(self::KEY_OP_METADATA_FOR_STATE . $state);
            return $resolvedOpMetadata;
        }

        throw new OidcClientException('Resolved OP metadata not found for state "' . $state . '".');
    }

    public function setClientRedirectUriForState(string $state, string $redirectUri): void
    {
        $this->sessionStore->put(self::KEY_REDIRECT_URI_FOR_STATE_ . $state, $redirectUri);
    }

    /**
     * @throws OidcClientException
     */
    public function getClientRedirectUriForState(string $state): string
    {
        $redirectUri = $this->sessionStore->get(self::KEY_REDIRECT_URI_FOR_STATE_ . $state);

        if (is_string($redirectUri)) {
            $this->sessionStore->delete(self::KEY_REDIRECT_URI_FOR_STATE_ . $state);
            return $redirectUri;
        }

        throw new OidcClientException('Redirect URI not found for state "' . $state . '".');
    }

    /**
     * Persist login data needed for logout in the session store: the raw ID
     * token (used as 'id_token_hint' in RP-Initiated Logout), its 'iss',
     * 'sub' and 'sid' claims (used to correlate OIDC Back-Channel Logout
     * requests with this login), the OP's end session endpoint (so logout
     * can be performed even when OP metadata is no longer at hand, e.g. for
     * OPs resolved per authorization flow), and the client ID the login was
     * performed with (so the logout request 'client_id' matches the
     * 'id_token_hint' even if the client registration changes in the
     * meantime, e.g. for dynamically registered clients).
     *
     * Claim extraction is best-effort: the ID token was already validated
     * during login, so an extraction error is only logged and the raw ID
     * token is stored anyway.
     */
    public function storeLoginData(
        ?string $idToken,
        ?string $opEndSessionEndpoint = null,
        ?string $clientId = null,
    ): void {
        $claims = [];

        if (is_string($idToken)) {
            try {
                $claims = $this->core->idTokenFactory()->fromToken($idToken)->getPayload();
            } catch (Throwable $throwable) {
                $this->logger?->warning(
                    'Error extracting claims from ID token while storing login data. ' . $throwable->getMessage(),
                );
            }
        }

        $this->sessionStore->put(self::KEY_LOGIN_DATA, [
            ParamsEnum::IdToken->value => $idToken,
            ClaimsEnum::Iss->value => is_string($iss = $claims[ClaimsEnum::Iss->value] ?? null) ? $iss : null,
            ClaimsEnum::Sub->value => is_string($sub = $claims[ClaimsEnum::Sub->value] ?? null) ? $sub : null,
            ClaimsEnum::Sid->value => is_string($sid = $claims[ClaimsEnum::Sid->value] ?? null) ? $sid : null,
            ClaimsEnum::EndSessionEndpoint->value => $opEndSessionEndpoint,
            ParamsEnum::ClientId->value => $clientId,
        ]);
    }

    /**
     * Get the login data persisted after the last successful login, or null
     * when not available (no login was performed, or the session expired).
     *
     * @return mixed[]|null
     */
    public function getLoginData(): ?array
    {
        $loginData = $this->sessionStore->get(self::KEY_LOGIN_DATA);

        return is_array($loginData) ? $loginData : null;
    }

    /**
     * Raw ID token received at login, usable as the 'id_token_hint'
     * RP-Initiated Logout parameter.
     */
    public function getLoginIdToken(): ?string
    {
        return $this->getLoginDataStringValue(ParamsEnum::IdToken->value);
    }

    /**
     * Issuer (iss) claim of the ID token received at login.
     */
    public function getLoginIssuer(): ?string
    {
        return $this->getLoginDataStringValue(ClaimsEnum::Iss->value);
    }

    /**
     * Subject (sub) claim of the ID token received at login.
     */
    public function getLoginSubject(): ?string
    {
        return $this->getLoginDataStringValue(ClaimsEnum::Sub->value);
    }

    /**
     * Session ID (sid) claim of the ID token received at login, if the OP
     * issued one. Used to correlate OP-initiated (back-channel) logout
     * requests with this login.
     */
    public function getLoginSessionId(): ?string
    {
        return $this->getLoginDataStringValue(ClaimsEnum::Sid->value);
    }

    /**
     * The OP's end session endpoint as advertised at login time.
     */
    public function getLoginEndSessionEndpoint(): ?string
    {
        return $this->getLoginDataStringValue(ClaimsEnum::EndSessionEndpoint->value);
    }

    /**
     * The client ID the login was performed with (the one the ID token was
     * issued to). Used as the 'client_id' logout request parameter, so it
     * matches the 'id_token_hint' even if the client registration changes
     * between login and logout.
     */
    public function getLoginClientId(): ?string
    {
        return $this->getLoginDataStringValue(ParamsEnum::ClientId->value);
    }

    /**
     * Remove persisted login data from the session store (local logout).
     */
    public function clearLoginData(): void
    {
        $this->sessionStore->delete(self::KEY_LOGIN_DATA);
    }

    protected function getLoginDataStringValue(string $key): ?string
    {
        $value = $this->getLoginData()[$key] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Get the state parameter value to use in an RP-Initiated Logout
     * request. Stored in the session (separately from the authorization
     * request state), so it can be verified on the post logout redirect.
     *
     * @throws OidcClientException
     */
    public function getLogoutState(): string
    {
        return $this->stateNonceDataHandler->get(StateNonce::LOGOUT_STATE_KEY);
    }

    /**
     * Build RP-Initiated Logout request parameters for the OP's end session
     * endpoint. Null parameters are omitted. Per the specification all
     * parameters are optional, but 'id_token_hint' is recommended, and when
     * 'post_logout_redirect_uri' is used the OP needs to identify the RP
     * ('id_token_hint' and / or 'client_id'), and its value must have been
     * registered on the OP as one of the client's
     * 'post_logout_redirect_uris'.
     *
     * @return array<string,string>
     */
    public function buildEndSessionParameters(
        ?string $idTokenHint = null,
        ?string $clientId = null,
        ?string $postLogoutRedirectUri = null,
        ?string $state = null,
        ?string $logoutHint = null,
        ?string $uiLocales = null,
    ): array {
        return array_filter([
            ParamsEnum::IdTokenHint->value => $idTokenHint,
            ParamsEnum::ClientId->value => $clientId,
            ParamsEnum::PostLogoutRedirectUri->value => $postLogoutRedirectUri,
            ParamsEnum::State->value => $state,
            ParamsEnum::LogoutHint->value => $logoutHint,
            ParamsEnum::UiLocales->value => $uiLocales,
        ]);
    }

    /**
     * Validate the request made to the post logout redirect URI after an
     * RP-Initiated Logout: when a state parameter was sent in the logout
     * request, the OP must return it unchanged, so verify it against the
     * stored logout state (which is removed on successful verification).
     *
     * @throws OidcClientException
     */
    public function validateLogoutCallbackResponse(
        ?ServerRequestInterface $request = null,
        bool $useState = true,
    ): void {
        if (!$useState) {
            return;
        }

        $queryParams = $request?->getQueryParams() ?? $_GET;
        $parsedBody = $request?->getParsedBody() ?? $_POST;
        $params = array_merge(
            $queryParams,
            is_array($parsedBody) ? $parsedBody : []
        );

        $state = $params[ParamsEnum::State->value] ?? null;
        if (!is_string($state) || $state === '') {
            throw new OidcClientException('Not all required parameters were provided (state).');
        }

        $this->stateNonceDataHandler->verify(StateNonce::LOGOUT_STATE_KEY, $state);
    }
}
