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
#[UsesClass(PreRegisteredClient::class)]
#[UsesClass(\Cicnavi\Oidc\DataStore\DataHandlers\AbstractDataHandler::class)]
#[UsesClass(\Cicnavi\Oidc\Protocol\RequestDataHandler::class)]
#[UsesClass(\Cicnavi\Oidc\Helpers\HttpHelper::class)]
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

    protected MockObject $sessionStoreMock;

    protected MockObject $requestDataHandlerMock;

    protected function setUp(): void
    {
        $this->registrationStoreMock = $this->createMock(ClientRegistrationStoreInterface::class);
        $this->registrationHandlerMock = $this->createMock(ClientRegistrationHandler::class);
        $this->metadataMock = $this->createMock(MetadataInterface::class);
        $this->cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $this->preRegisteredClientMock = $this->createMock(PreRegisteredClient::class);
        $this->sessionStoreMock = $this->createMock(SessionStoreInterface::class);
        $this->requestDataHandlerMock = $this->createMock(\Cicnavi\Oidc\Protocol\RequestDataHandler::class);

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
        array $postLogoutRedirectUris = [],
        ?\Cicnavi\Oidc\Protocol\RequestDataHandler $requestDataHandler = null,
        bool $injectRequestDataHandler = true,
    ): DynamicallyRegisteredClient {
        $registrationStore ??= $this->registrationStoreMock;
        $cache ??= $this->cacheMock;
        $metadata ??= $this->metadataMock;
        $registrationHandler ??= $this->registrationHandlerMock;

        if ($injectPreRegisteredClient) {
            $preRegisteredClient ??= $this->preRegisteredClientMock;
        }

        if ($injectRequestDataHandler) {
            $requestDataHandler ??= $this->requestDataHandlerMock;
        }

        $this->assertInstanceOf(ClientRegistrationStoreInterface::class, $registrationStore);
        $this->assertInstanceOf(ClientRegistrationHandler::class, $registrationHandler);
        $this->assertInstanceOf(SessionStoreInterface::class, $this->sessionStoreMock);
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
            sessionStore: $this->sessionStoreMock,
            httpClient: $this->createStub(\GuzzleHttp\Client::class),
            metadata: $metadata,
            requestDataHandler: $requestDataHandler,
            registrationHandler: $registrationHandler,
            preRegisteredClient: $preRegisteredClient,
            postLogoutRedirectUris: $postLogoutRedirectUris,
        );
    }

    /**
     * Client information response carrying the fingerprint of the metadata
     * the default sut() would currently send, so register() considers the
     * persisted registration in sync (no drift update / re-registration).
     *
     * @return mixed[]
     */
    protected function clientInformationResponseWithCurrentFingerprint(): array
    {
        return array_merge($this->clientInformationResponse, [
            DynamicallyRegisteredClient::CLAIM_REQUESTED_METADATA_FINGERPRINT =>
                $this->sut()->buildClientRegistrationMetadataFingerprint(),
        ]);
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
            'scope' => 'openid profile custom-scope',
            'contacts' => ['admin@example.org'],
        ])->buildClientRegistrationMetadata();

        $this->assertSame('openid profile custom-scope', $clientMetadata['scope']);
        $this->assertSame(['admin@example.org'], $clientMetadata['contacts']);
    }

    public function testAdditionalClientMetadataAllowsRuntimeCompatibleOverrides(): void
    {
        $clientMetadata = $this->sut(additionalClientMetadata: [
            'token_endpoint_auth_method' => 'client_secret_basic',
            'redirect_uris' => [$this->redirectUri, 'https://rp.example.org/other-callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ])->buildClientRegistrationMetadata();

        $this->assertSame(
            [$this->redirectUri, 'https://rp.example.org/other-callback'],
            $clientMetadata['redirect_uris'],
        );
        $this->assertSame(['authorization_code', 'refresh_token'], $clientMetadata['grant_types']);
    }

    public function testThrowsOnUnsupportedTokenEndpointAuthMethodOverride(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('token_endpoint_auth_method');

        $this->sut(additionalClientMetadata: ['token_endpoint_auth_method' => 'client_secret_post']);
    }

    public function testThrowsWhenRedirectUrisOverrideExcludesConfiguredRedirectUri(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('redirect_uris');

        $this->sut(additionalClientMetadata: ['redirect_uris' => ['https://other.example.org/callback']]);
    }

    public function testThrowsWhenGrantTypesOverrideExcludesAuthorizationCode(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('grant_types');

        $this->sut(additionalClientMetadata: ['grant_types' => ['client_credentials']]);
    }

    public function testThrowsWhenResponseTypesOverrideExcludesCode(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('response_types');

        $this->sut(additionalClientMetadata: ['response_types' => ['token']]);
    }

    public function testAllowsScopeSupersetOverride(): void
    {
        $clientMetadata = $this->sut(additionalClientMetadata: [
            'scope' => $this->scope . ' offline_access',
        ])->buildClientRegistrationMetadata();

        $this->assertSame($this->scope . ' offline_access', $clientMetadata['scope']);
    }

    public function testThrowsWhenScopeOverrideExcludesRuntimeScope(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('scope');

        // Runtime scope is 'openid profile' - override drops 'profile'.
        $this->sut(additionalClientMetadata: ['scope' => 'openid custom-scope']);
    }

    public function testUpdateRegistrationThrowsOnRuntimeIncompatibleOverride(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $this->registrationHandlerMock->expects($this->never())->method('update');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('token_endpoint_auth_method');

        $this->sut()->updateRegistration(['token_endpoint_auth_method' => 'client_secret_post']);
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

        // Registration is persisted under the current key and per client ID.
        $this->registrationStoreMock->expects($this->exactly(2))
            ->method('set')
            ->with(
                $this->isString(),
                $this->callback(
                    function (array $claims): bool {
                        $this->assertSame('client-id', $claims['client_id']);
                        // Requested metadata fingerprint is persisted for
                        // configuration drift detection.
                        $this->assertArrayHasKey(
                            DynamicallyRegisteredClient::CLAIM_REQUESTED_METADATA_FINGERPRINT,
                            $claims,
                        );
                        return true;
                    },
                ),
            );

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
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationHandlerMock->expects($this->never())->method('update');

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
            ->willReturn(array_merge($this->clientInformationResponse, ['client_id' => 'renewed-client-id']));

        // Replaced (expired) registration's per-client entry is removed.
        $this->registrationStoreMock->expects($this->once())
            ->method('delete')
            ->with($this->stringEndsWith('|client-id'));

        $registrationData = $this->sut()->register();

        $this->assertFalse($registrationData->isClientSecretExpired());
        $this->assertSame('renewed-client-id', $registrationData->getClientId());
    }

    public function testCanForceNewRegistration(): void
    {
        // A valid (non-expired) registration exists, but a new one is forced.
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->willReturn(array_merge($this->clientInformationResponse, ['client_id' => 'forced-client-id']));

        // Replaced registration's client secret is still valid, so its
        // per-client entry is kept for in-flight authorization flows.
        $this->registrationStoreMock->expects($this->never())->method('delete');

        $registrationData = $this->sut()->register(forceNewRegistration: true);

        $this->assertSame('forced-client-id', $registrationData->getClientId());
    }

    public function testRegisterUpdatesRegistrationOnClientMetadataChange(): void
    {
        // Persisted registration was requested with different (stale) metadata.
        $staleClaims = array_merge($this->clientInformationResponse, [
            DynamicallyRegisteredClient::CLAIM_REQUESTED_METADATA_FINGERPRINT => 'stale-fingerprint',
        ]);
        $this->registrationStoreMock->method('get')->willReturn($staleClaims);

        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationHandlerMock->expects($this->once())
            ->method('update')
            ->with(
                $this->clientInformationResponse['registration_client_uri'],
                $this->clientInformationResponse['registration_access_token'],
                $this->isArray(),
            )
            ->willReturn($this->clientInformationResponse);

        // Refreshed fingerprint is persisted with the updated registration.
        $this->registrationStoreMock->expects($this->exactly(2))
            ->method('set')
            ->with(
                $this->isString(),
                $this->callback(
                    function (array $claims): bool {
                        $this->assertNotSame(
                            'stale-fingerprint',
                            $claims[DynamicallyRegisteredClient::CLAIM_REQUESTED_METADATA_FINGERPRINT],
                        );
                        return true;
                    },
                ),
            );

        $registrationData = $this->sut()->register();

        $this->assertSame('client-id', $registrationData->getClientId());
    }

    public function testRegisterPerformsNewRegistrationWhenMetadataChangeUpdateNotPossible(): void
    {
        // Persisted registration with stale metadata and no management claims
        // (no registration_client_uri / registration_access_token).
        $this->registrationStoreMock->method('get')->willReturn([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'client_secret_expires_at' => 0,
        ]);
        $this->metadataMock->method('get')->with('registration_endpoint')
            ->willReturn($this->registrationEndpoint);

        $this->registrationHandlerMock->expects($this->once())
            ->method('register')
            ->willReturn(array_merge($this->clientInformationResponse, ['client_id' => 'new-client-id']));

        $registrationData = $this->sut()->register();

        $this->assertSame('new-client-id', $registrationData->getClientId());
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
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $this->preRegisteredClientMock->expects($this->once())
            ->method('authorize')
            ->willReturn(null);

        $this->assertNotInstanceOf(\Psr\Http\Message\ResponseInterface::class, $this->sut()->authorize());
    }

    public function testPostLogoutRedirectUrisAreIncludedInClientRegistrationMetadata(): void
    {
        $clientMetadata = $this->sut(
            postLogoutRedirectUris: ['https://rp.example.org/logged-out'],
        )->buildClientRegistrationMetadata();

        $this->assertSame(
            ['https://rp.example.org/logged-out'],
            $clientMetadata['post_logout_redirect_uris'],
        );
    }

    public function testPostLogoutRedirectUrisAreAbsentByDefault(): void
    {
        $this->assertArrayNotHasKey(
            'post_logout_redirect_uris',
            $this->sut()->buildClientRegistrationMetadata(),
        );
    }

    public function testThrowsForInvalidPostLogoutRedirectUri(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Post logout redirect URIs must be non-empty strings.');

        $this->sut(postLogoutRedirectUris: ['']);
    }

    public function testThrowsWhenPostLogoutRedirectUrisOverrideExcludesConfiguredUri(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('post_logout_redirect_uris');

        $this->sut(
            additionalClientMetadata: [
                'post_logout_redirect_uris' => ['https://rp.example.org/other'],
            ],
            postLogoutRedirectUris: ['https://rp.example.org/logged-out'],
        );
    }

    public function testLogoutUsesLoginDataWithoutPerformingRegistration(): void
    {
        // Logout must not perform or update client registration.
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationHandlerMock->expects($this->never())->method('update');
        // No persisted registration exists at all - logout still works from
        // session-stored login data.
        $this->registrationStoreMock->method('get')->willReturn(null);

        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')
            ->willReturn('https://op.example.org/end-session');
        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn('id-token');
        $this->requestDataHandlerMock->method('getLoginClientId')->willReturn('login-client-id');
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->expects($this->once())
            ->method('buildEndSessionParameters')
            ->with(
                'id-token',
                'login-client-id',
                'https://rp.example.org/logged-out',
                'logout-state',
                null,
                null,
            )
            ->willReturn(['id_token_hint' => 'id-token']);
        $this->requestDataHandlerMock->expects($this->once())->method('clearLoginData');

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool => str_starts_with(
                    $location,
                    'https://op.example.org/end-session?'
                ) && str_contains($location, 'id_token_hint=id-token'))
            )
            ->willReturn($response);

        $result = $this->sut()->logout(
            postLogoutRedirectUri: 'https://rp.example.org/logged-out',
            response: $response,
        );
        $this->assertSame($response, $result);
    }

    public function testLogoutFallsBackToPersistedRegistrationClientId(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationHandlerMock->expects($this->never())->method('update');
        // Persisted registration exists (note: without the current metadata
        // fingerprint, which would trigger an update if registration was
        // resolved) - only its client ID is read.
        $this->registrationStoreMock->method('get')->willReturn($this->clientInformationResponse);

        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')
            ->willReturn('https://op.example.org/end-session');
        $this->requestDataHandlerMock->method('getLoginClientId')->willReturn(null);
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->expects($this->once())
            ->method('buildEndSessionParameters')
            ->with(null, 'client-id', null, 'logout-state', null, null)
            ->willReturn([]);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);

        $result = $this->sut()->logout(response: $response);
        $this->assertSame($response, $result);
    }

    public function testLogoutUsesEndSessionEndpointFromOpMetadataAsFallback(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');

        $this->metadataMock->expects($this->exactly(1))->method('get')->willReturnMap([
            ['end_session_endpoint', 'https://op.example.org/end-session'],
        ]);

        $this->requestDataHandlerMock->method('getLoginEndSessionEndpoint')->willReturn(null);
        $this->requestDataHandlerMock->method('getLogoutState')->willReturn('logout-state');
        $this->requestDataHandlerMock->method('buildEndSessionParameters')->willReturn([]);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                $this->callback(fn(string $location): bool => str_starts_with(
                    $location,
                    'https://op.example.org/end-session'
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
            new OidcClientException('OIDC metadata parameter not supported'),
        );

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('End session endpoint not found in OP metadata');

        $this->sut()->logout();
    }

    public function testValidateLogoutCallbackDoesNotTouchRegistration(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationHandlerMock->expects($this->never())->method('update');
        $this->registrationStoreMock->expects($this->never())->method('get');

        $this->requestDataHandlerMock->expects($this->once())
            ->method('validateLogoutCallbackResponse')
            ->with(null, true);

        $this->sut()->validateLogoutCallback();
    }

    public function testGetIdTokenUsesSessionLoginData(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationStoreMock->expects($this->never())->method('get');

        $this->requestDataHandlerMock->method('getLoginIdToken')->willReturn('id-token');

        $this->assertSame('id-token', $this->sut()->getIdToken());
    }

    public function testGetLoginDataUsesSessionLoginData(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');
        $this->registrationStoreMock->expects($this->never())->method('get');

        $this->requestDataHandlerMock->method('getLoginData')->willReturn(['id_token' => 'id-token']);

        $this->assertSame(['id_token' => 'id-token'], $this->sut()->getLoginData());
    }

    public function testGetIdTokenWithLazilyBuiltRequestDataHandler(): void
    {
        $this->registrationHandlerMock->expects($this->never())->method('register');

        // Login data is read from the session store via a lazily built
        // request data handler (no instance provided in constructor).
        $this->sessionStoreMock->method('get')
            ->with(\Cicnavi\Oidc\Protocol\RequestDataHandler::KEY_LOGIN_DATA)
            ->willReturn(['id_token' => 'id-token']);

        $this->assertSame(
            'id-token',
            $this->sut(injectRequestDataHandler: false)->getIdToken(),
        );
    }

    public function testGetUserDataDelegatesToPreRegisteredClient(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willReturn(['sub' => 'user-id']);

        $this->assertSame(['sub' => 'user-id'], $this->sut()->getUserData());
    }

    public function testGetUserDataWrapsErrors(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willThrowException(new \Exception('Some error.'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('User data error');

        $this->sut()->getUserData();
    }

    public function testAuthorizeBindsFlowClientIdToSession(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        // Only the (public) client ID is bound to the session, never claims
        // containing client credentials.
        $this->sessionStoreMock->expects($this->once())
            ->method('put')
            ->with($this->isString(), 'client-id');

        $this->preRegisteredClientMock->expects($this->once())->method('authorize');

        $this->sut()->authorize();
    }

    public function testGetUserDataUsesFlowRegistrationBoundToSession(): void
    {
        $this->sessionStoreMock->method('get')->willReturn('flow-client-id');
        // Flow client ID binding is removed from session after successful use.
        $this->sessionStoreMock->expects($this->once())->method('delete');

        // Flow registration is resolved per client ID, taking precedence over
        // the current persisted registration.
        $this->registrationStoreMock->expects($this->once())
            ->method('get')
            ->with($this->stringContains('flow-client-id'))
            ->willReturn([
                'client_id' => 'flow-client-id',
                'client_secret' => 'flow-client-secret',
                'client_secret_expires_at' => 0,
            ]);

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willReturn(['sub' => 'user-id']);

        $this->assertSame(['sub' => 'user-id'], $this->sut()->getUserData());
    }

    public function testGetUserDataKeepsFlowRegistrationOnError(): void
    {
        $this->sessionStoreMock->method('get')->willReturn('flow-client-id');
        // Flow binding is kept in session, so the callback can be retried.
        $this->sessionStoreMock->expects($this->never())->method('delete');

        $this->registrationStoreMock->method('get')->willReturn([
            'client_id' => 'flow-client-id',
            'client_secret' => 'flow-client-secret',
            'client_secret_expires_at' => 0,
        ]);

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willThrowException(new \Exception('Token error.'));

        $this->expectException(OidcClientException::class);

        $this->sut()->getUserData();
    }

    public function testGetUserDataFallsBackToPersistedRegistrationOnUnresolvableFlowClientId(): void
    {
        $this->sessionStoreMock->method('get')->willReturn('flow-client-id');

        // No per-client registration available for the flow client ID, so the
        // current persisted registration is used.
        $this->registrationStoreMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                fn (string $key): ?array => str_contains($key, 'flow-client-id') ?
                null :
                $this->clientInformationResponseWithCurrentFingerprint(),
            );

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willReturn(['sub' => 'user-id']);

        $this->assertSame(['sub' => 'user-id'], $this->sut()->getUserData());
    }

    public function testGetUserDataFallsBackToPersistedRegistrationOnInvalidFlowRegistration(): void
    {
        $this->sessionStoreMock->method('get')->willReturn('flow-client-id');
        // Invalid flow binding is discarded, and session is cleared after success.
        $this->sessionStoreMock->expects($this->exactly(2))->method('delete');

        // Per-client registration entry is invalid (no valid client_id).
        $this->registrationStoreMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                fn (string $key): array => str_contains($key, 'flow-client-id') ?
                ['client_id' => ''] :
                $this->clientInformationResponseWithCurrentFingerprint(),
            );

        $this->preRegisteredClientMock->expects($this->once())
            ->method('getUserData')
            ->willReturn(['sub' => 'user-id']);

        $this->assertSame(['sub' => 'user-id'], $this->sut()->getUserData());
    }

    public function testResolvePreRegisteredClientBuildsNewInstanceOnEachCall(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $sut = $this->sut(injectPreRegisteredClient: false);

        // No memoization, so the current registration is always honored.
        $this->assertNotSame($sut->resolvePreRegisteredClient(), $sut->resolvePreRegisteredClient());
    }

    public function testResolvePreRegisteredClientThrowsWhenNoClientSecretIssued(): void
    {
        $clientInformationWithoutSecret = [
            'client_id' => 'client-id',
            DynamicallyRegisteredClient::CLAIM_REQUESTED_METADATA_FINGERPRINT =>
                $this->sut()->buildClientRegistrationMetadataFingerprint(),
        ];
        $this->registrationStoreMock->method('get')->willReturn($clientInformationWithoutSecret);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client secret');

        $this->sut(injectPreRegisteredClient: false)->resolvePreRegisteredClient();
    }

    public function testCanReadRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

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

        // Registration is persisted under the current key and per client ID.
        $this->registrationStoreMock->expects($this->exactly(2))
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
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

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

        $this->registrationStoreMock->expects($this->exactly(2))->method('set');

        $registrationData = $this->sut()->updateRegistration(['client_name' => 'Updated Client']);

        $this->assertSame('Updated Client', $registrationData->getClaims()['client_name']);
    }

    public function testCanDeleteRegistration(): void
    {
        $this->registrationStoreMock->method('get')->willReturn(
            $this->clientInformationResponseWithCurrentFingerprint(),
        );

        $this->registrationHandlerMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->clientInformationResponse['registration_client_uri'],
                $this->clientInformationResponse['registration_access_token'],
            );

        // Current and per-client registration entries are deleted.
        $this->registrationStoreMock->expects($this->exactly(2))
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
