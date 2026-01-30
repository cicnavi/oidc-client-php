<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Federation\RelyingPartyConfig;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SimpleSAML\OpenID\Codebooks\ClientAuthenticationMethodsEnum;
use SimpleSAML\OpenID\Codebooks\ParamsEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Exceptions\EntityStatementException;
use SimpleSAML\OpenID\Exceptions\InvalidValueException;
use SimpleSAML\OpenID\Exceptions\OpenIdException;
use SimpleSAML\OpenID\Exceptions\TrustChainException;
use SimpleSAML\OpenID\Federation\TrustChain;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairBag;
use SimpleSAML\OpenID\ValueAbstracts\Factories\SignatureKeyPairBagFactory;
use SimpleSAML\OpenID\ValueAbstracts\Factories\SignatureKeyPairFactory;
use Cicnavi\Oidc\Federation\EntityConfig;
use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ApplicationTypesEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\ClientRegistrationTypesEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\Codebooks\GrantTypesEnum;
use SimpleSAML\OpenID\Codebooks\HashAlgorithmsEnum;
use SimpleSAML\OpenID\Codebooks\ResponseTypesEnum;
use SimpleSAML\OpenID\Codebooks\TokenEndpointAuthMethodsEnum;
use SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Exceptions\TrustMarkException;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\EntityStatement;
use SimpleSAML\OpenID\Jwk;
use SimpleSAML\OpenID\Jwks\Factories\JwksDecoratorFactory;
use SimpleSAML\OpenID\Jwks\JwksDecorator;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use SimpleSAML\OpenID\ValueAbstracts\TrustAnchorConfigBag;

class FederatedClient
{
    protected readonly CacheInterface $cache;

    protected readonly SignatureKeyPairBag $federationSignatureKeyPairBag;

    protected readonly SignatureKeyPairFactory $signatureKeyPairFactory;

    protected readonly SignatureKeyPairBagFactory $signatureKeyPairBagFactory;

    protected readonly Federation $federation;

    protected JwksDecorator $federationJwksDecorator;

    protected SignatureKeyPairBag $connectSignatureKeyPairBag;

    protected JwksDecorator $relyingPartyJwksDecorator;

    protected RequestDataHandler $requestDataHandler;

    protected Core $core;

    protected Jwks $jwks;

