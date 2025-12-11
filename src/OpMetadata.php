<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Psr\Http\Client\ClientExceptionInterface as PsrHttpClientClientExceptionInterface;
use Psr\SimpleCache\CacheInterface;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;
use Throwable;

class OpMetadata implements MetadataInterface
{
    /**
     * @var mixed[] OpMetadata values (OIDC Configuration URL content).
     */
    protected array $metadata;

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

    /**
     * OpMetadata constructor.
     * @throws OidcClientException If OIDC Provider (OP) metadata could not be fetched.
     */
    public function __construct(
        protected readonly string $opConfigurationUrl,
        protected readonly CacheInterface $cache,
        protected readonly ClientInterface $httpClient = new Client(),
        protected readonly RequestFactoryInterface $httpRequestFactory = new RequestFactory(),
        protected ?int $defaultCacheTtl = null
    ) {
        try {
            if (!is_array($metadata = $this->cache->get(self::OIDC_METADATA_CACHE_KEY) ?? $this->requestMetadata())) {
                throw new OidcClientException('Unexpected metadata type.');
            }

            $this->metadata = $metadata;
        } catch (Throwable $throwable) {
            throw new OidcClientException('OIDC Provider (OP) Metadata fetch error. ' . $throwable->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        if (! isset($this->metadata[$key])) {
            throw new OidcClientException(sprintf('OIDC metadata parameter not supported (%s)', $key));
        }

        return $this->metadata[$key];
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
