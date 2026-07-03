<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Cicnavi\Oidc\Protocol\ClientRegistrationHandler;
use Cicnavi\Oidc\Protocol\OpMetadata;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Cicnavi\Oidc\Registration\ClientRegistrationData;
use Cicnavi\Oidc\Registration\FileClientRegistrationStore;
use Cicnavi\Oidc\Registration\Interfaces\ClientRegistrationStoreInterface;
use DateInterval;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\GrantTypesEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\ResponseModesEnum;
use SimpleSAML\OpenID\Codebooks\ResponseTypesEnum;
use SimpleSAML\OpenID\Codebooks\TokenEndpointAuthMethodsEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use Throwable;

/**
 * OIDC client which registers itself on the OpenID Provider using OpenID
 * Connect Dynamic Client Registration 1.0 (RFC 7591). After (automatic)
 * registration, it behaves like a pre-registered client using the issued
 * client credentials, with 'client_secret_basic' client authentication.
 *
 * The client registration (client credentials, Registration Access Token...)
 * is persisted using a client registration store, so registration is
 * performed only once. Subsequent client configuration management (read,
 * update, delete) as per RFC 7592 is also supported, authenticated with the
 * Registration Access Token issued at registration.
 * @see \Cicnavi\Tests\Oidc\DynamicallyRegisteredClientTest
 */
class DynamicallyRegisteredClient
{
    /**
     * @var CacheInterface $cache Cache instance, which can be used to fetch
     * existing values instead of sending HTTP requests to the auth server
     * each time.
     */
    protected CacheInterface $cache;

    /**
     * @var MetadataInterface $metadata OIDC Provider (OP) metadata. Contains
     * items from OIDC configuration URL.
     */
    protected MetadataInterface $metadata;

    /**
     * @var string Key used to store OIDC configuration URL in cache.
     */
    protected const CACHE_KEY_OP_CONFIGURATION_URL = 'OIDC_OP_CONFIGURATION_URL';

    protected readonly ClientRegistrationStoreInterface $registrationStore;

    protected readonly ClientRegistrationHandler $registrationHandler;

    protected ?ClientRegistrationData $registrationData = null;

