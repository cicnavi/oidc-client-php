<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Protocol;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Logout\Interfaces\LoginRevocationRegistryInterface;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use DateInterval;
use Exception;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Codebooks\ClientAssertionTypesEnum;
use SimpleSAML\OpenID\Codebooks\ClientAuthenticationMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Core\IdToken;
use SimpleSAML\OpenID\Core\Factories\IdTokenFactory;
use SimpleSAML\OpenID\Core\Factories\LogoutTokenFactory;
use SimpleSAML\OpenID\Core\LogoutToken;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\Jwks\JwksDecorator;
use SimpleSAML\OpenID\Jwks\JwksFetcher;

#[CoversClass(RequestDataHandler::class)]
final class RequestDataHandlerTest extends TestCase
{
    private MockObject $sessionStoreMock;

    private MockObject $coreMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\Psr\SimpleCache\CacheInterface
     */
    private \PHPUnit\Framework\MockObject\Stub $cacheMock;

    private MockObject $jwksMock;

    private MockObject $requestFactoryMock;

    private MockObject $guzzleBridgeMock;

    private MockObject $httpClientMock;

    private MockObject $stateNonceDataHandlerMock;

    private MockObject $pkceDataHandlerMock;

    private MockObject $loggerMock;

    private DateInterval $maxCacheDuration;

    private MockObject $loginRevocationRegistryMock;

    protected function setUp(): void
    {
        $this->sessionStoreMock = $this->createMock(SessionStoreInterface::class);
        $this->coreMock = $this->createMock(Core::class);
        $this->cacheMock = $this->createStub(CacheInterface::class);
        $this->jwksMock = $this->createMock(Jwks::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->guzzleBridgeMock = $this->createMock(GuzzleBridge::class);
        $this->httpClientMock = $this->createMock(Client::class);
        $this->stateNonceDataHandlerMock = $this->createMock(StateNonceDataHandlerInterface::class);
        $this->pkceDataHandlerMock = $this->createMock(PkceDataHandlerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->maxCacheDuration = new DateInterval('PT6H');
        $this->loginRevocationRegistryMock = $this->createMock(LoginRevocationRegistryInterface::class);
    }

    protected function sut(
        ?SessionStoreInterface $sessionStore = null,
        ?Core $core = null,
        ?CacheInterface $cache = null,
        ?Jwks $jwks = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?GuzzleBridge $guzzleBridge = null,
        ?Client $httpClient = null,
        ?StateNonceDataHandlerInterface $stateNonceDataHandler = null,
        ?PkceDataHandlerInterface $pkceDataHandler = null,
        ?LoggerInterface $logger = null,
        ?DateInterval $maxCacheDuration = null,
        ?LoginRevocationRegistryInterface $loginRevocationRegistry = null,
    ): RequestDataHandler {
        $sessionStore ??= $this->sessionStoreMock;
        $core ??= $this->coreMock;
        $cache ??= $this->cacheMock;
        $jwks ??= $this->jwksMock;
        $requestFactory ??= $this->requestFactoryMock;
        $guzzleBridge ??= $this->guzzleBridgeMock;
        $httpClient ??= $this->httpClientMock;
        $stateNonceDataHandler ??= $this->stateNonceDataHandlerMock;
        $pkceDataHandler ??= $this->pkceDataHandlerMock;
        $logger ??= $this->loggerMock;
        $maxCacheDuration ??= $this->maxCacheDuration;
        /** @var LoginRevocationRegistryInterface $loginRevocationRegistry */
        $loginRevocationRegistry ??= $this->loginRevocationRegistryMock;

        return new RequestDataHandler(
            $sessionStore,
            $core,
            $cache,
            $jwks,
            $requestFactory,
            $guzzleBridge,
            $httpClient,
            $stateNonceDataHandler,
            $pkceDataHandler,
            $logger,
            $maxCacheDuration,
            $loginRevocationRegistry,
        );
    }

    /**
     * Expect login data to be persisted in the session store with the given
     * values plus a current 'logged_in_at' timestamp.
     *
     * @param array<string,mixed> $expectedLoginData Expected login data,
     * without the 'logged_in_at' entry.
     */
    private function expectLoginDataPut(array $expectedLoginData): void
    {
        $this->sessionStoreMock->expects($this->once())
            ->method('put')
            ->with(
                RequestDataHandler::KEY_LOGIN_DATA,
                $this->callback(function (array $loginData) use ($expectedLoginData): bool {
                    $loggedInAt = $loginData[RequestDataHandler::KEY_LOGGED_IN_AT] ?? null;
                    unset($loginData[RequestDataHandler::KEY_LOGGED_IN_AT]);

                    return $loginData === $expectedLoginData &&
                        is_int($loggedInAt) &&
                        abs($loggedInAt - time()) < 60;
                }),
            );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(RequestDataHandler::class, $this->sut());
    }

    public function testGetStateDelegatesToHandler(): void
    {
        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('get')
            ->with(StateNonce::STATE_KEY)
            ->willReturn('test-state');

        $this->assertSame('test-state', $this->sut()->getState());
    }

    public function testGetNonceDelegatesToHandler(): void
    {
        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('get')
            ->with(StateNonce::NONCE_KEY)
            ->willReturn('test-nonce');

        $this->assertSame('test-nonce', $this->sut()->getNonce());
    }

    public function testGetCodeVerifierDelegatesToHandler(): void
    {
        $this->pkceDataHandlerMock->expects($this->once())
            ->method('getCodeVerifier')
            ->willReturn('test-verifier');

        $this->assertSame('test-verifier', $this->sut()->getCodeVerifier());
    }

    public function testGenerateCodeChallengeDelegatesToHandler(): void
    {
        $verifier = 'test-verifier';
        $method = PkceCodeChallengeMethodEnum::S256;
        $challenge = 'test-challenge';

        $this->pkceDataHandlerMock->expects($this->once())
            ->method('generateCodeChallengeFromCodeVerifier')
            ->with($verifier, $method)
            ->willReturn($challenge);

        $this->assertSame($challenge, $this->sut()->generateCodeChallengeFromCodeVerifier($verifier, $method));
    }

    public function testSetResolvedOpMetadataForState(): void
    {
        $state = 'test-state';
        $metadata = ['foo' => 'bar'];

        $this->sessionStoreMock->expects($this->once())
            ->method('put')
            ->with(RequestDataHandler::KEY_OP_METADATA_FOR_STATE . $state, $metadata);

        $this->sut()->setResolvedOpMetadataForState($state, $metadata);
    }

    public function testGetResolvedOpMetadataForStateReturnsMetadata(): void
    {
        $state = 'test-state';
        $metadata = ['foo' => 'bar'];

        $this->sessionStoreMock->expects($this->once())
            ->method('get')
            ->with(RequestDataHandler::KEY_OP_METADATA_FOR_STATE . $state)
            ->willReturn($metadata);

        $this->sessionStoreMock->expects($this->once())
            ->method('delete')
            ->with(RequestDataHandler::KEY_OP_METADATA_FOR_STATE . $state);

        $this->assertSame($metadata, $this->sut()->getResolvedOpMetadataForState($state));
    }

    public function testGetResolvedOpMetadataForStateThrowsExceptionWhenNotFound(): void
    {
        $state = 'test-state';

        $this->sessionStoreMock->expects($this->once())
            ->method('get')
            ->with(RequestDataHandler::KEY_OP_METADATA_FOR_STATE . $state)
            ->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Resolved OP metadata not found for state "' . $state . '".');

        $this->sut()->getResolvedOpMetadataForState($state);
    }

    public function testSetClientRedirectUriForState(): void
    {
        $state = 'test-state';
        $redirectUri = 'https://example.com/cb';

        $this->sessionStoreMock->expects($this->once())
            ->method('put')
            ->with(RequestDataHandler::KEY_REDIRECT_URI_FOR_STATE_ . $state, $redirectUri);

        $this->sut()->setClientRedirectUriForState($state, $redirectUri);
    }

    public function testGetClientRedirectUriForStateReturnsUri(): void
    {
        $state = 'test-state';
        $redirectUri = 'https://example.com/cb';

        $this->sessionStoreMock->expects($this->once())
            ->method('get')
            ->with(RequestDataHandler::KEY_REDIRECT_URI_FOR_STATE_ . $state)
            ->willReturn($redirectUri);

        $this->sessionStoreMock->expects($this->once())
            ->method('delete')
            ->with(RequestDataHandler::KEY_REDIRECT_URI_FOR_STATE_ . $state);

        $this->assertSame($redirectUri, $this->sut()->getClientRedirectUriForState($state));
    }

    public function testGetClientRedirectUriForStateThrowsExceptionWhenNotFound(): void
    {
        $state = 'test-state';

        $this->sessionStoreMock->expects($this->once())
            ->method('get')
            ->with(RequestDataHandler::KEY_REDIRECT_URI_FOR_STATE_ . $state)
            ->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Redirect URI not found for state "' . $state . '".');

        $this->sut()->getClientRedirectUriForState($state);
    }

    public function testValidateAuthorizationCallbackResponseThrowsOnErrorMessage(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'error' => 'invalid_request',
            'error_description' => 'Something went wrong',
            'hint' => 'Check logs',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Authorization server returned error "invalid_request" - Something went wrong (Check logs).'
        );

        $this->sut()->validateAuthorizationCallbackResponse($request);
    }

    public function testValidateAuthorizationCallbackResponseThrowsOnMissingCode(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Not all required parameters were provided (code).');

        $this->sut()->validateAuthorizationCallbackResponse($request);
    }

    public function testValidateAuthorizationCallbackResponseThrowsOnMissingStateWhenRequired(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'code' => 'some-code',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Not all required parameters were provided (state).');

        $this->sut()->validateAuthorizationCallbackResponse($request, true);
    }

    public function testValidateAuthorizationCallbackResponseVerifiesState(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'code' => 'some-code',
            'state' => 'some-state',
        ]);

        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('verify')
            ->with(StateNonce::STATE_KEY, 'some-state');

