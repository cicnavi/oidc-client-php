<?php

declare(strict_types=1);


namespace FederatedClient;

use Cicnavi\Oidc\FederatedClient;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class FederationDiscoveryController
{
    public function __construct(
        protected readonly FederatedClient $federatedClient,
        protected readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * Discover OpenID Providers grouped by trust anchor.
     *
     * Query params:
     * - force_refresh=1|true (optional)
     */
    public function openIdProviders(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();
        $forceRefresh = $this->parseBool($queryParams['force_refresh'] ?? null);

        $providersPerTrustAnchor = $this->federatedClient->discoverOpenIdProviders(
            sortClaimPaths: [
                ['metadata', 'openid_provider', 'display_name'],
                ['metadata', 'federation_entity', 'display_name'],
            ],
            forceRefresh: $forceRefresh,
        );

        $this->logger->info('OpenID Provider discovery completed.', [
            'force_refresh' => $forceRefresh,
            'trust_anchors' => count($providersPerTrustAnchor),
        ]);

        return $providersPerTrustAnchor;
    }

    /**
     * Discover entities grouped by trust anchor using custom criteria.
     *
     * Query params:
     * - entity_type=openid_provider,federation_entity (optional)
     * - trust_mark_type=https://example.org/tm/1,https://example.org/tm/2 (optional)
     * - query=search text (optional)
     * - sort_order=asc|desc (optional, default: asc)
     * - force_refresh=1|true (optional)
     */
    public function entities(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();

        $criteria = array_filter([
            'entity_type' => $this->parseCsv($queryParams['entity_type'] ?? null),
            'trust_mark_type' => $this->parseCsv($queryParams['trust_mark_type'] ?? null),
            'query' => $this->parseString($queryParams['query'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $sortOrder = $this->parseSortOrder($queryParams['sort_order'] ?? null);
        $forceRefresh = $this->parseBool($queryParams['force_refresh'] ?? null);

        $entitiesPerTrustAnchor = $this->federatedClient->discoverEntities(
            criteria: $criteria,
            sortClaimPaths: [
                ['metadata', 'openid_provider', 'display_name'],
                ['metadata', 'federation_entity', 'display_name'],
            ],
            sortOrder: $sortOrder,
            forceRefresh: $forceRefresh,
        );

        $this->logger->info('Federation entity discovery completed.', [
            'criteria' => $criteria,
            'sort_order' => $sortOrder,
            'force_refresh' => $forceRefresh,
            'trust_anchors' => count($entitiesPerTrustAnchor),
        ]);

        return $entitiesPerTrustAnchor;
    }

    private function parseSortOrder(mixed $sortOrder): string
    {
        if (!is_string($sortOrder)) {
            return 'asc';
        }

        return strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';
    }

    private function parseCsv(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $values = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        )));

        return $values === [] ? null : $values;
    }

    private function parseString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