    /**
     * DynamicallyRegisteredClient constructor.
     *
     * @param string $opConfigurationUrl URL where the OP configuration can be
     * fetched. The OP must advertise a "registration_endpoint".
     * @param string $redirectUri Client Redirect URI to which the OP will send
     * the authorization code. Will be registered on the OP.
     * @param string $scope Scopes to use in the authorization request. Will
     * also be sent as the "scope" claim during client registration.
     * @param ?string $clientName Optional human-readable client name to send
     * as the "client_name" claim during client registration.
     * @param ?string $initialAccessToken Optional Initial Access Token, used
     * as a Bearer token during registration when the OP protects its
     * registration endpoint.
     * @param ?ClientRegistrationStoreInterface $registrationStore Store used
     * to persist the client registration between requests. Defaults to a
     * simple file-based store. Note that losing a stored registration means
     * losing the issued client credentials, forcing a new registration.
     * @param mixed[] $additionalClientMetadata Any additional client metadata
     * claims to send during client registration. Values provided here
     * override the claims prepared by this client (redirect_uris,
     * grant_types, response_types, token_endpoint_auth_method, scope...), so
     * make sure to use the correct format for the particular claim.
     * @param bool $includeSoftwareId Whether to include the "software_id"
     * claim during client registration.
     * @param ?PreRegisteredClient $preRegisteredClient Pre-built client
     * instance to delegate protocol operations to. If not provided (default),
     * one will be built after registration using the issued client
     * credentials. Intended primarily for testing.
     *
     * For other parameters, refer to PreRegisteredClient - they are forwarded
     * to the underlying client instance which is built after registration.
     *
     * @throws OidcClientException If cache could not be (re)initialized.
     */
    public function __construct(
        protected string $opConfigurationUrl,
        protected string $redirectUri,
        protected string $scope,
        protected readonly ?string $clientName = null,
        protected readonly ?string $initialAccessToken = null,
        ?ClientRegistrationStoreInterface $registrationStore = null,
        protected readonly array $additionalClientMetadata = [],
        protected readonly bool $includeSoftwareId = true,
        protected bool $usePkce = true,
        protected PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod = PkceCodeChallengeMethodEnum::S256,
        protected readonly DateInterval $timestampValidationLeeway = new DateInterval('PT1M'),
        protected bool $useState = true,
        protected bool $useNonce = true,
        protected bool $fetchUserinfoClaims = true,
        protected readonly SupportedAlgorithms $supportedAlgorithms = new SupportedAlgorithms(
            new SignatureAlgorithmBag(
                SignatureAlgorithmEnum::EdDSA,
                SignatureAlgorithmEnum::ES256,
                SignatureAlgorithmEnum::ES384,
                SignatureAlgorithmEnum::ES512,
                SignatureAlgorithmEnum::PS256,
                SignatureAlgorithmEnum::PS384,
                SignatureAlgorithmEnum::PS512,
                SignatureAlgorithmEnum::RS256,
                SignatureAlgorithmEnum::RS384,
                SignatureAlgorithmEnum::RS512,
            ),
        ),
        protected readonly SupportedSerializers $supportedSerializers = new SupportedSerializers(),
        protected readonly ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        protected readonly SessionStoreInterface $sessionStore = new PhpSessionStore(),
        protected readonly Client $httpClient = new Client(),
        ?MetadataInterface $metadata = null,
        protected readonly ?Core $core = null,
        protected readonly ?Jwks $jwks = null,
        protected readonly DateInterval $maxCacheDuration = new DateInterval('PT6H'),
        // phpcs:ignore
        protected readonly AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = AuthorizationRequestMethodEnum::FormPost,
        protected readonly ?ResponseModesEnum $responseMode = null,
        protected readonly ?RequestDataHandler $requestDataHandler = null,
        protected readonly ParModeEnum $parMode = ParModeEnum::Auto,
        ?ClientRegistrationHandler $registrationHandler = null,
        protected ?PreRegisteredClient $preRegisteredClient = null,
    ) {
        $this->cache = $cache ?? new FileCache(
            'odrcpc-' . md5($this->opConfigurationUrl . '|' . $this->redirectUri),
        );

        $this->validateCache();

        $this->metadata = $metadata ?? new OpMetadata($this->opConfigurationUrl, $this->cache, $this->httpClient);

        $this->registrationStore = $registrationStore ?? new FileClientRegistrationStore();

        $this->registrationHandler = $registrationHandler ?? new ClientRegistrationHandler(
            httpClient: $this->httpClient,
            logger: $this->logger,
        );
    }

    /**
     * Check if the current cache state is considered valid. Cache is valid if
     * the cache contains metadata for OIDC Configuration URL, which is
     * available in current OIDC settings. If the cache is not valid
     * (OIDC Configuration URL was changed), the cache will be reinitialized.
     *
     * @throws OidcClientException If cache could not be reinitialized.
     */
    protected function validateCache(): void
    {
        try {
            if (
                $this->opConfigurationUrl !=
                $this->cache->get(self::CACHE_KEY_OP_CONFIGURATION_URL)
            ) {
                $this->cache->clear();
                $this->cache->set(self::CACHE_KEY_OP_CONFIGURATION_URL, $this->opConfigurationUrl);
            }
        } catch (Throwable $throwable) {
            $error = 'Cache validation error. ' . $throwable->getMessage();
            $this->logger?->error($error);
            throw new OidcClientException($error, $throwable->getCode(), $throwable);
        }
    }

    /**
     * Key under which the client registration is persisted in the client
     * registration store. Unique per OP configuration URL and redirect URI.
     */
    public function getRegistrationStoreKey(): string
    {
        return $this->opConfigurationUrl . '|' . $this->redirectUri;
    }

