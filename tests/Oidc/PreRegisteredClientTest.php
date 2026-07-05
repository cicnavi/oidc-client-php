<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use SimpleSAML\OpenID\Codebooks\ClientAuthenticationMethodsEnum;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use DateInterval;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use Cicnavi\Oidc\Helpers\HttpHelper;

#[CoversClass(PreRegisteredClient::class)]
#[UsesClass(HttpHelper::class)]
final class PreRegisteredClientTest extends TestCase
{
    private string $opConfigrationUrl;

    private string $clientId;

    private string $clientSecret;

    private string $redirectUri;

    private string $scope;

    private bool $usePkce;

    private PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod;

    private \DateInterval $timestampValidationLeeway;

    private bool $useState;

    private bool $useNonce;

    private bool $fetchUserinfoClaims;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\SimpleSAML\OpenID\SupportedAlgorithms
     */
    private \PHPUnit\Framework\MockObject\Stub $supportedAlgorithmsMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\SimpleSAML\OpenID\SupportedSerializers
     */
    private \PHPUnit\Framework\MockObject\Stub $supportedSerializersMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\Psr\Log\LoggerInterface
     */
    private \PHPUnit\Framework\MockObject\Stub $loggerMock;

    private \PHPUnit\Framework\MockObject\MockObject $cacheMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface
     */
    private \PHPUnit\Framework\MockObject\Stub $sessionStoreMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\GuzzleHttp\Client
     */
    private \PHPUnit\Framework\MockObject\Stub $httpClientMock;

    private \PHPUnit\Framework\MockObject\MockObject $metadataMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\SimpleSAML\OpenID\Core
     */
    private \PHPUnit\Framework\MockObject\Stub $coreMock;

    /**
     * @var \PHPUnit\Framework\MockObject\Stub&\SimpleSAML\OpenID\Jwks
     */
    private \PHPUnit\Framework\MockObject\Stub $jwksMock;

    private \DateInterval $maxCacheDuration;

    private AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod;

    private \PHPUnit\Framework\MockObject\MockObject $requestDataHandlerMock;

    protected function setUp(): void
    {
        $this->opConfigrationUrl = 'https://example.org/op-configuration';
        $this->clientId = 'client-id';
        $this->clientSecret = 'client-secret';
        $this->redirectUri = 'https://example.org/callback';
        $this->scope = 'openid profile';
        $this->usePkce = true;
        $this->pkceCodeChallengeMethod = PkceCodeChallengeMethodEnum::S256;
        $this->timestampValidationLeeway = new \DateInterval('PT1M');
        $this->useState = true;
        $this->useNonce = true;
        $this->fetchUserinfoClaims = true;
        $this->supportedAlgorithmsMock = $this->createStub(SupportedAlgorithms::class);
        $this->supportedSerializersMock = $this->createStub(SupportedSerializers::class);
        $this->loggerMock = $this->createStub(\Psr\Log\LoggerInterface::class);
        $this->cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $this->sessionStoreMock = $this->createStub(SessionStoreInterface::class);
        $this->httpClientMock = $this->createStub(Client::class);
        $this->metadataMock = $this->createMock(MetadataInterface::class);
        $this->coreMock = $this->createStub(\SimpleSAML\OpenID\Core::class);
        $this->jwksMock = $this->createStub(\SimpleSAML\OpenID\Jwks::class);
        $this->maxCacheDuration = new \DateInterval('PT6H');
        $this->defaultAuthorizationRequestMethod = AuthorizationRequestMethodEnum::FormPost;
        $this->requestDataHandlerMock = $this->createMock(RequestDataHandler::class);

        // By default, keep cache valid to avoid side-effects in most tests
        $this->cacheMock->method('get')->with('OIDC_OP_CONFIGURATION_URL')->willReturn($this->opConfigrationUrl);
    }

