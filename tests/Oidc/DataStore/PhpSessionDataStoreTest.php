<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Tests\Oidc\DataStore\Doubles\RecordingPhpSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSessionStore::class)]
// Reached while normalizing the session cookie params. The SAPI check this
// class used to make returned before ever getting there.
#[UsesClass(HttpHelper::class)]
final class PhpSessionDataStoreTest extends TestCase
{
    protected string $testKey = 'testKey';

    protected string $testValue = 'testValue';

    protected function setUp(): void
    {
        // The store works on the $_SESSION superglobal, so isolating it is the
        // test's job. It used to be the store's: it reset $_SESSION whenever
        // the SAPI was 'cli', which made the test suite pass and quietly threw
        // away real state anywhere else that ran outside a web request.
        $_SESSION = [];
    }

    public function testIsASessionStore(): void
    {
        $this->assertInstanceOf(SessionStoreInterface::class, new PhpSessionStore());
    }

    /**
     * Constructing the store must not start a session: that is a
     * process-wide side effect, and it sends a Set-Cookie header on behalf of
     * a caller who may never touch the session at all.
     */
    public function testConstructionDoesNotStartTheSession(): void
    {
        $sut = new RecordingPhpSessionStore();

        $this->assertSame(0, $sut->startCount);
    }

    /**
     * The regression this class' rewrite was really about: constructing a
     * store used to wipe $_SESSION outright whenever the SAPI was 'cli', so a
     * second store discarded whatever the first had put there.
     */
    public function testConstructionDoesNotDiscardExistingSessionData(): void
    {
        $_SESSION['pre-existing'] = 'value';

        new PhpSessionStore();
        new PhpSessionStore();

        $this->assertSame('value', $_SESSION['pre-existing']);
    }

    public function testPutAndGet(): void
    {
        $sut = new RecordingPhpSessionStore();

        $this->assertFalse($sut->exists($this->testKey));
        $this->assertNull($sut->get($this->testKey));

        $sut->put($this->testKey, $this->testValue);

        $this->assertTrue($sut->exists($this->testKey));
        $this->assertSame($this->testValue, $sut->get($this->testKey));
    }

    public function testDelete(): void
    {
        $sut = new RecordingPhpSessionStore();
        $sut->put($this->testKey, $this->testValue);

        $sut->delete($this->testKey);

        $this->assertFalse($sut->exists($this->testKey));
        $this->assertNull($sut->get($this->testKey));
    }

    /**
     * Every access starts the session if it is not running yet, but only the
     * first one does any work.
     */
    public function testStartsTheSessionOnceForManyAccesses(): void
    {
        $sut = new RecordingPhpSessionStore();

        $sut->put($this->testKey, $this->testValue);
        $sut->get($this->testKey);
        $sut->exists($this->testKey);
        $sut->delete($this->testKey);

        $this->assertSame(1, $sut->startCount);
    }

    /**
     * A store can outlive the session it started - a worker calling
     * session_write_close() between units of work, say. The next access must
     * start a fresh session rather than writing into a $_SESSION which is no
     * longer attached to anything, which is what remembering "already started"
     * would have done.
     */
    public function testStartsAFreshSessionAfterTheCurrentOneIsClosed(): void
    {
        $sut = new RecordingPhpSessionStore();
        $sut->put($this->testKey, $this->testValue);

        $sut->sessionClosed = true;
        $sut->put($this->testKey, $this->testValue);

        $this->assertSame(2, $sut->startCount);
    }

    public function testRegenerateIdRotatesTheIdentifier(): void
    {
        $sut = new RecordingPhpSessionStore();

        $sut->regenerateId();

        $this->assertSame(1, $sut->regenerateCount);
    }

    /**
     * There is no identifier to rotate until a session exists, so rotating one
     * has to start it first - a login can be the very first thing that touches
     * the session.
     */
    public function testRegenerateIdStartsTheSessionFirst(): void
    {
        $sut = new RecordingPhpSessionStore();

        $sut->regenerateId();

        $this->assertSame(1, $sut->startCount);
    }

    /**
     * Failing to rotate is not something to log and carry on from: carrying on
     * would record the login against an identifier that may already be known.
     */
    public function testThrowsWhenTheIdentifierCannotBeRotated(): void
    {
        $sut = new RecordingPhpSessionStore(regenerationSucceeds: false);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Could not regenerate the PHP session ID.');

        $sut->regenerateId();
    }

    public function testThrowsWhenSessionCookieParamsCannotBeSet(): void
    {
        $sut = new RecordingPhpSessionStore(cookieParamsSucceed: false);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Could not set session cookie params.');

        $sut->get($this->testKey);
    }

    /**
     * The message names both the usual cause and the alternative store,
     * because PHP itself reports this as nothing more than a false.
     */
    public function testThrowsWhenSessionCannotBeStarted(): void
    {
        $sut = new RecordingPhpSessionStore(sessionStartSucceeds: false);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('ArraySessionStore');

        $sut->put($this->testKey, $this->testValue);
    }
}
