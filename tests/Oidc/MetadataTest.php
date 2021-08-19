<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\SimpleFileCache\Exceptions\InvalidArgumentException;
use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\Config;
use Cicnavi\Oidc\Metadata;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * Class MetadataTest
 * @package Cicnavi\Tests
 *
 * @covers \Cicnavi\Oidc\Metadata
 */
class MetadataTest extends TestCase
{
    protected static string $oidcConfigurationJson;

    /**
     * @var array<string,mixed>
     */
    protected static array $validConfigOptions = [
        Config::OPTION_OP_CONFIGURATION_URL => 'https://login.aaiedu.hr/.well-known/openid-configuration',
        Config::OPTION_CLIENT_ID => 'some-client-id',
        Config::OPTION_CLIENT_SECRET => 'some-client-secret',
        Config::OPTION_REDIRECT_URI => 'https://some-redirect-uri.example.org/callback',
        Config::OPTION_SCOPE => 'openid profile',
    ];

    protected static Config $config;

    protected static string $cachePath;

    protected static CacheInterface $cache;

    public static function setUpBeforeClass(): void
    {
        self::$config = new Config(self::$validConfigOptions);
        self::$oidcConfigurationJson = file_get_contents(dirname(__DIR__) . '/data/oidc-config.json');

        self::$cachePath = dirname(__DIR__, 2) . '/tmp/cache-test';
        mkdir(self::$cachePath, 0764, true);
        self::$cache = new FileCache('oidc-cache', self::$cachePath);
    }

    public static function tearDownAfterClass(): void
    {
        Tools::rmdirRecursive(self::$cachePath);
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testConstruct(): Metadata
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->willReturn($oidcConfigurationResponse);

        $metadata = new Metadata(self::$config, self::$cache, $httpClientStub);

        $this->assertSame(json_decode(self::$oidcConfigurationJson, true)['issuer'], $metadata->get('issuer'));

        return $metadata;
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testConstructThrowsOnRequestException(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->will(
                $this->throwException(
                    new RequestException(
                        'Sample error.',
                        new Request('GET', 'https:://example.com')
                    )
                )
            );

        $this->expectException(\Exception::class);
        new Metadata(self::$config, $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testConstructThrowsOnWrongResposeCode(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);

        $oidcConfigurationResponse = new Response(500, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->willReturn($oidcConfigurationResponse);

        $this->expectException(\Exception::class);
        new Metadata(self::$config, $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testConstructThrowsOnCacheSetException(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);
        $cacheStub->method('set')
            ->will($this->throwException(new InvalidArgumentException('Cache error.')));

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->willReturn($oidcConfigurationResponse);

        $this->expectException(\Exception::class);
        new Metadata(self::$config, $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testMetadataValidationNotArray(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);

        $oidcConfiguration = json_encode('invalid-not-array');

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], $oidcConfiguration);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->willReturn($oidcConfigurationResponse);

        $this->expectException(\Exception::class);
        new Metadata(self::$config, $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     *
     * @uses \Cicnavi\Oidc\Cache\FileCache
     * @uses \Cicnavi\Oidc\Http\RequestFactory
     * @uses \Cicnavi\Oidc\Config
     */
    public function testMetadataValidationMissingProperties(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);

        $oidcConfiguration = json_decode(self::$oidcConfigurationJson, true);
        unset($oidcConfiguration['issuer']);
        $oidcConfiguration = json_encode($oidcConfiguration);

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], $oidcConfiguration);

        $httpClientStub = $this->createStub(Client::class);
        /**
         * @psalm-suppress DeprecatedMethod This is not a call to Guzzle, but to stub a method.
         * @psalm-suppress PossiblyUndefinedMethod This is not a call to Guzzle, but to stub a method.
         */
        $httpClientStub->method('sendRequest')
            ->willReturn($oidcConfigurationResponse);

        $this->expectException(\Exception::class);
        new Metadata(self::$config, $cacheStub, $httpClientStub);
    }

    /**
     * @depends testConstruct
     * @param Metadata $metadata
     * @throws \Exception
     */
    public function testGetUsingInvalidKeyThrows(Metadata $metadata): void
    {
        $this->expectException(\Exception::class);

        $metadata->get('some-invalid-key');
    }

    /**
     * @depends testConstruct
     * @param Metadata $metadata
     * @throws \Exception
     */
    public function testAllGetMethods(Metadata $metadata): void
    {
        $oidcConfigurationArray = json_decode(self::$oidcConfigurationJson, true);

        $this->assertSame($oidcConfigurationArray['issuer'], $metadata->get('issuer'));
        $this->assertSame($oidcConfigurationArray['authorization_endpoint'], $metadata->get('authorization_endpoint'));
        $this->assertSame($oidcConfigurationArray['token_endpoint'], $metadata->get('token_endpoint'));
        $this->assertSame($oidcConfigurationArray['userinfo_endpoint'], $metadata->get('userinfo_endpoint'));
        $this->assertSame($oidcConfigurationArray['jwks_uri'], $metadata->get('jwks_uri'));
        $this->assertSame($oidcConfigurationArray['scopes_supported'], $metadata->get('scopes_supported'));
        $this->assertSame(
            $oidcConfigurationArray['response_types_supported'],
            $metadata->get('response_types_supported')
        );
        $this->assertSame(
            $oidcConfigurationArray['subject_types_supported'],
            $metadata->get('subject_types_supported')
        );
        $this->assertSame(
            $oidcConfigurationArray['id_token_signing_alg_values_supported'],
            $metadata->get('id_token_signing_alg_values_supported')
        );
        $this->assertSame(
            $oidcConfigurationArray['code_challenge_methods_supported'],
            $metadata->get('code_challenge_methods_supported')
        );
    }
}