    protected function sut(
        ?string $opConfigurationUrl = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = null,
        ?string $scope = null,
        ?bool $usePkce = null,
        ?PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod = null,
        ?\DateInterval $timestampValidationLeeway = null,
        ?bool $useState = null,
        ?bool $useNonce = null,
        ?bool $fetchUserinfoClaims = null,
        ?SupportedAlgorithms $supportedAlgorithms = null,
        ?SupportedSerializers $supportedSerializers = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\Psr\SimpleCache\CacheInterface $cache = null,
        ?SessionStoreInterface $sessionStore = null,
        ?Client $httpClient = null,
        ?MetadataInterface $metadata = null,
        ?\SimpleSAML\OpenID\Core $core = null,
        ?\SimpleSAML\OpenID\Jwks $jwks = null,
        ?DateInterval $maxCacheDuration = null,
        ?AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = null,
        ?ResponseModesEnum $responseMode = null,
        ?RequestDataHandler $requestDataHandler = null,
    ): PreRegisteredClient {
        return new PreRegisteredClient(
            $opConfigurationUrl ?? $this->opConfigrationUrl,
            $clientId ?? $this->clientId,
            $clientSecret ?? $this->clientSecret,
            $redirectUri ?? $this->redirectUri,
            $scope ?? $this->scope,
            $usePkce ?? $this->usePkce,
            $pkceCodeChallengeMethod ?? $this->pkceCodeChallengeMethod,
            $timestampValidationLeeway ?? $this->timestampValidationLeeway,
            $useState ?? $this->useState,
            $useNonce ?? $this->useNonce,
            $fetchUserinfoClaims ?? $this->fetchUserinfoClaims,
            $supportedAlgorithms ?? $this->supportedAlgorithmsMock,
            $supportedSerializers ?? $this->supportedSerializersMock,
            $logger ?? $this->loggerMock,
            $cache ?? $this->cacheMock,
            $sessionStore ?? $this->sessionStoreMock,
            $httpClient ?? $this->httpClientMock,
            $metadata ?? $this->metadataMock,
            $core ?? $this->coreMock,
            $jwks ?? $this->jwksMock,
            $maxCacheDuration ?? $this->maxCacheDuration,
            $defaultAuthorizationRequestMethod ?? $this->defaultAuthorizationRequestMethod,
            $responseMode,
            $requestDataHandler ?? $this->requestDataHandlerMock,
        );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(PreRegisteredClient::class, $this->sut());
    }

    public function testReinitializeCache(): void
    {
        $this->cacheMock->expects($this->once())->method('clear');
        $this->cacheMock->expects($this->once())->method('set')->with(
            'OIDC_OP_CONFIGURATION_URL',
            $this->opConfigrationUrl
        );

        $this->sut()->reinitializeCache();
    }

    public function testValidateCacheTriggersReinitializeWhenUrlDiffers(): void
    {
        // Simulate a different OP configuration URL than cached to trigger reinitialization
        $differentUrl = 'https://different.example.org/op-configuration';

        $this->cacheMock->expects($this->once())->method('clear');
        $this->cacheMock->expects($this->once())->method('set')->with(
            'OIDC_OP_CONFIGURATION_URL',
            $differentUrl
        );

        $this->sut(opConfigurationUrl: $differentUrl);
    }

    public function testValidateCacheThrowsOnException(): void
    {
        $this->cacheMock->method('get')->willThrowException(new \Exception('Cache error'));

        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage('Cache validation error. Cache error');

        $this->sut();
    }

    public function testGetMetadata(): void
    {
        $sut = $this->sut();
        $this->assertSame($this->metadataMock, $sut->getMetadata());
    }

