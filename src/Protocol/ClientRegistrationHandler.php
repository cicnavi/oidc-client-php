<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\HttpMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use Throwable;

/**
 * Handler for client registration requests as per OpenID Connect Dynamic
 * Client Registration 1.0 (RFC 7591), and subsequent client configuration
 * management (read, update, delete) as per RFC 7592.
 * @see \Cicnavi\Tests\Oidc\Protocol\ClientRegistrationHandlerTest
 */
class ClientRegistrationHandler
{
    public function __construct(
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        protected readonly GuzzleBridge $guzzleBridge = new GuzzleBridge(),
        protected readonly Client $httpClient = new Client(),
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Register a client on the OP's client registration endpoint (HTTP POST).
     *
     * @param string $registrationEndpoint The OP's client registration
     * endpoint ("registration_endpoint" from OP metadata).
     * @param mixed[] $clientMetadata Client metadata to register.
     * @param ?string $initialAccessToken Optional Initial Access Token, used
     * as a Bearer token when the OP protects its registration endpoint.
     * @return mixed[] Client information response claims (client_id,
     * client_secret, registration_access_token...).
     * @throws OidcClientException
     */
    public function register(
        string $registrationEndpoint,
        array $clientMetadata,
        ?string $initialAccessToken = null,
    ): array {
        $this->logger?->debug(
            'Sending client registration request.',
            ['registrationEndpoint' => $registrationEndpoint, 'clientMetadata' => $clientMetadata],
        );

        $response = $this->sendJsonRequest(
            HttpMethodsEnum::POST,
            $registrationEndpoint,
            $clientMetadata,
            $initialAccessToken,
            'Client registration',
        );

        // Per OpenID Connect Dynamic Client Registration 1.0, a successful
        // registration response uses HTTP 201 Created.
        $this->validateResponseStatusCode($response, [201], 'Client registration');

        return $this->decodeJsonResponse($response, 'Client registration');
    }

    /**
     * Read the current client registration from the OP's client configuration
     * endpoint (HTTP GET), as per RFC 7592.
     *
     * @param string $registrationClientUri The client configuration endpoint
     * ("registration_client_uri" from the client information response).
     * @param string $registrationAccessToken Registration Access Token issued
     * at registration.
     * @return mixed[] Client information response claims.
     * @throws OidcClientException
     */
    public function read(
        string $registrationClientUri,
        string $registrationAccessToken,
    ): array {
        $this->logger?->debug(
            'Sending client registration read request.',
            ['registrationClientUri' => $registrationClientUri],
        );

        $response = $this->sendJsonRequest(
            HttpMethodsEnum::GET,
            $registrationClientUri,
            null,
            $registrationAccessToken,
            'Client registration read',
        );

        $this->validateResponseStatusCode($response, [200], 'Client registration read');

        return $this->decodeJsonResponse($response, 'Client registration read');
    }

    /**
     * Update the client registration on the OP's client configuration
     * endpoint (HTTP PUT), as per RFC 7592.
     *
     * Note that per RFC 7592 the update request must contain the whole client
     * metadata set (not a partial update), including the "client_id" claim.
     * Claims which must not be sent in update requests
     * (registration_access_token, registration_client_uri, client_id_issued_at,
     * client_secret_expires_at) are removed automatically.
     *
     * @param string $registrationClientUri The client configuration endpoint
     * ("registration_client_uri" from the client information response).
     * @param string $registrationAccessToken Registration Access Token issued
     * at registration.
     * @param mixed[] $clientMetadata Full client metadata set to update to,
     * including the "client_id" claim.
     * @return mixed[] Client information response claims.
     * @throws OidcClientException
     */
    public function update(
        string $registrationClientUri,
        string $registrationAccessToken,
        array $clientMetadata,
    ): array {
        $clientId = $clientMetadata[ClaimsEnum::ClientId->value] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new OidcClientException(
                'Client registration update metadata must contain a valid "client_id" claim.',
            );
        }

        // Per RFC 7592, these claims must not be included in update requests.
        unset(
            $clientMetadata[ClaimsEnum::RegistrationAccessToken->value],
            $clientMetadata[ClaimsEnum::RegistrationClientUri->value],
            $clientMetadata[ClaimsEnum::ClientIdIssuedAt->value],
            $clientMetadata[ClaimsEnum::ClientSecretExpiresAt->value],
        );

        $this->logger?->debug(
            'Sending client registration update request.',
            ['registrationClientUri' => $registrationClientUri, 'clientMetadata' => $clientMetadata],
        );

        $response = $this->sendJsonRequest(
            HttpMethodsEnum::PUT,
            $registrationClientUri,
            $clientMetadata,
            $registrationAccessToken,
            'Client registration update',
        );

        $this->validateResponseStatusCode($response, [200], 'Client registration update');

        return $this->decodeJsonResponse($response, 'Client registration update');
    }

