<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\CodeBooks\ParModeEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Oidc\Helpers\MetadataHelper;
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
class DynamicallyRegisteredClient extends AbstractOidcClient
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

    /**
     * @var string Key used to store the client ID bound to the current
     * authorization flow in the session store.
     */
    protected const SESSION_KEY_FLOW_CLIENT_ID = 'OIDC_DCR_FLOW_CLIENT_ID';

    /**
     * @var string Claim used to persist the fingerprint of the requested
     * client metadata alongside the client registration claims, so client
     * metadata changes (configuration drift) can be detected. Library
     * specific, never sent to the OP.
     */
    public const CLAIM_REQUESTED_METADATA_FINGERPRINT = 'oidc_client_php_requested_metadata_fingerprint';

    protected readonly ClientRegistrationStoreInterface $registrationStore;

    protected readonly ClientRegistrationHandler $registrationHandler;

    protected ?ClientRegistrationData $registrationData = null;

    /**
     * Request data handler resolved for session-stored login / logout data
     * operations (see resolveRequestDataHandler()).
     */
    protected ?RequestDataHandler $resolvedRequestDataHandler = null;

    protected readonly SessionStoreInterface $sessionStore;

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
     * Registrations are persisted under a "current" key and under a
     * per-client-ID key (used to resolve the registration an authorization
     * flow was initiated with, see authorize()), so the store normally holds
     * two entries. Per-client entries of replaced registrations with expired
     * client secrets are removed automatically; other stale per-client
     * entries (forced replacement, concurrent registrations) are kept so
     * in-flight authorization flows can complete - they are not reused
     * afterwards and are safe to remove externally.
     * @param mixed[] $additionalClientMetadata Any additional client metadata
     * claims to send during client registration. Values provided here
     * override the claims prepared by this client, so make sure to use the
     * correct format for the particular claim. Claims which affect protocol
     * operations are validated against what this client actually uses at
     * runtime: "token_endpoint_auth_method" can only be
     * "client_secret_basic", "redirect_uris", "grant_types" and
     * "response_types" overrides must contain the values this client uses
     * (the configured redirect URI, "authorization_code" and "code",
     * respectively), and a "scope" override must contain every configured
     * runtime scope value.
     * @param bool $includeSoftwareId Whether to include the "software_id"
     * claim during client registration.
     * @param ?PreRegisteredClient $preRegisteredClient Pre-built client
     * instance to delegate protocol operations to. If not provided (default),
     * one will be built after registration using the issued client
     * credentials. Intended primarily for testing.
     * @param string[] $postLogoutRedirectUris URIs to register as
     * 'post_logout_redirect_uris' during client registration, so they can be
     * used as the post logout redirect URI in RP-Initiated Logout (see
     * logout()). Note that providing this changes the client metadata set,
     * so an existing registration will be updated (or replaced) accordingly.
     * @param ?string $backchannelLogoutUri URI to register as
     * 'backchannel_logout_uri' during client registration - the endpoint on
     * which this client handles OIDC Back-Channel Logout requests from the
     * OP (see handleBackchannelLogoutRequest()). Note that providing this
     * changes the client metadata set, so an existing registration will be
     * updated (or replaced) accordingly.
     * @param ?bool $backchannelLogoutSessionRequired Value to register as
     * 'backchannel_logout_session_required' during client registration
     * (whether the OP should include a 'sid' claim in logout tokens sent to
     * this client), null to leave it out. Only registered when
     * $backchannelLogoutUri is provided.
     * @param ?string $idTokenSignedResponseAlg The JWS algorithm the OP uses
     * to sign this client's ID tokens and OIDC Back-Channel Logout tokens.
     * Back-Channel Logout tokens signed with a different algorithm are
     * rejected. Defaults to 'RS256' (the OpenID Connect default). Set to null
     * to accept any supported algorithm. When registering a specific
     * 'id_token_signed_response_alg' via $additionalClientMetadata, set this
     * to the same value.
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
        ?SessionStoreInterface $sessionStore = null,
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
        protected readonly array $postLogoutRedirectUris = [],
        protected readonly ?string $backchannelLogoutUri = null,
        protected readonly ?bool $backchannelLogoutSessionRequired = null,
        protected readonly ?string $idTokenSignedResponseAlg = SignatureAlgorithmEnum::RS256->value,
    ) {
        // The default store is given this client's logger, so that anything
        // it has to report about the session cookie reaches the same place as
        // the rest of the client's logging.
        $this->sessionStore = $sessionStore ?? new PhpSessionStore($this->logger);

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

        $this->validateClientMetadata($this->additionalClientMetadata);
    }

    /**
     * Ensure that provided client metadata claims which affect protocol
     * operations are compatible with what this client actually uses at
     * runtime. Registering different values would make the OP enforce
     * behavior this client can not deliver, causing hard-to-trace errors
     * during authorization flows (e.g., registering
     * "token_endpoint_auth_method" other than "client_secret_basic" while
     * token requests always authenticate using "client_secret_basic").
     *
     * @param mixed[] $clientMetadata
     * @throws OidcClientException
     */
    protected function validateClientMetadata(array $clientMetadata): void
    {
        $tokenEndpointAuthMethod = $clientMetadata[ClaimsEnum::TokenEndpointAuthMethod->value] ?? null;

        if (
            $tokenEndpointAuthMethod !== null &&
            $tokenEndpointAuthMethod !== TokenEndpointAuthMethodsEnum::ClientSecretBasic->value
        ) {
            throw new OidcClientException(
                sprintf(
                    'Client metadata claim "%s" can only be "%s", since this client always uses that ' .
                    'client authentication method.',
                    ClaimsEnum::TokenEndpointAuthMethod->value,
                    TokenEndpointAuthMethodsEnum::ClientSecretBasic->value,
                ),
            );
        }

        $this->validateClientMetadataContains($clientMetadata, ClaimsEnum::RedirectUris->value, $this->redirectUri);
        $this->validateClientMetadataContains(
            $clientMetadata,
            ClaimsEnum::GrantTypes->value,
            GrantTypesEnum::AuthorizationCode->value,
        );
        $this->validateClientMetadataContains(
            $clientMetadata,
            ClaimsEnum::ResponseTypes->value,
            ResponseTypesEnum::Code->value,
        );
        $this->validateClientMetadataScope($clientMetadata);

        foreach ($this->postLogoutRedirectUris as $postLogoutRedirectUri) {
            if ($postLogoutRedirectUri === '') {
                throw new OidcClientException(
                    'Post logout redirect URIs must be non-empty strings.',
                );
            }

            $this->validateClientMetadataContains(
                $clientMetadata,
                ClaimsEnum::PostLogoutRedirectUris->value,
                $postLogoutRedirectUri,
            );
        }

        if (is_string($this->backchannelLogoutUri)) {
            if ($this->backchannelLogoutUri === '') {
                throw new OidcClientException(
                    'Backchannel logout URI must be a non-empty string.',
                );
            }

            $backchannelLogoutUriOverride = $clientMetadata[ClaimsEnum::BackChannelLogoutUri->value] ?? null;

            if (
                $backchannelLogoutUriOverride !== null &&
                $backchannelLogoutUriOverride !== $this->backchannelLogoutUri
            ) {
                throw new OidcClientException(sprintf(
                    'Client metadata claim "%s" must match the configured backchannel logout URI "%s".',
                    ClaimsEnum::BackChannelLogoutUri->value,
                    $this->backchannelLogoutUri,
                ));
            }
        }
    }

    /**
     * Ensure that a "scope" client metadata claim, when provided, is a
     * (space-delimited) scope string containing every scope value this
     * client uses at runtime (overrides may register supersets, but not
     * exclude scopes in actual use).
     *
     * @param mixed[] $clientMetadata
     * @throws OidcClientException
     */
    protected function validateClientMetadataScope(array $clientMetadata): void
    {
        if (!array_key_exists(ClaimsEnum::Scope->value, $clientMetadata)) {
            return;
        }

        $scopeOverride = $clientMetadata[ClaimsEnum::Scope->value];

        if (is_string($scopeOverride)) {
            $overrideScopes = preg_split('/\s+/', $scopeOverride, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $runtimeScopes = preg_split('/\s+/', $this->scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (array_diff($runtimeScopes, $overrideScopes) === []) {
                return;
            }
        }

        throw new OidcClientException(
            sprintf(
                'Client metadata claim "%s" must be a scope string containing every scope value this ' .
                'client uses at runtime ("%s").',
                ClaimsEnum::Scope->value,
                $this->scope,
            ),
        );
    }

    /**
     * Ensure that a client metadata claim, when provided, is an array
     * containing the value this client uses at runtime (overrides may
     * register supersets, but not exclude the value in actual use).
     *
     * @param mixed[] $clientMetadata
     * @throws OidcClientException
     */
    protected function validateClientMetadataContains(
        array $clientMetadata,
        string $claim,
        string $requiredValue,
    ): void {
        if (!array_key_exists($claim, $clientMetadata)) {
            return;
        }

        $value = $clientMetadata[$claim];

        if (is_array($value) && in_array($requiredValue, $value, true)) {
            return;
        }

        throw new OidcClientException(
            sprintf(
                'Client metadata claim "%s" must be an array containing "%s", since this client uses that ' .
                'value at runtime.',
                $claim,
                $requiredValue,
            ),
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
     * Key under which the current client registration is persisted in the
     * client registration store. Unique per OP configuration URL and redirect
     * URI.
     */
    public function getRegistrationStoreKey(): string
    {
        return $this->opConfigurationUrl . '|' . $this->redirectUri;
    }

    /**
     * Key under which the client registration for a particular client ID is
     * persisted in the client registration store. Used to resolve the client
     * registration an authorization flow was initiated with, even if the
     * current registration is replaced in the meantime (see authorize()).
     */
    public function getClientRegistrationStoreKey(string $clientId): string
    {
        return $this->getRegistrationStoreKey() . '|' . $clientId;
    }

    /**
     * Register this client on the OP's client registration endpoint, or
     * return the existing (persisted) registration when one is available and
     * its client secret has not expired. When the persisted client secret has
     * expired, a new registration is performed automatically.
     *
     * When the persisted registration was made with different client metadata
     * than this client would currently send (configuration change), the
     * client registration is updated on the OP (RFC 7592) when possible, or
     * replaced with a new registration.
     *
     * @param bool $forceNewRegistration Perform a new registration even if a
     * persisted registration exists. Note that this abandons the existing
     * registration on the OP. Its per-client store entry is kept (so
     * in-flight authorization flows initiated with it can still complete),
     * but is not reused afterwards and is safe to remove externally.
     * @throws OidcClientException
     */
    public function register(bool $forceNewRegistration = false): ClientRegistrationData
    {
        $existingRegistrationData = $this->loadRegistrationData();

        if (!$forceNewRegistration && $existingRegistrationData instanceof ClientRegistrationData) {
            if (!$existingRegistrationData->isClientSecretExpired()) {
                if ($this->isRegistrationMetadataInSync($existingRegistrationData)) {
                    return $existingRegistrationData;
                }

                $this->logger?->notice(
                    'Client metadata has changed since registration, updating client registration.',
                    ['clientId' => $existingRegistrationData->getClientId()],
                );

                try {
                    return $this->updateRegistration();
                } catch (Throwable $throwable) {
                    $this->logger?->warning(
                        'Client registration update failed, performing new client registration. ' .
                        $throwable->getMessage(),
                        ['clientId' => $existingRegistrationData->getClientId()],
                    );
                }
            } else {
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

        $claims[self::CLAIM_REQUESTED_METADATA_FINGERPRINT] = $this->buildClientRegistrationMetadataFingerprint();

        $registrationData = new ClientRegistrationData($claims);

        $this->registrationStore->set($this->getRegistrationStoreKey(), $claims);
        // Also persist per client ID, so authorization flows can resolve the
        // registration they were initiated with (see authorize()).
        $this->registrationStore->set(
            $this->getClientRegistrationStoreKey($registrationData->getClientId()),
            $claims,
        );

        $this->clearReplacedClientRegistration($existingRegistrationData, $registrationData);

        $this->logger?->info(
            'Client registered on OpenID Provider.',
            ['clientId' => $registrationData->getClientId()],
        );

        // Discard any provided client instance so the new credentials are used.
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
     * Note that the persisted requested-metadata fingerprint (used to detect
     * configuration drift) always reflects the constructor-derived client
     * metadata, not one-off $clientMetadata overrides provided here.
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

        // Overrides must not register behavior this client can not deliver.
        $this->validateClientMetadata($updateMetadata);

        $claims = $this->registrationHandler->update(
            $this->requireRegistrationClientUri($registrationData),
            $this->requireRegistrationAccessToken($registrationData),
            $updateMetadata,
        );

        // The update carried the current client metadata set.
        $claims[self::CLAIM_REQUESTED_METADATA_FINGERPRINT] = $this->buildClientRegistrationMetadataFingerprint();

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
        $this->registrationStore->delete(
            $this->getClientRegistrationStoreKey($registrationData->getClientId()),
        );
        $this->registrationData = null;
        $this->preRegisteredClient = null;

        $this->logger?->info('Client registration deleted on OpenID Provider.');
    }

    /**
     * Send an authorization request to the authorization server using
     * authorization code grant flow. Client registration is performed first
     * if needed.
     *
     * The client ID used to initiate the flow is bound to the user session,
     * so the callback request (handled by getUserData()) can resolve the same
     * client registration (persisted per client ID) even if the current
     * persisted registration is replaced by another process in the meantime
     * (concurrent first-use registration, client secret expiry rollover...).
     * Only the client ID - which the user agent sees in the authorization
     * request anyway - is bound to the session; client credentials never
     * leave the client registration store.
     *
     * @throws OidcClientException If something goes wrong :)
     */
    public function authorize(
        ?AuthorizationRequestMethodEnum $authorizationRequestMethod = null,
        ?ResponseInterface $response = null,
        ?ResponseModesEnum $responseMode = null,
        ?ParModeEnum $parMode = null,
    ): ?ResponseInterface {
        $registrationData = $this->register();

        $this->sessionStore->put(
            self::SESSION_KEY_FLOW_CLIENT_ID,
            $registrationData->getClientId(),
        );

        return $this->resolvePreRegisteredClientFor($registrationData)->authorize(
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
     * Uses the client registration bound to the current authorization flow
     * (per user session), falling back to the persisted registration. The
     * flow registration is removed from the session after successful use, so
     * a transiently failed callback can be retried with the same credentials.
     *
     * @return mixed[] User data.
     * @throws OidcClientException
     */
    public function getUserData(?ServerRequestInterface $request = null): array
    {
        try {
            $registrationData = $this->loadFlowRegistrationData() ?? $this->register();

            $userData = $this->resolvePreRegisteredClientFor($registrationData)->getUserData($request);

            $this->clearFlowRegistrationData();

            return $userData;
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
     * Handle an OIDC Back-Channel Logout request from the OP: validate the
     * logout token from the request, record the login revocation it
     * requests, and deliver the appropriate HTTP response (200 when the
     * logout was performed, 400 with a JSON error body when not). Register
     * the URI of the endpoint calling this method as the
     * 'backchannel_logout_uri' client metadata (see the $backchannelLogoutUri
     * constructor parameter).
     *
     * The logout token audience is validated against the persisted client
     * registrations - the current one and any retained per-client entry of a
     * replaced registration - so a logout for a superseded (but still
     * persisted) registration is still honored while old-client sessions may
     * exist. No client registration is performed or updated here. When the
     * audience matches no persisted registration, the logout token can not be
     * validated and the request is rejected.
     *
     * For notes on how the revocation terminates the affected login, refer
     * to PreRegisteredClient::handleBackchannelLogoutRequest().
     *
     * @see PreRegisteredClient::handleBackchannelLogoutRequest()
     */
    public function handleBackchannelLogoutRequest(
        ?ServerRequestInterface $request = null,
        ?ResponseInterface $response = null,
    ): ?ResponseInterface {
        $requestDataHandler = $this->resolveRequestDataHandler();

        try {
            $logoutToken = $requestDataHandler->parseBackchannelLogoutRequest($request);

            if (!is_string($opJwksUri = $this->metadata->get(ClaimsEnum::JwksUri->value))) {
                throw new OidcClientException('JWKS URI not found in OP metadata.');
            }

            // Determine which persisted client registration the logout token
            // is addressed to (by audience / authorized party), so a logout
            // for a replaced registration whose per-client entry is still
            // persisted is honored - not only the current registration.
            $audienceInfo = $requestDataHandler->parseBackchannelLogoutTokenAudienceInfo($logoutToken);
            $registrationData = $this->resolveRegistrationForLogout(
                $audienceInfo['audiences'],
                $audienceInfo['authorizedParty'],
            );

            if (!$registrationData instanceof \Cicnavi\Oidc\Registration\ClientRegistrationData) {
                throw new OidcClientException(
                    'Logout token audience does not match any persisted client registration, ' .
                    'so it can not be validated.',
                );
            }

            // Validate against the policy of the matched registration itself,
            // not the current one: a registration that was replaced (e.g. after
            // changing 'id_token_signed_response_alg' or
            // 'backchannel_logout_session_required') keeps the signing algorithm
            // and 'sid' requirement it was registered with, so logout tokens for
            // sessions under the old client are validated correctly.
            $logoutTokenJws = $requestDataHandler->validateLogoutToken(
                logoutToken: $logoutToken,
                jwksUri: $opJwksUri,
                expectedIssuer: MetadataHelper::optionalString($this->metadata, ClaimsEnum::Issuer->value),
                expectedClientId: $registrationData->getClientId(),
                expectedSigningAlgorithm: $this->registeredIdTokenSignedResponseAlg($registrationData),
                requireSid: $this->registeredBackchannelLogoutSessionRequired($registrationData),
            );

            $requestDataHandler->registerLogoutTokenRevocation($logoutTokenJws);
        } catch (Throwable $throwable) {
            $this->logger?->error('Back-channel logout request error. ' . $throwable->getMessage());

            return HttpHelper::dispatchBackchannelLogoutResponse(
                $response,
                $throwable->getMessage(),
                $this->logger,
            );
        }

        $this->logger?->debug('Back-channel logout performed.');

        return HttpHelper::dispatchBackchannelLogoutResponse($response, null, $this->logger);
    }

    /**
     * The signing algorithm the OP uses for the given registration's ID and
     * logout tokens, read from that registration's own persisted metadata
     * ('id_token_signed_response_alg').
     *
     * When the registration does not carry the claim (e.g. it was registered
     * with the default algorithm, and the OP did not echo it back), the
     * fallback depends on which registration this is:
     * - for the CURRENT registration, this client's configured
     *   'idTokenSignedResponseAlg' applies (the expected algorithm for the live
     *   client);
     * - for a retained (replaced) registration, whose original configuration is
     *   not known here, the OpenID Connect default 'RS256' applies - NOT the
     *   current configuration, since a later config change (e.g. to 'ES256')
     *   must not reject valid logout tokens for old-client sessions that were
     *   signed with the previously effective algorithm.
     */
    protected function registeredIdTokenSignedResponseAlg(ClientRegistrationData $registrationData): ?string
    {
        $alg = $registrationData->getClaims()[ClaimsEnum::IdTokenSignedResponseAlg->value] ?? null;
        if (is_string($alg) && $alg !== '') {
            return $alg;
        }

        if ($registrationData->getClientId() === $this->loadRegistrationData()?->getClientId()) {
            return $this->idTokenSignedResponseAlg;
        }

        return SignatureAlgorithmEnum::RS256->value;
    }

    /**
     * Whether the given registration declares 'backchannel_logout_session_required'
     * as true, read from that registration's own persisted metadata. When true,
     * logout tokens without a 'sid' claim are rejected (a subject-wide fallback
     * would be broader than what that registration required). Derived per
     * registration so a replaced registration keeps the 'sid' policy it was
     * registered with, independent of the current configuration.
     */
    protected function registeredBackchannelLogoutSessionRequired(ClientRegistrationData $registrationData): bool
    {
        return ($registrationData->getClaims()[ClaimsEnum::BackChannelLogoutSessionRequired->value] ?? null) === true;
    }

    /**
     * Resolve which of this client's persisted registrations a back-channel
     * logout token is addressed to, by matching the token audience(s) against
     * the current registration and any retained per-client registration entry
     * (of a replaced registration). Returns the matching registration snapshot
     * (so its own signing algorithm / 'sid' policy can be used for validation),
     * or null when nothing matches a persisted registration. No client
     * registration is performed or updated here (registration entries are only
     * read).
     *
     * When the token has multiple audiences, its authorized party ('azp')
     * identifies the party it is intended for, so it is preferred - this
     * disambiguates the case where several audiences are persisted client
     * IDs (and matches the 'azp' check performed during validation).
     *
     * @param mixed[] $audiences Logout token audience value(s).
     * @param ?string $authorizedParty Logout token 'azp' claim value.
     */
    protected function resolveRegistrationForLogout(
        array $audiences,
        ?string $authorizedParty,
    ): ?ClientRegistrationData {
        if (is_string($authorizedParty) && $authorizedParty !== '') {
            return $this->loadPersistedClientRegistration($authorizedParty);
        }

        foreach ($audiences as $audience) {
            if (
                is_string($audience) &&
                $audience !== '' &&
                ($registrationData = $this->loadPersistedClientRegistration($audience))
                instanceof \Cicnavi\Oidc\Registration\ClientRegistrationData
            ) {
                return $registrationData;
            }
        }

        return null;
    }

    /**
     * Load a persisted client registration for the given client ID - either the
     * current registration or a retained per-client entry of a replaced
     * registration - or null when none is persisted (or it can not be read).
     * Registration entries are only read; no client registration is performed
     * or updated.
     */
    protected function loadPersistedClientRegistration(string $clientId): ?ClientRegistrationData
    {
        $currentRegistrationData = $this->loadRegistrationData();
        if (
            $currentRegistrationData instanceof ClientRegistrationData &&
            $clientId === $currentRegistrationData->getClientId()
        ) {
            return $currentRegistrationData;
        }

        try {
            $claims = $this->registrationStore->get($this->getClientRegistrationStoreKey($clientId));

            return is_array($claims) ? new ClientRegistrationData($claims) : null;
        } catch (Throwable $throwable) {
            $this->logger?->warning(
                'Error reading persisted client registration while resolving a back-channel ' .
                'logout token audience. ' . $throwable->getMessage(),
                ['clientId' => $clientId],
            );

            return null;
        }
    }

    /**
     * @inheritDoc
     */
    protected function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @inheritDoc
     *
     * The configured OP's 'end_session_endpoint', so that a logout can still
     * be attempted when nothing was persisted at login.
     */
    protected function fallbackEndSessionEndpoint(): ?string
    {
        return MetadataHelper::optionalString($this->metadata, ClaimsEnum::EndSessionEndpoint->value);
    }

    /**
     * @inheritDoc
     *
     * The client ID of the persisted client registration. Only reads it - no
     * registration is performed or updated, so logout is never blocked by
     * dynamic client registration state (stale, expired or missing
     * registration, unavailable registration endpoint...).
     */
    protected function fallbackLogoutClientId(): ?string
    {
        return $this->loadRegistrationData()?->getClientId();
    }

    /**
     * @inheritDoc
     */
    protected function usesState(): bool
    {
        return $this->useState;
    }

    /**
     * Get the request data handler used for session-stored login / logout
     * data operations. Uses the constructor-provided instance when
     * available, otherwise lazily builds one over the same session store
     * that the underlying pre-registered client instances use (so
     * session-stored data is shared either way).
     *
     * Never performs client registration, so neither this nor any of the
     * inherited operations reaching the session store through it -
     * validateLogoutCallback(), getIdToken(), getIdTokenClaims(),
     * getLoginData() - can be blocked by dynamic client registration state
     * (stale, expired or missing registration, unavailable registration
     * endpoint...).
     */
    protected function resolveRequestDataHandler(): RequestDataHandler
    {
        if ($this->resolvedRequestDataHandler instanceof RequestDataHandler) {
            return $this->resolvedRequestDataHandler;
        }

        if ($this->requestDataHandler instanceof RequestDataHandler) {
            return $this->resolvedRequestDataHandler = $this->requestDataHandler;
        }

        $core = $this->core ?? new Core(
            $this->supportedAlgorithms,
            $this->supportedSerializers,
            $this->timestampValidationLeeway,
            $this->logger,
        );

        $jwks = $this->jwks ?? new Jwks(
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            maxCacheDuration: $this->maxCacheDuration,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            cache: $this->cache,
            logger: $this->logger,
            httpClient: $this->httpClient,
        );

        return $this->resolvedRequestDataHandler = new RequestDataHandler(
            sessionStore: $this->sessionStore,
            core: $core,
            cache: $this->cache,
            jwks: $jwks,
            httpClient: $this->httpClient,
            logger: $this->logger,
            maxCacheDuration: $this->maxCacheDuration,
        );
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

        if ($this->postLogoutRedirectUris !== []) {
            $clientMetadata[ClaimsEnum::PostLogoutRedirectUris->value] = array_values(
                $this->postLogoutRedirectUris,
            );
        }

        if (is_string($this->backchannelLogoutUri)) {
            $clientMetadata[ClaimsEnum::BackChannelLogoutUri->value] = $this->backchannelLogoutUri;

            if (is_bool($this->backchannelLogoutSessionRequired)) {
                $clientMetadata[ClaimsEnum::BackChannelLogoutSessionRequired->value] =
                $this->backchannelLogoutSessionRequired;
            }
        }

        return array_merge($clientMetadata, $this->additionalClientMetadata);
    }

    /**
     * Fingerprint of the client metadata set this client would currently send
     * at registration. Persisted with the client registration claims, and
     * used to detect client metadata changes (configuration drift) on
     * subsequent runs.
     *
     * @throws OidcClientException
     */
    public function buildClientRegistrationMetadataFingerprint(): string
    {
        try {
            return hash(
                'sha256',
                json_encode($this->buildClientRegistrationMetadata(), JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'Could not build client registration metadata fingerprint. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }
    }

    /**
     * Check if the provided (persisted) client registration was requested
     * with the same client metadata set this client would currently send.
     *
     * @throws OidcClientException
     */
    protected function isRegistrationMetadataInSync(ClientRegistrationData $registrationData): bool
    {
        return ($registrationData->getClaims()[self::CLAIM_REQUESTED_METADATA_FINGERPRINT] ?? null) ===
        $this->buildClientRegistrationMetadataFingerprint();
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
        return $this->resolvePreRegisteredClientFor($this->register());
    }

    /**
     * Get the underlying pre-registered client instance built using the
     * client credentials from the provided client registration. A new
     * instance is built on each call, so the provided (current) registration
     * is always honored. A pre-built client instance provided in the
     * constructor (intended primarily for testing) takes precedence.
     *
     * @throws OidcClientException
     */
    protected function resolvePreRegisteredClientFor(ClientRegistrationData $registrationData): PreRegisteredClient
    {
        if ($this->preRegisteredClient instanceof PreRegisteredClient) {
            return $this->preRegisteredClient;
        }

        if (!is_string($clientSecret = $registrationData->getClientSecret())) {
            throw new OidcClientException(
                'Client registration does not contain a client secret, which is required for the ' .
                '"client_secret_basic" client authentication method.',
            );
        }

        return new PreRegisteredClient(
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
            // The algorithm the OP actually registered for this client, which
            // is not necessarily this client's configured default.
            idTokenSignedResponseAlg: $this->registeredIdTokenSignedResponseAlg($registrationData),
        );
    }

    /**
     * Load the client registration bound to the current authorization flow,
     * using the client ID from the session store to resolve the registration
     * from the client registration store. Returns null when not resolvable
     * (so the current persisted registration can be used as fallback);
     * errors are logged and the session entry is discarded.
     */
    protected function loadFlowRegistrationData(): ?ClientRegistrationData
    {
        try {
            $clientId = $this->sessionStore->get(self::SESSION_KEY_FLOW_CLIENT_ID);

            if (!is_string($clientId) || $clientId === '') {
                return null;
            }

            $claims = $this->registrationStore->get($this->getClientRegistrationStoreKey($clientId));

            if (is_array($claims)) {
                return new ClientRegistrationData($claims);
            }
        } catch (Throwable $throwable) {
            $this->logger?->error(
                'Error loading client registration bound to the current authorization flow, falling back ' .
                'to the persisted registration. ' . $throwable->getMessage(),
            );
            $this->clearFlowRegistrationData();
        }

        return null;
    }

    /**
     * Remove the client ID bound to the current authorization flow from the
     * session store (best-effort).
     */
    protected function clearFlowRegistrationData(): void
    {
        try {
            $this->sessionStore->delete(self::SESSION_KEY_FLOW_CLIENT_ID);
        } catch (Throwable $throwable) {
            $this->logger?->warning(
                'Error removing client ID bound to the current authorization flow from session. ' .
                $throwable->getMessage(),
            );
        }
    }

    /**
     * Remove the per-client store entry of a replaced client registration
     * (best-effort), so the store does not accumulate superseded
     * registrations. Only registrations whose client secret has expired are
     * removed - authorization flows initiated with them could not complete
     * anyway. Replaced registrations with a still-valid client secret
     * (forced replacement) are kept, so in-flight authorization flows
     * initiated with them can still complete; such entries are not reused
     * afterwards and are safe to remove externally.
     */
    protected function clearReplacedClientRegistration(
        ?ClientRegistrationData $replacedRegistrationData,
        ClientRegistrationData $newRegistrationData,
    ): void {
        if (!$replacedRegistrationData instanceof ClientRegistrationData) {
            return;
        }

        if ($replacedRegistrationData->getClientId() === $newRegistrationData->getClientId()) {
            return;
        }

        if (!$replacedRegistrationData->isClientSecretExpired()) {
            return;
        }

        try {
            $this->registrationStore->delete(
                $this->getClientRegistrationStoreKey($replacedRegistrationData->getClientId()),
            );
        } catch (Throwable $throwable) {
            $this->logger?->warning(
                'Error removing replaced client registration from the client registration store. ' .
                $throwable->getMessage(),
                ['replacedClientId' => $replacedRegistrationData->getClientId()],
            );
        }
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
        // Also persist per client ID, so authorization flows can resolve the
        // registration they were initiated with (see authorize()).
        $this->registrationStore->set(
            $this->getClientRegistrationStoreKey($registrationData->getClientId()),
            $claims,
        );

        // Discard any provided client instance in case credentials changed.
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
