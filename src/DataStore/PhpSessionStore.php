<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Psr\Log\LoggerInterface;

/**
 * Session store backed by the PHP session ($_SESSION).
 *
 * The session is started on first access rather than on construction.
 * Starting one is a process-wide side effect - it sends a Set-Cookie header
 * and binds the process to a session file - and constructing an object should
 * not do that on the caller's behalf, particularly when nothing was going to
 * touch the session at all.
 *
 * Where a PHP session is unwanted rather than merely unstarted - a CLI entry
 * point, a worker, a test suite that must not leak state between cases -
 * inject an ArraySessionStore instead of this one. Earlier versions made that
 * choice silently, substituting an array whenever the SAPI was 'cli': a
 * console deployment appeared to log a user in and then lost the login, and
 * every further construction reset $_SESSION on top of that.
 *
 * One caveat when an application destroys the session itself: PHP reports a
 * destroyed session and a closed one identically, so the next access here
 * starts a session either way, and with session.use_strict_mode off it will
 * be started under whatever identifier the request cookie still carries.
 * PHP's own guidance applies here: delete the session cookie when you destroy
 * the session.
 *
 * @see \Cicnavi\Tests\Oidc\DataStore\PhpSessionDataStoreTest
 */
class PhpSessionStore implements SessionStoreInterface
{
    /**
     * Two deliberate deviations from how the rest of this library holds a
     * logger, both about not breaking existing subclasses of this class:
     *
     * - declared with a default rather than promoted, and so not readonly,
     *   because a subclass written against the version of this class which had
     *   no constructor will not call parent::__construct(); a promoted property
     *   would then be uninitialized and fatal on first access instead of simply
     *   being absent;
     * - private rather than protected, because a subclass declaring a $logger
     *   of its own - the obvious way to add logging to a session store - would
     *   otherwise collide with this one and fatal while the class is loaded.
     *
     * Nothing outside this class needs it: a subclass wanting to log from an
     * overridden startSession() can hold its own.
     */
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Appended to session start failures. PHP reports these as a bare false,
     * so the alternative is worth naming rather than leaving the caller to
     * work out what to do about it.
     */
    protected const SESSION_UNAVAILABLE_HINT = 'Where a PHP session is not wanted, use an ArraySessionStore instead.';

    /**
     * Start the PHP session unless one is already running.
     *
     * Called before every access rather than from the constructor. The
     * structure is otherwise the one it always had, including this early
     * return, so a subclass which overrode this method still has its version
     * called for every case it used to - only *when* has changed.
     *
     * The already-running question goes to PHP each time rather than being
     * remembered: a session can stop being active without this store hearing
     * about it - session_write_close() and session_destroy() both do that -
     * and a remembered answer would send every later write into a $_SESSION
     * attached to nothing, losing it silently.
     *
     * @throws OidcClientException If the PHP session could not be started.
     */
    protected function startSession(): void
    {
        if ($this->isSessionActive()) {
            return;
        }

        $this->validatePhpSession();

        $cookieParams = HttpHelper::normalizeSessionCookieParams(session_get_cookie_params(), $this->logger);

        if (! $this->setSessionCookieParams($cookieParams)) {
            throw new OidcClientException('Could not set session cookie params. ' . self::SESSION_UNAVAILABLE_HINT);
        }

        if (! $this->startPhpSession()) {
            throw new OidcClientException('Could not start PHP session. ' . self::SESSION_UNAVAILABLE_HINT);
        }
    }

    /**
     * @throws OidcClientException
     */
    protected function validatePhpSession(): void
    {
        if (headers_sent()) {
            throw new OidcClientException('Session start error - headers already sent.');
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            throw new OidcClientException('Can not use PHP Session since PHP sessions are disabled.');
        }
    }

    /**
     * Whether a session is running right now - started by the application
     * itself, by another store in this same process, or by an earlier access
     * through this one.
     *
     * Wrapped like the two below it: a PHP session is process-wide, so a test
     * that let a real one start would change the answer here for every case
     * that ran afterwards.
     */
    protected function isSessionActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Wrapper around the PHP function, so that a test can drive both outcomes.
     * PHP's own function cannot be made to fail on demand, and the branch it
     * guards is one this store must get right.
     *
     * @param mixed[] $cookieParams
     */
    protected function setSessionCookieParams(array $cookieParams): bool
    {
        /** @phpstan-ignore argument.type */
        return session_set_cookie_params($cookieParams);
    }

    /**
     * Wrapper around the PHP function, for the same reason as above.
     */
    protected function startPhpSession(): bool
    {
        return session_start();
    }

    /**
     * Wrapper around the PHP function, for the same reason as above. The old
     * session file is deleted rather than left behind, so the previous
     * identifier stops addressing anything at all.
     */
    protected function regeneratePhpSessionId(): bool
    {
        return session_regenerate_id(true);
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If the PHP session could not be started.
     */
    public function get(string $key): mixed
    {
        $this->startSession();

        return $_SESSION[$key] ?? null;
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If the PHP session could not be started.
     */
    public function exists(string $key): bool
    {
        $this->startSession();

        return isset($_SESSION[$key]);
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If the PHP session could not be started.
     */
    public function put(string $key, mixed $value): void
    {
        $this->startSession();

        $_SESSION[$key] = $value;
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If the PHP session could not be started.
     */
    public function delete(string $key): void
    {
        $this->startSession();

        unset($_SESSION[$key]);
    }

    /**
     * @inheritDoc
     * @throws OidcClientException If the PHP session could not be started, or
     * the identifier could not be rotated.
     */
    public function regenerateId(): void
    {
        // There has to be a session before there is an identifier to rotate.
        $this->startSession();

        if (! $this->regeneratePhpSessionId()) {
            throw new OidcClientException('Could not regenerate the PHP session ID.');
        }
    }
}
