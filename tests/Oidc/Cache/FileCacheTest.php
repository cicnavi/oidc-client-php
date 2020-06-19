<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Cache;

use Cicnavi\Oidc\Cache\FileCache;
use Exception;
use PHPUnit\Framework\TestCase;
use Cicnavi\Tests\Oidc\Tools;

/**
 * Class FileCacheTest
 * @package Cicnavi\Tests\Cache
 *
 * @covers \Cicnavi\Oidc\Cache\FileCache
 */
class FileCacheTest extends TestCase
{
    protected static string $testCachePath;

    protected static string $testKey = 'testKey';
    protected static string $testValue = 'testValue';
    protected static string $testCacheName = 'oidc-cache';

    protected static array $testItemArray;

    public function setUp(): void
    {
        self::$testCachePath = dirname(__DIR__, 3) . '/tmp/cache-test';
        mkdir(self::$testCachePath, 0764, true);
    }

    public function tearDown(): void
    {
        Tools::rmdirRecursive(self::$testCachePath);
    }
    public function testConstructWithCustomCacheName(): void
    {
        $cache = new FileCache(self::$testCachePath, self::$testCacheName);
        $this->assertSame(self::$testCacheName, $cache->getCacheName());
        $this->assertTrue(is_dir($cache->getCachePath()));
    }

    public function testSetHasGet(): void
    {
        $cache = new FileCache(self::$testCachePath, self::$testCacheName);
        $cache->set(self::$testKey, self::$testValue);
        $this->assertTrue($cache->has(self::$testKey));
        $this->assertSame(self::$testValue, $cache->get(self::$testKey));
        $cache->delete(self::$testKey);
        $this->assertFalse($cache->has(self::$testKey));
    }
}
