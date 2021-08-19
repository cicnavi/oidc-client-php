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
    public function getOpConfigurationUrl(): string;

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
     * Get allowed signature algorithms.
     * @return string[]
     */
    public function getIdTokenValidationAllowedSignatureAlgs(): array;

    /**
     * Get allowed encryption algorithms.
     * @return string[]
     */
    public function getIdTokenValidationAllowedEncryptionAlgs(): array;

    /**
     * Get supported signature algorithms.
     * @return string[]
     */
    public static function getIdTokenValidationSupportedSignatureAlgs(): array;

    /**
     * Get supported encryption algorithms.
     * @return string[]
     */
    public static function getIdTokenValidationSupportedEncryptionAlgs(): array;

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

    /**
     * @return bool
     */
    public function shouldFetchUserInfoClaims(): bool;
}
