<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Protocol;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Cicnavi\Oidc\Protocol\ClientRegistrationHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(ClientRegistrationHandler::class)]
#[UsesClass(RequestFactory::class)]
#[UsesClass(GuzzleBridge::class)]
final class ClientRegistrationHandlerTest extends TestCase
{
    protected MockObject $httpClientMock;

    protected ?RequestInterface $capturedRequest = null;

    protected string $registrationEndpoint = 'https://op.example.org/register';

    protected string $registrationClientUri = 'https://op.example.org/register/client-id';

    protected string $registrationAccessToken = 'registration-access-token';

    /**
     * @var mixed[]
     */
    protected array $clientMetadata = [
        'redirect_uris' => ['https://rp.example.org/callback'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ];

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

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(Client::class);
        $this->capturedRequest = null;
    }

    protected function sut(
        ?Client $httpClient = null,
    ): ClientRegistrationHandler {
        $httpClient ??= $this->httpClientMock;
        $this->assertInstanceOf(Client::class, $httpClient);

        return new ClientRegistrationHandler(
            httpClient: $httpClient,
        );
    }

    protected function mockHttpResponse(Response $response): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($response): Response {
                $this->capturedRequest = $request;
                return $response;
            });
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(ClientRegistrationHandler::class, $this->sut());
    }

    public function testCanRegisterClient(): void
    {
        $this->mockHttpResponse(new Response(201, [], json_encode($this->clientInformationResponse)));

        $claims = $this->sut()->register($this->registrationEndpoint, $this->clientMetadata);

        $this->assertSame($this->clientInformationResponse, $claims);

        $this->assertInstanceOf(RequestInterface::class, $this->capturedRequest);
        $this->assertSame('POST', $this->capturedRequest->getMethod());
        $this->assertSame($this->registrationEndpoint, (string) $this->capturedRequest->getUri());
        $this->assertSame('application/json', $this->capturedRequest->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $this->capturedRequest->getHeaderLine('Accept'));
        $this->assertFalse($this->capturedRequest->hasHeader('Authorization'));
        $this->assertSame(
            $this->clientMetadata,
            json_decode((string) $this->capturedRequest->getBody(), true),
        );
    }

    public function testCanRegisterClientWithInitialAccessToken(): void
    {
        $this->mockHttpResponse(new Response(201, [], json_encode($this->clientInformationResponse)));

        $this->sut()->register($this->registrationEndpoint, $this->clientMetadata, 'initial-access-token');

        $this->assertInstanceOf(RequestInterface::class, $this->capturedRequest);
        $this->assertSame(
            'Bearer initial-access-token',
            $this->capturedRequest->getHeaderLine('Authorization'),
        );
    }

    public function testRegisterThrowsOnUnexpectedStatusCode(): void
    {
        $this->mockHttpResponse(new Response(200, [], json_encode($this->clientInformationResponse)));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('not successful');

        $this->sut()->register($this->registrationEndpoint, $this->clientMetadata);
    }

    public function testRegisterSurfacesErrorResponse(): void
    {
        $this->mockHttpResponse(new Response(400, [], json_encode([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'Redirect URI is not valid.',
        ])));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('invalid_redirect_uri');

        $this->sut()->register($this->registrationEndpoint, $this->clientMetadata);
    }

    public function testRegisterThrowsOnInvalidJsonResponse(): void
    {
        $this->mockHttpResponse(new Response(201, [], 'not-json'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('JSON response is not valid');

        $this->sut()->register($this->registrationEndpoint, $this->clientMetadata);
    }

    public function testRegisterWrapsHttpClientErrors(): void
    {
        $this->httpClientMock->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new \Exception('Connection error.'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('request error');

        $this->sut()->register($this->registrationEndpoint, $this->clientMetadata);
    }

    public function testCanReadClientRegistration(): void
    {
        $this->mockHttpResponse(new Response(200, [], json_encode($this->clientInformationResponse)));

        $claims = $this->sut()->read($this->registrationClientUri, $this->registrationAccessToken);

        $this->assertSame($this->clientInformationResponse, $claims);

        $this->assertInstanceOf(RequestInterface::class, $this->capturedRequest);
        $this->assertSame('GET', $this->capturedRequest->getMethod());
        $this->assertSame($this->registrationClientUri, (string) $this->capturedRequest->getUri());
        $this->assertSame(
            'Bearer ' . $this->registrationAccessToken,
            $this->capturedRequest->getHeaderLine('Authorization'),
        );
    }

    public function testCanUpdateClientRegistration(): void
    {
        $this->mockHttpResponse(new Response(200, [], json_encode($this->clientInformationResponse)));

        $updateMetadata = array_merge(
            ['client_id' => 'client-id'],
            $this->clientMetadata,
            [
                'registration_access_token' => 'must-be-removed',
                'registration_client_uri' => 'must-be-removed',
                'client_id_issued_at' => 123,
                'client_secret_expires_at' => 456,
            ],
        );

        $claims = $this->sut()->update(
            $this->registrationClientUri,
            $this->registrationAccessToken,
            $updateMetadata,
        );

        $this->assertSame($this->clientInformationResponse, $claims);

        $this->assertInstanceOf(RequestInterface::class, $this->capturedRequest);
        $this->assertSame('PUT', $this->capturedRequest->getMethod());
        $this->assertSame(
            'Bearer ' . $this->registrationAccessToken,
            $this->capturedRequest->getHeaderLine('Authorization'),
        );

        $sentMetadata = json_decode((string) $this->capturedRequest->getBody(), true);
        $this->assertIsArray($sentMetadata);
        $this->assertSame('client-id', $sentMetadata['client_id']);
        // Per RFC 7592, these claims must not be sent in update requests.
        $this->assertArrayNotHasKey('registration_access_token', $sentMetadata);
        $this->assertArrayNotHasKey('registration_client_uri', $sentMetadata);
        $this->assertArrayNotHasKey('client_id_issued_at', $sentMetadata);
        $this->assertArrayNotHasKey('client_secret_expires_at', $sentMetadata);
    }

    public function testUpdateThrowsOnMissingClientId(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client_id');

        $this->sut()->update(
            $this->registrationClientUri,
            $this->registrationAccessToken,
            $this->clientMetadata,
        );
    }

    public function testCanDeleteClientRegistration(): void
    {
        $this->mockHttpResponse(new Response(204));

        $this->sut()->delete($this->registrationClientUri, $this->registrationAccessToken);

        $this->assertInstanceOf(RequestInterface::class, $this->capturedRequest);
        $this->assertSame('DELETE', $this->capturedRequest->getMethod());
        $this->assertSame(
            'Bearer ' . $this->registrationAccessToken,
            $this->capturedRequest->getHeaderLine('Authorization'),
        );
    }

    public function testDeleteThrowsOnUnexpectedStatusCode(): void
    {
        $this->mockHttpResponse(new Response(403, [], json_encode(['error' => 'invalid_token'])));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('invalid_token');

        $this->sut()->delete($this->registrationClientUri, $this->registrationAccessToken);
    }
}
