<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Helpers;

use Cicnavi\Oidc\Helpers\HttpHelper;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
