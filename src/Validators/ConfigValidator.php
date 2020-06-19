<?php

namespace Cicnavi\Oidc\Validators;

use Cicnavi\Oidc\Exceptions\OidcClientException;

class ConfigValidator
{
    /**
     * @param array $keys
     * @param array $config
     * @throws OidcClientException
     */
    public static function hasRequiredKeys(array $keys, array $config): void
    {
        foreach ($keys as $key) {
            if (!isset($config[$key])) {
                self::throwValidationException($key, 'value must be set');
            }
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @throws OidcClientException
     */
    public static function isUrl(string $key, array $config): void
    {
        if (! filter_var($config[$key], FILTER_VALIDATE_URL)) {
            self::throwValidationException($key, 'must be URL');
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @throws OidcClientException
     */
    public static function isString(string $key, array $config): void
    {
        $configValue = $config[$key];
        if (
            !is_string($configValue) ||
            !mb_strlen($configValue)
        ) {
            self::throwValidationException($key, 'must be string >= 1 chars');
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @throws OidcClientException
     */
    public static function isBool(string $key, array $config): void
    {
        if (!is_bool($config[$key])) {
            self::throwValidationException($key, 'must be bool');
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @param array $validValues
     * @throws OidcClientException
     */
    public static function isValidValue(string $key, array $config, array $validValues): void
    {
        if (!in_array($config[$key], $validValues)) {
            self::throwValidationException($key, 'can be: ' . implode(', ', $validValues));
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @param array $validValues
     * @throws OidcClientException
     */
    public static function isArrayWithValidValues(string $key, array $config, array $validValues): void
    {
        $configValue = $config[$key];

        if (
            !is_array($configValue) ||
            count(array_diff($configValue, $validValues))
        ) {
            self::throwValidationException(
                $key,
                'must be array with following possible values: ' . implode(', ', $validValues)
            );
        }
    }

    /**
     * @param string $key
     * @param array $config
     * @throws OidcClientException
     */
    public static function isFalseZeroOrPositiveInt(string $key, array $config): void
    {
        $configValue = $config[$key];

        if (false !== $configValue) {
            if (!is_int($configValue) || $configValue < 0) {
                self::throwValidationException($key, 'can be false or int >= 0');
            }
        }
    }

    /**
     * @param string $key
     * @param string $helpMessage
     * @throws OidcClientException If config value is not valid
     */
    public static function throwValidationException(string $key, string $helpMessage = ''): void
    {
        $message = sprintf('Invalid OIDC config value for %s (%s)', $key, $helpMessage);
        throw new OidcClientException($message);
    }
}