    /**
     * Register this client on the OP's client registration endpoint, or
     * return the existing (persisted) registration when one is available and
     * its client secret has not expired. When the persisted client secret has
     * expired, a new registration is performed automatically.
     *
     * @param bool $forceNewRegistration Perform a new registration even if a
     * persisted registration exists. Note that this abandons the existing
     * registration on the OP.
     * @throws OidcClientException
     */
    public function register(bool $forceNewRegistration = false): ClientRegistrationData
    {
        if (!$forceNewRegistration) {
            $existingRegistrationData = $this->loadRegistrationData();

            if ($existingRegistrationData instanceof ClientRegistrationData) {
                if (!$existingRegistrationData->isClientSecretExpired()) {
                    return $existingRegistrationData;
                }

                $this->logger?->notice(
                    'Persisted client secret has expired, performing new client registration.',
                    ['clientId' => $existingRegistrationData->getClientId()],
                );
            }
        }

        $claims = $this->registrationHandler->register(
            $this->resolveRegistrationEndpoint(),
            $this->buildClientRegistrationMetadata(),
            $this->initialAccessToken,
        );

        $registrationData = new ClientRegistrationData($claims);

        $this->registrationStore->set($this->getRegistrationStoreKey(), $claims);

        $this->logger?->info(
            'Client registered on OpenID Provider.',
            ['clientId' => $registrationData->getClientId()],
        );

        // Make sure the underlying client is rebuilt with the new credentials.
        $this->preRegisteredClient = null;

        return $this->registrationData = $registrationData;
    }

    /**
     * Get the current client registration data, performing registration if
     * needed.
     *
     * @throws OidcClientException
     */
    public function getRegistrationData(): ClientRegistrationData
    {
        return $this->register();
    }

    /**
     * Read the current client registration from the OP's client configuration
     * endpoint (RFC 7592), and persist the (possibly updated) client
     * information returned by the OP. A registration with a Registration
     * Access Token and a client configuration endpoint URI must already
     * exist.
     *
     * @throws OidcClientException
     */
    public function readRegistration(): ClientRegistrationData
    {
        $registrationData = $this->requireExistingRegistrationData();

        $claims = $this->registrationHandler->read(
            $this->requireRegistrationClientUri($registrationData),
            $this->requireRegistrationAccessToken($registrationData),
        );

        return $this->persistRegistrationClaims($claims, $registrationData);
    }

    /**
     * Update the client registration on the OP's client configuration
     * endpoint (RFC 7592), and persist the client information returned by the
     * OP. A registration with a Registration Access Token and a client
     * configuration endpoint URI must already exist.
     *
     * Per RFC 7592, the update request carries the whole client metadata set,
     * not a partial update. The metadata sent is the same set this client
     * would send at registration (including additional client metadata
     * provided in constructor), with provided client metadata claims taking
     * precedence.
     *
     * @param mixed[] $clientMetadata Client metadata claims to override the
     * prepared client metadata set with.
     * @throws OidcClientException
     */
    public function updateRegistration(array $clientMetadata = []): ClientRegistrationData
    {
        $registrationData = $this->requireExistingRegistrationData();

        $updateMetadata = array_merge(
            [ClaimsEnum::ClientId->value => $registrationData->getClientId()],
            $this->buildClientRegistrationMetadata(),
            $clientMetadata,
        );

        $claims = $this->registrationHandler->update(
            $this->requireRegistrationClientUri($registrationData),
            $this->requireRegistrationAccessToken($registrationData),
            $updateMetadata,
        );

        return $this->persistRegistrationClaims($claims, $registrationData);
    }

    /**
     * Delete (deprovision) the client registration on the OP's client
     * configuration endpoint (RFC 7592), and remove it from the client
     * registration store. A registration with a Registration Access Token and
     * a client configuration endpoint URI must already exist.
     *
     * @throws OidcClientException
     */
    public function deleteRegistration(): void
    {
        $registrationData = $this->requireExistingRegistrationData();

        $this->registrationHandler->delete(
            $this->requireRegistrationClientUri($registrationData),
            $this->requireRegistrationAccessToken($registrationData),
        );

        $this->registrationStore->delete($this->getRegistrationStoreKey());
        $this->registrationData = null;
        $this->preRegisteredClient = null;

        $this->logger?->info('Client registration deleted on OpenID Provider.');
    }

