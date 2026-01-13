<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\Federation\RelyingPartyConfig;
use SimpleSAML\OpenID\ValueAbstracts\KeyPair;
use SimpleSAML\OpenID\ValueAbstracts\KeyPairFilenameConfig;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPair;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfig;
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
use SimpleSAML\OpenID\Jwk\JwkDecorator;
use SimpleSAML\OpenID\Jwks\Factories\JwksDecoratorFactory;
use SimpleSAML\OpenID\Jwks\JwksDecorator;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;

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
        protected readonly ?Client $client = null,
        // phpcs:ignore
        protected readonly TrustMarkStatusEndpointUsagePolicyEnum $defaultTrustMarkStatusEndpointUsagePolicyEnum = TrustMarkStatusEndpointUsagePolicyEnum::NotUsed,
        ?Federation $federation = null,
        protected Jwk $jwk = new Jwk(),
        protected readonly HashAlgorithmsEnum $jwkThumbprintHashAlgo = HashAlgorithmsEnum::SHA_256,
        SignatureKeyPairFactory $signatureKeyPairFactory = null,
        SignatureKeyPairBagFactory $signatureKeyPairBagFactory = null,
        protected readonly JwksDecoratorFactory $jwksDecoratorFactory = new JwksDecoratorFactory(),
        protected readonly bool $includeSoftwareId = true,
    ) {
        $this->cache = $cache ?? new FileCache('ofacpc-' . md5($this->entityConfig->getEntityId()));
        $this->signatureKeyPairFactory = $signatureKeyPairFactory ?? new SignatureKeyPairFactory($this->jwk);
        $this->signatureKeyPairBagFactory = $signatureKeyPairBagFactory ?? new SignatureKeyPairBagFactory(
            $this->signatureKeyPairFactory,
        );

        $this->federation = $federation ?? new Federation(
            $this->supportedAlgorithms,
            $this->supportedSerializers,
            $this->maxCacheDuration,
            $this->timestampValidationLeeway,
            $this->maxTrustChainDepth,
            $this->cache,
            $this->logger,
            $this->client,
            $this->defaultTrustMarkStatusEndpointUsagePolicyEnum,
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

    public function getDefaultTrustMarkStatusEndpointUsagePolicyEnum(): TrustMarkStatusEndpointUsagePolicyEnum
    {
        return $this->defaultTrustMarkStatusEndpointUsagePolicyEnum;
    }

    public function shouldIncludeSoftwareId(): bool
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
        https://openid.net/specs/openid-connect-rp-metadata-choices-1_0-01.html
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

        if (is_string($jwksUri = $this->relyingPartyConfig->getJwksUri())) {
            $rpMetadata[ClaimsEnum::JwksUri->value] = $jwksUri;
        }

        if (is_string($signedJwksUri = $this->relyingPartyConfig->getSignedJwksUri())) {
            $rpMetadata[ClaimsEnum::SignedJwksUri->value] = $signedJwksUri;
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

    /**
     * @return non-empty-string[]
     */
    protected function getSigningAlgorithmValuesSupported(
        SignatureKeyPair $defaultSignatureKeyPair,
        SignatureKeyPair ...$additionalSignatureKeyPairs,
    ): array {
        return array_unique(
            array_merge([$defaultSignatureKeyPair->getSignatureAlgorithm()->value], array_map(
                fn(SignatureKeyPair $signatureKeyPair): string => $signatureKeyPair->getSignatureAlgorithm()->value,
                $additionalSignatureKeyPairs,
            ))
        );
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
}
