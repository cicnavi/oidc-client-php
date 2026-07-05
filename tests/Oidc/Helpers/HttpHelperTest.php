<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Helpers;

use Cicnavi\Oidc\Helpers\HttpHelper;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpHelper::class)]
final class HttpHelperTest extends TestCase
{
    /**
     * @var false|resource
     */
    protected static $errorLog;

    /**
     * @var false|string
     */
    protected static $errorLogBackup;

    public static function setUpBeforeClass(): void
    {
        // Since we use error_log function to report errors, do this temporary error_log redirection to
        // avoid showing error messages in the CLI.
        self::$errorLog = tmpfile();
        if (self::$errorLog) {
            self::$errorLogBackup = ini_set('error_log', stream_get_meta_data(self::$errorLog)['uri']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$errorLogBackup) {
            ini_set('error_log', self::$errorLogBackup);
        }
    }

    #[BackupGlobals(true)]
    public function testIsRequestHttpSecure(): void
    {
        global $_SERVER;

        $this->assertFalse(HttpHelper::isRequestHttpSecure());
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['HTTPS'] = 'invalid';
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        $_SERVER = [];
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'invalid';
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['HTTP_X_FORWARDED_SSL'] = 'on';
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['HTTP_X_FORWARDED_SSL'] = 'invalid';
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        unset($_SERVER['HTTP_X_FORWARDED_SSL']);
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['REQUEST_SCHEME'] = 'invalid';
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        unset($_SERVER['REQUEST_SCHEME']);
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['SERVER_PORT'] = 80;
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        unset($_SERVER['SERVER_PORT']);
        $_SERVER['HTTP_X_FORWARDED_PORT'] = 443;
        $this->assertTrue(HttpHelper::isRequestHttpSecure());
        $_SERVER['HTTP_X_FORWARDED_PORT'] = 80;
        $this->assertFalse(HttpHelper::isRequestHttpSecure());

        unset($_SERVER['HTTP_X_FORWARDED_PORT']);
    }

    #[BackupGlobals(true)]
    public function testNormalizeSessionCookieParams(): void
    {
        global $_SERVER;

        $cookieParams = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => false,
            'samesite' => ''
        ];

        $this->assertSame('Lax', HttpHelper::normalizeSessionCookieParams($cookieParams)['samesite']);

        $cookieParams['samesite'] = 'invalid';
        $this->assertSame('Lax', HttpHelper::normalizeSessionCookieParams($cookieParams)['samesite']);

        // Test when not on HTTPS
        $cookieParams['samesite'] = 'None';
        $this->assertSame('Lax', HttpHelper::normalizeSessionCookieParams($cookieParams)['samesite']);

        // Test when on HTTPS, but 'secure' is wrongly false
        $_SERVER['HTTPS'] = 'on';
        $cookieParams['samesite'] = 'None';
        $this->assertTrue(HttpHelper::normalizeSessionCookieParams($cookieParams)['secure']);

        $cookieParams['samesite'] = 'Strict';
        $this->assertSame('Lax', HttpHelper::normalizeSessionCookieParams($cookieParams)['samesite']);
    }

    public function testGenerateAutoSubmitPostForm(): void
    {
        $url = 'https://example.com/auth';
        $parameters = [
            'client_id' => 'test_client',
            'scope' => 'openid profile',
        ];

        $html = HttpHelper::generateAutoSubmitPostForm($url, $parameters);

        $this->assertStringContainsString('action="https://example.com/auth"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="client_id" value="test_client"', $html);
        $this->assertStringContainsString('name="scope" value="openid profile"', $html);
        $this->assertStringContainsString('onload="document.forms[0].submit()"', $html);
    }

    public function testDispatchFrontChannelRequestQueryPopulatesLocationHeader(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withHeader')
            ->with(
                'Location',
                'https://example.com/auth?client_id=test_client&scope=openid+profile',
            )
            ->willReturn($response);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('debug');

        $result = HttpHelper::dispatchFrontChannelRequest(
            'https://example.com/auth',
            [
                'client_id' => 'test_client',
                'scope' => 'openid profile',
            ],
            \Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum::Query,
            $response,
            $logger,
        );

        $this->assertSame($response, $result);
    }

    public function testDispatchFrontChannelRequestFormPostPopulatesResponseBody(): void
    {
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $html): bool =>
                str_contains($html, 'action="https://example.com/auth"') &&
                str_contains($html, 'name="client_id" value="test_client"')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($body);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'text/html')
            ->willReturn($response);

        $result = HttpHelper::dispatchFrontChannelRequest(
            'https://example.com/auth',
            ['client_id' => 'test_client'],
            \Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum::FormPost,
            $response,
        );

        $this->assertSame($response, $result);
    }

    public function testDispatchBackchannelLogoutResponsePopulatesSuccessResponse(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturn($response);
        $response->expects($this->once())
            ->method('withHeader')
            ->with('Cache-Control', 'no-store')
            ->willReturn($response);
        $response->expects($this->never())->method('getBody');

        $result = HttpHelper::dispatchBackchannelLogoutResponse($response);

        $this->assertSame($response, $result);
    }

    public function testDispatchBackchannelLogoutResponsePopulatesErrorResponse(): void
    {
        $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $body->expects($this->once())
            ->method('write')
            ->with($this->callback(fn(string $json): bool =>
                str_contains($json, '"error": "invalid_request"') === false && // pretty print not used
                str_contains($json, '"invalid_request"') &&
                str_contains($json, 'Logout token is not valid.')));

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->expects($this->once())
            ->method('withStatus')
            ->with(400)
            ->willReturn($response);
        $response->method('getBody')->willReturn($body);

        $headers = [];
        $response->expects($this->exactly(2))
            ->method('withHeader')
            ->willReturnCallback(function (string $name, string $value) use (&$headers, $response): MockObject {
                $headers[$name] = $value;
                return $response;
            });

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('debug');

        $result = HttpHelper::dispatchBackchannelLogoutResponse(
            $response,
            'Logout token is not valid.',
            $logger,
        );

        $this->assertSame($response, $result);
        $this->assertSame(
            ['Cache-Control' => 'no-store', 'Content-Type' => 'application/json'],
            $headers,
        );
    }
}