    /**
     * Send an authorization request to the authorization server using
     * authorization code grant flow. Client registration is performed first
     * if needed.
     *
     * @throws OidcClientException If something goes wrong :)
     */
    public function authorize(
        ?AuthorizationRequestMethodEnum $authorizationRequestMethod = null,
        ?ResponseInterface $response = null,
        ?ResponseModesEnum $responseMode = null,
        ?ParModeEnum $parMode = null,
    ): ?ResponseInterface {
        return $this->resolvePreRegisteredClient()->authorize(
            $authorizationRequestMethod,
            $response,
            $responseMode,
            $parMode,
        );
    }

    /**
     * Get user data by performing an HTTP request to a token endpoint first
     * and then to the userinfo endpoint using tokens to get user data.
     *
     * @return mixed[] User data.
     * @throws OidcClientException
     */
    public function getUserData(?ServerRequestInterface $request = null): array
    {
        try {
            return $this->resolvePreRegisteredClient()->getUserData($request);
        } catch (OidcClientException $oidcClientException) {
            throw $oidcClientException;
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'User data error. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * @return MetadataInterface OIDC Configuration URL content (OIDC metadata).
     */
    public function getMetadata(): MetadataInterface
    {
        return $this->metadata;
    }

    public function getParMode(): ParModeEnum
    {
        return $this->parMode;
    }

    /**
     * Build the client metadata set to send during client registration.
     *
     * @return mixed[]
     */
    public function buildClientRegistrationMetadata(): array
    {
        $clientMetadata = [
            ClaimsEnum::RedirectUris->value => [$this->redirectUri],
            ClaimsEnum::GrantTypes->value => [GrantTypesEnum::AuthorizationCode->value],
            ClaimsEnum::ResponseTypes->value => [ResponseTypesEnum::Code->value],
            ClaimsEnum::TokenEndpointAuthMethod->value => TokenEndpointAuthMethodsEnum::ClientSecretBasic->value,
            ClaimsEnum::Scope->value => $this->scope,
        ];

        if (is_string($this->clientName)) {
            $clientMetadata[ClaimsEnum::ClientName->value] = $this->clientName;
        }

        if ($this->includeSoftwareId) {
            $clientMetadata[ClaimsEnum::SoftwareId->value] = 'https://github.com/cicnavi/oidc-client-php';
        }

        return array_merge($clientMetadata, $this->additionalClientMetadata);
    }

    /**
     * Get the underlying pre-registered client instance built using the
     * client credentials issued at registration, performing registration
     * first if needed.
     *
     * @throws OidcClientException
     */
    public function resolvePreRegisteredClient(): PreRegisteredClient
    {
        $registrationData = $this->register();

        if ($this->preRegisteredClient instanceof PreRegisteredClient) {
            return $this->preRegisteredClient;
        }

        if (!is_string($clientSecret = $registrationData->getClientSecret())) {
            throw new OidcClientException(
                'Client registration does not contain a client secret, which is required for the ' .
                '"client_secret_basic" client authentication method.',
            );
        }

        return $this->preRegisteredClient = new PreRegisteredClient(
            opConfigurationUrl: $this->opConfigurationUrl,
            clientId: $registrationData->getClientId(),
            clientSecret: $clientSecret,
            redirectUri: $this->redirectUri,
            scope: $this->scope,
            usePkce: $this->usePkce,
            pkceCodeChallengeMethod: $this->pkceCodeChallengeMethod,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            useState: $this->useState,
            useNonce: $this->useNonce,
            fetchUserinfoClaims: $this->fetchUserinfoClaims,
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            logger: $this->logger,
            cache: $this->cache,
            sessionStore: $this->sessionStore,
            httpClient: $this->httpClient,
            metadata: $this->metadata,
            core: $this->core,
            jwks: $this->jwks,
            maxCacheDuration: $this->maxCacheDuration,
            defaultAuthorizationRequestMethod: $this->defaultAuthorizationRequestMethod,
            responseMode: $this->responseMode,
            requestDataHandler: $this->requestDataHandler,
            parMode: $this->parMode,
        );
    }

    /**
     * Load registration data from memory or the client registration store.
     * Invalid stored entries are discarded (with an error logged), so a new
     * registration can be performed.
     */
    protected function loadRegistrationData(): ?ClientRegistrationData
    {
        if ($this->registrationData instanceof ClientRegistrationData) {
            return $this->registrationData;
        }

        try {
            $claims = $this->registrationStore->get($this->getRegistrationStoreKey());

            if (is_array($claims)) {
                return $this->registrationData = new ClientRegistrationData($claims);
            }
        } catch (Throwable $throwable) {
            $this->logger?->error(
                'Error loading persisted client registration, discarding it. ' . $throwable->getMessage(),
                ['registrationStoreKey' => $this->getRegistrationStoreKey()],
            );
        }

        return null;
    }

    /**
     * @throws OidcClientException If no registration exists yet.
     */
    protected function requireExistingRegistrationData(): ClientRegistrationData
    {
        $registrationData = $this->loadRegistrationData();

        if (!$registrationData instanceof ClientRegistrationData) {
            throw new OidcClientException(
                'No existing client registration available. Register the client first.',
            );
        }

        return $registrationData;
    }

    /**
     * @return non-empty-string
     * @throws OidcClientException
     */
    protected function requireRegistrationClientUri(ClientRegistrationData $registrationData): string
    {
        if (!is_string($registrationClientUri = $registrationData->getRegistrationClientUri())) {
            throw new OidcClientException(
                'Client registration does not contain a "registration_client_uri" claim, so client ' .
                'configuration management is not available.',
            );
        }

        return $registrationClientUri;
    }

    /**
     * @return non-empty-string
     * @throws OidcClientException
     */
    protected function requireRegistrationAccessToken(ClientRegistrationData $registrationData): string
    {
        if (!is_string($registrationAccessToken = $registrationData->getRegistrationAccessToken())) {
            throw new OidcClientException(
                'Client registration does not contain a "registration_access_token" claim, so client ' .
                'configuration management is not available.',
            );
        }

        return $registrationAccessToken;
    }

    /**
     * Persist client information claims returned by the OP. Since the OP is
     * not required to repeat the "registration_access_token" and
     * "registration_client_uri" claims in read / update responses, previous
     * claims are used as the base, with new claims taking precedence.
     *
     * @param mixed[] $claims
     * @throws OidcClientException
     */
    protected function persistRegistrationClaims(
        array $claims,
        ClientRegistrationData $previousRegistrationData,
    ): ClientRegistrationData {
        $claims = array_merge($previousRegistrationData->getClaims(), $claims);

        $registrationData = new ClientRegistrationData($claims);

        $this->registrationStore->set($this->getRegistrationStoreKey(), $claims);

        // Make sure the underlying client is rebuilt in case credentials changed.
        $this->preRegisteredClient = null;

        return $this->registrationData = $registrationData;
    }

    /**
     * @return non-empty-string
     * @throws OidcClientException If the OP does not advertise a
     * "registration_endpoint".
     */
    protected function resolveRegistrationEndpoint(): string
    {
        try {
            $registrationEndpoint = $this->metadata->get(ClaimsEnum::RegistrationEndpoint->value);
        } catch (OidcClientException) {
            $registrationEndpoint = null;
        }

        if (!is_string($registrationEndpoint) || $registrationEndpoint === '') {
            $error = 'OpenID Provider does not advertise a "registration_endpoint", so dynamic client ' .
            'registration is not available.';
            $this->logger?->error($error);
            throw new OidcClientException($error);
        }

        return $registrationEndpoint;
    }
}
