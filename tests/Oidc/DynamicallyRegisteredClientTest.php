<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DynamicallyRegisteredClient;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Cicnavi\Oidc\PreRegisteredClient;
use Cicnavi\Oidc\Protocol\ClientRegistrationHandler;
use Cicnavi\Oidc\Registration\ClientRegistrationData;
use Cicnavi\Oidc\Registration\Interfaces\ClientRegistrationStoreInterface;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DynamicallyRegisteredClient::class)]
#[UsesClass(ClientRegistrationData::class)]
final class DynamicallyRegisteredClientTest extends TestCase
{
    protected string $opConfigurationUrl = 'https://op.example.org/.well-known/openid-configuration';

    protected string $redirectUri = 'https://rp.example.org/callback';

    protected string $scope = 'openid profile';

    protected string $registrationEndpoint = 'https://op.example.org/register';

    /**
     * @var mixed[]
     */
    protected array $clientInformationResponse = [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'client_secret_expires_at' => 0,
        'registration_access_token' => 'registration-access-token',
        'registration_client_uri' => 'https://op.example.org/register/client-id',
    ];

    protected MockObject $registrationStoreMock;

    protected MockObject $registrationHandlerMock;

    protected MockObject $metadataMock;

    protected MockObject $cacheMock;

    protected MockObject $preRegisteredClientMock;

    protected function setUp(): void
    {
        $this->registrationStoreMock = $this->createMock(ClientRegistrationStoreInterface::class);
        $this->registrationHandlerMock = $this->createMock(ClientRegistrationHandler::class);
        $this->metadataMock = $this->createMock(MetadataInterface::class);
        $this->cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $this->preRegisteredClientMock = $this->createMock(PreRegisteredClient::class);

        // By default, keep cache valid to avoid side-effects in most tests.
        $this->cacheMock->method('get')->with('OIDC_OP_CONFIGURATION_URL')
            ->willReturn($this->opConfigurationUrl);
    }

    protected function sut(
        ?string $opConfigurationUrl = null,
        ?string $redirectUri = null,
        ?string $scope = null,
        ?string $clientName = null,
        ?string $initialAccessToken = null,
        ?ClientRegistrationStoreInterface $registrationStore = null,
        array $additionalClientMetadata = [],
        bool $includeSoftwareId = true,
        ?\Psr\SimpleCache\CacheInterface $cache = null,
        ?MetadataInterface $metadata = null,
        ?ClientRegistrationHandler $registrationHandler = null,
        ?PreRegisteredClient $preRegisteredClient = null,
        bool $injectPreRegisteredClient = true,
    ): DynamicallyRegisteredClient {
        $registrationStore ??= $this->registrationStoreMock;
        $cache ??= $this->cacheMock;
        $metadata ??= $this->metadataMock;
        $registrationHandler ??= $this->registrationHandlerMock;

        if ($injectPreRegisteredClient) {
            $preRegisteredClient ??= $this->preRegisteredClientMock;
        }

        $this->assertInstanceOf(ClientRegistrationStoreInterface::class, $registrationStore);
        $this->assertInstanceOf(ClientRegistrationHandler::class, $registrationHandler);
        $this->assertInstanceOf(
            SessionStoreInterface::class,
            $this->createStub(\Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface::class),
        );
        $this->assertInstanceOf(Client::class, $this->createStub(\GuzzleHttp\Client::class));

        return new DynamicallyRegisteredClient(
            opConfigurationUrl: $opConfigurationUrl ?? $this->opConfigurationUrl,
            redirectUri: $redirectUri ?? $this->redirectUri,
            scope: $scope ?? $this->scope,
            clientName: $clientName,
            initialAccessToken: $initialAccessToken,
            registrationStore: $registrationStore,
            additionalClientMetadata: $additionalClientMetadata,
            includeSoftwareId: $includeSoftwareId,
            cache: $cache,
            sessionStore: $this->createStub(\Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface::class),
            httpClient: $this->createStub(\GuzzleHttp\Client::class),
            metadata: $metadata,
            registrationHandler: $registrationHandler,
            preRegisteredClient: $preRegisteredClient,
        );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(DynamicallyRegisteredClient::class, $this->sut());
    }

    public function testCanGetMetadata(): void
    {
        $this->assertSame($this->metadataMock, $this->sut()->getMetadata());
    }