    /**
     * TODO mivanci Federation participation limit by Trust Marks.
     * @param RelyingPartyConfig $relyingPartyConfig Configuration related to
     * the Relying Party (RP) role of this federation entity.     *
     * @param \DateInterval $entityStatementDuration Entity statement duration
     * which determines the Expiration Time (exp) claim set in entity statement
     * JWSs published by this RP. Defaults to 1 day.
     * @param CacheInterface|null $cache Cache implementation to use for storing
     * fetched artifacts. Defaults to a FileCache in the system temporary
     * directory.
     * @param \DateInterval $maxCacheDuration Maximum duration for which fetched
     * artifacts will be cached. Defaults to 6 hours.
     * @param \DateInterval $timestampValidationLeeway Leeway used for timestamp
     * validation. Defaults to 1 minute.
     *
     * @throws CacheException
     */
    public function __construct(
        protected readonly EntityConfig $entityConfig,
        protected readonly RelyingPartyConfig $relyingPartyConfig,
        protected readonly \DateInterval $entityStatementDuration = new \DateInterval('P1D'),
        ?CacheInterface $cache = null,
        protected readonly \DateInterval $maxCacheDuration = new \DateInterval('PT6H'),
        protected readonly \DateInterval $timestampValidationLeeway = new \DateInterval('PT1M'),
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
        protected readonly int $maxTrustChainDepth = 9,
        // phpcs:ignore
        protected readonly TrustMarkStatusEndpointUsagePolicyEnum $defaultTrustMarkStatusEndpointUsagePolicyEnum = TrustMarkStatusEndpointUsagePolicyEnum::NotUsed,
        ?Federation $federation = null,
        protected Jwk $jwk = new Jwk(),
        protected readonly HashAlgorithmsEnum $jwkThumbprintHashAlgo = HashAlgorithmsEnum::SHA_256,
        ?SignatureKeyPairFactory $signatureKeyPairFactory = null,
        ?SignatureKeyPairBagFactory $signatureKeyPairBagFactory = null,
        protected readonly JwksDecoratorFactory $jwksDecoratorFactory = new JwksDecoratorFactory(),
        protected readonly bool $includeSoftwareId = true,
        protected readonly \DateInterval $privateKeyJwtDuration = new \DateInterval('PT5M'),
        protected readonly bool $useNonce = true,
        protected readonly bool $usePkce = true,
        protected bool $fetchUserinfoClaims = true,
        protected readonly PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod = PkceCodeChallengeMethodEnum::S256,
        protected readonly SessionStoreInterface $sessionStore = new PhpSessionStore(),
        protected readonly Client $httpClient = new Client(),
        ?Core $core = null,
        ?Jwks $jwks = null,
        ?RequestDataHandler $requestDataHandler = null,
        // phpcs:ignore
        protected readonly AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = AuthorizationRequestMethodEnum::FormPost,
    ) {
        $this->cache = $cache ?? new FileCache('ofacpc-' . md5($this->entityConfig->getEntityId()));
        $this->signatureKeyPairFactory = $signatureKeyPairFactory ?? new SignatureKeyPairFactory($this->jwk);
        $this->signatureKeyPairBagFactory = $signatureKeyPairBagFactory ?? new SignatureKeyPairBagFactory(
            $this->signatureKeyPairFactory,
        );

        $this->core = $core ?? new Core(
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            logger: $this->logger,
        );

        $this->jwks = $jwks ?? new Jwks(
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            maxCacheDuration: $this->maxCacheDuration,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            cache: $this->cache,
            logger: $this->logger,
            httpClient: $this->httpClient,
        );

        $this->requestDataHandler = $requestDataHandler ?? new RequestDataHandler(
            sessionStore: $this->sessionStore,
            core: $this->core,
            cache: $this->cache,
            jwks: $this->jwks,
            httpClient: $this->httpClient,
            logger: $this->logger,
            maxCacheDuration: $this->maxCacheDuration,
        );

        $this->federation = $federation ?? new Federation(
            supportedAlgorithms: $this->supportedAlgorithms,
            supportedSerializers: $this->supportedSerializers,
            maxCacheDuration: $this->maxCacheDuration,
            timestampValidationLeeway: $this->timestampValidationLeeway,
            maxTrustChainDepth: $this->maxTrustChainDepth,
            cache: $this->cache,
            logger: $this->logger,
            client: $this->httpClient,
            defaultTrustMarkStatusEndpointUsagePolicyEnum: $this->defaultTrustMarkStatusEndpointUsagePolicyEnum,
        );

        $this->federationSignatureKeyPairBag = $this->signatureKeyPairBagFactory->fromConfig(
            $this->entityConfig->getFederationSignatureKeyPairConfigBag(),
        );

        $this->federationJwksDecorator = $this->jwksDecoratorFactory->fromJwkDecorators(
            ...$this->federationSignatureKeyPairBag->getAllPublicKeys(),
        );

        $this->connectSignatureKeyPairBag = $this->signatureKeyPairBagFactory->fromConfig(
            $this->relyingPartyConfig->getConnectSignatureKeyPairBag(),
        );

        $this->relyingPartyJwksDecorator = $this->jwksDecoratorFactory->fromJwkDecorators(
            ...$this->connectSignatureKeyPairBag->getAllPublicKeys(),
        );
    }

    public function getEntityConfig(): EntityConfig
    {
        return $this->entityConfig;
    }

    public function getEntityStatementDuration(): \DateInterval
    {
        return $this->entityStatementDuration;
    }

    public function getMaxCacheDuration(): \DateInterval
    {
        return $this->maxCacheDuration;
    }

    public function getTimestampValidationLeeway(): \DateInterval
    {
        return $this->timestampValidationLeeway;
    }

    public function getSupportedAlgorithms(): SupportedAlgorithms
    {
        return $this->supportedAlgorithms;
    }

    public function getSupportedSerializers(): SupportedSerializers
    {
        return $this->supportedSerializers;
    }

    public function getMaxTrustChainDepth(): int
    {
        return $this->maxTrustChainDepth;
    }

    public function getDefaultTrustMarkStatusEndpointUsagePolicy(): TrustMarkStatusEndpointUsagePolicyEnum
    {
        return $this->defaultTrustMarkStatusEndpointUsagePolicyEnum;
    }

    public function includeSoftwareId(): bool
    {
        return $this->includeSoftwareId;
    }

    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    public function clearCache(): void
    {
        try {
            $result = $this->cache->clear();
            $this->logger?->notice($result ? 'Cache cleared.' : 'Cache NOT cleared.');
        } catch (CacheException $cacheException) {
            $this->logger?->error('Error clearing cache: ' . $cacheException->getMessage());
        }
    }

