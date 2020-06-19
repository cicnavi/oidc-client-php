<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;

interface ConfigInterface
{
    /**
     * Get a configuration value for provided key.
     *
     * @param string $key Configuration key
     * @return string|array|false Configuration value
     * @throws OidcClientException If config parameter (key) is not supported.
     */
    public function get(string $key);

    /**
     * @return string
     */
    public function getOidcConfigurationUrl(): string;

    /**
     * @return string
     */
    public function getClientId(): string;

    /**
     * @return string
     */
    public function getClientSecret(): string;

    /**
     * @return string
     */
    public function getRedirectUri(): string;

    /**
     * @return string
     */
    public function getScope(): string;

    /**
     * @return bool
     */
    public function isConfidentialClient(): bool;

    /**
     * @return string
     */
    public function getPkceCodeChallengeMethod(): string;

    /**
     * @return string[]
     */
    public function getIdTokenValidationAllowedAlgs(): array;

    /**
     * @return int|false
     */
    public function getIdTokenValidationExpLeeway();

    /**
     * @return int|false
     */
    public function getIdTokenValidationIatLeeway();

    /**
     * @return int|false
     */
    public function getIdTokenValidationNbfLeeway();

    /**
     * @return bool
     */
    public function isStateCheckEnabled(): bool;

    /**
     * @return bool
     */
    public function isNonceCheckEnabled(): bool;
}