    public function testRegistrationStoreKeyContainsOpConfigurationUrlAndRedirectUri(): void
    {
        $registrationStoreKey = $this->sut()->getRegistrationStoreKey();

        $this->assertStringContainsString($this->opConfigurationUrl, $registrationStoreKey);
        $this->assertStringContainsString($this->redirectUri, $registrationStoreKey);
    }

    public function testCanBuildClientRegistrationMetadata(): void
    {
        $clientMetadata = $this->sut(clientName: 'Test Client')->buildClientRegistrationMetadata();

        $this->assertSame([$this->redirectUri], $clientMetadata['redirect_uris']);
        $this->assertSame(['authorization_code'], $clientMetadata['grant_types']);
        $this->assertSame(['code'], $clientMetadata['response_types']);
        $this->assertSame('client_secret_basic', $clientMetadata['token_endpoint_auth_method']);
        $this->assertSame($this->scope, $clientMetadata['scope']);
        $this->assertSame('Test Client', $clientMetadata['client_name']);
        $this->assertArrayHasKey('software_id', $clientMetadata);
    }

    public function testCanBuildClientRegistrationMetadataWithoutOptionalClaims(): void
    {
        $clientMetadata = $this->sut(includeSoftwareId: false)->buildClientRegistrationMetadata();

        $this->assertArrayNotHasKey('client_name', $clientMetadata);
        $this->assertArrayNotHasKey('software_id', $clientMetadata);
    }

    public function testAdditionalClientMetadataOverridesPreparedClaims(): void
    {
        $clientMetadata = $this->sut(additionalClientMetadata: [
            'token_endpoint_auth_method' => 'client_secret_post',
            'contacts' => ['admin@example.org'],
        ])->buildClientRegistrationMetadata();

        $this->assertSame('client_secret_post', $clientMetadata['token_endpoint_auth_method']);
        $this->assertSame(['admin@example.org'], $clientMetadata['contacts']);
    }

    public function testCanRegisterClient(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(null);
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->with(
                $this->registrationEndpoint,
                $this->callback(
                    function (array $clientMetadata): bool {
                        $this->assertSame([$this->redirectUri], $clientMetadata['redirect_uris']);
                        return true;
                    },
                ),
                null,
            )
            ->willReturn($this->clientInformationResponse);

        $this->registrationStoreMock->expects($this->once())
            ->method('set')
            ->with($this->isString(), $this->clientInformationResponse);

        $registrationData = $this->sut()->register();

        $this->assertSame('client-id', $registrationData->getClientId());
        $this->assertSame('client-secret', $registrationData->getClientSecret());
    }

    public function testRegisterUsesInitialAccessToken(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(null);
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->with($this->registrationEndpoint, $this->isArray(), 'initial-access-token')
            ->willReturn($this->clientInformationResponse);

        $this->sut(initialAccessToken: 'initial-access-token')->register();
    }

    public function testRegisterReturnsPersistedRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);
        $this->registrationHandlerMock->expects($this->never())->method('register');

        $registrationData = $this->sut()->register();

        $this->assertSame('client-id', $registrationData->getClientId());
    }

    public function testRegisterPerformsNewRegistrationOnExpiredClientSecret(): void
    {
        $expiredClientInformation = array_merge($this->clientInformationResponse, [
            'client_secret_expires_at' => time() - 60,
        ]);

        $this->registrationStoreMock->method('get')->willReturn($expiredClientInformation);
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->willReturn($this->clientInformationResponse);

        $registrationData = $this->sut()->register();

        $this->assertFalse($registrationData->isClientSecretExpired());
    }

    public function testCanForceNewRegistration(): void
    {
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationStoreMock->expects($this->never())->method('get');
        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->willReturn($this->clientInformationResponse);

        $this->sut()->register(forceNewRegistration: true);
    }

    public function testRegisterThrowsWhenRegistrationEndpointNotAvailable(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(null);
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willThrowException(new OidcClientException('OIDC metadata parameter not supported.'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('registration_endpoint');

        $this->sut()->register();
    }

    public function testAuthorizeDelegatesToPreRegisteredClient(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->preRegisteredClientMock->expects($this->once())
            ->method('authorize')
            ->willReturn(null);

        $this->assertNotInstanceOf(\Psr\Http\Message\ResponseInterface::class, $this->sut()->authorize());
    }

    public function testGetUserDataDelegatesToPreRegisteredClient(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willReturn(['sub' => 'user-id']);

        $this->assertSame(['sub' => 'user-id'], $this->sut()->getUserData());
    }

    public function testGetUserDataWrapsErrors(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willThrowException(new \Exception('Some error.'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('User data error');

        $this->sut()->getUserData();
    }

    public function testResolvePreRegisteredClientThrowsWhenNoClientSecretIssued(): void
    {
        $clientInformationWithoutSecret = ['client_id' => 'client-id'];
        $this->registrationStoreMock->method('get')->willReturn($clientInformationWithoutSecret);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client secret');

        $this->sut(injectPreRegisteredClient: false)->resolvePreRegisteredClient();
    }

    public function testCanReadRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        // Simulate OP not repeating registration management claims in read response.
        $readResponse = [
            'client_id' => 'client-id',
            'client_secret' => 'rotated-client-secret',
            'client_secret_expires_at' => 0,
        ];

        $this->registrationHandlerMock->expects($this->once())
            ->method('read')
            ->with(
                $this->clientInformationResponse['registration_client_uri'],
                $this->clientInformationResponse['registration_access_token'],
            )
            ->willReturn($readResponse);

        $this->registrationStoreMock->expects($this->once())
            ->method('set')
            ->with(
                $this->isString(),
                $this->callback(
                    function (array $claims): bool {
                        $this->assertSame('rotated-client-secret', $claims['client_secret']);
                        $this->assertSame('registration-access-token', $claims['registration_access_token']);
                        return true;
                    },
                ),
            );

        $registrationData = $this->sut()->readRegistration();

        $this->assertSame('rotated-client-secret', $registrationData->getClientSecret());
        // Previous claims are kept when not repeated in the response.
        $this->assertSame('registration-access-token', $registrationData->getRegistrationAccessToken());
    }

    public function testCanUpdateRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->registrationHandlerMock->expects($this->once())
            ->method('update')
            ->with(
                $this->clientInformationResponse['registration_client_uri'],
                $this->clientInformationResponse['registration_access_token'],
                $this->callback(
                    function (array $clientMetadata): bool {
                        $this->assertSame('client-id', $clientMetadata['client_id']);
                        $this->assertSame('Updated Client', $clientMetadata['client_name']);
                        return true;
                    },
                ),
            )
            ->willReturn(array_merge($this->clientInformationResponse, ['client_name' => 'Updated Client']));

        $this->registrationStoreMock->expects($this->once())->method('set');

        $registrationData = $this->sut()->updateRegistration(['client_name' => 'Updated Client']);

        $this->assertSame('Updated Client', $registrationData->getClaims()['client_name']);
    }

    public function testCanDeleteRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->registrationHandlerMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->clientInformationResponse['registration_client_uri'],
                $this->clientInformationResponse['registration_access_token'],
            );

        $this->registrationStoreMock->expects($this->once())
            ->method('delete')
            ->with($this->isString());

        $this->sut()->deleteRegistration();
    }

    public function testManagementThrowsWhenNoRegistrationExists(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('No existing client registration');

        $this->sut()->readRegistration();
    }

    public function testManagementThrowsWhenRegistrationClientUriNotAvailable(): void
    {
        $this->registrationStoreMock->method('get')->willReturn([
            'client_id' => 'client-id',
            'registration_access_token' => 'registration-access-token',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('registration_client_uri');

        $this->sut()->readRegistration();
    }

    public function testManagementThrowsWhenRegistrationAccessTokenNotAvailable(): void
    {
        $this->registrationStoreMock->method('get')->willReturn([
            'client_id' => 'client-id',
            'registration_client_uri' => 'https://op.example.org/register/client-id',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('registration_access_token');

        $this->sut()->readRegistration();
    }

    public function testCacheIsReinitializedOnOpConfigurationUrlChange(): void
    {
        $cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cacheMock->method('get')->with('OIDC_OP_CONFIGURATION_URL')->willReturn('https://other.example.org');
        $cacheMock->expects($this->once())->method('clear');
        $cacheMock->expects($this->once())->method('set')
            ->with('OIDC_OP_CONFIGURATION_URL', $this->opConfigurationUrl);

        $this->sut(cache: $cacheMock);
    }

    public function testThrowsOnCacheError(): void
    {
        $cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cacheMock->method('get')->willThrowException(new \Exception('Cache error.'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Cache validation error');

        $this->sut(cache: $cacheMock);
    }
}