    public function getPrivateKeyJwtDuration(): \DateInterval
    {
        return $this->privateKeyJwtDuration;
    }

    public function useNonce(): bool
    {
        return $this->useNonce;
    }

    public function usePkce(): bool
    {
        return $this->usePkce;
    }

    public function fetchUserinfoClaims(): bool
    {
        return $this->fetchUserinfoClaims;
    }

    public function getPkceCodeChallengeMethod(): PkceCodeChallengeMethodEnum
    {
        return $this->pkceCodeChallengeMethod;
    }

    public function getDefaultAuthorizationRequestMethod(): AuthorizationRequestMethodEnum
    {
        return $this->defaultAuthorizationRequestMethod;
    }

    public function buildEntityStatement(): EntityStatement
    {
        $issuedAt = $this->federation->helpers()->dateTime()->getUtc();

        $payload = $this->entityConfig->getAdditionalClaimBag()->getAll();
        $payload[ClaimsEnum::Iss->value] = $this->entityConfig->getEntityId();
        $payload[ClaimsEnum::Sub->value] = $this->entityConfig->getEntityId();
        $payload[ClaimsEnum::Iat->value] = $issuedAt->getTimestamp();
        $payload[ClaimsEnum::Exp->value] = $issuedAt->add($this->entityStatementDuration)->getTimestamp();
        $payload[ClaimsEnum::Jti->value] = $this->federation->helpers()->random()->string();
        $payload[ClaimsEnum::Jwks->value] = $this->federationJwksDecorator->jsonSerialize();

        if (($authorityHints = $this->entityConfig->getAuthorityHintBag()->getAll()) !== []) {
            $payload[ClaimsEnum::AuthorityHints->value] = $authorityHints;
        }

        try {
            $trustMarks = $this->prepareTrustMarksClaimValue();
            if ($trustMarks !== []) {
                $payload[ClaimsEnum::TrustMarks->value] = $trustMarks;
            }
        } catch (\Throwable $throwable) {
            $this->logger?->error('Error preparing Trust Marks claim value: ' . $throwable->getMessage());
        }

        $rpMetadata = $this->relyingPartyConfig->getAdditionalClaimBag()->getAll();
        $rpMetadata[ClaimsEnum::ApplicationType->value] = ApplicationTypesEnum::Web->value;
        $rpMetadata[ClaimsEnum::GrantTypes->value] = [GrantTypesEnum::AuthorizationCode->value];
        $rpMetadata[ClaimsEnum::RedirectUris->value] = $this->getRelyingPartyConfig()->getRedirectUriBag()
            ->getAll();
        // https://openid.net/specs/openid-connect-registration-1_0.html#ClientMetadata
        $rpMetadata[ClaimsEnum::TokenEndpointAuthMethod->value] = TokenEndpointAuthMethodsEnum::PrivateKeyJwt->value;
        https: //openid.net/specs/openid-connect-rp-metadata-choices-1_0-01.html
        $rpMetadata[ClaimsEnum::TokenEndpointAuthMethodsSupported->value] = [
            TokenEndpointAuthMethodsEnum::PrivateKeyJwt->value,
        ];
        $rpMetadata[ClaimsEnum::TokenEndpointAuthSigningAlgValuesSupported->value] =
        $this->relyingPartyConfig->getConnectSignatureKeyPairBag()->getAllAlgorithmNamesUnique();
        $rpMetadata[ClaimsEnum::ResponseTypes->value] = [ResponseTypesEnum::Code->value];
        $rpMetadata[ClaimsEnum::ClientRegistrationTypes->value] = [
            ClientRegistrationTypesEnum::Automatic->value,
        ];
        $rpMetadata[ClaimsEnum::IdTokenSigningAlgValuesSupported->value] = array_map(
            fn(SignatureAlgorithmEnum $signatureAlgorithmEnum): string => $signatureAlgorithmEnum->value,
            $this->supportedAlgorithms->getSignatureAlgorithmBag()->getAll(),
        );
        $rpMetadata[ClaimsEnum::RequestObjectSigningAlgValuesSupported->value] =
        $this->relyingPartyConfig->getConnectSignatureKeyPairBag()->getAllAlgorithmNamesUnique();
        $rpMetadata[ClaimsEnum::Scope->value] = $this->relyingPartyConfig->getScopeBag()->toString();
        if ($this->includeSoftwareId) {
            $rpMetadata[ClaimsEnum::SoftwareId->value] = 'https://github.com/cicnavi/oidc-client-php';
        }

        if (is_string($initiateLoginUri = $this->relyingPartyConfig->getInitiateLoginUri())) {
            $rpMetadata[ClaimsEnum::InitiateLoginUri->value] = $initiateLoginUri;
        }

        if (is_string($logoUri = $this->relyingPartyConfig->getLogoUri())) {
            $rpMetadata[ClaimsEnum::LogoUri->value] = $logoUri;
        }

        if (is_string($jwksUri = $this->relyingPartyConfig->getJwksUri())) {
            $rpMetadata[ClaimsEnum::JwksUri->value] = $jwksUri;
        }

        if (is_string($signedJwksUri = $this->relyingPartyConfig->getSignedJwksUri())) {
            $rpMetadata[ClaimsEnum::SignedJwksUri->value] = $signedJwksUri;
        }

        if (
            (!array_key_exists(ClaimsEnum::JwksUri->value, $rpMetadata)) &&
            (!array_key_exists(ClaimsEnum::SignedJwksUri->value, $rpMetadata))
        ) {
            $rpMetadata[ClaimsEnum::Jwks->value] = $this->relyingPartyJwksDecorator->jsonSerialize();
        }

        $payloadMetadata = is_array($payloadMetadata = $payload[ClaimsEnum::Metadata->value] ?? null) ?
        $payloadMetadata :
        [];
        $payloadMetadata[EntityTypesEnum::OpenIdRelyingParty->value] = $rpMetadata;

        /** @var array<non-empty-string,mixed> $payload */
        $payload[ClaimsEnum::Metadata->value] = $payloadMetadata;

        $signatureKeyPair = $this->federationSignatureKeyPairBag->getFirstOrFail();

        $header = [
            ClaimsEnum::Kid->value => $signatureKeyPair->getKeyPair()->getKeyId(),
        ];

        return $this->federation->entityStatementFactory()->fromData(
            $signatureKeyPair->getKeyPair()->getPrivateKey(),
            $signatureKeyPair->getSignatureAlgorithm(),
            $payload,
            $header,
        );
    }

