<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Http\RequestFactory;
use Psr\Http\Client\ClientExceptionInterface as PsrHttpClientClientExceptionInterface;
use Psr\SimpleCache\CacheInterface;
use Cicnavi\Oidc\Interfaces\{ConfigInterface, MetadataInterface};
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;
use Throwable;

class Metadata implements MetadataInterface
{
    /**
     * @var ConfigInterface $config
     */
    protected ConfigInterface $config;

    /**
     * @var CacheInterface $cache
     */
    protected CacheInterface $cache;

    /**
     * @var ClientInterface $httpClient
     */
    protected ClientInterface $httpClient;

    /**
     * @var RequestFactoryInterface $httpRequestFactory
     */
    protected RequestFactoryInterface $httpRequestFactory;

    /**
     * @var array Metadata values (OIDC Configuration URL content).
     */
    protected array $metadata;

    /**
     * @var string Key used to store metadata values.
     */
    protected const OIDC_METADATA_CACHE_KEY = 'OIDC_METADATA';

    /**
     * @var array
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
     * Metadata constructor.
     * @param ConfigInterface $config
     * @param CacheInterface $cache
     * @param ClientInterface|null $httpClient
     * @param RequestFactoryInterface|null $httpRequestFactory
     * @throws OidcClientException If OIDC Provider (OP) metadata could not be fetched.
     */
    public function __construct(
        ConfigInterface $config,
        CacheInterface $cache,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpRequestFactory = null
    ) {
        $this->httpClient = $httpClient ?? new Client();
        $this->httpRequestFactory = $httpRequestFactory ?? new RequestFactory();

        $this->config = $config;
        $this->cache = $cache;
        try {
            $this->metadata = $cache->get(self::OIDC_METADATA_CACHE_KEY) ?? $this->requestMetadata();
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            throw new OidcClientException('OIDC Provider (OP) Metadata fetch error. ' . $exception->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        if (! isset($this->metadata[$key])) {
            throw new OidcClientException(sprintf('OIDC metadata parameter not supported (%s)', $key));
        }

        return $this->metadata[$key];
    }

    /**
     * Fetch data from OIDC configuration URL and store it in cache.
     *
     * @return array OIDC metadata values (OIDC configuration URL content).
     * @throws OidcClientException
     */
    protected function requestMetadata(): array
    {
        try {
            $request = $this->httpRequestFactory
                ->createRequest('GET', $this->config->getOidcConfigurationUrl())
                ->withHeader('Accept', 'application/json');
            $response = $this->httpClient->sendRequest($request);
        } catch (PsrHttpClientClientExceptionInterface $exception) {
            throw new OidcClientException('Could not fetch OIDC configuration from provided URL.');
        }

        if (200 !== $response->getStatusCode()) {
            throw new OidcClientException('OIDC configuration fetch request did not return 200 OK status code.');
        }

        $metadata = json_decode((string) $response->getBody(), true);
        $this->validateMetadata($metadata);

        try {
            $this->cache->set(self::OIDC_METADATA_CACHE_KEY, $metadata);
            return $metadata;
        } catch (Throwable | PsrSimpleCacheInvalidArgumentException $exception) {
            throw new OidcClientException('Could not store fetched OIDC configuration to cache.');
        }
    }

    /**
     * @param array $metadata
     * @return bool True if valid, else false.
     */
    protected function isValidMetadata(array $metadata): bool
    {
        if (array_diff(self::REQUIRED_OIDC_CONFIGURATION_PARAMETERS, array_keys($metadata))) {
            return false;
        }

        return true;
    }

    /**
     * @param array $metadata
     * @throws OidcClientException If OIDC Provider (OP) metdata is not valid.
     */
    protected function validateMetadata($metadata): void
    {
        if (! $this->isValidMetadata($metadata)) {
            throw new OidcClientException('OIDC Provider (OP) metadata is not valid.');
        }
    }
}