        $result = $this->sut()->validateAuthorizationCallbackResponse($request, true);

        $this->assertSame([
            'code' => 'some-code',
            'state' => 'some-state',
        ], $result);
    }

    public function testValidateAuthorizationCallbackResponseVerifiesStateFromParsedBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn([
            'code' => 'some-code',
            'state' => 'some-state',
        ]);

        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('verify')
            ->with(StateNonce::STATE_KEY, 'some-state');

        $result = $this->sut()->validateAuthorizationCallbackResponse($request, true);

        $this->assertSame([
            'code' => 'some-code',
            'state' => 'some-state',
        ], $result);
    }

    public function testValidateHttpResponseOkThrowsOnNon200(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getReasonPhrase')->willReturn('Bad Request');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('HTTP response is not valid (400 - Bad Request)');

        $this->sut()->validateHttpResponseOk($response);
    }

    public function testValidateHttpResponseOkPassesOn200(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->sut()->validateHttpResponseOk($response);
        $this->addToAssertionCount(1);
    }

    public function testGetDecodedHttpResponseJsonThrowsOnInvalidJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('invalid-json');
        $response->method('getBody')->willReturn($stream);

        $this->loggerMock->expects($this->once())->method('error');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('HTTP request JSON response is not valid.');

        $this->sut()->getDecodedHttpResponseJson($response);
    }

    public function testGetDecodedHttpResponseJsonReturnsArray(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"foo": "bar"}');
        $response->method('getBody')->willReturn($stream);

        $this->assertSame(['foo' => 'bar'], $this->sut()->getDecodedHttpResponseJson($response));
    }

    public function testValidateTokenDataArrayThrowsOnMissingAccessToken(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Token data does not contain access token value.');

        $this->sut()->validateTokenDataArray([]);
    }

    public function testValidateTokenDataArrayThrowsOnMissingTokenType(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Token data does not contain token type.');

        $this->sut()->validateTokenDataArray(['access_token' => 'token']);
    }

    public function testValidateTokenDataArrayThrowsOnInvalidIdToken(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Token data contains invalid ID token value.');

        $this->sut()->validateTokenDataArray([
            'access_token' => 'token',
            'token_type' => 'Bearer',
            'id_token' => '',
        ]);
    }

    public function testValidateTokenDataArrayReturnsValidData(): void
    {
        $input = [
            'access_token' => 'token',
            'token_type' => 'Bearer',
            'id_token' => 'id-token',
            'extra' => 'ignored',
        ];

        $expected = [
            ParamsEnum::IdToken->value => 'id-token',
            ParamsEnum::AccessToken->value => 'token',
            ParamsEnum::TokenType->value => 'Bearer',
        ];

        $this->assertSame($expected, $this->sut()->validateTokenDataArray($input));
    }

    public function testRequestTokenDataThrowsOnMissingClientSecretForBasicAuth(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Client secret must be provided for client authentication method "client_secret_basic".',
        );

        $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::ClientSecretBasic,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb'
        );
    }

    public function testRequestTokenDataThrowsOnMissingClientAssertionForPrivateKeyJwt(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Client assertion must be provided for client authentication method "private_key_jwt".',
        );

        $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::PrivateKeyJwt,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb'
        );
    }

    public function testRequestTokenDataSuccess(): void
    {
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');

        $stream = $this->createStub(StreamInterface::class);
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($stream);

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"access_token": "at"}');
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $result = $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb'
        );

        $this->assertSame(['access_token' => 'at'], $result);
    }

    public function testRequestTokenDataThrowsOnRequestError(): void
    {
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $this->httpClientMock->method('sendRequest')->willThrowException(new Exception('Network error'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Token data request error. Network error');

        $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb'
        );
    }

    public function testGetJwksUriContentThrowsWhenRefreshCacheFails(): void
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $jwksFetcher->method('fromJwksUri')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Invalid JWKS URI content.');

        $this->sut()->getJwksUriContent('https://op.example.com/jwks', true);
    }

    public function testGetJwksUriContentThrowsWhenCacheAndFetchFail(): void
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Invalid JWKS URI content.');

        $this->sut()->getJwksUriContent('https://op.example.com/jwks', false);
    }

    public function testGetJwksUriContentSuccess(): void
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);

        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);

        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $this->assertSame(['keys' => []], $this->sut()->getJwksUriContent('https://op.example.com/jwks', false));
    }

    public function testGetDataFromIdTokenThrowsOnBuildError(): void
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenFactory->method('fromToken')->willThrowException(new JwsException('Invalid token'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Error building ID Token: Invalid token');

        $this->sut()->getDataFromIdToken('invalid-token', 'https://op.example.com/jwks');
    }

    public function testGetDataFromIdTokenRetriesOnVerificationFailure(): void
    {
        // Mock JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        // First call fromCacheOrJwksUri (initial attempt)
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);
        // Second call fromJwksUri (refresh attempt)
        $jwksFetcher->expects($this->once())->method('fromJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);

        // First verification fails
        $idTokenJws->expects($this->exactly(2))->method('verifyWithKeySet')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Exception('Sig fail')),
                null // Second succeeds
            );

        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'user']);

        $this->stateNonceDataHandlerMock->expects($this->once())->method('verify');

        $result = $this->sut()->getDataFromIdToken('token', 'https://op.example.com/jwks');
        $this->assertSame(['sub' => 'user'], $result);
    }

    public function testGetDataFromIdTokenThrowsAfterRetryFailure(): void
    {
        // Mock JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);
        $jwksFetcher->method('fromJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);

        // Both verifications fail
        $idTokenJws->method('verifyWithKeySet')->willThrowException(new Exception('Sig fail'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('ID token is not valid. Sig fail');

        $this->sut()->getDataFromIdToken('token', 'https://op.example.com/jwks');
    }

    public function testGetDataFromIdTokenThrowsOnMissingNonce(): void
    {
        // Mock JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);

        $idTokenJws->method('getNonce')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Nonce parameter is not present in ID token.');

        $this->sut()->getDataFromIdToken('token', 'https://op.example.com/jwks');
    }

    public function testRequestUserDataFromUserInfoEndpointThrowsOnMissingSub(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"name": "John"}');
        $response->method('getBody')->willReturn($stream);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('UserInfo Response does not contain mandatory sub claim.');

        $this->sut()->requestUserDataFromUserInfoEndpoint('at', 'https://op.example.com/userinfo');
    }

    public function testRequestUserDataFromUserInfoEndpointSuccess(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"sub": "123", "name": "John"}');
        $response->method('getBody')->willReturn($stream);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $result = $this->sut()->requestUserDataFromUserInfoEndpoint('at', 'https://op.example.com/userinfo');
        $this->assertSame(['sub' => '123', 'name' => 'John'], $result);
    }

    public function testGetClaimsThrowsOnSubMismatch(): void
    {
        // Mock JWKS for getDataFromIdToken
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'sub1']);

        // Mock UserInfo request
        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"sub": "sub2"}');
        $response->method('getBody')->willReturn($stream);
        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('ID token and UserInfo sub claim must be equal.');

        $this->sut()->getClaims(
            [
                ParamsEnum::AccessToken->value => 'at',
                ParamsEnum::TokenType->value => 'Bearer',
                ParamsEnum::IdToken->value => 'id-token',
            ],
            'https://op.example.com/jwks',
            'https://op.example.com/userinfo'
        );
    }

    public function testGetClaimsSuccess(): void
    {
        // Mock JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'sub1', 'email' => 'e1']);

        // Mock UserInfo request
        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"sub": "sub1", "name": "n1"}');
        $response->method('getBody')->willReturn($stream);
        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $result = $this->sut()->getClaims(
            [
                ParamsEnum::AccessToken->value => 'at',
                ParamsEnum::TokenType->value => 'Bearer',
                ParamsEnum::IdToken->value => 'id-token',
            ],
            'https://op.example.com/jwks',
            'https://op.example.com/userinfo'
        );

        $this->assertSame(['sub' => 'sub1', 'email' => 'e1', 'name' => 'n1'], $result);
    }

    public function testGetUserData(): void
    {
        // 1. requestTokenData mocks
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $tokenRequest = $this->createMock(RequestInterface::class);
        $userInfoRequest = $this->createMock(RequestInterface::class);
        $userInfoRequest->method('withHeader')->willReturn($userInfoRequest);

        // 1 for token, 1 for userinfo
        $this->requestFactoryMock->expects($this->exactly(2))->method('createRequest')
            ->willReturnOnConsecutiveCalls($tokenRequest, $userInfoRequest);

        $tokenRequest->method('withBody')->willReturn($tokenRequest);
        $tokenRequest->method('withHeader')->willReturn($tokenRequest);

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenStream = $this->createMock(StreamInterface::class);
        $tokenStream->method('__toString')->willReturn(json_encode([
            'access_token' => 'at',
            'token_type' => 'Bearer',
            'id_token' => 'id-token'
        ]));
        $tokenResponse->method('getBody')->willReturn($tokenStream);

        // 2. getClaims mocks
        // JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        // ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'sub1']);

        // UserInfo Response
        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse->method('getStatusCode')->willReturn(200);
        $userInfoStream = $this->createMock(StreamInterface::class);
        $userInfoStream->method('__toString')->willReturn('{"sub": "sub1"}');
        $userInfoResponse->method('getBody')->willReturn($userInfoStream);

        $this->httpClientMock->expects($this->exactly(2))->method('sendRequest')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        // Expect PKCE removal
        $this->pkceDataHandlerMock->expects($this->once())->method('removeCodeVerifier');

        $result = $this->sut()->getUserData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'code',
            'client-id',
            'redirect-uri',
            'jwks-uri',
            'token-endpoint',
            'userinfo-endpoint'
        );

        $this->assertSame(['sub' => 'sub1'], $result);
    }

    public function testRequestTokenDataWithClientSecretBasicAddsHeader(): void
    {
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);

        $request->expects($this->exactly(3))
            ->method('withHeader')
            ->willReturnCallback(function (string $name, $value) use ($request): MockObject {
                if ($name === 'Authorization') {
                    // Per RFC 6749 section 2.3.1, client ID and secret must be
                    // form-urlencoded before being used as Basic credentials.
                    $this->assertSame('Basic ' . base64_encode('client-id:secret_w%3Ai%2Bt%25h+spec%2Fals'), $value);
                }

                return $request;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"access_token": "at"}');
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::ClientSecretBasic,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb',
            'secret_w:i+t%h spec/als'
        );
    }

    public function testRequestTokenDataWithPrivateKeyJwtAddsParams(): void
    {
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');

        $this->guzzleBridgeMock->expects($this->once())
            ->method('psr7StreamFor')
            ->willReturnCallback(
                function (
                    callable|float|StreamInterface|bool|\Iterator|int|string|null $body,
                ): MockObject&StreamInterface {
                    parse_str($body, $params);
                    $this->assertArrayHasKey('client_assertion_type', $params);
                    $this->assertSame(ClientAssertionTypesEnum::JwtBaerer->value, $params['client_assertion_type']);
                    $this->assertArrayHasKey('client_assertion', $params);
                    $this->assertSame('assertion', $params['client_assertion']);
                    return $this->createMock(StreamInterface::class);
                }
            );

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"access_token": "at"}');
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $this->sut()->requestTokenData(
            ClientAuthenticationMethodsEnum::PrivateKeyJwt,
            'https://op.example.com/token',
            'auth-code',
            'client-id',
            'https://client.example.com/cb',
            null,
            'assertion'
        );
    }

    public function testValidateAuthorizationCallbackResponseWithoutStateVerification(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'code' => 'some-code',
            'state' => 'some-state',
        ]);

        $this->stateNonceDataHandlerMock->expects($this->never())->method('verify');

        $result = $this->sut()->validateAuthorizationCallbackResponse($request, false);

        $this->assertSame([
            'code' => 'some-code',
            'state' => null,
        ], $result);
    }

    public function testGetUserDataWithoutPkce(): void
    {
        // 1. requestTokenData mocks
        // getCodeVerifier should NOT be called
        $this->pkceDataHandlerMock->expects($this->never())->method('getCodeVerifier');

        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $tokenRequest = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($tokenRequest);
        $tokenRequest->method('withBody')->willReturn($tokenRequest);
        $tokenRequest->method('withHeader')->willReturn($tokenRequest);

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenStream = $this->createMock(StreamInterface::class);
        $tokenStream->method('__toString')->willReturn(json_encode([
            'access_token' => 'at',
            'token_type' => 'Bearer',
            'id_token' => 'id-token'
        ]));
        $tokenResponse->method('getBody')->willReturn($tokenStream);

        // 2. getClaims mocks
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'sub1']);

        // UserInfo Response
        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse->method('getStatusCode')->willReturn(200);
        $userInfoStream = $this->createMock(StreamInterface::class);
        $userInfoStream->method('__toString')->willReturn('{"sub": "sub1"}');
        $userInfoResponse->method('getBody')->willReturn($userInfoStream);

        $this->httpClientMock->expects($this->exactly(2))->method('sendRequest')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        // Expect PKCE removal NOT called
        $this->pkceDataHandlerMock->expects($this->never())->method('removeCodeVerifier');

        $result = $this->sut()->getUserData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'code',
            'client-id',
            'redirect-uri',
            'jwks-uri',
            'token-endpoint',
            'userinfo-endpoint',
            null,
            null,
            false // usePkce = false
        );

        $this->assertSame(['sub' => 'sub1'], $result);
    }

    public function testGetUserDataWithoutUserinfo(): void
    {
        // 1. requestTokenData mocks
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $tokenRequest = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($tokenRequest);
        $tokenRequest->method('withBody')->willReturn($tokenRequest);
        $tokenRequest->method('withHeader')->willReturn($tokenRequest);

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenStream = $this->createMock(StreamInterface::class);
        $tokenStream->method('__toString')->willReturn(json_encode([
            'access_token' => 'at',
            'token_type' => 'Bearer',
            'id_token' => 'id-token'
        ]));
        $tokenResponse->method('getBody')->willReturn($tokenStream);

        // 2. getClaims mocks
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn(['sub' => 'sub1']);

        // Expect only 1 request (token)
        $this->httpClientMock->expects($this->once())->method('sendRequest')
            ->willReturn($tokenResponse);

        $result = $this->sut()->getUserData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'code',
            'client-id',
            'redirect-uri',
            'jwks-uri',
            'token-endpoint',
            'userinfo-endpoint',
            null,
            null,
            true,
            true,
            false // fetchUserinfoClaims = false
        );

        $this->assertSame(['sub' => 'sub1'], $result);
    }

    public function testGetClaimsWithoutIdToken(): void
    {
        // Mock UserInfo request
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturn($request);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"sub": "sub1", "name": "n1"}');
        $response->method('getBody')->willReturn($stream);
        $this->httpClientMock->method('sendRequest')->willReturn($response);

        // No ID token provided
        $result = $this->sut()->getClaims(
            [
                ParamsEnum::AccessToken->value => 'at',
                ParamsEnum::TokenType->value => 'Bearer',
                ParamsEnum::IdToken->value => null,
            ],
            'https://op.example.com/jwks',
            'https://op.example.com/userinfo'
        );

        // Should return only userinfo claims
        $this->assertSame(['sub' => 'sub1', 'name' => 'n1'], $result);
    }

    public function testGetDataFromIdTokenWithoutNonceVerification(): void
    {
        // Mock JWKS
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        // Mock ID Token
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);

        // Should not call getNonce or verify
        $idTokenJws->expects($this->never())->method('getNonce');
        $this->stateNonceDataHandlerMock->expects($this->never())->method('verify');

        $idTokenJws->method('getPayload')->willReturn(['sub' => 'user']);

        $result = $this->sut()->getDataFromIdToken('token', 'https://op.example.com/jwks', false);
        $this->assertSame(['sub' => 'user'], $result);
    }

    public function testGetDecodedHttpResponseJsonThrowsOnNonArrayJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('"string-json"');
        $response->method('getBody')->willReturn($stream);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('HTTP request JSON response is not valid.');

        $this->sut()->getDecodedHttpResponseJson($response);
    }

    public function testResolveParEndpointReturnsNullWhenModeOff(): void
    {
        $this->assertNull(
            $this->sut()->resolvePushedAuthorizationRequestEndpoint(
                ['pushed_authorization_request_endpoint' => 'https://op.example.com/par'],
                ParModeEnum::Off,
            ),
        );
    }

    public function testResolveParEndpointAutoReturnsNullWhenOpDoesNotRequirePar(): void
    {
        // OP advertises the endpoint but does not require PAR: 'auto' does not use it.
        $this->assertNull(
            $this->sut()->resolvePushedAuthorizationRequestEndpoint(
                ['pushed_authorization_request_endpoint' => 'https://op.example.com/par'],
                ParModeEnum::Auto,
            ),
        );
    }

    public function testResolveParEndpointAutoReturnsEndpointWhenOpRequiresPar(): void
    {
        $this->assertSame(
            'https://op.example.com/par',
            $this->sut()->resolvePushedAuthorizationRequestEndpoint(
                [
                    'pushed_authorization_request_endpoint' => 'https://op.example.com/par',
                    'require_pushed_authorization_requests' => true,
                ],
                ParModeEnum::Auto,
            ),
        );
    }

    public function testResolveParEndpointAutoThrowsWhenRequiredButEndpointMissing(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('does not advertise a "pushed_authorization_request_endpoint"');

        $this->sut()->resolvePushedAuthorizationRequestEndpoint(
            ['require_pushed_authorization_requests' => true],
            ParModeEnum::Auto,
        );
    }

    public function testResolveParEndpointRequiredReturnsEndpoint(): void
    {
        $this->assertSame(
            'https://op.example.com/par',
            $this->sut()->resolvePushedAuthorizationRequestEndpoint(
                ['pushed_authorization_request_endpoint' => 'https://op.example.com/par'],
                ParModeEnum::Required,
            ),
        );
    }

    public function testResolveParEndpointRequiredThrowsWhenEndpointMissing(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('does not advertise a "pushed_authorization_request_endpoint"');

        $this->sut()->resolvePushedAuthorizationRequestEndpoint([], ParModeEnum::Required);
    }

    public function testPushAuthorizationRequestRejectsRequestUriInParameters(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('"request_uri" parameter must not be sent');

        $this->sut()->pushAuthorizationRequest(
            ClientAuthenticationMethodsEnum::ClientSecretBasic,
            'https://op.example.com/par',
            ['request_uri' => 'urn:should:not:be:here'],
            'client-id',
            'client-secret',
        );
    }

    public function testPushAuthorizationRequestSuccessWithPrivateKeyJwt(): void
    {
        $this->guzzleBridgeMock->expects($this->once())
            ->method('psr7StreamFor')
            ->willReturnCallback(
                function (
                    callable|float|StreamInterface|bool|\Iterator|int|string|null $body,
                ): MockObject&StreamInterface {
                    parse_str((string) $body, $params);
                    // client_id is forced into the body.
                    $this->assertSame('client-id', $params['client_id']);
                    // response_type is pushed.
                    $this->assertSame('code', $params['response_type']);
                    // private_key_jwt client authentication params are present.
                    $this->assertSame(
                        ClientAssertionTypesEnum::JwtBaerer->value,
                        $params['client_assertion_type'],
                    );
                    $this->assertSame('assertion', $params['client_assertion']);
                    // request_uri must not be in the body.
                    $this->assertArrayNotHasKey('request_uri', $params);
                    return $this->createMock(StreamInterface::class);
                }
            );

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn(
            '{"request_uri": "urn:ietf:params:oauth:request_uri:abc", "expires_in": 60}'
        );
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $result = $this->sut()->pushAuthorizationRequest(
            ClientAuthenticationMethodsEnum::PrivateKeyJwt,
            'https://op.example.com/par',
            [
                'response_type' => 'code',
                'redirect_uri' => 'https://client.example.com/cb',
                'scope' => 'openid',
            ],
            'client-id',
            null,
            'assertion',
        );

        $this->assertSame(
            ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60],
            $result,
        );
    }

    public function testPushAuthorizationRequestWithClientSecretBasicAddsHeader(): void
    {
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->expects($this->exactly(3))
            ->method('withHeader')
            ->willReturnCallback(function (string $name, $value) use ($request): MockObject {
                if ($name === 'Authorization') {
                    // Per RFC 6749 section 2.3.1, client ID and secret must be
                    // form-urlencoded before being used as Basic credentials.
                    $this->assertSame('Basic ' . base64_encode('client-id:secret_w%3Ai%2Bt%25h+spec%2Fals'), $value);
                }

                return $request;
            });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"request_uri": "urn:abc", "expires_in": 90}');
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $result = $this->sut()->pushAuthorizationRequest(
            ClientAuthenticationMethodsEnum::ClientSecretBasic,
            'https://op.example.com/par',
            ['response_type' => 'code'],
            'client-id',
            'secret_w:i+t%h spec/als',
        );

        $this->assertSame(['request_uri' => 'urn:abc', 'expires_in' => 90], $result);
    }

    public function testPushAuthorizationRequestSurfacesJsonError(): void
    {
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $request = $this->createMock(RequestInterface::class);
        $this->requestFactoryMock->method('createRequest')->willReturn($request);
        $request->method('withBody')->willReturn($request);
        $request->method('withHeader')->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getReasonPhrase')->willReturn('Bad Request');
        $responseBody = $this->createMock(StreamInterface::class);
        $responseBody->method('__toString')->willReturn(
            '{"error": "invalid_request", "error_description": "client_id is missing"}'
        );
        $response->method('getBody')->willReturn($responseBody);

        $this->httpClientMock->method('sendRequest')->willReturn($response);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Pushed authorization request rejected by OpenID Provider - error "invalid_request": client_id is missing.'
        );

        $this->sut()->pushAuthorizationRequest(
            ClientAuthenticationMethodsEnum::ClientSecretBasic,
            'https://op.example.com/par',
            ['response_type' => 'code'],
            'client-id',
            'client-secret',
        );
    }

    public function testValidatePushedAuthorizationResponseDataThrowsOnMissingRequestUri(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('does not contain a valid "request_uri"');

        $this->sut()->validatePushedAuthorizationResponseData(['expires_in' => 60]);
    }

    public function testValidatePushedAuthorizationResponseDataDefaultsExpiresInToZero(): void
    {
        $this->assertSame(
            ['request_uri' => 'urn:abc', 'expires_in' => 0],
            $this->sut()->validatePushedAuthorizationResponseData(['request_uri' => 'urn:abc']),
        );
    }

    public function testGetLogoutStateDelegatesToHandler(): void
    {
        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('get')
            ->with(StateNonce::LOGOUT_STATE_KEY)
            ->willReturn('logout-state');

        $this->assertSame('logout-state', $this->sut()->getLogoutState());
    }

    public function testStoreLoginDataStoresIdTokenAndClaims(): void
    {
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->with('id-token')->willReturn($idTokenJws);
        $idTokenJws->method('getPayload')->willReturn([
            'iss' => 'https://op.example.org',
            'sub' => 'user-1',
            'sid' => 'op-session-1',
        ]);

        $this->expectLoginDataPut([
            'id_token' => 'id-token',
            'iss' => 'https://op.example.org',
            'sub' => 'user-1',
            'sid' => 'op-session-1',
            'end_session_endpoint' => 'https://op.example.org/end-session',
            'client_id' => 'client-id',
        ]);

        $this->sut()->storeLoginData('id-token', 'https://op.example.org/end-session', 'client-id');
    }

    public function testStoreLoginDataStoresRawIdTokenOnClaimExtractionError(): void
    {
        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenFactory->method('fromToken')->willThrowException(new JwsException('Parse error'));

        $this->loggerMock->expects($this->once())->method('warning');

        $this->expectLoginDataPut([
            'id_token' => 'id-token',
            'iss' => null,
            'sub' => null,
            'sid' => null,
            'end_session_endpoint' => null,
            'client_id' => null,
        ]);

        $this->sut()->storeLoginData('id-token');
    }

    public function testStoreLoginDataWithoutIdToken(): void
    {
        $this->coreMock->expects($this->never())->method('idTokenFactory');

        $this->expectLoginDataPut([
            'id_token' => null,
            'iss' => null,
            'sub' => null,
            'sid' => null,
            'end_session_endpoint' => 'https://op.example.org/end-session',
            'client_id' => 'client-id',
        ]);

        $this->sut()->storeLoginData(null, 'https://op.example.org/end-session', 'client-id');
    }

    public function testLoginDataGetters(): void
    {
        $this->sessionStoreMock->method('get')
            ->with(RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn([
                'id_token' => 'id-token',
                'iss' => 'https://op.example.org',
                'sub' => 'user-1',
                'sid' => 'op-session-1',
                'end_session_endpoint' => 'https://op.example.org/end-session',
                'client_id' => 'client-id',
            ]);

        $sut = $this->sut();

        $this->assertSame('id-token', $sut->getLoginIdToken());
        $this->assertSame('https://op.example.org', $sut->getLoginIssuer());
        $this->assertSame('user-1', $sut->getLoginSubject());
        $this->assertSame('op-session-1', $sut->getLoginSessionId());
        $this->assertSame('https://op.example.org/end-session', $sut->getLoginEndSessionEndpoint());
        $this->assertSame('client-id', $sut->getLoginClientId());
    }

    public function testLoginDataGettersReturnNullWhenNoLoginData(): void
    {
        $this->sessionStoreMock->method('get')->willReturn(null);

        $sut = $this->sut();

        $this->assertNull($sut->getLoginData());
        $this->assertNull($sut->getLoginIdToken());
        $this->assertNull($sut->getLoginIssuer());
        $this->assertNull($sut->getLoginSubject());
        $this->assertNull($sut->getLoginSessionId());
        $this->assertNull($sut->getLoginEndSessionEndpoint());
        $this->assertNull($sut->getLoginClientId());
    }

    public function testClearLoginData(): void
    {
        $this->sessionStoreMock->expects($this->once())
            ->method('delete')
            ->with(RequestDataHandler::KEY_LOGIN_DATA);

        $this->sut()->clearLoginData();
    }

    public function testGetUserDataStoresLoginData(): void
    {
        // requestTokenData mocks
        $this->pkceDataHandlerMock->method('getCodeVerifier')->willReturn('verifier');
        $this->guzzleBridgeMock->method('psr7StreamFor')->willReturn($this->createStub(StreamInterface::class));

        $tokenRequest = $this->createMock(RequestInterface::class);
        $userInfoRequest = $this->createMock(RequestInterface::class);
        $userInfoRequest->method('withHeader')->willReturn($userInfoRequest);

        $this->requestFactoryMock->method('createRequest')
            ->willReturnOnConsecutiveCalls($tokenRequest, $userInfoRequest);

        $tokenRequest->method('withBody')->willReturn($tokenRequest);
        $tokenRequest->method('withHeader')->willReturn($tokenRequest);

        $tokenResponse = $this->createMock(ResponseInterface::class);
        $tokenResponse->method('getStatusCode')->willReturn(200);
        $tokenStream = $this->createMock(StreamInterface::class);
        $tokenStream->method('__toString')->willReturn(json_encode([
            'access_token' => 'at',
            'token_type' => 'Bearer',
            'id_token' => 'id-token'
        ]));
        $tokenResponse->method('getBody')->willReturn($tokenStream);

        // getClaims mocks
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $idTokenFactory = $this->createMock(IdTokenFactory::class);
        $this->coreMock->method('idTokenFactory')->willReturn($idTokenFactory);
        $idTokenJws = $this->createMock(IdToken::class);
        $idTokenFactory->method('fromToken')->willReturn($idTokenJws);
        $idTokenJws->method('getNonce')->willReturn('nonce');
        $idTokenJws->method('getPayload')->willReturn([
            'iss' => 'https://op.example.org',
            'sub' => 'sub1',
            'sid' => 'op-session-1',
        ]);

        $userInfoResponse = $this->createMock(ResponseInterface::class);
        $userInfoResponse->method('getStatusCode')->willReturn(200);
        $userInfoStream = $this->createMock(StreamInterface::class);
        $userInfoStream->method('__toString')->willReturn('{"sub": "sub1"}');
        $userInfoResponse->method('getBody')->willReturn($userInfoStream);

        $this->httpClientMock->method('sendRequest')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        // Login data is persisted after successful login.
        $this->expectLoginDataPut([
            'id_token' => 'id-token',
            'iss' => 'https://op.example.org',
            'sub' => 'sub1',
            'sid' => 'op-session-1',
            'end_session_endpoint' => 'https://op.example.org/end-session',
            'client_id' => 'client-id',
        ]);

        $this->sut()->getUserData(
            ClientAuthenticationMethodsEnum::ClientSecretPost,
            'code',
            'client-id',
            'redirect-uri',
            'jwks-uri',
            'token-endpoint',
            'userinfo-endpoint',
            opEndSessionEndpoint: 'https://op.example.org/end-session',
        );
    }

    public function testBuildEndSessionParametersOmitsNullValues(): void
    {
        $this->assertSame([], $this->sut()->buildEndSessionParameters());

        $this->assertSame(
            [
                'id_token_hint' => 'id-token',
                'client_id' => 'client-id',
            ],
            $this->sut()->buildEndSessionParameters(
                idTokenHint: 'id-token',
                clientId: 'client-id',
            ),
        );
    }

    public function testBuildEndSessionParametersWithAllValues(): void
    {
        $this->assertSame(
            [
                'id_token_hint' => 'id-token',
                'client_id' => 'client-id',
                'post_logout_redirect_uri' => 'https://rp.example.org/logged-out',
                'state' => 'logout-state',
                'logout_hint' => 'user@example.org',
                'ui_locales' => 'hr en',
            ],
            $this->sut()->buildEndSessionParameters(
                idTokenHint: 'id-token',
                clientId: 'client-id',
                postLogoutRedirectUri: 'https://rp.example.org/logged-out',
                state: 'logout-state',
                logoutHint: 'user@example.org',
                uiLocales: 'hr en',
            ),
        );
    }

    public function testValidateLogoutCallbackResponseVerifiesState(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'state' => 'logout-state',
        ]);

        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('verify')
            ->with(StateNonce::LOGOUT_STATE_KEY, 'logout-state');

        $this->sut()->validateLogoutCallbackResponse($request);
    }

    public function testValidateLogoutCallbackResponseVerifiesStateFromParsedBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn([
            'state' => 'logout-state',
        ]);

        $this->stateNonceDataHandlerMock->expects($this->once())
            ->method('verify')
            ->with(StateNonce::LOGOUT_STATE_KEY, 'logout-state');

        $this->sut()->validateLogoutCallbackResponse($request);
    }

    public function testValidateLogoutCallbackResponseThrowsOnMissingState(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Not all required parameters were provided (state).');

        $this->sut()->validateLogoutCallbackResponse($request);
    }

    public function testValidateLogoutCallbackResponseSkipsVerificationWithoutState(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $this->stateNonceDataHandlerMock->expects($this->never())->method('verify');

        $this->sut()->validateLogoutCallbackResponse($request, false);
    }

    public function testParseBackchannelLogoutRequestReturnsLogoutToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            RequestDataHandler::PARAM_LOGOUT_TOKEN => 'logout-token',
        ]);

        $this->assertSame('logout-token', $this->sut()->parseBackchannelLogoutRequest($request));
    }

    public function testParseBackchannelLogoutRequestThrowsOnNonPostMethod(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Back-channel logout request must use the HTTP POST method.');

        $this->sut()->parseBackchannelLogoutRequest($request);
    }

    public function testParseBackchannelLogoutRequestThrowsOnMissingLogoutToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Back-channel logout request does not contain a "logout_token"');

        $this->sut()->parseBackchannelLogoutRequest($request);
    }

    /**
     * Set up JWKS and logout token factory mocks, returning the LogoutToken
     * mock which the factory produces.
     */
    private function mockLogoutTokenSetup(): MockObject
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);
        $jwksFetcher->method('fromJwksUri')->willReturn($keySet);

        $logoutTokenFactory = $this->createMock(LogoutTokenFactory::class);
        $this->coreMock->method('logoutTokenFactory')->willReturn($logoutTokenFactory);
        $logoutTokenJws = $this->createMock(LogoutToken::class);
        $logoutTokenFactory->method('fromToken')->willReturn($logoutTokenJws);

        return $logoutTokenJws;
    }

    /**
     * Configure a LogoutToken mock with a valid claim set.
     */
    private function configureValidLogoutToken(MockObject $logoutTokenJws): void
    {
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getAudience')->willReturn(['client-id']);
        $logoutTokenJws->method('getType')->willReturn('logout+jwt');
        $logoutTokenJws->method('getIssuedAt')->willReturn(time());
        $logoutTokenJws->method('getExpirationTime')->willReturn(time() + 120);
        $logoutTokenJws->method('getJwtId')->willReturn('jti-1');
        $logoutTokenJws->method('getSessionId')->willReturn('op-session-1');
    }

    public function testValidateLogoutTokenReturnsVerifiedToken(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);
        $logoutTokenJws->expects($this->once())->method('verifyWithKeySet');

        $result = $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            'https://op.example.org',
            'client-id',
        );

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testValidateLogoutTokenThrowsOnBuildError(): void
    {
        $jwksFetcher = $this->createMock(JwksFetcher::class);
        $this->jwksMock->method('jwksFetcher')->willReturn($jwksFetcher);
        $keySet = $this->createMock(JwksDecorator::class);
        $keySet->method('jsonSerialize')->willReturn(['keys' => []]);
        $jwksFetcher->method('fromCacheOrJwksUri')->willReturn($keySet);

        $logoutTokenFactory = $this->createMock(LogoutTokenFactory::class);
        $this->coreMock->method('logoutTokenFactory')->willReturn($logoutTokenFactory);
        $logoutTokenFactory->method('fromToken')->willThrowException(new JwsException('Invalid token'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Error building Logout Token: Invalid token');

        $this->sut()->validateLogoutToken('invalid-token', 'https://op.example.org/jwks');
    }

    public function testValidateLogoutTokenRetriesOnVerificationFailure(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        // First verification fails, second (after JWKS refresh) succeeds.
        $logoutTokenJws->expects($this->exactly(2))->method('verifyWithKeySet')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Exception('Sig fail')),
                null,
            );

        $result = $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            'https://op.example.org',
            'client-id',
        );

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testValidateLogoutTokenThrowsAfterRetryFailure(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $logoutTokenJws->method('verifyWithKeySet')->willThrowException(new Exception('Sig fail'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token is not valid. Sig fail');

        $this->sut()->validateLogoutToken('logout-token', 'https://op.example.org/jwks');
    }

    public function testValidateLogoutTokenThrowsOnIssuerMismatch(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('does not match expected issuer');

        $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            'https://other-op.example.org',
        );
    }

    public function testValidateLogoutTokenThrowsOnAudienceMismatch(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('does not contain expected client ID');

        $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            'https://op.example.org',
            'other-client-id',
        );
    }

    public function testValidateLogoutTokenWarnsOnUnexpectedTypHeader(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getAudience')->willReturn(['client-id']);
        $logoutTokenJws->method('getType')->willReturn('JWT');
        $logoutTokenJws->method('getIssuedAt')->willReturn(time());
        $logoutTokenJws->method('getExpirationTime')->willReturn(time() + 120);
        $logoutTokenJws->method('getJwtId')->willReturn('jti-1');

        $this->loggerMock->expects($this->once())->method('warning');

        $result = $this->sut()->validateLogoutToken('logout-token', 'https://op.example.org/jwks');

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testValidateLogoutTokenThrowsOnStaleIssuedAt(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getAudience')->willReturn(['client-id']);
        $logoutTokenJws->method('getType')->willReturn('logout+jwt');
        // Older than the default maximum age of 5 minutes.
        $logoutTokenJws->method('getIssuedAt')->willReturn(time() - 600);
        $logoutTokenJws->method('getExpirationTime')->willReturn(time() + 120);
        $logoutTokenJws->method('getJwtId')->willReturn('jti-1');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token is too old');

        $this->sut()->validateLogoutToken('logout-token', 'https://op.example.org/jwks');
    }

    public function testValidateLogoutTokenAcceptsStaleIssuedAtWhenMaxAgeDisabled(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getAudience')->willReturn(['client-id']);
        $logoutTokenJws->method('getType')->willReturn('logout+jwt');
        $logoutTokenJws->method('getIssuedAt')->willReturn(time() - 600);
        $logoutTokenJws->method('getExpirationTime')->willReturn(time() + 120);
        $logoutTokenJws->method('getJwtId')->willReturn('jti-1');

        $result = $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            logoutTokenMaxAge: null,
        );

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testValidateLogoutTokenThrowsOnJtiReplay(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        // A logout token with the same jti was already consumed.
        $this->cacheMock->method('get')->willReturn(time());

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token replay detected');

        $this->sut()->validateLogoutToken('logout-token', 'https://op.example.org/jwks');
    }

    public function testValidateLogoutTokenSkipsJtiReplayCheckWhenDisabled(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        $this->cacheMock->method('get')->willReturn(time());

        $result = $this->sut()->validateLogoutToken(
            'logout-token',
            'https://op.example.org/jwks',
            checkJtiReplay: false,
        );

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testValidateLogoutTokenSkipsJtiReplayCheckOnCacheError(): void
    {
        $logoutTokenJws = $this->mockLogoutTokenSetup();
        $this->configureValidLogoutToken($logoutTokenJws);

        $this->cacheMock->method('get')->willThrowException(new Exception('Cache error.'));

        $result = $this->sut()->validateLogoutToken('logout-token', 'https://op.example.org/jwks');

        $this->assertSame($logoutTokenJws, $result);
    }

    public function testRegisterLogoutTokenRevocationRevokesSession(): void
    {
        $logoutTokenJws = $this->createMock(LogoutToken::class);
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getSessionId')->willReturn('op-session-1');
        $logoutTokenJws->method('getSubject')->willReturn('user-1');

        $this->loginRevocationRegistryMock->expects($this->once())
            ->method('revokeSession')
            ->with('https://op.example.org', 'op-session-1');
        $this->loginRevocationRegistryMock->expects($this->never())->method('revokeSubject');

        $this->sut()->registerLogoutTokenRevocation($logoutTokenJws);
    }

    public function testRegisterLogoutTokenRevocationRevokesSubjectWithoutSessionId(): void
    {
        $logoutTokenJws = $this->createMock(LogoutToken::class);
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getSessionId')->willReturn(null);
        $logoutTokenJws->method('getSubject')->willReturn('user-1');

        $this->loginRevocationRegistryMock->expects($this->never())->method('revokeSession');
        $this->loginRevocationRegistryMock->expects($this->once())
            ->method('revokeSubject')
            ->with('https://op.example.org', 'user-1');

        $this->sut()->registerLogoutTokenRevocation($logoutTokenJws);
    }

    public function testRegisterLogoutTokenRevocationThrowsWithoutSessionIdAndSubject(): void
    {
        $logoutTokenJws = $this->createMock(LogoutToken::class);
        $logoutTokenJws->method('getIssuer')->willReturn('https://op.example.org');
        $logoutTokenJws->method('getSessionId')->willReturn(null);
        $logoutTokenJws->method('getSubject')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token does not identify a session or a subject.');

        $this->sut()->registerLogoutTokenRevocation($logoutTokenJws);
    }

    public function testGetLoginDataClearsRevokedSessionLogin(): void
    {
        $loggedInAt = time() - 60;
        $this->sessionStoreMock->method('get')
            ->with(RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn([
                'iss' => 'https://op.example.org',
                'sub' => 'user-1',
                'sid' => 'op-session-1',
                RequestDataHandler::KEY_LOGGED_IN_AT => $loggedInAt,
            ]);

        $this->loginRevocationRegistryMock->method('isSessionRevoked')
            ->with('https://op.example.org', 'op-session-1', $loggedInAt)
            ->willReturn(true);

        $this->sessionStoreMock->expects($this->once())
            ->method('delete')
            ->with(RequestDataHandler::KEY_LOGIN_DATA);

        $this->assertNull($this->sut()->getLoginData());
    }

    public function testGetLoginDataClearsRevokedSubjectLogin(): void
    {
        $loggedInAt = time() - 60;
        $this->sessionStoreMock->method('get')
            ->with(RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn([
                'iss' => 'https://op.example.org',
                'sub' => 'user-1',
                'sid' => 'op-session-1',
                RequestDataHandler::KEY_LOGGED_IN_AT => $loggedInAt,
            ]);

        $this->loginRevocationRegistryMock->method('isSessionRevoked')->willReturn(false);
        $this->loginRevocationRegistryMock->method('isSubjectRevoked')
            ->with('https://op.example.org', 'user-1', $loggedInAt)
            ->willReturn(true);

        $this->sessionStoreMock->expects($this->once())
            ->method('delete')
            ->with(RequestDataHandler::KEY_LOGIN_DATA);

        $this->assertNull($this->sut()->getLoginData());
    }

    public function testGetLoginDataReturnsDataWhenNotRevoked(): void
    {
        $loginData = [
            'iss' => 'https://op.example.org',
            'sub' => 'user-1',
            'sid' => 'op-session-1',
            RequestDataHandler::KEY_LOGGED_IN_AT => time() - 60,
        ];
        $this->sessionStoreMock->method('get')
            ->with(RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn($loginData);

        $this->loginRevocationRegistryMock->method('isSessionRevoked')->willReturn(false);
        $this->loginRevocationRegistryMock->method('isSubjectRevoked')->willReturn(false);

        $this->sessionStoreMock->expects($this->never())->method('delete');

        $this->assertSame($loginData, $this->sut()->getLoginData());
    }

    public function testGetLoginDataSkipsRevocationCheckWithoutIssuer(): void
    {
        $loginData = [
            'iss' => null,
            'sub' => 'user-1',
            'sid' => 'op-session-1',
            RequestDataHandler::KEY_LOGGED_IN_AT => time() - 60,
        ];
        $this->sessionStoreMock->method('get')
            ->with(RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn($loginData);

        $this->loginRevocationRegistryMock->expects($this->never())->method('isSessionRevoked');
        $this->loginRevocationRegistryMock->expects($this->never())->method('isSubjectRevoked');

        $this->assertSame($loginData, $this->sut()->getLoginData());
    }
}