    public function getFederation(): Federation
    {
        return $this->federation;
    }

    /**
     * @throws JwsException
     * @throws TrustMarkException
     * @return array<array{trust_mark_type: non-empty-string, trust_mark: string}>
     */
    protected function prepareTrustMarksClaimValue(): array
    {
        $trustmarks = [];

        if (($staticTrustMarkTokens = $this->entityConfig->getStaticTrustMarkBag()->getAll()) !== []) {
            $trustMarks = array_map(
                function (string $trustMarkToken): array {
                    $trustMarkEntity = $this->federation->trustMarkFactory()->fromToken($trustMarkToken);

                    if ($trustMarkEntity->getSubject() === $this->entityConfig->getEntityId()) {
                        return [
                            ClaimsEnum::TrustMarkType->value => $trustMarkEntity->getTrustMarkType(),
                            ClaimsEnum::TrustMark->value => $trustMarkToken,
                        ];
                    }

                    $this->logger?->error(
                        'Trust mark subject does not match entity ID.',
                        [
                            'trustMarkSubject' => $trustMarkEntity->getSubject(),
                            'entityId' => $this->entityConfig->getEntityId(),
                        ],
                    );
                    throw new \RuntimeException('Trust mark subject does not match entity ID.');
                },
                $staticTrustMarkTokens,
            );
        }

        if (($dynamicTrustMarks = $this->entityConfig->getDynamicTrustMarkBag()->getAll()) !== []) {
            foreach ($dynamicTrustMarks as $dynamicTrustMark) {
                try {
                    $trustMarkType = $dynamicTrustMark->getKey();
                    $trustMarkIssuer = $dynamicTrustMark->getValue();

                    $trustMarkIssuerConfigurationStatement = $this->federation->entityStatementFetcher()
                        ->fromCacheOrWellKnownEndpoint($trustMarkIssuer);

                    $trustMarkEntity = $this->federation->trustMarkFetcher()->fromCacheOrFederationTrustMarkEndpoint(
                        $trustMarkType,
                        $this->entityConfig->getEntityId(),
                        $trustMarkIssuerConfigurationStatement,
                    );
                    $trustmarks[] = [
                        ClaimsEnum::TrustMarkType->value => $trustMarkType,
                        ClaimsEnum::TrustMark->value => $trustMarkEntity->getToken(),
                    ];
                } catch (\Throwable $throwable) {
                    $this->logger?->error(
                        'Error fetching Trust Mark: ' . $throwable->getMessage(),
                        [
                            'trustMarkType' => $dynamicTrustMark->getKey(),
                            'trustMarkIssuer' => $dynamicTrustMark->getValue(),
                            'entityId' => $this->entityConfig->getEntityId(),
                        ],
                    );
                }
            }
        }

        return $trustmarks;
    }

