<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Logout\Interfaces;

/**
 * Registry of login revocations initiated by the OpenID Provider (OIDC
 * Back-Channel Logout).
 *
 * A back-channel logout request arrives on a dedicated endpoint, outside the
 * context of the End-User's session (no session cookie is sent with it), so
 * the affected login can not be removed from its session store directly.
 * Instead, the revocation is recorded here, in a store shared between
 * requests, and the login is considered terminated as soon as the revocation
 * is observed - typically on the next request in the End-User's session,
 * when persisted login data is read.
 *
 * Logins are identified the way the Back-Channel Logout specification
 * identifies sessions: by the OP issuer identifier combined with the 'sid'
 * (session ID) claim, or by the issuer combined with the 'sub' (subject)
 * claim, in which case all of the subject's logins with that issuer are
 * affected.
 *
 * Revocations are recorded with the time they happened, so logins
 * established after a revocation (e.g., the End-User logs in again) are not
 * affected by it.
 */
interface LoginRevocationRegistryInterface
{
    /**
     * Record revocation for the login session identified by the OP issuer
     * identifier and the 'sid' (session ID) claim value.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException If the revocation
     * could not be recorded (the logout must not be acknowledged in that
     * case).
     */
    public function revokeSession(string $issuer, string $sessionId): void;

    /**
     * Record revocation for all login sessions of the subject identified by
     * the OP issuer identifier and the 'sub' (subject) claim value.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException If the revocation
     * could not be recorded (the logout must not be acknowledged in that
     * case).
     */
    public function revokeSubject(string $issuer, string $subject): void;

    /**
     * Check if the login session identified by the OP issuer identifier and
     * the 'sid' (session ID) claim value, established at the given time, has
     * been revoked.
     *
     * @param int $loggedInAt Unix timestamp at which the login was
     * established. Logins established after a recorded revocation are not
     * considered revoked by it.
     */
    public function isSessionRevoked(string $issuer, string $sessionId, int $loggedInAt): bool;

    /**
     * Check if logins of the subject identified by the OP issuer identifier
     * and the 'sub' (subject) claim value, established at the given time,
     * have been revoked.
     *
     * @param int $loggedInAt Unix timestamp at which the login was
     * established. Logins established after a recorded revocation are not
     * considered revoked by it.
     */
    public function isSubjectRevoked(string $issuer, string $subject, int $loggedInAt): bool;
}
