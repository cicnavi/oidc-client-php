<?php

declare(strict_types=1);

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\Federation\EntityConfig;
use Cicnavi\Oidc\Federation\RelyingPartyConfig;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Codebooks\EntityTypesEnum;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\ValueAbstracts\ClaimBag;
use SimpleSAML\OpenID\ValueAbstracts\KeyPairFilenameConfig;
use SimpleSAML\OpenID\ValueAbstracts\RedirectUriBag;
use SimpleSAML\OpenID\ValueAbstracts\ScopeBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfig;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\TrustAnchorConfig;
use SimpleSAML\OpenID\ValueAbstracts\TrustAnchorConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag;

return [
    'entity_config' => new EntityConfig(
        entityId: 'https://rp.example.org/',
        trustAnchorBag: new TrustAnchorConfigBag(
            new TrustAnchorConfig(
                'https://ta.example.org',
                '{
    "keys": [
      {
        "alg": "ES512",
        "crv": "P-521",
        "iat": 1754904676,
        "kid": "Lk6QsLBCg7q66QvAQdqcx5xaJ56I26MQTqPo2zzhNW0",
        "kty": "EC",
        "use": "sig",
        "x": "AGbfMmKBDdf8zVVGBXb4AVc6dZMduS0kl-W6ZmnHIFsJT7hQF-829Bdyzp-WuKB84mN--iQFti3s9ApCzo3jEKLs",
        "y": "AFfjqR5C6QVJtgkFMbg8VQER58o44e4VQSDSZk8G_a54RsHgDAz5lfmnfvIN0vahDHpsToXkC1ptkiI-Fp1MNumQ"
      }
    ]
}', // Optional: The trust anchor's JWKS
            ),
        ),
        authorityHintBag: new UniqueStringBag(
            'https://ta.example.org',
        ),
        federationSignatureKeyPairConfigBag: new SignatureKeyPairConfigBag(
            new SignatureKeyPairConfig(
                SignatureAlgorithmEnum::RS256,
                new KeyPairFilenameConfig(
                    dirname(__DIR__) . '/keys/federation-sig.key',
                    dirname(__DIR__) . '/keys/federation-sig.pub',
                    keyId: 'fed-sig-01',
                ),
            ),
        ),
        additionalClaimBag: new ClaimBag(
            [
                ClaimsEnum::Metadata->value => [
                    EntityTypesEnum::FederationEntity->value => [
                        ClaimsEnum::DisplayName->value => 'Example Federated RP',
                    ],
                ],
            ],
        ),
    ),
    'relying_party_config' => new RelyingPartyConfig(
        new RedirectUriBag(
            'https://rp.example.org/callback',
        ),
        connectSignatureKeyPairBag: new SignatureKeyPairConfigBag(
            new SignatureKeyPairConfig(
                SignatureAlgorithmEnum::RS256,
                new KeyPairFilenameConfig(
                    dirname(__DIR__) . '/keys/connect-sig.key',
                    dirname(__DIR__) . '/keys/connect-sig.pub',
                    keyId: 'conn-sig-01',
                ),
            ),
        ),
        scopeBag: new ScopeBag('openid', 'profile', 'email'),
        additionalClaimBag: new ClaimBag(
            [
                ClaimsEnum::ClientName->value => 'Example Federated RP',
            ],
        ),
    ),
    'entity_statement_duration' => new \DateInterval('P1D'),
    'max_cache_duration' => new \DateInterval('PT6H'),
    'timestamp_validation_leeway' => new \DateInterval('PT1M'),
    'max_trust_chain_depth' => 9,
    // phpcs:ignore
    'default_trust_mark_status_endpoint_usage_policy' => \SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum::RequiredIfEndpointProvidedForNonExpiringTrustMarksOnly,
    'include_software_id' => true,
    'supported_algorithms' => new SupportedAlgorithms(
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
    'private_key_jwt_duration' => new \DateInterval('PT5M'),
    'use_nonce' => true,
    'use_pkce' => true,
    'pkce_code_challenge_method' => \SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum::S256,
    'fetch_userinfo_claims' => true,
    'authorization_request_method' => AuthorizationRequestMethodEnum::FormPost,

    // Hardcoded OpenID Providers, until discovery is implemented.
    'open_id_providers' => new \SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag(
        'https://idp.mivanci.incubator.hexaa.eu',
        'https://oidfed-op-demo.incubator.geant.org',
    ),
];
