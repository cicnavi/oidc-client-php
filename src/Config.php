<?php

declare(strict_types=1);

namespace Cicnavi\Oidc;

use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Interfaces\ConfigInterface;
use Cicnavi\Oidc\Validators\ConfigValidator;
use Jose\Component\Signature\Algorithm;
use Jose\Component\Encryption\Algorithm\ContentEncryption;
use Jose\Component\Encryption\Algorithm\KeyEncryption;
use ReflectionClass;
use Throwable;

class Config implements ConfigInterface
{
    // Keys for config options
    public const OPTION_OP_CONFIGURATION_URL = 'OIDC_OP_CONFIGURATION_URL';
    public const OPTION_CLIENT_ID = 'OIDC_CLIENT_ID';
    public const OPTION_CLIENT_SECRET = 'OIDC_CLIENT_SECRET';
    public const OPTION_REDIRECT_URI = 'OIDC_REDIRECT_URI';
    public const OPTION_SCOPE = 'OIDC_SCOPE';
    public const OPTION_IS_CONFIDENTIAL_CLIENT = 'OIDC_IS_CONFIDENTIAL_CLIENT';
    public const OPTION_PKCE_CODE_CHALLENGE_METHOD = 'OIDC_PKCE_CODE_CHALLENGE_METHOD';
    public const OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS =
        'OIDC_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS';
    public const OPTION_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS =
        'OIDC_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS';
    public const OPTION_ID_TOKEN_VALIDATION_EXP_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY';
    public const OPTION_ID_TOKEN_VALIDATION_IAT_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY';
    public const OPTION_ID_TOKEN_VALIDATION_NBF_LEEWAY = 'OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY';
    public const OPTION_IS_STATE_CHECK_ENABLED = 'OIDC_IS_STATE_CHECK_ENABLED';
    public const OPTION_IS_NONCE_CHECK_ENABLED = 'OIDC_IS_NONCE_CHECK_ENABLED';
    public const OPTION_SHOULD_FETCH_USERINFO_CLAIMS = 'OIDC_SHOULD_FETCH_USERINFO_CLAIMS';
    public const OPTION_DEFAULT_CACHE_TTL = 'OIDC_DEFAULT_CACHE_TTL';

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
        $config = array_intersect_key($config, $this->getDefaultConfig());
        // Merge with default values
        $newConfig = array_merge($this->getDefaultConfig(), $config);
        $this->validateConfig($newConfig);
        $this->config = $newConfig;
    }

    /**
     * @return array<string,mixed> Default OIDC configuration parameters which will be used by OIDC client.
     * @throws OidcClientException
     */
    public function getDefaultConfig(): array
    {
        return [
            // Mandatory config items
            self::OPTION_OP_CONFIGURATION_URL => null,
            self::OPTION_CLIENT_ID => null,
            self::OPTION_CLIENT_SECRET => null,
            self::OPTION_REDIRECT_URI => null,
            self::OPTION_SCOPE => null,

            // Optional config items with default values
            // Additional time for which the claim 'exp' is considered valid. If false, the check will be skipped.
            self::OPTION_ID_TOKEN_VALIDATION_EXP_LEEWAY => 0,
            // Additional time for which the claim 'iat' is considered valid. If false, the check will be skipped.
            self::OPTION_ID_TOKEN_VALIDATION_IAT_LEEWAY => 0,
            // Additional time for which the claim 'nbf' is considered valid. If false, the check will be skipped.
            self::OPTION_ID_TOKEN_VALIDATION_NBF_LEEWAY => 0,
            // Enable or disable State check
            self::OPTION_IS_STATE_CHECK_ENABLED => true,
            // Enable or disable Nonce check
            self::OPTION_IS_NONCE_CHECK_ENABLED => true,
            // Set allowed signature algorithms
            self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS =>
                self::getIdTokenValidationSupportedSignatureAlgs(),
            // Set allowed encryption algorithms
            self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS =>
                self::getIdTokenValidationSupportedEncryptionAlgs(),
            // Should client fetch userinfo claims
            self::OPTION_SHOULD_FETCH_USERINFO_CLAIMS => true,
            // Choose if client should act as confidential client or public client
            self::OPTION_IS_CONFIDENTIAL_CLIENT => true,
            // If public client, set PKCE code challenge method to use
            self::OPTION_PKCE_CODE_CHALLENGE_METHOD => 'S256',
            // Default cache time-to-live in seconds
            self::OPTION_DEFAULT_CACHE_TTL => 60 * 60 * 24,
        ];
    }

    /**
     * @param array $config
     * @throws OidcClientException If config is not valid
     */
    protected function validateConfig(array $config): void
    {
        ConfigValidator::hasRequiredKeys($this->getConfigKeys(), $config);
        ConfigValidator::isUrl(self::OPTION_OP_CONFIGURATION_URL, $config);
        ConfigValidator::isString(self::OPTION_CLIENT_ID, $config);
        ConfigValidator::isString(self::OPTION_CLIENT_SECRET, $config);
        ConfigValidator::isUrl(self::OPTION_REDIRECT_URI, $config);
        ConfigValidator::isString(self::OPTION_SCOPE, $config);
        ConfigValidator::isBool(self::OPTION_IS_CONFIDENTIAL_CLIENT, $config);
        ConfigValidator::isValidValue(
            self::OPTION_PKCE_CODE_CHALLENGE_METHOD,
            $config,
            Pkce::VALID_PKCE_CODE_CHALLENGE_METHODS
        );
        ConfigValidator::isArrayWithValidValues(
            self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS,
            $config,
            self::getIdTokenValidationSupportedSignatureAlgs()
        );
        ConfigValidator::isArrayWithValidValues(
            self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS,
            $config,
            self::getIdTokenValidationSupportedEncryptionAlgs()
        );
        ConfigValidator::isFalseZeroOrPositiveInt(self::OPTION_ID_TOKEN_VALIDATION_EXP_LEEWAY, $config);
        ConfigValidator::isFalseZeroOrPositiveInt(self::OPTION_ID_TOKEN_VALIDATION_IAT_LEEWAY, $config);
        ConfigValidator::isFalseZeroOrPositiveInt(self::OPTION_ID_TOKEN_VALIDATION_NBF_LEEWAY, $config);
        ConfigValidator::isBool(self::OPTION_IS_STATE_CHECK_ENABLED, $config);
        ConfigValidator::isBool(self::OPTION_IS_NONCE_CHECK_ENABLED, $config);
        ConfigValidator::isBool(self::OPTION_SHOULD_FETCH_USERINFO_CLAIMS, $config);
        ConfigValidator::isNullZeroOrPositiveInt(self::OPTION_DEFAULT_CACHE_TTL, $config);
    }

    /**
     * @return array Available configuration parameters.
     * @throws OidcClientException
     */
    public function getConfigKeys(): array
    {
        return array_keys($this->getDefaultConfig());
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
    public function getOpConfigurationUrl(): string
    {
        return $this->config[self::OPTION_OP_CONFIGURATION_URL];
    }

    /**
     * @inheritDoc
     */
    public function getClientId(): string
    {
        return $this->config[self::OPTION_CLIENT_ID];
    }

    /**
     * @inheritDoc
     */
    public function getClientSecret(): string
    {
        return $this->config[self::OPTION_CLIENT_SECRET];
    }

    /**
     * @inheritDoc
     */
    public function getRedirectUri(): string
    {
        return $this->config[self::OPTION_REDIRECT_URI];
    }

    /**
     * @inheritDoc
     */
    public function getScope(): string
    {
        return $this->config[self::OPTION_SCOPE];
    }

    /**
     * @inheritDoc
     */
    public function isConfidentialClient(): bool
    {
        return (bool)$this->config[self::OPTION_IS_CONFIDENTIAL_CLIENT];
    }

    /**
     * @inheritDoc
     */
    public function getPkceCodeChallengeMethod(): string
    {
        return $this->config[self::OPTION_PKCE_CODE_CHALLENGE_METHOD];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationExpLeeway()
    {
        return $this->config[self::OPTION_ID_TOKEN_VALIDATION_EXP_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationIatLeeway()
    {
        return $this->config[self::OPTION_ID_TOKEN_VALIDATION_IAT_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenValidationNbfLeeway()
    {
        return $this->config[self::OPTION_ID_TOKEN_VALIDATION_NBF_LEEWAY];
    }

    /**
     * @inheritDoc
     */
    public function isStateCheckEnabled(): bool
    {
        return (bool)$this->config[self::OPTION_IS_STATE_CHECK_ENABLED];
    }

    /**
     * @inheritDoc
     */
    public function isNonceCheckEnabled(): bool
    {
        return (bool)$this->config[self::OPTION_IS_NONCE_CHECK_ENABLED];
    }

    /**
     * @inheritDoc
     */
    public function shouldFetchUserInfoClaims(): bool
    {
        return (bool)$this->config[self::OPTION_SHOULD_FETCH_USERINFO_CLAIMS];
    }

    public function getIdTokenValidationAllowedSignatureAlgs(): array
    {
        return $this->config[self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS];
    }

    public function getIdTokenValidationAllowedEncryptionAlgs(): array
    {
        return $this->config[self::OPTION_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS];
    }

    /**
     * @throws OidcClientException
     */
    public static function getIdTokenValidationSupportedSignatureAlgs(): array
    {
        $algClasses = [
            Algorithm\HS256::class,
            Algorithm\HS384::class,
            Algorithm\HS512::class,
            Algorithm\RS256::class,
            Algorithm\RS384::class,
            Algorithm\RS512::class,
            Algorithm\PS256::class,
            Algorithm\PS384::class,
            Algorithm\PS512::class,
            Algorithm\ES256::class,
            Algorithm\ES384::class,
            Algorithm\ES512::class,
            Algorithm\EdDSA::class,
            // Algorithm\None::class, // TODO mivanci consider allowing none alg (override Validate class)
        ];

        return self::getAlgorithmNamesArray($algClasses);
    }

    /**
     * @throws OidcClientException
     */
    public static function getIdTokenValidationSupportedEncryptionAlgs(): array
    {
        $algClasses = [
            KeyEncryption\A128GCMKW::class,
            KeyEncryption\A192GCMKW::class,
            KeyEncryption\A256GCMKW::class,
            KeyEncryption\A128KW::class,
            KeyEncryption\A192KW::class,
            KeyEncryption\A256KW::class,
            KeyEncryption\Dir::class,
            KeyEncryption\ECDHES::class,
            KeyEncryption\ECDHESA128KW::class,
            KeyEncryption\ECDHESA192KW::class,
            KeyEncryption\ECDHESA256KW::class,
            KeyEncryption\PBES2HS256A128KW::class,
            KeyEncryption\PBES2HS384A192KW::class,
            KeyEncryption\PBES2HS512A256KW::class,
            KeyEncryption\RSA15::class,
            KeyEncryption\RSAOAEP::class,
            KeyEncryption\RSAOAEP256::class,
            ContentEncryption\A128GCM::class,
            ContentEncryption\A192GCM::class,
            ContentEncryption\A256GCM::class,
            ContentEncryption\A128CBCHS256::class,
            ContentEncryption\A192CBCHS384::class,
            ContentEncryption\A256CBCHS512::class,
        ];

        return self::getAlgorithmNamesArray($algClasses);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultCacheTtl(): ?int
    {
        return $this->config[self::OPTION_DEFAULT_CACHE_TTL];
    }

    /**
     * @param array<class-string<\Jose\Component\Core\Algorithm>> $algClasses
     * @return string[]
     * @throws OidcClientException
     */
    protected static function getAlgorithmNamesArray(array $algClasses): array
    {
        return array_map(
            /**
             * @param class-string<\Jose\Component\Core\Algorithm> $algClass
             * @throws OidcClientException
             */
            function (string $algClass) {
                try {
                    /** @var \Jose\Component\Core\Algorithm $alg */
                    $alg = (new ReflectionClass($algClass))->newInstance();
                    return $alg->name();
                } catch (Throwable $exception) {
                    throw new OidcClientException('Error generating algorithm names. ' . $exception->getMessage());
                }
            },
            $algClasses
        );
    }
}
