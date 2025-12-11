<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Cache;

use Cicnavi\Oidc\Cache\FileCache;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Cicnavi\Tests\Oidc\Tools;

#[CoversClass(FileCache::class)]
final class FileCacheTest extends TestCase
{
    protected static string $testCachePath;

    protected static string $testKey = 'testKey';

    protected static string $testValue = 'testValue';

    protected static string $testCacheName = 'oidc-cache';

    protected static array $testItemArray;

    protected function setUp(): void
    {
        self::$testCachePath = dirname(__DIR__, 3) . '/tmp/cache-test';
        mkdir(self::$testCachePath, 0764, true);
    }

    protected function tearDown(): void
    {
        Tools::rmdirRecursive(self::$testCachePath);
    }

    public function testConstruct(): void
    {
        $cache = new FileCache();
        $this->assertIsString($cache->getCacheName(), $cache->getCacheName());
        $this->assertDirectoryExists($cache->getCachePath());
    }

    public function testConstructWithCustomCacheNameAndPath(): void
    {
        $cache = new FileCache(self::$testCacheName, self::$testCachePath);
        $this->assertSame(self::$testCacheName, $cache->getCacheName());
        $this->assertDirectoryExists($cache->getCachePath());
    }

    public function testSetHasGet(): void
    {
        $cache = new FileCache(self::$testCacheName, self::$testCachePath);
        $cache->set(self::$testKey, self::$testValue);
        $this->assertTrue($cache->has(self::$testKey));
        $this->assertSame(self::$testValue, $cache->get(self::$testKey));
        $cache->delete(self::$testKey);
        $this->assertFalse($cache->has(self::$testKey));
    }
}
