<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
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

    private \PHPUnit\Framework\MockObject\Stub $supportedAlgorithmsMock;

    private \PHPUnit\Framework\MockObject\Stub $supportedSerializersMock;

    private \PHPUnit\Framework\MockObject\Stub $loggerMock;

    private \PHPUnit\Framework\MockObject\MockObject $cacheMock;

    private \PHPUnit\Framework\MockObject\Stub $sessionStoreMock;

    private \PHPUnit\Framework\MockObject\Stub $httpClientMock;

    private \PHPUnit\Framework\MockObject\MockObject $metadataMock;

    private \PHPUnit\Framework\MockObject\Stub $coreMock;

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
        ?\DateInterval $maxCacheDuration = null,
        ?AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = null,
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
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
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
        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['authorization_endpoint', 'https://auth.example.org/authorize'],
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

        $this->metadataMock->expects($this->exactly(3))->method('get')->willReturnMap([
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::JwksUri->value, 'https://op.example.org/jwks'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::TokenEndpoint->value, 'https://op.example.org/token'],
            [\SimpleSAML\OpenID\Codebooks\ClaimsEnum::UserinfoEndpoint->value, 'https://op.example.org/userinfo'],
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
}
