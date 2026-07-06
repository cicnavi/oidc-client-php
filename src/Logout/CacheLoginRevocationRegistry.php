<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Logout;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Logout\Interfaces\LoginRevocationRegistryInterface;
use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Login revocation registry backed by a PSR-16 cache.
 *
 * The cache instance must be shared between requests (like the default
 * file-based client cache is), so revocations recorded during a back-channel
 * logout request are observable in the End-User's session requests.
 *
 * Each revocation is stored as a Unix timestamp under a key derived from the
 * OP issuer identifier and the 'sid' / 'sub' claim value. A login is
 * considered revoked when a revocation entry exists which was recorded at,
 * or after, the time the login was established.
 *
 * @see \Cicnavi\Tests\Oidc\Logout\CacheLoginRevocationRegistryTest
 */
class CacheLoginRevocationRegistry implements LoginRevocationRegistryInterface
{
    protected const KEY_PREFIX_SESSION = 'bcl_sid_';

    protected const KEY_PREFIX_SUBJECT = 'bcl_sub_';

    /**
     * @param CacheInterface $cache Cache shared between requests in which to
     * record revocations.
     * @param DateInterval $revocationDuration How long a recorded revocation
     * is kept. Must be at least as long as the longest possible application
     * session lifetime, so a stale session can not outlive the revocation
     * which terminates it. Default is 'P1D' (1 day).
     */
    public function __construct(
        protected readonly CacheInterface $cache,
        protected readonly DateInterval $revocationDuration = new DateInterval('P1D'),
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function revokeSession(string $issuer, string $sessionId): void
    {
        $this->recordRevocation(self::KEY_PREFIX_SESSION, $issuer, $sessionId);
    }

    public function revokeSubject(string $issuer, string $subject): void
    {
        $this->recordRevocation(self::KEY_PREFIX_SUBJECT, $issuer, $subject);
    }

    public function isSessionRevoked(string $issuer, string $sessionId, int $loggedInAt): bool
    {
        return $this->isRevoked(self::KEY_PREFIX_SESSION, $issuer, $sessionId, $loggedInAt);
    }

    public function isSubjectRevoked(string $issuer, string $subject, int $loggedInAt): bool
    {
        return $this->isRevoked(self::KEY_PREFIX_SUBJECT, $issuer, $subject, $loggedInAt);
    }

    /**
     * @throws OidcClientException If the revocation could not be recorded.
     */
    protected function recordRevocation(string $keyPrefix, string $issuer, string $value): void
    {
        $key = $this->buildKey($keyPrefix, $issuer, $value);

        try {
            if (!$this->cache->set($key, time(), $this->revocationDuration)) {
                throw new OidcClientException('Cache set operation was not successful.');
            }
        } catch (Throwable $throwable) {
            $error = 'Could not record login revocation. ' . $throwable->getMessage();
            $this->logger?->error($error);
            throw new OidcClientException($error, (int) $throwable->getCode(), $throwable);
        }
    }

    /**
     * Check for a revocation entry recorded at, or after, the login time.
     *
     * A cache read error is logged but reported as "not revoked", so a
     * (temporarily) broken cache does not terminate every login.
     */
    protected function isRevoked(string $keyPrefix, string $issuer, string $value, int $loggedInAt): bool
    {
        try {
            $revokedAt = $this->cache->get($this->buildKey($keyPrefix, $issuer, $value));
        } catch (Throwable $throwable) {
            $this->logger?->error('Could not check for login revocation. ' . $throwable->getMessage());
            return false;
        }

        if (!is_numeric($revokedAt)) {
            return false;
        }

        return (int) $revokedAt >= $loggedInAt;
    }

    /**
     * Derive the cache key for a revocation entry. The issuer and claim
     * value are hashed, so the key stays within the 64 character length that
     * PSR-16 implementations are guaranteed to support, and contains no
     * reserved characters.
     */
    protected function buildKey(string $keyPrefix, string $issuer, string $value): string
    {
        return $keyPrefix . substr(hash('sha256', $issuer . "\n" . $value), 0, 56);
    }
}
