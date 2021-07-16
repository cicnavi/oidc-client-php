<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\Config;
use Cicnavi\Oidc\Interfaces\ConfigInterface;
use PHPUnit\Framework\TestCase;
use Exception;

/**
 * Class ConfigTest
 * @package Cicnavi\Tests
 */
class ConfigTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    protected static array $validConfigOptions = [
        Config::CONFIG_KEY_OIDC_CONFIGURATION_URL => 'https://login.aaiedu.hr/.well-known/openid-configuration',
        Config::CONFIG_KEY_OIDC_CLIENT_ID => 'some-client-id',
        Config::CONFIG_KEY_OIDC_CLIENT_SECRET => 'some-client-secret',
        Config::CONFIG_KEY_OIDC_REDIRECT_URI => 'https://some-redirect-uri.example.org/callback',
        Config::CONFIG_KEY_OIDC_SCOPE => 'openid profile',
        Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS => ['RS256', 'RS512'], // TODO mivanci remove_in_2
        Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS => ['RS256', 'RS512'],
        Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ENCRYPTION_ALGS => ['RSA-OAEP-256', 'A256GCM'],
    ];

    /**
     * @var Config Instance
     */
    protected static Config $config;

    public static function setUpBeforeClass(): void
    {
        self::$config = new Config(self::$validConfigOptions);
    }

    public function testIsInstanceOfConfigInterface(): void
    {
        $this->assertInstanceOf(ConfigInterface::class, new Config(self::$validConfigOptions));
    }

    public function testThrowsOnEmptyConfig(): void
    {
        $this->expectException(Exception::class);

        new Config([]);
    }

    /**
     * @dataProvider invalidOptionsProvider
     * @covers \Cicnavi\Oidc\Validators\ConfigValidator
     * @param array<string,mixed> $invalidOption Option with value which should trigger exception
     */
    public function testThrowsOnInvalidOption(array $invalidOption): void
    {
        $configOptions = array_merge(self::$validConfigOptions, $invalidOption);

        $this->expectException(Exception::class);

        new Config($configOptions);
    }

    public function invalidOptionsProvider(): array
    {
        return [
            [[Config::CONFIG_KEY_OIDC_CONFIGURATION_URL => '']],
            [[Config::CONFIG_KEY_OIDC_CLIENT_ID => '']],
            [[Config::CONFIG_KEY_OIDC_CLIENT_SECRET => '']],
            [[Config::CONFIG_KEY_OIDC_REDIRECT_URI => '']],
            [[Config::CONFIG_KEY_OIDC_SCOPE => '']],
            [[Config::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT => '']],
            [[Config::CONFIG_KEY_OIDC_PKCE_CODE_CHALLENGE_METHOD => '']],
            [[Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_ALLOWED_ALGS => ['a', 'b']]],
            [[Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_EXP_LEEWAY => -1]],
            [[Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_IAT_LEEWAY => -1]],
            [[Config::CONFIG_KEY_OIDC_ID_TOKEN_VALIDATION_NBF_LEEWAY => -1]],
            [[Config::CONFIG_KEY_OIDC_IS_STATE_CHECK_ENABLED => '']],
            [[Config::CONFIG_KEY_OIDC_IS_NONCE_CHECK_ENABLED => '']],
        ];
    }

    public function testGet(): void
    {
        $sampleConfigKey = array_key_first(self::$validConfigOptions) ?: Config::CONFIG_KEY_OIDC_CONFIGURATION_URL;
        $this->assertEquals(
            self::$validConfigOptions[$sampleConfigKey],
            self::$config->get($sampleConfigKey)
        );
    }

    public function testGetThrowsExceptionOnInvalidOption(): void
    {
        $this->expectException(Exception::class);

        self::$config->get('foo');
    }

    public function testGetConfigKeys(): void
    {
        $this->assertTrue(in_array(array_key_first(self::$validConfigOptions), self::$config->getConfigKeys()));
    }

    public function testGetOidcConfigurationUrl(): void
    {
        $this->assertEquals(
            self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CONFIGURATION_URL],
            self::$config->getOidcConfigurationUrl()
        );
    }

    public function testGetClientId(): void
    {
        $this->assertEquals(
            self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CLIENT_ID],
            self::$config->getClientId()
        );
    }

    public function testGetClientSecret(): void
    {
        $this->assertEquals(
            self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CLIENT_SECRET],
            self::$config->getClientSecret()
        );
    }

    public function testGetRedirectUri(): void
    {
        $this->assertEquals(
            self::$validConfigOptions[Config::CONFIG_KEY_OIDC_REDIRECT_URI],
            self::$config->getRedirectUri()
        );
    }

    public function testGetScope(): void
    {
        $this->assertEquals(
            self::$validConfigOptions[Config::CONFIG_KEY_OIDC_SCOPE],
            self::$config->getScope()
        );
    }

    public function testIsConfidentialClient(): void
    {
        $this->assertTrue(self::$config->isConfidentialClient());
    }

    public function testGetPkceCodeChallengeMethod(): void
    {
        $this->assertEquals('S256', self::$config->getPkceCodeChallengeMethod());
    }

    public function testGetIdTokenValidationAllowedAlgs(): void
    {
        $this->assertEquals(['RS256', 'RS512'], self::$config->getIdTokenValidationAllowedAlgs());
    }

    public function testGetIdTokenValidationAllowedSignatureAlgs(): void
    {
        $this->assertEquals(['RS256', 'RS512'], self::$config->getIdTokenValidationAllowedSignatureAlgs());
    }

    public function testGetIdTokenValidationAllowedEncryptionAlgs(): void
    {
        $this->assertEquals(['RSA-OAEP-256', 'A256GCM'], self::$config->getIdTokenValidationAllowedEncryptionAlgs());
    }

    public function testGetIdTokenValidationExpLeeway(): void
    {
        $this->assertEquals(0, self::$config->getIdTokenValidationExpLeeway());
    }

    public function testGetIdTokenValidationIatLeeway(): void
    {
        $this->assertEquals(0, self::$config->getIdTokenValidationIatLeeway());
    }

    public function testGetIdTokenValidationNbfLeeway(): void
    {
        $this->assertEquals(0, self::$config->getIdTokenValidationNbfLeeway());
    }

    public function testIsStateCheckEnabled(): void
    {
        $this->assertTrue(self::$config->isStateCheckEnabled());
    }

    public function testIsNonceCheckEnabled(): void
    {
        $this->assertTrue(self::$config->isNonceCheckEnabled());
    }

    public function testShouldFetchUserinfoClaims(): void
    {
        $this->assertTrue(self::$config->shouldFetchUserinfoClaims());
    }
}
