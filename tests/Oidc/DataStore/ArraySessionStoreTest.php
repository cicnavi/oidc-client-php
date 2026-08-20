<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\ArraySessionStore;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySessionStore::class)]
final class ArraySessionStoreTest extends TestCase
{
    private ArraySessionStore $sut;

    protected function setUp(): void
    {
        $this->sut = new ArraySessionStore();
    }

    public function testIsASessionStore(): void
    {
        $this->assertInstanceOf(SessionStoreInterface::class, $this->sut);
    }

    public function testPutAndGet(): void
    {
        $this->assertFalse($this->sut->exists('key'));
        $this->assertNull($this->sut->get('key'));

        $this->sut->put('key', 'value');

        $this->assertTrue($this->sut->exists('key'));
        $this->assertSame('value', $this->sut->get('key'));
    }

    public function testPutOverwrites(): void
    {
        $this->sut->put('key', 'first');
        $this->sut->put('key', 'second');

        $this->assertSame('second', $this->sut->get('key'));
    }

    public function testDelete(): void
    {
        $this->sut->put('key', 'value');
        $this->sut->delete('key');

        $this->assertFalse($this->sut->exists('key'));
        $this->assertNull($this->sut->get('key'));
    }

    public function testDeletingAnAbsentKeyIsHarmless(): void
    {
        $this->sut->delete('never-stored');

        $this->assertFalse($this->sut->exists('never-stored'));
    }

    public function testAcceptsInitialContents(): void
    {
        $sut = new ArraySessionStore(['seeded' => 'value']);

        $this->assertTrue($sut->exists('seeded'));
        $this->assertSame('value', $sut->get('seeded'));
    }

    /**
     * PhpSessionStore reports a key holding null as absent, because it tests
     * with isset(). This store must agree, or swapping one for the other would
     * quietly change behaviour.
     */
    public function testAValueStoredAsNullReadsBackAsAbsent(): void
    {
        $this->sut->put('key', null);

        $this->assertFalse($this->sut->exists('key'));
        $this->assertNull($this->sut->get('key'));
    }

    /**
     * Two stores are two sessions - nothing is shared through globals, which
     * is the whole point of using this one in a test suite.
     */
    public function testInstancesDoNotShareState(): void
    {
        $other = new ArraySessionStore();
        $this->sut->put('key', 'value');

        $this->assertFalse($other->exists('key'));
    }
}
