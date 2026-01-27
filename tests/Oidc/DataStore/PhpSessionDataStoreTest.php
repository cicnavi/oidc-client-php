<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSessionStore::class)]
final class PhpSessionDataStoreTest extends TestCase
{
    protected SessionStoreInterface $sessionStore;

    protected string $testKey = 'testKey';

    protected string $testValue = 'testValue';

    protected function setUp(): void
    {
        $this->sessionStore = new PhpSessionStore();
    }

    public function testPut(): void
    {
        $this->sessionStore->put($this->testKey, $this->testValue);

        $this->assertTrue($this->sessionStore->exists($this->testKey));

        $this->assertEquals($this->testValue, $this->sessionStore->get($this->testKey));
    }

    public function testDelete(): void
    {
        $this->assertNotTrue($this->sessionStore->exists($this->testKey));

        $this->sessionStore->put($this->testKey, $this->testValue);

        $this->assertTrue($this->sessionStore->exists($this->testKey));

        $this->sessionStore->delete($this->testKey);

        $this->assertNotTrue($this->sessionStore->exists($this->testKey));
    }

    public function testExists(): void
    {
        $this->assertNotTrue($this->sessionStore->exists($this->testKey));

        $this->sessionStore->put($this->testKey, $this->testValue);

        $this->assertTrue($this->sessionStore->exists($this->testKey));
    }

    public function testConstruct(): void
    {
        $this->assertInstanceOf(SessionStoreInterface::class, new PhpSessionStore());
    }

    public function testGet(): void
    {
        $this->assertNotTrue($this->sessionStore->exists($this->testKey));

        $this->assertEquals(null, $this->sessionStore->get($this->testKey));

        $this->sessionStore->put($this->testKey, $this->testValue);

        $this->assertTrue($this->sessionStore->exists($this->testKey));

        $this->assertEquals($this->testValue, $this->sessionStore->get($this->testKey));
    }
}
