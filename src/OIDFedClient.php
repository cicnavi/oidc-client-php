<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\Entities\KeyPair;
use Cicnavi\Oidc\Entities\KeyPairConfig;
use Cicnavi\Oidc\Entities\SignatureKeyPair;
use Cicnavi\Oidc\Entities\SignatureKeyPairBag;
use Cicnavi\Oidc\Entities\SignatureKeyPairConfig;
use Cicnavi\Oidc\Factories\SignatureKeyPairBagFactory;
use Cicnavi\Oidc\Factories\SignatureKeyPairFactory;
use Cicnavi\Oidc\Federation\EntityConfig;
use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\HashAlgorithmsEnum;
use SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\EntityStatement;
use SimpleSAML\OpenID\Jwk;
use SimpleSAML\OpenID\Jwk\JwkDecorator;
use SimpleSAML\OpenID\Jwks\Factories\JwksDecoratorFactory;
use SimpleSAML\OpenID\Jwks\JwksDecorator;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;

class OIDFedClient
{
    protected readonly CacheInterface $cache;

    protected readonly SignatureKeyPair $federationDefaultSignatureKeyPair;

    protected readonly SignatureKeyPairFactory $signatureKeyPairFactory;

    protected readonly SignatureKeyPairBagFactory $signatureKeyPairBagFactory;

    protected readonly SignatureKeyPairBag $federationAdditionalSignatureKeyPairs;

    protected readonly Federation $federation;

    protected JwksDecorator $federationJwks;

    /**
     * TODO mivanci Federation participation limit by Trust Marks.
     *
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

        $this->federationDefaultSignatureKeyPair = $this->signatureKeyPairFactory->fromConfig(
            $this->entityConfig->getDefaultSignatureKeyPair(),
        );

        $this->federationAdditionalSignatureKeyPairs = $this->signatureKeyPairBagFactory->fromConfig(
            $this->entityConfig->getAdditionalSignatureKeyPairs(),
        );

        $this->federationJwks = $this->jwksDecoratorFactory->fromJwkDecorators(
            $this->federationDefaultSignatureKeyPair->getKeyPair()->getPublicKey(),
            ...array_map(
                fn(SignatureKeyPair $signatureKeyPair): JwkDecorator => $signatureKeyPair->getKeyPair()->getPublicKey(),
                $this->federationAdditionalSignatureKeyPairs->getAll()
            ),
        );
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

    public function getEntityConfiguration(): EntityStatement
    {
        $issuedAt = $this->federation->helpers()->dateTime()->getUtc();

        $payload = $this->entityConfig->getAdditionalClaims()->getAll();
        $payload[ClaimsEnum::Iss->value] = $this->entityConfig->getEntityId();
        $payload[ClaimsEnum::Sub->value] = $this->entityConfig->getEntityId();
        $payload[ClaimsEnum::Iat->value] = $issuedAt->getTimestamp();
        $payload[ClaimsEnum::Exp->value] = $issuedAt->add($this->entityStatementDuration)->getTimestamp();
        $payload[ClaimsEnum::Jti->value] = $this->federation->helpers()->random()->string();
        $payload[ClaimsEnum::Jwks->value] = $this->federationJwks->jsonSerialize();

        $this->entityConfig->getRpConfig()->getAdditionalClaims()->getAll();

        $header = [];
        return $this->federation->entityStatementFactory()->fromData(
            $this->federationDefaultSignatureKeyPair->getKeyPair()->getPrivateKey(),
            $this->federationDefaultSignatureKeyPair->getSignatureAlgorithm(),
            $payload,
            $header,
        );
    }
}
