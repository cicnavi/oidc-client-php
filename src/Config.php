<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\ConfigInterface;
use Cicnavi\Oidc\Validators\ConfigValidator;

class Config implements ConfigInterface
{
    // Config keys
    public const CONFIG_KEY_OIDC_CONFIGURATION_URL = 'OIDC_CONFIGURATION_URL';
    public const CONFIG_KEY_OIDC_CLIENT_ID = 'OIDC_CLIENT_ID';
    public const CONFIG_KEY_OIDC_CLIENT_SECRET = 'OIDC_CLIENT_SECRET';
    public const CONFIG_KEY_OIDC_REDIRECT_URI = 'OIDC_REDIRECT_URI';
    public const CONFIG_KEY_OIDC_SCOPE = 'OIDC_SCOPE';
    public const CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT = 'OIDC_IS_CONFIDENTIAL_CLIENT';
    public const CONFIG_KEY_OIDC_PKCE_CODE_CHALLENGE_METHOD = 'OIDC_PKCE_CODE_CHALLENGE_METHOD';
    public const CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS = 'OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS';
    public const CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY';
    public const CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY';
    public const CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY';
    public const CONFIG_KEY_OIDC_IS_STATE_CHECK_ENABLED = 'OIDC_IS_STATE_CHECK_ENABLED';
    public const CONFIG_KEY_OIDC_IS_NONCE_CHECK_ENABLED = 'OIDC_IS_NONCE_CHECK_ENABLED';
    public const CONFIG_KEY_OIDC_SHOULD_FETCH_USERINFO_CLAIMS = 'OIDC_SHOULD_FETCH_USERINFO_CLAIMS';

    /**
     * @var array<string,mixed> $config Default OIDC configuration parameters which will be used by OIDC client.
     */
    protected static array $defaultConfig = [
        self::CONFIG_KEY_OIDC_CONFIGURATION_URL => null,
        self::CONFIG_KEY_OIDC_CLIENT_ID => null,
        self::CONFIG_KEY_OIDC_CLIENT_SECRET => null,
        self::CONFIG_KEY_OIDC_REDIRECT_URI => null,
        self::CONFIG_KEY_OIDC_SCOPE => null,
        self::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT => true,
        self::CONFIG_KEY_OIDC_PKCE_CODE_CHALLENGE_METHOD => 'S256',
        // Validation related
        self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS => ['RS256', 'RS512'],
        // Additional time for which the claim 'exp' is considered valid. If false, the check will be skipped.
        self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY => 0,
        // Additional time for which the claim 'iat' is considered valid. If false, the check will be skipped.
        self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY => 0,
        // Additional time for which the claim 'nbf' is considered valid. If false, the check will be skipped.
        self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY => 0,
        // State and nonce checks are enabled by default
        self::CONFIG_KEY_OIDC_IS_STATE_CHECK_ENABLED => true,
        self::CONFIG_KEY_OIDC_IS_NONCE_CHECK_ENABLED => true,
        self::CONFIG_KEY_OIDC_SHOULD_FETCH_USERINFO_CLAIMS => true,
    ];

    /**
     * @var array<string,mixed> $config
     */
    protected array $config;

    /**
     * Config constructor.
     * @param array $config
     * @throws OidcClientException If config array is not valid.
     */
    public function __construct(array $config)
    {
        // Remove config which is not supported.
        $config = array_intersect_key($config, self::$defaultConfig);
        // Merge with default values
        $newConfig = array_merge(self::$defaultConfig, $config);
        $this->validateConfig($newConfig);
        $this->config = $newConfig;
    }

    /**
     * @param array $config
     * @throws OidcClientException If config is not valid
     */
    protected function validateConfig(array $config): void
    {
        ConfigValidator::hasRequiredKeys($this->getConfigKeys(), $config);
        ConfigValidator::isUrl(self::CONFIG_KEY_OIDC_CONFIGURATION_URL, $config);
        ConfigValidator::isString(self::CONFIG_KEY_OIDC_CLIENT_ID, $config);
        ConfigValidator::isString(self::CONFIG_KEY_OIDC_CLIENT_SECRET, $config);
        ConfigValidator::isUrl(self::CONFIG_KEY_OIDC_REDIRECT_URI, $config);
        ConfigValidator::isString(self::CONFIG_KEY_OIDC_SCOPE, $config);
        ConfigValidator::isBool(self::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT, $config);
        ConfigValidator::isValidValue(
            self::CONFIG_KEY_OIDC_PKCE_CODE_CHALLENGE_METHOD,
            $config,
            Pkce::VALID_PKCE_CODE_CHALLENGE_METHODS
        );
        // TODO Consider if valid algs values array should be expanded.
        ConfigValidator::isArrayWithValidValues(
            self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS,
            $config,
            self::$defaultConfig[self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS]
        );
        ConfigValidator::isFalseZeroOrPositiveInt(self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY, $config);
        ConfigValidator::isFalseZeroOrPositiveInt(self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY, $config);
        ConfigValidator::isFalseZeroOrPositiveInt(self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY, $config);
        ConfigValidator::isBool(self::CONFIG_KEY_OIDC_IS_STATE_CHECK_ENABLED, $config);
        ConfigValidator::isBool(self::CONFIG_KEY_OIDC_IS_NONCE_CHECK_ENABLED, $config);
        ConfigValidator::isBool(self::CONFIG_KEY_OIDC_SHOULD_FETCH_USERINFO_CLAIMS, $config);
    }

    /**
     * @return array Available configuration parameters.
     */
    public function getConfigKeys(): array
    {
        return array_keys(self::$defaultConfig);
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        if (!isset($this->config[$key])) {
            throw new OidcClientException(sprintf('OIDC config parameter not supported (%s)', $key));
        }

        return $this->config[$key];
    }


    /**
     * @inheritDoc
     */
    public function getOidcConfigurationUrl(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_CONFIGURATION_URL];
    }

    /**
     * @inheritDoc
     */
    public function getClientId(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_CLIENT_ID];
    }

    /**
     * @inheritDoc
     */
    public function getClientSecret(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_CLIENT_SECRET];
    }

    /**
     * @inheritDoc
     */
    public function getRedirectUri(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_REDIRECT_URI];
    }

    /**
     * @inheritDoc
     */
    public function getScope(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_SCOPE];
    }

    /**
     * @inheritDoc
     */
    public function isConfidentialClient(): bool
    {
        return (bool)$this->config[self::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT];
    }

    /**
     * @inheritDoc
     */
    public function getPkceCodeChallengeMethod(): string
    {
        return $this->config[self::CONFIG_KEY_OIDC_PKCE_CODE_CHALLENGE_METHOD];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationAllowedAlgs(): array
    {
        return $this->config[self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationExpLeeway()
    {
        return $this->config[self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationIatLeeway()
    {
        return $this->config[self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationNbfLeeway()
    {
        return $this->config[self::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function isStateCheckEnabled(): bool
    {
        return (bool)$this->config[self::CONFIG_KEY_OIDC_IS_STATE_CHECK_ENABLED];
    }

    /**
     * @inheritDoc
     */
    public function isNonceCheckEnabled(): bool
    {
        return (bool)$this->config[self::CONFIG_KEY_OIDC_IS_NONCE_CHECK_ENABLED];
    }

    /**
     * @inheritDoc
     */
    public function shouldFetchUserinfoClaims(): bool
    {
        return (bool)$this->config[self::CONFIG_KEY_OIDC_SHOULD_FETCH_USERINFO_CLAIMS];
    }
}