    /**
     * Delete (deprovision) the client registration on the OP's client
     * configuration endpoint (HTTP DELETE), as per RFC 7592.
     *
     * @param string $registrationClientUri The client configuration endpoint
     * ("registration_client_uri" from the client information response).
     * @param string $registrationAccessToken Registration Access Token issued
     * at registration.
     * @throws OidcClientException
     */
    public function delete(
        string $registrationClientUri,
        string $registrationAccessToken,
    ): void {
        $this->logger?->debug(
            'Sending client registration delete request.',
            ['registrationClientUri' => $registrationClientUri],
        );

        $response = $this->sendJsonRequest(
            HttpMethodsEnum::DELETE,
            $registrationClientUri,
            null,
            $registrationAccessToken,
            'Client registration delete',
        );

        $this->validateResponseStatusCode($response, [204], 'Client registration delete');
    }

    /**
     * Send an HTTP request with an optional JSON body and optional Bearer
     * token authorization.
     *
     * @param ?mixed[] $jsonBody Body to send as JSON, or null for no body.
     * @throws OidcClientException
     */
    protected function sendJsonRequest(
        HttpMethodsEnum $httpMethod,
        string $uri,
        ?array $jsonBody,
        ?string $bearerToken,
        string $context,
    ): ResponseInterface {
        try {
            $request = $this->httpRequestFactory
                ->createRequest($httpMethod->value, $uri)
                ->withHeader('Accept', 'application/json');

            if (is_string($bearerToken)) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
            }

            if (is_array($jsonBody)) {
                $bodyStream = $this->guzzleBridge->psr7StreamFor(
                    json_encode($jsonBody, JSON_THROW_ON_ERROR),
                );
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($bodyStream);
            }

            return $this->httpClient->sendRequest($request);
        } catch (Throwable $throwable) {
            $message = sprintf('%s request error. %s', $context, $throwable->getMessage());
            $this->logger?->error($message);
            throw new OidcClientException($message, $throwable->getCode(), $throwable);
        }
    }

    /**
     * Ensure that the response has one of the expected HTTP status codes.
     * Error responses are returned as JSON objects with 'error' and optional
     * 'error_description' members, so surface those when present.
     *
     * @param int[] $expectedStatusCodes
     * @throws OidcClientException If the response does not indicate success.
     */
    protected function validateResponseStatusCode(
        ResponseInterface $response,
        array $expectedStatusCodes,
        string $context,
    ): void {
        $httpStatusCode = $response->getStatusCode();
        if (in_array($httpStatusCode, $expectedStatusCodes, true)) {
            return;
        }

        $message = sprintf(
            '%s was not successful (HTTP %s - %s).',
            $context,
            $httpStatusCode,
            $response->getReasonPhrase(),
        );

        try {
            $errorData = $this->decodeJsonOrThrow((string) $response->getBody());
            $error = $errorData[ParamsEnum::Error->value] ?? null;
            $errorDescription = $errorData[ParamsEnum::ErrorDescription->value] ?? null;
            if (is_string($error)) {
                $message = sprintf(
                    '%s rejected by OpenID Provider - error "%s"%s',
                    $context,
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
     * @return mixed[] Decoded JSON from the response body.
     * @throws OidcClientException
     */
    protected function decodeJsonResponse(ResponseInterface $response, string $context): array
    {
        $responseBody = (string) $response->getBody();

        try {
            return $this->decodeJsonOrThrow($responseBody);
        } catch (Throwable $throwable) {
            $message = sprintf('%s JSON response is not valid.', $context);
            $this->logger?->error($message, ['responseBody' => $responseBody]);
            throw new OidcClientException($message, $throwable->getCode(), $throwable);
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
}
