<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\Cache\FileCache;
use Cicnavi\Oidc\Client;
use Cicnavi\Oidc\Config;
use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use Exception;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Core\JWK;
use Jose\Easy\Build;
use Throwable;

/**
 * Class ClientTest
 * @package Cicnavi\Tests
 *
 * @covers \Cicnavi\Oidc\Client
 */
class ClientTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    protected static array $validConfigOptions = [
        Config::CONFIG_KEY_OIDC_CONFIGURATION_URL => 'https://login.example.org/.well-known/openid-configuration',
        Config::CONFIG_KEY_OIDC_CLIENT_ID => '6e55295209782b7b2',
        Config::CONFIG_KEY_OIDC_CLIENT_SECRET => 'ad6dsd63sgj54hd5s',
        Config::CONFIG_KEY_OIDC_REDIRECT_URI => 'https://some-redirect-uri.example.org/callback',
        Config::CONFIG_KEY_OIDC_SCOPE => 'openid profile',
    ];

    protected static array $sampleTokenDataArray = [
        'id_token' => 'sample',
        'token_type' => 'Bearer',
        'expires_in' => 600,
        'access_token' => 'sample',
        'refresh_token' => 'sample',
    ];

    protected static array $sampleIdTokenHeaderJws = [
        'typ' => 'JWT',
        'alg' => 'RS256',
        'kid' => '69d8c46574'
    ];

    protected static array $sampleIdTokenPayload = [
        'iss' => 'https://login.example.org',
        'sub' => 'bfa1605be44a50a7c',
        'aud' => '6e55295209782b7b2',
        'exp' => 1602675070,
        'iat' => 1602674470,
        'auth_time' => 1602674470,
        'nonce' => 'dtnmeBL5HVnhQkIR',
        'jti' => 'c44f4cffcc84f7990f7a1d5b2c',
        'nbf' => 1602674470,
    ];

    protected static array $sampleUserinfoPayload = [
        'name' => 'John Doe',
        'family_name' => 'Doe',
        'given_name' => 'John',
        'preferred_username' => 'jdoe@example.org',
        'email' => 'john.doe@example.org',
        'hrEduPersonUniqueNumber' =>
            array (
                0 => 'LOCAL_NO: 1234',
                1 => 'OIB: 12345678912',
                2 => 'JMBAG: 1234567891',
            ),
    ];

    protected static Config $config;

    protected static string $cachePath;

    protected static string $cacheName = 'oidc-cache';

    protected static FileCache $cache;

    protected static GuzzleHttpClient $guzzleHttpClientStub;

    protected static string $oidcConfigurationJson;

    protected static JWK $privateTestKeyJwkSig;

    protected static JWK $publicTestKeyJwkSig;

    protected static array $sampleJwksArray = [
        'keys' => [
            // Invalid, different kid
            ['kid' => '123', 'use' => 'sig', 'n' => '123', 'e' => 'AQAB', 'kty' => 'RSA',],
            // Invalid, different alg
            ['kid' => '69d8c46574', 'alg' => 'RS384', 'use' => 'sig', 'n' => '123', 'e' => 'AQAB', 'kty' => 'RSA',],
            // Invalid, different use
            ['kid' => '69d8c46574', 'alg' => 'RS256', 'use' => 'enc', 'n' => '123', 'e' => 'AQAB', 'kty' => 'RSA',],
            // Valid key to be added in setUpBeforeClass()
        ]
    ];


    public static function setUpBeforeClass(): void
    {
        self::$config = new Config(self::$validConfigOptions);
        self::$cachePath = dirname(__DIR__, 2) . '/tmp/cache';
        self::$oidcConfigurationJson = file_get_contents(dirname(__DIR__) . '/data/oidc-config.json');
        self::$privateTestKeyJwkSig = JWKFactory::createFromKeyFile(
            dirname(__DIR__) . '/data/rsa-pcks8-private-test-key.pem',
            null,
            ['use' => 'sig',]
        );
        self::$publicTestKeyJwkSig = JWKFactory::createFromKeyFile(
            dirname(__DIR__) . '/data/rsa-pcks8-public-test-key.pem',
            null,
            ['use' => 'sig', 'kid' => '69d8c46574', 'alg' => 'RS256']
        );

        self::$sampleJwksArray['keys'][] = self::$publicTestKeyJwkSig->all();
    }

    protected static function generateTokenDataArray(
        bool $shouldContainValidIdToken = false
    ): array {
        $tokenArray = self::$sampleTokenDataArray;

        if ($shouldContainValidIdToken) {
            $tokenArray['id_token'] = self::generateSampleIdTokenJws();
        }

        return $tokenArray;
    }

    protected static function generateSampleIdTokenJws(
        bool $shouldBeValid = true,
        ?array $customPayload = null,
        ?array $customHeader = null
    ): string {
        $time = time();

        $header = $customHeader ?? self::$sampleIdTokenHeaderJws;
        $payload = $customPayload ?? self::$sampleIdTokenPayload;

        if ($shouldBeValid) {
            $payload['exp'] = $time + 600; // 10 min
        }

        $jwsBuilder = Build::jws()
            ->payload($payload);

        foreach ($header as $key => $value) {
            $jwsBuilder = $jwsBuilder->header($key, $value);
        }

        /**
         * @psalm-suppress UndefinedMethod This works fine.
         */
        return $jwsBuilder->sign(self::$privateTestKeyJwkSig);
    }

    public function setUp(): void
    {
        mkdir(self::$cachePath, 0764, true);
        self::$cache = new FileCache(self::$cachePath);

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $tokenResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::generateTokenDataArray(true)));

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $userinfoResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleUserinfoPayload));

        // Create a mock and queue responses.
        $responses = [
            $oidcConfigurationResponse,
            $tokenResponse,
            $jwksResponse,
            $userinfoResponse,
//            new Response(200, ['Content-Type' => 'application/json'], json_encode('test')),
//            new Response(202, ['Content-Length' => 0]),
//            new RequestException('Error Communicating with Server', new Request('GET', 'test'))
        ];

        self::$guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);
    }

    public function tearDown(): void
    {
        Tools::rmdirRecursive(self::$cachePath);
    }

    protected function prepareGuzzleHttpClientStub(array $responses): GuzzleHttpClient
    {
        $guzzleMockHandler = new MockHandler($responses);

        $guzzleHandlerStack = HandlerStack::create($guzzleMockHandler);
        return new GuzzleHttpClient(['handler' => $guzzleHandlerStack]);
    }

    /**
     * TESTS
     */

    /**
     * @return Client
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     *
     * @psalm-suppress RedundantCondition
     */
    public function testConstruct(): Client
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->assertInstanceOf(Client::class, $client);
        return $client;
    }

    public function testGetMetadata(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->assertInstanceOf(MetadataInterface::class, $client->getMetadata());
    }

    /**
     * @runInSeparateProcess
     * @uses \Cicnavi\Oidc\DataStore\DataHandlers\Pkce
     * @uses \Cicnavi\Oidc\DataStore\DataHandlers\StateNonce
     */
    public function testAuthorize(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $client->authorize();
        $authorizationEndpoint = json_decode(self::$oidcConfigurationJson, true)['authorization_endpoint'];

        $allHeaders = xdebug_get_headers();
        $redirectHeader = null;
        foreach ($allHeaders as $header) {
            if (mb_strpos($header, 'Location: ') !== false) {
                $redirectHeader = $header;
            }
        }

        $this->assertNotNull($redirectHeader);

        $redirectUrl = str_replace('Location: ', '', $redirectHeader);
        $this->assertTrue(mb_strpos($redirectUrl, $authorizationEndpoint) !== false);

        $redirectUrlParts = parse_url($redirectUrl);
        $this->assertArrayHasKey('query', $redirectUrlParts);

        /**
         * @psalm-suppress PossiblyUndefinedArrayOffset We know 'query' exists in our test.
         */
        $this->assertTrue(mb_strpos($redirectUrlParts['query'], 'response_type=code') !== false);
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'client_id=' . urlencode(self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CLIENT_ID])
            ) !== false
        );
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'redirect_uri=' . urlencode(self::$validConfigOptions[Config::CONFIG_KEY_OIDC_REDIRECT_URI])
            ) !== false
        );
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'scope=' . urlencode(self::$validConfigOptions[Config::CONFIG_KEY_OIDC_SCOPE])
            ) !== false
        );
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'state='
            ) !== false
        );
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'nonce='
            ) !== false
        );
    }

    /**
     * @runInSeparateProcess
     * @uses \Cicnavi\Oidc\DataStore\DataHandlers\Pkce
     * @uses \Cicnavi\Oidc\DataStore\DataHandlers\StateNonce
     */
    public function testAuthorizeForPublicClient(): void
    {
        $publicClientConfigArray = array_merge(
            self::$validConfigOptions,
            [Config::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT => false]
        );

        $publicConfig = new Config($publicClientConfigArray);

        $client = new Client($publicConfig, self::$cache, null, self::$guzzleHttpClientStub);
        $client->authorize();

        $allHeaders = xdebug_get_headers();
        $redirectHeader = null;
        foreach ($allHeaders as $header) {
            if (mb_strpos($header, 'Location: ') !== false) {
                $redirectHeader = $header;
            }
        }

        $this->assertNotNull($redirectHeader);

        $redirectUrl = str_replace('Location: ', '', $redirectHeader);

        $redirectUrlParts = parse_url($redirectUrl);
        $this->assertArrayHasKey('query', $redirectUrlParts);

        /**
         * @psalm-suppress PossiblyUndefinedArrayOffset We know 'query' exists in our test.
         */
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'code_challenge='
            ) !== false
        );
        $this->assertTrue(
            mb_strpos(
                $redirectUrlParts['query'],
                'code_challenge_method=S256'
            ) !== false
        );
    }

    public function testAuthenticateMissingCodeThrows(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Throwable::class);
        $client->authenticate();
    }

    /**
     * @throws Exception
     *
     * @backupGlobals enabled
     */
    public function testAuthenticateErrorThrows(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Throwable::class);
        global $_GET;
        $_GET['error'] = 'invalid_request';
        $client->authenticate();
    }

    /**
     * @throws Exception
     */
    public function testAuthenticateMissingStateThrows(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Throwable::class);
        global $_GET;
        $_GET['code'] = 'sample-auth-code';
        $client->authenticate();
    }

    /**
     * @throws Exception
     */
    public function testAuthenticate(): void
    {
        $stateNonceStub = $this->createStub(StateNonce::class);
        $stateNonceStub->method('verify');

        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub, null, $stateNonceStub);

        global $_GET;
        $_GET['code'] = 'sample-auth-code';
        $_GET['state'] = 'sample-state';

        $userData = $client->authenticate();

        $this->assertSame(self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CLIENT_ID], $userData['aud']);
        $this->assertSame(json_decode(self::$oidcConfigurationJson, true)['issuer'], $userData['iss']);
    }

    /**
     * @throws Exception
     */
    public function testAuthenticateForPublicClient(): void
    {
        $publicClientConfigArray = array_merge(
            self::$validConfigOptions,
            [Config::CONFIG_KEY_OIDC_IS_CONFIDENTIAL_CLIENT => false]
        );

        $publicConfig = new Config($publicClientConfigArray);

        $stateNonceStub = $this->createStub(StateNonce::class);
        $stateNonceStub->method('verify');

        $client = new Client($publicConfig, self::$cache, null, self::$guzzleHttpClientStub, null, $stateNonceStub);

        global $_GET;
        $_GET['code'] = 'sample-auth-code';
        $_GET['state'] = 'sample-state';

        $userData = $client->authenticate();

        $this->assertSame(self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CLIENT_ID], $userData['aud']);
        $this->assertSame(json_decode(self::$oidcConfigurationJson, true)['issuer'], $userData['iss']);
    }

    public function testValidateTokenDataArrayThrowsIfNoAccessToken(): void
    {
        $tokenData = self::$sampleTokenDataArray;
        unset($tokenData['access_token']);
        $this->expectException(Exception::class);
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $client->validateTokenDataArray($tokenData);
    }

    public function testValidateTokenDataArrayThrowsIfNoTokenType(): void
    {
        $tokenData = self::$sampleTokenDataArray;
        unset($tokenData['token_type']);
        $this->expectException(Exception::class);
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $client->validateTokenDataArray($tokenData);
    }

    public function testUserDataFetchFromUserinfoEndpointIsNotPerformed(): void
    {
        $configOptions = self::$validConfigOptions;
        $configOptions[Config::CONFIG_KEY_OIDC_SHOULD_FETCH_USERINFO_CLAIMS] = false;
        $config = new Config($configOptions);

        $stateNonceStub = $this->createStub(StateNonce::class);
        $stateNonceStub->method('verify');

        $client = new Client($config, self::$cache, null, self::$guzzleHttpClientStub, null, $stateNonceStub);

        $userData = $client->authenticate();

        $this->assertFalse(array_key_exists('name', $userData));
    }

    public function testRequestUserDataFromUserinfoEndpointThrowsWhenNotSupported(): void
    {
        $accessToken = self::$sampleTokenDataArray['access_token'];

        $oidcConfiguration = json_decode(self::$oidcConfigurationJson, true);
        unset($oidcConfiguration['userinfo_endpoint']);

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode($oidcConfiguration));


        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub([$oidcConfigurationResponse]);

        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);

        $client->requestUserDataFromUserinfoEndpoint($accessToken);
    }

    public function testGetDataFromIdTokenThrowsForInvalidIdTokenFormat(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->getDataFromIDToken('invalid-token');
    }

    public function testGetDataFromIdTokenThrowsForInvalidIdToken(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);
        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);
        $client->getDataFromIDToken(self::generateSampleIdTokenJws(false));
    }

    public function testGetDataFromIdTokenThrowsForInvalidJson(): void
    {
        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->getDataFromIDToken('invalid.token.json');
    }

    public function testGetDataFromIdTokenThrowsForSignatureKeyError(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        // Prepare just one invalid key.
        $jwksArray = ['keys' => [self::$sampleJwksArray['keys'][0]]];

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode($jwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);
        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);

        $client->getDataFromIDToken(self::generateSampleIdTokenJws(true));
    }

    public function testGetDataFromIdTokenThrowsForMissingNonce(): void
    {
        $idTokenPayload = self::$sampleIdTokenPayload;
        unset($idTokenPayload['nonce']);
        $idToken = self::generateSampleIdTokenJws(true, $idTokenPayload);

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);

        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);
        $client->getDataFromIDToken($idToken);
    }

    public function testGetDataFromIdTokenThrowsForMissingJweSupport(): void
    {
        $idTokenHeader = self::$sampleIdTokenHeaderJws;
        $idTokenHeader['enc'] = 'some-algo';
        $idToken = self::generateSampleIdTokenJws(true, null, $idTokenHeader);

        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);

        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);
        $client->getDataFromIDToken($idToken);
    }

    public function testValidateJwksUriContentArrayThrowsIfNoKeys(): void
    {
        $jwksUriContentArray = self::$sampleJwksArray;
        $jwksUriContentArray['keys'] = [];

        $client = new Client(self::$config, self::$cache, null, self::$guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->validateJwksUriContentArary($jwksUriContentArray);
    }

    public function testGetJwksUriContent(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);
        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $jwksUriContent = $client->getJwksUriContent();
        $this->assertArrayHasKey('keys', $jwksUriContent);
        $jwksUriContent = $client->getJwksUriContent(); // ...from cache
        $this->assertArrayHasKey('keys', $jwksUriContent);
    }

    public function testValidateCacheThrows(): void
    {
        $configStub = $this->createStub(Config::class);
        $configStub->method('getOidcConfigurationUrl')
            ->will($this->throwException(new Exception('Sample error')));

        $this->expectException(Exception::class);
        new Client($configStub, self::$cache);
    }

    /**
     * @throws Exception
     */
    public function testRequestTokenDataThrows(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $tokenResponse = new Response(500, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleTokenDataArray));

        $responses = [
            $oidcConfigurationResponse,
            $tokenResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);

        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->requestTokenData('sample-auth-code');
    }

    public function testGetJwksUriContentThrowsForCacheError(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);

        $cacheStub = $this->createStub(FileCache::class);
        $cacheStub->method('get')
            ->will($this->onConsecutiveCalls(
                // for check if the OIDC metadata URL was changed
                self::$validConfigOptions[Config::CONFIG_KEY_OIDC_CONFIGURATION_URL],
                // For Metadata OIDC Configuration fetch
                null,
                // For simulating cache error on get JWKS content
                $this->throwException(new Exception('Sample cache error.'))
            ));

        $client = new Client(self::$config, $cacheStub, null, $guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->getJwksUriContent();
    }

    public function testGetJwksUriContentThrowsForRequestError(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(500, [
            'Content-Type' => 'application/json'
        ], json_encode(self::$sampleJwksArray));

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);
        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);

        $this->expectException(Exception::class);
        $client->getJwksUriContent();
    }

    public function testGetDecodedHttpResponseJsonThrowsForInvalidJson(): void
    {
        $oidcConfigurationResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], self::$oidcConfigurationJson);

        $jwksResponse = new Response(200, [
            'Content-Type' => 'application/json'
        ], "{'foo': 'bar'}"); // Invalid JSON

        $responses = [
            $oidcConfigurationResponse,
            $jwksResponse,
        ];

        $guzzleHttpClientStub = $this->prepareGuzzleHttpClientStub($responses);

        $client = new Client(self::$config, self::$cache, null, $guzzleHttpClientStub);
        $this->expectException(Exception::class);
        $client->getJwksUriContent();
    }
}
