<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;

class OIDFedAutomaticClient
{
    protected CacheInterface $cache;

    /**
     * TODO mivanci Federation participation limit by Trust Marks.
     *
     * @param array<string,?string> $trustAnchors Trust Anchors which are valid
     * for this entity. The key represents the Trust Anchor Entity ID, while the
     * value can be the Trust Anchor's JWKS JSON object string value, or null.
     * @param string[] $authorityHints Authority Hints which are valid for this
     * entity. Value is an array of strings representing the Entity Identifiers
     * of Intermediate Entities (or Trust Anchors), that is - the superiors of
     * this entity.
     * @param string $privateKeyFilename Path to the PEM file containing the
     * private key used to sign the JWTs.
     * @param string|null $privateKeyPassword Password for the private key, if
     * any.
     * @param string $publicKeyFilename Path to the PEM file containing the
     * public key used to verify the JWTs.
     * @param SignatureAlgorithmEnum $signatureAlgorithm Signature algorithm
     * to use for signing JWTs. Defaults to RS256.
     * @param string[] $keywords Keywords associated with this entity.
     * @param string[] $contacts Contacts associated with this entity.
     * @param string[] $staticTrustMarks Array of Trust Mark token strings
     * (signed JWTs), each representing a Trust Mark issued for this entity.
     * This option is intended for long-lasting or non-expiring tokens, so it
     * is not necessary to dynamically fetch / refresh them.
     * @param array<string,string> $dynamicTrustMarks Array of key-value pairs
     * representing Trust Marks for dynamic fetching, each representing a Trust
     * Mark issued to this entity. The key represents the Trust Mark Type, while
     * the value is the Trust Mark Issuer ID. Each Trust Mark Type in this array
     * will be dynamically fetched from the noted Trust Mark Issuer as
     * necessary. Fetched Trust Marks will also be cached until their expiry.
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
        protected readonly string $entityId,
        protected readonly array $trustAnchors,
        protected readonly array $authorityHints,
        protected readonly string $privateKeyFilename,
        protected readonly string $publicKeyFilename,
        protected readonly ?string $privateKeyPassword = null,
        protected readonly SignatureAlgorithmEnum $signatureAlgorithm = SignatureAlgorithmEnum::RS256,
        protected readonly ?string $organizationName = null,
        protected readonly ?string $displayName = null,
        protected readonly ?string $description = null,
        protected readonly array $keywords = [],
        protected readonly array $contacts = [],
        protected readonly ?string $logoUri = null,
        protected readonly ?string $policyUri = null,
        protected readonly ?string $informationUri = null,
        protected readonly ?string $organizationUri = null,
        protected readonly array $staticTrustMarks = [],
        protected readonly array $dynamicTrustMarks = [],
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
        protected ?Federation $federation = null,
    ) {
        $this->cache = $cache ?? new FileCache('ofacpc-' . md5($this->entityId));

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
    }
}
