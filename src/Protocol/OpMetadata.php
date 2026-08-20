<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientExceptionInterface as PsrHttpClientClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * OpenID Provider metadata, from the OP's OIDC configuration URL.
 *
 * The document is fetched when a value is first asked for, not when this object
 * is built. Constructing something should not make an HTTP request: it made
 * every consumer pay for a network round trip whether or not it went on to read
 * any metadata, and it made the object impossible to build at all while the OP
 * was unreachable - which is how a local logout, needing nothing from the OP,
 * ended up depending on the OP being up.
 *
 * @see \Cicnavi\Tests\Oidc\OpMetadataTest
 */
class OpMetadata implements MetadataInterface
{
    /**
     * @var ?mixed[] OpMetadata values (OIDC Configuration URL content), or
     * null until something asks for one of them.
     */
    protected ?array $metadata = null;

    /**
     * @var string Key used to store metadata values.
     */
    protected const OIDC_METADATA_CACHE_KEY = 'OIDC_METADATA';

    /**
     * @var string[]
     */
    public const REQUIRED_OIDC_CONFIGURATION_PARAMETERS = [
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'jwks_uri',
        'response_types_supported',
        'subject_types_supported',
        'id_token_signing_alg_values_supported',
    ];

    public function __construct(
        protected readonly string $opConfigurationUrl,
        protected readonly CacheInterface $cache,
        protected readonly ClientInterface $httpClient = new Client(),
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        protected ?int $defaultCacheTtl = null
    ) {
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If OIDC Provider (OP) metadata could not be
     * fetched, or the key does not exist.
     */
    public function get(string $key): mixed
    {
        $metadata = $this->resolveMetadata();

        if (! isset($metadata[$key])) {
            throw new OidcClientException(sprintf('OIDC metadata parameter not supported (%s)', $key));
        }

        return $metadata[$key];
    }

    /**
     * The metadata document, fetched from cache or from the OP the first time
     * it is needed and kept for the lifetime of this object afterwards.
     *
     * @return mixed[]
     * @throws OidcClientException If OIDC Provider (OP) metadata could not be
     * fetched.
     */
    protected function resolveMetadata(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }

        try {
            if (!is_array($metadata = $this->cache->get(self::OIDC_METADATA_CACHE_KEY) ?? $this->requestMetadata())) {
                throw new OidcClientException('Unexpected metadata type.');
            }
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'OIDC Provider (OP) Metadata fetch error. ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this->metadata = $metadata;
    }

    /**
     * Fetch data from OIDC configuration URL and store it in a cache.
     *
     * @return mixed[] OIDC metadata values (OIDC configuration URL content).
     * @throws OidcClientException
     */
    protected function requestMetadata(): array
    {
        try {
            $request = $this->httpRequestFactory
                ->createRequest('GET', $this->opConfigurationUrl)
                ->withHeader('Accept', 'application/json');
            $response = $this->httpClient->sendRequest($request);
        } catch (PsrHttpClientClientExceptionInterface) {
            throw new OidcClientException('Could not fetch OIDC configuration from provided URL.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new OidcClientException('OIDC configuration fetch request did not return 200 OK status code.');
        }

        if (!is_array($metadata = json_decode((string) $response->getBody(), true))) {
            throw new OidcClientException('Could not decode JSON response from OIDC configuration URL.');
        }

        $this->validateMetadata($metadata);

        try {
            $this->cache->set(self::OIDC_METADATA_CACHE_KEY, $metadata, $this->defaultCacheTtl);
            return $metadata;
        } catch (Throwable) {
            throw new OidcClientException('Could not store fetched OIDC configuration to cache.');
        }
    }

    /**
     * @param mixed[] $metadata
     * @return bool True if valid, else false.
     */
    protected function isValidMetadata(array $metadata): bool
    {
        return !array_diff(self::REQUIRED_OIDC_CONFIGURATION_PARAMETERS, array_keys($metadata));
    }

    /**
     * @param mixed[] $metadata
     * @throws OidcClientException If OIDC Provider (OP) metdata is not valid.
     */
    protected function validateMetadata($metadata): void
    {
        if (! $this->isValidMetadata($metadata)) {
            throw new OidcClientException('OIDC Provider (OP) metadata is not valid.');
        }
    }
}