    public function getRelyingPartyConfig(): RelyingPartyConfig
    {
        return $this->relyingPartyConfig;
    }

    public function getFederationSignatureKeyPairBag(): SignatureKeyPairBag
    {
        return $this->federationSignatureKeyPairBag;
    }

    /**
     * @param non-empty-string $openIdProviderEntityId OpenID Provider Entity
     * Identifier.
     * @param TrustAnchorConfigBag|null $specificTrustAnchors Optional, specific
     * Trust Anchors to use for resolving the trust chain. Must be a subset of
     * the original Trust Anchors configured for the client. It can also be used
     * to set the desired priority of Trust Anchors for Trust Chain resolution.
     * @param ResponseInterface|null $response Optional, the HTTP response which
     * will be populated with proper redirect headers, and then returned by this
     * method. If not provided, an immediate redirect will be performed.
     * @param string|null $clientRedirectUri Optional, the redirect URI of
     * the client to use for the authorization request. If not provided, the
     * default redirect URI configured for the client will be used. If set,
     * the value must match one of the redirect URIs configured for the client.
     * @param AuthorizationRequestMethodEnum|null $authorizationRequestMethod
     * Optional, the authorization request method to use. If not provided, the
     * default authorization request method configured for the client will be
     * used.
     *
     * @throws InvalidValueException
     * @throws JwsException
     * @throws OidcClientException
     * @throws OpenIdException
     * @throws TrustChainException
     * @throws EntityStatementException
     */
    public function autoRegisterAndAuthenticate(
        string $openIdProviderEntityId,
        ?string $loginHint = null,
        ?TrustAnchorConfigBag $specificTrustAnchors = null,
        ?ResponseInterface $response = null,
        ?string $clientRedirectUri = null,
        ?AuthorizationRequestMethodEnum $authorizationRequestMethod = null,
    ): ?ResponseInterface {
        $authorizationRequestMethod ??= $this->defaultAuthorizationRequestMethod;
        $trustAnchorBag = $this->entityConfig->getTrustAnchorBag();
        if ($specificTrustAnchors instanceof TrustAnchorConfigBag) {
            $trustAnchorBag = $trustAnchorBag->getInCommonWith($specificTrustAnchors);
        }

        $validTrustAnchorIds = $trustAnchorBag->getAllEntityIds();

        if ($validTrustAnchorIds === []) {
            $this->logger?->error(
                'No valid Trust Anchors configured for the client.',
            );
            throw new OidcClientException('No valid Trust Anchors configured for the client.');
        }

        try {
            // Resolve Trust Chains to the OpenID Provider.
            $opTrustChainBag = $this->federation->trustChainResolver()->for(
                $openIdProviderEntityId,
                $validTrustAnchorIds,
            );
        } catch (TrustChainException $trustChainException) {
            $this->logger?->error(
                'Error resolving Trust Chain to OP: ' . $trustChainException->getMessage(),
                [
                    'openIdProviderId' => $openIdProviderEntityId,
                    'entityId' => $this->entityConfig->getEntityId(),
                ],
            );
            throw new OidcClientException(
                $trustChainException->getMessage(),
                $trustChainException->getCode(),
                $trustChainException,
            );
        }

        $this->logger?->debug(sprintf('Trust Chains resolved to OP: %s.', $opTrustChainBag->getCount()), [
            $opTrustChainBag->getAll(),
        ]);

        $opTrustChain = $opTrustChainBag->getShortest();

        $this->logger?->debug(
            'Shortest Trust Chain to OP: ',
            $opTrustChain->jsonSerialize(),
        );

        if ($specificTrustAnchors instanceof TrustAnchorConfigBag) {
            $this->logger?->debug(
                'Specific Trust Anchors provided, getting shortest Trust Chain to OP by Trust Anchor priority.',
                ['specificTrustAnchorIds' => $trustAnchorBag->getAllEntityIds()],
            );
            $prioritizedTrustChain = $opTrustChainBag->getShortestByTrustAnchorPriority(
                ...$trustAnchorBag->getAllEntityIds(),
            );

            if ($prioritizedTrustChain instanceof TrustChain) {
                $this->logger?->debug(
                    'Prioritized Trust Chain to OP found.',
                    ['prioritizedTrustChain' => $prioritizedTrustChain->jsonSerialize()],
                );
                $opTrustChain = $prioritizedTrustChain;
            }

            $this->logger?->debug(
                'Prioritized Trust Chain to OP not found, using shortest chain instead.'
            );
        }

        $opEntityStatement = $opTrustChain->getResolvedLeaf();

        if (
            ($opEntityStatement->getSubject() !== $openIdProviderEntityId) ||
            ($opEntityStatement->getIssuer() !== $openIdProviderEntityId)
        ) {
            $this->logger?->error(
                'OpenID Provider subject / issuer does not match the expected one.',
                [
                    'openIdProviderSubject' => $opEntityStatement->getSubject(),
                    'openIdProviderIssuer' => $opEntityStatement->getIssuer(),
                    'expectedOpenIdProviderId' => $openIdProviderEntityId,
                ],
            );
            throw new OidcClientException('OpenID Provider subject / issuer does not match the expected one.');
        }

        $opResolvedMetadata = $opTrustChain->getResolvedMetadata(EntityTypesEnum::OpenIdProvider);

        if (!is_array($opResolvedMetadata)) {
            $this->logger?->error(
                'OpenID Provider resolved metadata not available.',
                [
                    'entityId' => $openIdProviderEntityId,
                ],
            );
            throw new OidcClientException('OpenID Provider resolved metadata not available.');
        }

        $opAuthorizationEndpoint = $opResolvedMetadata[ClaimsEnum::AuthorizationEndpoint->value] ?? null;

        if (!is_string($opAuthorizationEndpoint)) {
            $this->logger?->error(
                'OpenID Provider authorization endpoint not available.',
                [
                    'entityId' => $openIdProviderEntityId,
                ],
            );
            throw new OidcClientException('OpenID Provider authorization endpoint not available.');
        }

        $signingKeyPair = $this->federation->keyPairResolver()->resolveSignatureKeyPairByAlgorithm(
            signatureKeyPairBag: $this->connectSignatureKeyPairBag,
            receiverEntityMetadata: $opResolvedMetadata,
            receiverDesignatedSignatureAlgorithmMetadataKey: null,
            receiverSupportedSignatureAlgorithmsMetadataKey: ClaimsEnum::RequestObjectSigningAlgValuesSupported->value,
        );

        try {
            // Resolve own RP Trust Chain using the resolved OP's Trust Anchor.
            $this->logger?->debug(
                "Resolving own RP Trust Chain using the resolved OP's Trust Anchor.",
                ['resolvedOpTrustAnchorId' => $opTrustChain->getResolvedTrustAnchor()->getIssuer()],
            );
            $rpTrustChain = $this->federation->trustChainResolver()->for(
                $this->entityConfig->getEntityId(),
                [$opTrustChain->getResolvedTrustAnchor()->getIssuer()],
            )->getShortest();
        } catch (TrustChainException $trustChainException) {
            $this->logger?->error(
                'Error resolving own RP Trust Chain: ' . $trustChainException->getMessage(),
            );
            throw new OidcClientException(
                $trustChainException->getMessage(),
                $trustChainException->getCode(),
                $trustChainException,
            );
        }

        $this->logger?->debug(
            'RP Trust Chain resolved: ',
            $rpTrustChain->jsonSerialize(),
        );

        $currentTimeUtc = $this->federation->helpers()->dateTime()->getUtc();
        $clientRedirectUri = $this->resolveClientRedirectUriForAuthorizationRequest($clientRedirectUri);
        $state = $this->requestDataHandler->getState();
        $nonce = $this->useNonce ? $this->requestDataHandler->getNonce() : null;
        $pkceCodeChallenge = $this->usePkce ?
        $this->requestDataHandler->generateCodeChallengeFromCodeVerifier(
            $this->requestDataHandler->getCodeVerifier(),
            $this->pkceCodeChallengeMethod,
        ) :
        null;
        $pkceCodeChallengeMethod = $this->usePkce ? $this->pkceCodeChallengeMethod->value : null;
        $scope = $this->relyingPartyConfig->getScopeBag()->toString();

        // Set resolved OP metadata for the state, so we can fetch it on callback.
        $this->requestDataHandler->setResolvedOpMetadataForState($state, $opResolvedMetadata);
        // Set used redirect URI for state, so we can fetch it on callback.
        $this->requestDataHandler->setClientRedirectUriForState($state, $clientRedirectUri);

        $requestObjectPayload = array_filter([
            ClaimsEnum::Aud->value => $openIdProviderEntityId,
            ClaimsEnum::ClientId->value => $this->entityConfig->getEntityId(),
            ClaimsEnum::Iss->value => $this->entityConfig->getEntityId(),
            ClaimsEnum::Jti->value => $this->federation->helpers()->random()->string(),
            ClaimsEnum::Exp->value => $currentTimeUtc->add($this->privateKeyJwtDuration)->getTimestamp(),
            ClaimsEnum::Iat->value => $currentTimeUtc->getTimestamp(),

            ParamsEnum::ResponseType->value => ResponseTypesEnum::Code->value,
            ParamsEnum::RedirectUri->value => $clientRedirectUri,
            ParamsEnum::Scope->value => $scope,
            ParamsEnum::State->value => $state,
            ParamsEnum::Nonce->value => $nonce,
            ParamsEnum::CodeChallenge->value => $pkceCodeChallenge,
            ParamsEnum::CodeChallengeMethod->value => $pkceCodeChallengeMethod,
            ParamsEnum::LoginHint->value => $loginHint,
        ]);

        $requestObjectHeader = [
            ClaimsEnum::Kid->value => $signingKeyPair->getKeyPair()->getKeyId(),
        ];

        if ($authorizationRequestMethod === AuthorizationRequestMethodEnum::FormPost) {
            $requestObjectHeader[ClaimsEnum::TrustChain->value] = $rpTrustChain->jsonSerialize();
            $requestObjectHeader[ClaimsEnum::PeerTrustChain->value] = $opTrustChain->jsonSerialize();
        }

        $this->logger?->debug(
            'Request Object payload and header prepared: ',
            [
                'payload' => $requestObjectPayload,
                'header' => $requestObjectHeader,
            ],
        );

        $requestObject = $this->federation->requestObjectFactory()->fromData(
            $signingKeyPair->getKeyPair()->getPrivateKey(),
            $signingKeyPair->getSignatureAlgorithm(),
            $requestObjectPayload,
            $requestObjectHeader,
        );

        $authorizationParameters = array_filter([
            ParamsEnum::Request->value => $requestObject->getToken(),
            // We have the following in the Request Object; however,
            // they are mandatory by the OpenID Connect Core 1.0 specification.
            ParamsEnum::Scope->value => $scope,
            ParamsEnum::ResponseType->value => ResponseTypesEnum::Code->value,
            ParamsEnum::ClientId->value => $this->entityConfig->getEntityId(),
            ParamsEnum::RedirectUri->value => $clientRedirectUri,
        ]);

        $this->logger?->debug(
            'Authorization parameters prepared: ',
            $authorizationParameters,
        );

        if ($authorizationRequestMethod === AuthorizationRequestMethodEnum::FormPost) {
            $formHtml = HttpHelper::generateAutoSubmitPostForm($opAuthorizationEndpoint, $authorizationParameters);
            if ($response instanceof ResponseInterface) {
                $this->logger?->debug('Returning FormPost HTML in response body.');
                $response->getBody()->write($formHtml);
                return $response->withHeader('Content-Type', 'text/html');
            }

            echo $formHtml;
            exit;
        }

        $opAuthorizationEndpointUri = $opAuthorizationEndpoint . '?' . http_build_query($authorizationParameters);

        if ($response instanceof ResponseInterface) {
            $this->logger?->debug('Redirecting to authorization endpoint.');
            return $response->withHeader('Location', $opAuthorizationEndpointUri);
        }

        header('Location: ' . $opAuthorizationEndpointUri);
        exit;
    }

