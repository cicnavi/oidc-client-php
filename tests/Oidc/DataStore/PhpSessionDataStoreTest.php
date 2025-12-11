<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionDataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSessionDataStore::class)]
final class PhpSessionDataStoreTest extends TestCase
{
    protected DataStoreInterface $store;

    protected string $testKey = 'testKey';

    protected string $testValue = 'testValue';

    protected function setUp(): void
    {
        $this->store = new PhpSessionDataStore();
    }

    public function testPut(): void
    {
        $this->store->put($this->testKey, $this->testValue);

        $this->assertTrue($this->store->exists($this->testKey));

        $this->assertEquals($this->testValue, $this->store->get($this->testKey));
    }

    public function testDelete(): void
    {
        $this->assertNotTrue($this->store->exists($this->testKey));

        $this->store->put($this->testKey, $this->testValue);

        $this->assertTrue($this->store->exists($this->testKey));

        $this->store->delete($this->testKey);

        $this->assertNotTrue($this->store->exists($this->testKey));
    }

    public function testExists(): void
    {
        $this->assertNotTrue($this->store->exists($this->testKey));

        $this->store->put($this->testKey, $this->testValue);

        $this->assertTrue($this->store->exists($this->testKey));
    }

    public function testConstruct(): void
    {
        $this->assertInstanceOf(DataStoreInterface::class, new PhpSessionDataStore());
    }

    public function testGet(): void
    {
        $this->assertNotTrue($this->store->exists($this->testKey));

        $this->assertEquals(null, $this->store->get($this->testKey));

        $this->store->put($this->testKey, $this->testValue);

        $this->assertTrue($this->store->exists($this->testKey));

        $this->assertEquals($this->testValue, $this->store->get($this->testKey));
    }
}
