<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore\Doubles;

use Cicnavi\Oidc\DataStore\PhpSessionStore;

/**
 * PhpSessionStore with its three calls into PHP's session machinery replaced
 * by stand-ins which record what happened and can be told to fail.
 *
 * A real session would start perfectly well in the test suite, but it is
 * process-wide: once active it stays active, and every later case would
 * silently take the already-started path. Everything except the three
 * overrides below is the real class.
 */
final class RecordingPhpSessionStore extends PhpSessionStore
{
    public int $startCount = 0;

    public bool $sessionClosed = false;

    public int $regenerateCount = 0;

    /**
     * Deliberately does not call parent::__construct(). A subclass written
     * against a version of PhpSessionStore which had no constructor would not
     * have called it either, so every test using this double doubles as a
     * check that the parent still works when it is skipped.
     */
    public function __construct(
        private readonly bool $cookieParamsSucceed = true,
        private readonly bool $sessionStartSucceeds = true,
        private readonly bool $regenerationSucceeds = true,
    ) {
    }

    /**
     * Mirrors PHP: a session is active once started, and stops being active
     * when the application closes or destroys it.
     */
    #[\Override]
    protected function isSessionActive(): bool
    {
        return $this->startCount > 0 && ! $this->sessionClosed;
    }

    /**
     * @param mixed[] $cookieParams
     */
    #[\Override]
    protected function setSessionCookieParams(array $cookieParams): bool
    {
        return $this->cookieParamsSucceed;
    }

    #[\Override]
    protected function startPhpSession(): bool
    {
        if (! $this->sessionStartSucceeds) {
            return false;
        }

        ++$this->startCount;
        $this->sessionClosed = false;

        return true;
    }

    #[\Override]
    protected function regeneratePhpSessionId(): bool
    {
        if (! $this->regenerationSucceeds) {
            return false;
        }

        ++$this->regenerateCount;

        return true;
    }
}