    protected function resolveClientRedirectUriForAuthorizationRequest(?string $specificRedirectUri): string
    {
        if (!is_string($specificRedirectUri)) {
            return $this->relyingPartyConfig->getRedirectUriBag()->getDefaultRedirectUri();
        }

        if (in_array($specificRedirectUri, $this->relyingPartyConfig->getRedirectUriBag()->getAll(), true)) {
            return $specificRedirectUri;
        }

        $this->logger?->warning(
            'Redirect URI provided does not match any configured redirect URI. Using default redirect URI instead.',
        );

        return $this->relyingPartyConfig->getRedirectUriBag()->getDefaultRedirectUri();
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
        $params = $this->requestDataHandler->validateAuthorizationCallbackResponse($request);

        $authorizationCode = $params[ParamsEnum::Code->value];
        $state = $params[ParamsEnum::State->value];

        $resolvedOpMetadata = $this->requestDataHandler->getResolvedOpMetadataForState($state);
        $clientRedirectUri = $this->requestDataHandler->getClientRedirectUriForState($state);

        $opJwksUri = $resolvedOpMetadata[ClaimsEnum::JwksUri->value] ?? null;
        if (!is_string($opJwksUri)) {
            $this->logger?->error('OpenID Provider JWKS URI not available.', [
                'resolvedOpMetadata' => $resolvedOpMetadata,
            ]);
            throw new OidcClientException('OpenID Provider JWKS URI not available.');
        }

        $opTokenEndpoint = $resolvedOpMetadata[ClaimsEnum::TokenEndpoint->value] ?? null;
        if (!is_string($opTokenEndpoint)) {
            $this->logger?->error(
                'OpenID Provider token endpoint not available.',
                ['resolvedOpMetadata' => $resolvedOpMetadata],
            );
            throw new OidcClientException('OpenID Provider token endpoint not available.');
        }

        $opUserinfoEndpoint = $resolvedOpMetadata[ClaimsEnum::UserinfoEndpoint->value] ?? null;
        $opUserinfoEndpoint = is_string($opUserinfoEndpoint) ? $opUserinfoEndpoint : null;

        $opEntityId = $resolvedOpMetadata[ClaimsEnum::Issuer->value] ?? null;
        if (!is_string($opEntityId)) {
            $this->logger?->error(
                'OpenID Provider entity ID not available.',
                ['resolvedOpMetadata' => $resolvedOpMetadata],
            );
            throw new OidcClientException('OpenID Provider entity ID not available.');
        }

        $signingKeyPair = $this->federation->keyPairResolver()->resolveSignatureKeyPairByAlgorithm(
            signatureKeyPairBag: $this->connectSignatureKeyPairBag,
            receiverEntityMetadata: $resolvedOpMetadata,
            receiverSupportedSignatureAlgorithmsMetadataKey:
            ClaimsEnum::TokenEndpointAuthSigningAlgValuesSupported->value,
        );

        $currentTimeUtc = $this->federation->helpers()->dateTime()->getUtc();

        $clientAssertionPayload = array_filter([
            ClaimsEnum::Iss->value => $this->entityConfig->getEntityId(),
            ClaimsEnum::Sub->value => $this->entityConfig->getEntityId(),
            ClaimsEnum::Aud->value => $opEntityId,
            ClaimsEnum::Jti->value => $this->federation->helpers()->random()->string(),
            ClaimsEnum::Exp->value => $currentTimeUtc->add($this->privateKeyJwtDuration)->getTimestamp(),
            ClaimsEnum::Iat->value => $currentTimeUtc->getTimestamp(),
        ]);

        $clientAssertionHeader = [
            ClaimsEnum::Kid->value => $signingKeyPair->getKeyPair()->getKeyId(),
        ];

        $clientAssertion = $this->core->clientAssertionFactory()->fromData(
            $signingKeyPair->getKeyPair()->getPrivateKey(),
            $signingKeyPair->getSignatureAlgorithm(),
            $clientAssertionPayload,
            $clientAssertionHeader,
        );

        return $this->requestDataHandler->getUserData(
            clientAuthenticationMethod: ClientAuthenticationMethodsEnum::PrivateKeyJwt,
            authorizationCode: $authorizationCode,
            clientId: $this->entityConfig->getEntityId(),
            clientRedirectUri: $clientRedirectUri,
            opJwksUri: $opJwksUri,
            opTokenEndpoint: $opTokenEndpoint,
            opUserinfoEndpoint: $opUserinfoEndpoint,
            clientSecret: null,
            clientAssertion: $clientAssertion->getToken(),
            usePkce: $this->usePkce,
            useNonce: $this->useNonce,
            fetchUserinfoClaims: $this->fetchUserinfoClaims
        );
    }
}
