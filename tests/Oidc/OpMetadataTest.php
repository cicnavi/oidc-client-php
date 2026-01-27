<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\Http\RequestFactory;
use Cicnavi\Oidc\Protocol\OpMetadata;
use Cicnavi\SimpleFileCache\Exceptions\InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(OpMetadata::class)]
#[UsesClass(FileCache::class)]
#[UsesClass(RequestFactory::class)]
final class OpMetadataTest extends TestCase
{
    protected static string $oidcConfigurationJson;

    /**
     * @var array<string,mixed>
     */
    protected static array $validConfigOptions = [
        'opConfigurationUrl' => 'https://login.aaiedu.hr/.well-known/openid-configuration',
        'clientId' => 'some-client-id',
        'clientSecret' => 'some-client-secret',
        'redirectUri' => 'https://some-redirect-uri.example.org/callback',
        'scope' => 'openid profile',
    ];



    protected static string $cachePath;

    protected static CacheInterface $cache;

    public static function setUpBeforeClass(): void
    {
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
     */
    public function testConstruct(): OpMetadata
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

        $metadata = new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], self::$cache, $httpClientStub);

        $this->assertSame(json_decode(self::$oidcConfigurationJson, true)['issuer'], $metadata->get('issuer'));

        return $metadata;
    }

    /**
     * @throws \Exception
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
            ->willThrowException(
                new RequestException(
                    'Sample error.',
                    new Request('GET', 'https:://example.com')
                )
            );

        $this->expectException(\Exception::class);
        new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
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
        new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     */
    public function testConstructThrowsOnCacheSetException(): void
    {
        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->willReturn(null);
        $cacheStub->method('set')
            ->willThrowException(new InvalidArgumentException('Cache error.'));

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
        new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
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
        new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
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
        new OpMetadata(self::$validConfigOptions['opConfigurationUrl'], $cacheStub, $httpClientStub);
    }

    /**
     * @throws \Exception
     */
    #[Depends('testConstruct')]
    public function testGetUsingInvalidKeyThrows(OpMetadata $metadata): void
    {
        $this->expectException(\Exception::class);

        $metadata->get('some-invalid-key');
    }

    /**
     * @throws \Exception
     */
    #[Depends('testConstruct')]
    public function testAllGetMethods(OpMetadata $metadata): void
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
