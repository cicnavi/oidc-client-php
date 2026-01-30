<?php

declare(strict_types=1);


namespace FederatedClient;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\FederatedClient;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum;
use SimpleSAML\OpenID\SupportedAlgorithms;

class FederatedClientFactory
{
    /**
     * @param array $config Configuration array (e.g., from federated-client-config-example.php)
     * @param LoggerInterface|null $logger Optional logger
     * @param CacheInterface|null $cache Optional cache
     */
    public function __construct(
        protected readonly array $config,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly ?CacheInterface $cache = null,
    )
    {
    }

    public function build(): FederatedClient
    {
        return new FederatedClient(
            entityConfig: $this->config['entity_config'],
            relyingPartyConfig: $this->config['relying_party_config'],
            entityStatementDuration: $this->config['entity_statement_duration'],
            cache: $this->cache,
            maxCacheDuration: $this->config['max_cache_duration'],
            timestampValidationLeeway: $this->config['timestamp_validation_leeway'],
            supportedAlgorithms: $this->config['supported_algorithms'] ?? new SupportedAlgorithms(
            new SignatureAlgorithmBag(
                SignatureAlgorithmEnum::ES256,
                SignatureAlgorithmEnum::RS256,
            ),
        ),
            logger: $this->logger,
            maxTrustChainDepth: $this->config['max_trust_chain_depth'] ?? 9,
            defaultTrustMarkStatusEndpointUsagePolicyEnum: $this->config['default_trust_mark_status_endpoint_usage_policy'] ?? TrustMarkStatusEndpointUsagePolicyEnum::NotUsed,
            includeSoftwareId: $this->config['include_software_id'] ?? true,
            privateKeyJwtDuration: $this->config['private_key_jwt_duration'] ?? new \DateInterval('PT5M'),
            useNonce: $this->config['use_nonce'] ?? true,
            usePkce: $this->config['use_pkce'] ?? true,
            fetchUserinfoClaims: $this->config['fetch_userinfo_claims'] ?? true,
            pkceCodeChallengeMethod: $this->config['pkce_code_challenge_method'] ?? PkceCodeChallengeMethodEnum::S256,
            defaultAuthorizationRequestMethod: $this->config['authorization_request_method'] ?? AuthorizationRequestMethodEnum::FormPost,
        );
    }
}
