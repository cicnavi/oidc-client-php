<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Protocol;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Closure;
use Psr\Log\LoggerInterface;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Jws\ParsedJws;
use Throwable;

/**
 * Validation shared by the signed tokens this client consumes from an OP: the
 * ID Token (OpenID Connect Core 1.0, section 3.1.3.7) and the Logout Token
 * (OpenID Connect Back-Channel Logout 1.0, section 2.6).
 *
 * The two specifications deliberately define the same checks for the parts
 * covered here - Back-Channel Logout section 2.6 refers back to ID Token
 * validation for them - so they are performed in one place, in one order:
 *
 *  1. the 'alg' header, before anything else, so a token signed with an
 *     algorithm this client does not accept is rejected without spending an
 *     HTTP request on a JWKS refresh;
 *  2. the signature, with a single JWKS refresh retry (an OP key rotation
 *     leaves a cached JWKS unable to verify a legitimately signed token);
 *  3. the 'iss' claim;
 *  4. the 'aud' claim, and 'azp' when the token carries several audiences.
 *
 * Everything specific to one token type stays with its caller: nonce, 'sub',
 * 'exp' and 'iat' for the ID Token; 'typ', 'sid', freshness and 'jti' replay
 * detection for the Logout Token.
 *
 * This class was extracted after the two copies of these checks drifted apart
 * far enough to produce bugs in both directions - the ID Token copy accepted
 * an empty 'aud' and never looked at 'alg', while the Logout Token copy
 * carried a dead 'none' guard which was then propagated into the ID Token
 * copy. Keep them merged: a fix that has to be remembered twice eventually
 * will not be.
 *
 * @see \Cicnavi\Tests\Oidc\Protocol\TokenValidatorTest
 */