    public function testAuthorizeFormPostWithResponse(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', null],
            ['require_pushed_authorization_requests', null],
        ]);

        $this->requestDataHandlerMock->expects($this->once())->method('getState')->willReturn('state-123');
        $this->requestDataHandlerMock->expects($this->once())->method('getNonce')->willReturn('nonce-123');
        $this->requestDataHandlerMock->expects($this->once())->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock
            ->expects($this->once())
            ->method('generateCodeChallengeFromCodeVerifier')
            ->with('code-verifier', $this->pkceCodeChallengeMethod)
            ->willReturn('code-challenge');

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $html): bool => str_contains($html, '<form') &&
                str_contains($html, 'name="client_id"') &&
                str_contains($html, 'name="response_type"') &&
                str_contains($html, 'name="code_challenge"') &&
                str_contains($html, 'name="code_challenge_method"')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($body);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->sut()->authorize(AuthorizationRequestMethodEnum::FormPost, $response);
        $this->assertSame($response, $result);
    }

    public function testAuthorizeRedirectGetWithResponse(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', null],
            ['require_pushed_authorization_requests', null],
        ]);

        $this->requestDataHandlerMock->method('getState')->willReturn('state-abc');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce-abc');
        $this->requestDataHandlerMock->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock
            ->method('generateCodeChallengeFromCodeVerifier')
            ->willReturn('code-challenge');

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool => str_starts_with(
                    $location,
                    'https://auth.example.org/authorize?'
                ) &&
                    str_contains($location, 'response_type=code') &&
                    str_contains($location, 'client_id=' . urlencode($this->clientId)) &&
                    str_contains($location, 'redirect_uri=' . urlencode($this->redirectUri)) &&
                    str_contains($location, 'scope=' . urlencode($this->scope)))
            )
            ->willReturn($response);

        $result = $this->sut()->authorize(AuthorizationRequestMethodEnum::Query, $response);
        $this->assertSame($response, $result);
    }

    public function testGetUserDataSuccess(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')
            ->willReturn([
                \SimpleSAML\OpenID\Codebooks\ParamsEnum::Code->value => 'auth-code-123',
            ]);

        $this->metadataMock->expects($this->exactly(5))->method('get')->willReturnMap([
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::JwksUri->value, 'https://op.example.org/jwks'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::TokenEndpoint->value, 'https://op.example.org/token'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::UserinfoEndpoint->value, 'https://op.example.org/userinfo'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::Issuer->value, 'https://op.example.org'],
            [
                \SimpleSAML\OpenID\Codebooks\ClaimsEnum::EndSessionEndpoint->value,
                'https://op.example.org/end-session',
            ],
        ]);

        $expected = ['sub' => 'user-1'];
        $this->requestDataHandlerMock->expects($this->once())->method('getUserData')->willReturn($expected);

        $result = $this->sut()->getUserData();
        $this->assertSame($expected, $result);
    }

    public function testGetUserDataThrowsIfMissingJwksUri(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')
            ->willReturn([
                \SimpleSAML\OpenID\Codebooks\ParamsEnum::Code->value => 'auth-code-123',
            ]);

        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::JwksUri->value, null],
        ]);

        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage('JWKS URI not found in OP metadata.');

        $this->sut()->getUserData();
    }

    public function testGetUserDataThrowsIfMissingTokenEndpoint(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')
            ->willReturn([
                \SimpleSAML\OpenID\Codebooks\ParamsEnum::Code->value => 'auth-code-123',
            ]);

        $this->metadataMock->expects($this->exactly(2))->method('get')->willReturnMap([
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::JwksUri->value, 'https://op.example.org/jwks'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::TokenEndpoint->value, null],
        ]);

        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage('Token endpoint not found in OP metadata.');

        $this->sut()->getUserData();
    }

    public function testConstructorThrowsIfResponseModeIsFragment(): void
    {
        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage("The 'fragment' response mode is not supported");

        $this->sut(responseMode: ResponseModesEnum::Fragment);
    }

    public function testAuthorizeThrowsIfResponseModeIsFragment(): void
    {
        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage("The 'fragment' response mode is not supported");

        $this->sut()->authorize(
            authorizationRequestMethod: AuthorizationRequestMethodEnum::Query,
            responseMode: ResponseModesEnum::Fragment
        );
    }

    public function testAuthorizeQueryWithResponseMode(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', null],
            ['require_pushed_authorization_requests', null],
        ]);

        $this->requestDataHandlerMock->method('getState')->willReturn('state-abc');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce-abc');
        $this->requestDataHandlerMock->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock
            ->method('generateCodeChallengeFromCodeVerifier')
            ->willReturn('code-challenge');

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool =>
                    str_contains($location, 'response_mode=query'))
            )
            ->willReturn($response);

        $result = $this->sut()->authorize(
            AuthorizationRequestMethodEnum::Query,
            $response,
            ResponseModesEnum::Query
        );
        $this->assertSame($response, $result);
    }

    public function testGetParModeDefaultsToAuto(): void
    {
        $this->assertSame(ParModeEnum::Auto, $this->sut()->getParMode());
    }

    public function testAuthorizeUsesParAndReducesFrontChannelRequest(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', 'https://auth.example.org/par'],
            ['require_pushed_authorization_requests', true],
        ]);

        $this->requestDataHandlerMock->method('getState')->willReturn('state-123');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce-123');
        $this->requestDataHandlerMock->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock->method('generateCodeChallengeFromCodeVerifier')
            ->willReturn('code-challenge');

        $this->requestDataHandlerMock->method('resolvePushedAuthorizationRequestEndpoint')
            ->willReturn('https://auth.example.org/par');

        // The full authorization parameter set must be pushed (with client auth).
        $this->requestDataHandlerMock->expects($this->once())
            ->method('pushAuthorizationRequest')
            ->with(
                ClientAuthenticationMethodsEnum::ClientSecretBasic,
                'https://auth.example.org/par',
                $this->callback(function (array $parameters): bool {
                    $this->assertSame('code', $parameters['response_type'] ?? null);
                    $this->assertSame($this->clientId, $parameters['client_id'] ?? null);
                    $this->assertSame($this->scope, $parameters['scope'] ?? null);
                    $this->assertSame('code-challenge', $parameters['code_challenge'] ?? null);
                    return true;
                }),
                $this->clientId,
                $this->clientSecret,
            )
            ->willReturn(['request_uri' => 'urn:par:abc', 'expires_in' => 60]);

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $html): bool =>
                // Only client_id + request_uri are delivered in the front channel.
                str_contains($html, 'name="client_id"') &&
                str_contains($html, 'name="request_uri"') &&
                str_contains($html, 'value="urn:par:abc"') &&
                !str_contains($html, 'name="response_type"') &&
                !str_contains($html, 'name="scope"')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($body);
        $response->method('withHeader')->with('Content-Type', 'text/html')->willReturn($response);

        $result = $this->sut()->authorize(AuthorizationRequestMethodEnum::FormPost, $response);
        $this->assertSame($response, $result);
    }

    public function testAuthorizeDoesNotUseParWhenResolverReturnsNull(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', null],
            ['require_pushed_authorization_requests', null],
        ]);

        $this->requestDataHandlerMock->method('getState')->willReturn('state-abc');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce-abc');
        $this->requestDataHandlerMock->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock->method('generateCodeChallengeFromCodeVerifier')
            ->willReturn('code-challenge');

        $this->requestDataHandlerMock->method('resolvePushedAuthorizationRequestEndpoint')->willReturn(null);
        $this->requestDataHandlerMock->expects($this->never())->method('pushAuthorizationRequest');

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool =>
                    str_contains($location, 'response_type=code') &&
                    !str_contains($location, 'request_uri'))
            )
            ->willReturn($response);

        $result = $this->sut()->authorize(AuthorizationRequestMethodEnum::Query, $response);
        $this->assertSame($response, $result);
    }

    public function testAuthorizeFormPostWithResponseMode(): void
    {
        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
            ['pushed_authorization_request_endpoint', null],
            ['require_pushed_authorization_requests', null],
        ]);

        $this->requestDataHandlerMock->method('getState')->willReturn('state-123');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce-123');
        $this->requestDataHandlerMock->method('getCodeVerifier')->willReturn('code-verifier');
        $this->requestDataHandlerMock
            ->method('generateCodeChallengeFromCodeVerifier')
            ->willReturn('code-challenge');

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $html): bool =>
                str_contains($html, 'name="response_mode"') && str_contains($html, 'value="form_post"')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($body);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->sut()->authorize(
            AuthorizationRequestMethodEnum::FormPost,
            $response,
            ResponseModesEnum::FormPost
        );
        $this->assertSame($response, $result);
    }

    public function testLogoutRedirectWithResponse(): void
    {
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')->willReturn(null);
        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn('id-token');
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->expects($this->once())
            ->method('buildEndSessionParameters')
            ->with(
                'id-token',
                $this->clientId,
                'https://rp.example.org/logged-out',
                'logout-state',
                null,
                null,
            )
            ->willReturn([
                'id_token_hint' => 'id-token',
                'client_id' => $this->clientId,
                'post_logout_redirect_uri' => 'https://rp.example.org/logged-out',
                'state' => 'logout-state',
            ]);

        $this->requestDataHandlerMock->expects($this->once())->method('clearLoginData');

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool => str_starts_with(
                    $location,
                    'https://op.example.org/end-session?'
                ) &&
                    str_contains($location, 'id_token_hint=id-token') &&
                    str_contains($location, 'client_id=' . urlencode($this->clientId)) &&
                    str_contains(
                        $location,
                        'post_logout_redirect_uri=' . urlencode('https://rp.example.org/logged-out')
                    ) &&
                    str_contains($location, 'state=logout-state'))
            )
            ->willReturn($response);

        $result = $this->sut()->logout(
            postLogoutRedirectUri: 'https://rp.example.org/logged-out',
            response: $response,
        );
        $this->assertSame($response, $result);
    }

    public function testLogoutUsesEndSessionEndpointFromLoginData(): void
    {
        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')
            ->willReturn('https://op.example.org/end-session-from-login');
        $this->metadataMock->expects($this->never())->method('get');

        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->method('buildEndSessionParameters')->willReturn([]);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool => str_starts_with(
                    $location,
                    'https://op.example.org/end-session-from-login'
                ))
            )
            ->willReturn($response);

        $result = $this->sut()->logout(response: $response);
        $this->assertSame($response, $result);
    }

    public function testLogoutThrowsWhenEndSessionEndpointNotAvailable(): void
    {
        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')->willReturn(null);
        $this->metadataMock->method('get')->willThrowException(
            new \Cicnavi\Oidc\Exceptions\OidcClientException('OIDC metadata parameter not supported'),
        );

        $this->expectException(\Cicnavi\Oidc\Exceptions\OidcClientException::class);
        $this->expectExceptionMessage('End session endpoint not found in OP metadata');

        $this->sut()->logout();
    }

    public function testLogoutFormPostWithResponse(): void
    {
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->method('buildEndSessionParameters')->willReturn([
            'id_token_hint' => 'id-token',
        ]);

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $html): bool => str_contains($html, '<form') &&
                str_contains($html, 'action="https://op.example.org/end-session"') &&
                str_contains($html, 'name="id_token_hint"')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($body);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = $this->sut()->logout(
            logoutRequestMethod: AuthorizationRequestMethodEnum::FormPost,
            response: $response,
        );
        $this->assertSame($response, $result);
    }

    public function testLogoutPrefersLoginTimeClientId(): void
    {
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        // The client registration changed between login and logout, so the
        // 'client_id' logout parameter must match the one the stored ID
        // token (id_token_hint) was issued to.
        $this->requestDataHandlerMock->method('getLoginClientId')->willReturn('login-time-client-id');
        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn('id-token');
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->expects($this->once())
            ->method('buildEndSessionParameters')
            ->with(
                'id-token',
                'login-time-client-id',
                null,
                'logout-state',
                null,
                null,
            )
            ->willReturn([]);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);

        $result = $this->sut()->logout(response: $response);
        $this->assertSame($response, $result);
    }

    public function testLogoutWarnsWhenNoIdTokenHintAvailable(): void
    {
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        // No login data available (e.g., the application session was
        // destroyed before calling logout()).
        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn(null);
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->method('buildEndSessionParameters')->willReturn([]);

        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('id_token_hint'));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);

        $result = $this->sut(logger: $loggerMock)->logout(response: $response);
        $this->assertSame($response, $result);
    }

    public function testLogoutWithoutStateOmitsLogoutState(): void
    {
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        $this->requestDataHandlerMock->expects($this->never())->method('getLogoutState');
        $this->requestDataHandlerMock->expects($this->once())
            ->method('buildEndSessionParameters')
            ->with(null, $this->clientId, null, null, null, null)
            ->willReturn([]);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);

        $result = $this->sut(useState: false)->logout(response: $response);
        $this->assertSame($response, $result);
    }

    public function testValidateLogoutCallbackDelegates(): void
    {
        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);

        $this->requestDataHandlerMock->expects($this->once())
            ->method('validateLogoutCallbackResponse')
            ->with($request, true);

        $this->sut()->validateLogoutCallback($request);
    }

    public function testValidateLogoutCallbackHonorsUseStateSetting(): void
    {
        $this->requestDataHandlerMock->expects($this->once())
            ->method('validateLogoutCallbackResponse')
            ->with(null, false);

        $this->sut(useState: false)->validateLogoutCallback();
    }

    public function testGetIdTokenDelegates(): void
    {
        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn('id-token');

        $this->assertSame('id-token', $this->sut()->getIdToken());
    }

    public function testGetLoginDataDelegates(): void
    {
        $this->requestDataHandlerMock->method('getLoginData')->willReturn(['id_token' => 'id-token']);

        $this->assertSame(['id_token' => 'id-token'], $this->sut()->getLoginData());
    }

    public function testHandleBackchannelLogoutRequestSuccess(): void
    {
        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);

        $this->requestDataHandlerMock->expects($this->once())
            ->method('parseBackchannelLogoutRequest')
            ->with($request)
            ->willReturn('logout-token');

        $this->metadataMock->method('get')->willReturnMap([
            ['jwks_uri', 'https://op.example.org/jwks'],
            ['issuer', 'https://op.example.org'],
        ]);

        $logoutTokenJws = $this->createStub(\SimpleSAML\OpenID\Core\LogoutToken::class);

        $this->requestDataHandlerMock->expects($this->once())
            ->method('validateLogoutToken')
            ->with(
                'logout-token',
                'https://op.example.org/jwks',
                'https://op.example.org',
                $this->clientId,
            )
            ->willReturn($logoutTokenJws);

        $this->requestDataHandlerMock->expects($this->once())
            ->method('registerLogoutTokenRevocation')
            ->with($logoutTokenJws);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())->method('withStatus')->with(200)->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Cache-Control', 'no-store')
            ->willReturn($response);

        $this->assertSame($response, $this->sut()->handleBackchannelLogoutRequest($request, $response));
    }

    public function testHandleBackchannelLogoutRequestRespondsWith400OnInvalidLogoutToken(): void
    {
        $this->requestDataHandlerMock->method('parseBackchannelLogoutRequest')->willReturn('logout-token');

        $this->metadataMock->method('get')->willReturnMap([
            ['jwks_uri', 'https://op.example.org/jwks'],
            ['issuer', 'https://op.example.org'],
        ]);

        $this->requestDataHandlerMock->method('validateLogoutToken')
            ->willThrowException(new \Cicnavi\Oidc\Exceptions\OidcClientException('Logout token is not valid.'));

        $this->requestDataHandlerMock->expects($this->never())->method('registerLogoutTokenRevocation');

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->stringContains('Logout token is not valid.'));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())->method('withStatus')->with(400)->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $response->method('getBody')->willReturn($body);

        $this->assertSame($response, $this->sut()->handleBackchannelLogoutRequest(null, $response));
    }

    public function testHandleBackchannelLogoutRequestRespondsWith400OnParseError(): void
    {
        $this->requestDataHandlerMock->method('parseBackchannelLogoutRequest')
            ->willThrowException(new \Cicnavi\Oidc\Exceptions\OidcClientException(
                'Back-channel logout request must use the HTTP POST method.',
            ));

        $this->requestDataHandlerMock->expects($this->never())->method('validateLogoutToken');

        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->stringContains('must use the HTTP POST method'));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())->method('withStatus')->with(400)->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $response->method('getBody')->willReturn($body);

        $this->assertSame($response, $this->sut()->handleBackchannelLogoutRequest(null, $response));
    }
}