class TokenValidator
{
    public function __construct(
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Run every check shared by the ID Token and the Logout Token, in the
     * order documented on this class.
     *
     * @param \SimpleSAML\OpenID\Jws\ParsedJws $jws The parsed token to validate.
     * @param string $tokenName How the token is named in log entries and error
     * messages, sentence-initial ('ID token', 'Logout token'). These messages
     * reach the application, and in the Back-Channel Logout case the HTTP
     * response, so the two token types must remain distinguishable.
     * @param array{keys:array<array<string,mixed>>} $jwks The OP's JWKS, as
     * already resolved by the caller.
     * @param ?\Closure(): array{keys:array<array<string,mixed>>} $refreshedJwksResolver
     * Returns a freshly fetched (not cached) JWKS, used for the single retry
     * after a failed signature verification. Pass null to disable the retry -
     * which is what the caller does when the JWKS it provided was already
     * fetched fresh, since retrying with the same keys could only fail again.
     * @param ?string $expectedIssuer When provided, the 'iss' claim must equal
     * it. Null skips the check (and is reported), since there is then no
     * value to compare the claim against.
     * @param ?string $expectedClientId When provided, the 'aud' claim must
     * contain it. Null skips the check (and is reported), as above.
     * @param ?string $expectedSigningAlgorithm When provided, the 'alg' header
     * must equal it - the single algorithm the RP registered, rather than the
     * OP's broad set of advertised ones. Null only leaves the comparison out;
     * an unsigned or unsupported algorithm is rejected regardless.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException If the token fails
     * any of the shared checks.
     */
    public function validate(
        ParsedJws $jws,
        string $tokenName,
        array $jwks,
        ?Closure $refreshedJwksResolver = null,
        ?string $expectedIssuer = null,
        ?string $expectedClientId = null,
        ?string $expectedSigningAlgorithm = null,
    ): void {
        $this->validateSigningAlgorithm($jws, $tokenName, $expectedSigningAlgorithm);
        $this->verifySignature($jws, $tokenName, $jwks, $refreshedJwksResolver);
        $this->validateIssuer($jws, $tokenName, $expectedIssuer);
        $this->validateAudienceAndAuthorizedParty($jws, $tokenName, $expectedClientId);
    }

    /**
     * Validate the 'alg' header parameter.
     *
     * Both token types MUST be signed (OpenID Connect Core section 2 and
     * Back-Channel Logout section 2.6), so an 'alg' of 'none' is never
     * acceptable. Rejecting it is left to getAlgorithm(), which throws both
     * for 'none' and for an unrecognized algorithm, and returns null only when
     * the header is absent altogether - an explicit 'none' comparison here
     * would be dead code.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     */
    protected function validateSigningAlgorithm(
        ParsedJws $jws,
        string $tokenName,
        ?string $expectedSigningAlgorithm,
    ): void {
        try {
            $algorithm = $jws->getAlgorithm();
        } catch (Throwable $throwable) {
            // Normalize into this library's own exception type, so the caller
            // sees it rather than a lower-level one, and so it is logged.
            throw $this->reject(
                sprintf(
                    '%s uses an unsigned, unsupported or otherwise invalid signing algorithm. %s',
                    $tokenName,
                    $throwable->getMessage(),
                ),
                $throwable,
            );
        }

        if ($algorithm === null) {
            throw $this->reject(
                sprintf('%s is unsigned (it carries no "alg" header), which is not allowed.', $tokenName),
            );
        }

        if ($expectedSigningAlgorithm !== null && $algorithm !== $expectedSigningAlgorithm) {
            throw $this->reject(sprintf(
                '%s signing algorithm "%s" does not match the expected algorithm "%s".',
                $tokenName,
                $algorithm,
                $expectedSigningAlgorithm,
            ));
        }
    }

    /**
     * Verify the signature against the OP's JWKS, retrying once with a freshly
     * fetched JWKS when a resolver for one was provided. The retry covers the
     * ordinary case of an OP rotating its keys after this client cached them.
     *
     * @param array{keys:array<array<string,mixed>>} $jwks
     * @param ?\Closure(): array{keys:array<array<string,mixed>>} $refreshedJwksResolver
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     */
    protected function verifySignature(
        ParsedJws $jws,
        string $tokenName,
        array $jwks,
        ?Closure $refreshedJwksResolver,
    ): void {
        try {
            $jws->verifyWithKeySet($jwks);
            return;
        } catch (Throwable $throwable) {
            if (!$refreshedJwksResolver instanceof Closure) {
                throw $this->reject(
                    sprintf('%s is not valid. %s', $tokenName, $throwable->getMessage()),
                    $throwable,
                );
            }

            $this->logger?->warning(sprintf(
                '%s signature verification failed, but trying once more with JWKS refresh.',
                $tokenName,
            ));
        }

        try {
            $jws->verifyWithKeySet($refreshedJwksResolver());
        } catch (Throwable $throwable) {
            throw $this->reject(
                sprintf('%s is not valid. %s', $tokenName, $throwable->getMessage()),
                $throwable,
            );
        }
    }

    /**
     * Validate the 'iss' claim against the issuer the token is expected to
     * have come from.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     */
    protected function validateIssuer(ParsedJws $jws, string $tokenName, ?string $expectedIssuer): void
    {
        if ($expectedIssuer === null) {
            // Nothing to compare the claim against, so the check cannot run.
            // This happens when the OP discovery document carries no 'issuer'
            // (which makes it invalid), or when a caller reached the public
            // validation method without supplying one - make the gap visible
            // instead of passing the token silently.
            $this->logger?->warning(
                sprintf('%s issuer (iss) validation skipped: no expected issuer given.', $tokenName),
            );
            return;
        }

        $issuer = $jws->getIssuer();

        if ($issuer !== $expectedIssuer) {
            throw $this->reject(sprintf(
                '%s issuer (iss) claim "%s" does not match expected issuer "%s".',
                $tokenName,
                $issuer ?? '',
                $expectedIssuer,
            ));
        }
    }

    /**
     * Validate the 'aud' claim, and the 'azp' claim which becomes meaningful
     * once the token names more than one audience.
     *
     * @throws \Cicnavi\Oidc\Exceptions\OidcClientException
     */
    protected function validateAudienceAndAuthorizedParty(
        ParsedJws $jws,
        string $tokenName,
        ?string $expectedClientId,
    ): void {
        if ($expectedClientId === null) {
            $this->logger?->warning(sprintf(
                '%s audience (aud) and authorized party (azp) validation skipped: no expected client ID given.',
                $tokenName,
            ));
            return;
        }

        // An absent or empty 'aud' cannot contain the client ID, so it is
        // rejected rather than skipped. (Both token abstractions already throw
        // for an absent 'aud', since the claim is REQUIRED - the null
        // coalescing here only satisfies the base class' looser signature.)
        $audience = $jws->getAudience() ?? [];

        if (!in_array($expectedClientId, $audience, true)) {
            throw $this->reject(sprintf(
                '%s audience (aud) claim does not contain expected client ID "%s".',
                $tokenName,
                $expectedClientId,
            ));
        }

        if (count($audience) < 2) {
            return;
        }

        // With several audiences, 'azp' is what identifies the party the token
        // was actually issued to, so it must be present and must be this
        // client. Read as a raw payload claim rather than through IdToken's
        // getAuthorizedParty(), which the Logout Token abstraction does not
        // have; for an ID Token the value was already type-checked while the
        // token was being built.
        $authorizedParty = $jws->getPayloadClaim(ClaimsEnum::Azp->value);

        if ($authorizedParty === null) {
            throw $this->reject(sprintf(
                '%s authorized party (azp) claim is missing but multiple audiences are present.',
                $tokenName,
            ));
        }

        if ($authorizedParty !== $expectedClientId) {
            throw $this->reject(sprintf(
                '%s authorized party (azp) claim does not match expected client ID "%s".',
                $tokenName,
                $expectedClientId,
            ));
        }
    }

    /**
     * Log a validation failure and build the exception to throw for it. The
     * caller throws the returned exception, so that control flow stays visible
     * at the point of rejection.
     */
    protected function reject(string $error, ?Throwable $previous = null): OidcClientException
    {
        $this->logger?->error($error);

        return new OidcClientException($error, (int) $previous?->getCode(), $previous);
    }
}
