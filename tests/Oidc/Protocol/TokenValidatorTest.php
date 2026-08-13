<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Protocol;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Protocol\TokenValidator;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SimpleSAML\OpenID\Codebooks\ClaimsEnum;
use SimpleSAML\OpenID\Exceptions\JwsException;
use SimpleSAML\OpenID\Jws\ParsedJws;

#[CoversClass(TokenValidator::class)]
final class TokenValidatorTest extends TestCase
{
    private MockObject&LoggerInterface $loggerMock;

    /**
     * The validator is deliberately typed to the shared ParsedJws base class
     * rather than to IdToken or LogoutToken, so that is what these tests use.
     *
     * @var array{keys:array<array<string,mixed>>}
     */
    private array $jwks = ['keys' => []];

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    protected function sut(?LoggerInterface $logger = null): TokenValidator
    {
        return new TokenValidator($logger ?? $this->loggerMock);
    }

    /**
     * A token mock which passes every shared check by default, so each test
     * only has to say how the token it is about differs.
     *
     * The values are parameters rather than stubs a test would override,
     * because PHPUnit keeps the first stub configured for a method: a second
     * willReturn() on the same method is silently ignored, which is a
     * comfortable way to write a test that passes for the wrong reason.
     *
     * @param ?string[] $audience
     * @return \PHPUnit\Framework\MockObject\MockObject&\SimpleSAML\OpenID\Jws\ParsedJws
     */
    private function tokenMock(
        ?string $algorithm = 'RS256',
        ?string $issuer = 'https://op.example.org',
        ?array $audience = ['client-id'],
    ): MockObject {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')->willReturn($algorithm);
        $jws->method('getIssuer')->willReturn($issuer);
        $jws->method('getAudience')->willReturn($audience);

        return $jws;
    }

    public function testAcceptsTokenPassingEverySharedCheck(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->once())->method('verifyWithKeySet')->with($this->jwks);
        $this->loggerMock->expects($this->never())->method('warning');
        $this->loggerMock->expects($this->never())->method('error');

        $this->sut()->validate(
            jws: $jws,
            tokenName: 'ID token',
            jwks: $this->jwks,
            expectedIssuer: 'https://op.example.org',
            expectedClientId: 'client-id',
            expectedSigningAlgorithm: 'RS256',
        );
    }

    /**
     * getAlgorithm() throws both for an 'alg' of 'none' and for an algorithm
     * the underlying library does not recognise. Either way the failure must
     * surface as this library's own exception type - and before any JWKS is
     * spent on verifying the signature.
     */
    public function testRejectsUnsupportedSigningAlgorithm(): void
    {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')
            ->willThrowException(new JwsException('Invalid Algorithm header claim (none).'));
        $jws->expects($this->never())->method('verifyWithKeySet');

        $this->loggerMock->expects($this->once())->method('error');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('ID token uses an unsigned, unsupported or otherwise invalid signing algorithm');

        $this->sut()->validate($jws, 'ID token', $this->jwks);
    }

    /**
     * A null return means the 'alg' header is absent altogether, which
     * getAlgorithm() does not throw for.
     */
    public function testRejectsMissingAlgorithmHeader(): void
    {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')->willReturn(null);
        $jws->expects($this->never())->method('verifyWithKeySet');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token is unsigned (it carries no "alg" header)');

        $this->sut()->validate($jws, 'Logout token', $this->jwks);
    }

    public function testRejectsAlgorithmOtherThanTheExpectedOne(): void
    {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')->willReturn('ES256');
        $jws->expects($this->never())->method('verifyWithKeySet');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('signing algorithm "ES256" does not match the expected algorithm "RS256"');

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedSigningAlgorithm: 'RS256');
    }

    /**
     * Without an expected algorithm there is nothing to compare against, but a
     * supported one is still required - only the comparison is left out.
     */
    public function testAcceptsAnySupportedAlgorithmWhenNoneIsExpected(): void
    {
        $jws = $this->tokenMock(algorithm: 'ES256');
        $jws->expects($this->once())->method('verifyWithKeySet');

        $this->sut()->validate($jws, 'ID token', $this->jwks);
    }

    /**
     * An OP rotating its signing keys leaves a cached JWKS unable to verify a
     * perfectly legitimate token, so one refreshed attempt is made.
     */
    public function testRetriesSignatureVerificationWithRefreshedJwks(): void
    {
        $refreshedJwks = ['keys' => [['kid' => 'new-key']]];
        $jws = $this->tokenMock();
        $jws->expects($this->exactly(2))->method('verifyWithKeySet')
            ->willReturnCallback(function (array $jwks): void {
                if ($jwks === $this->jwks) {
                    throw new Exception('Sig fail');
                }
            });

        $resolverCalls = 0;
        $this->loggerMock->expects($this->once())->method('warning')
            ->with($this->stringContains('trying once more with JWKS refresh'));

        $this->sut()->validate(
            jws: $jws,
            tokenName: 'ID token',
            jwks: $this->jwks,
            refreshedJwksResolver: function () use ($refreshedJwks, &$resolverCalls): array {
                ++$resolverCalls;
                return $refreshedJwks;
            },
            // Supplied so the retry warning is the only one to account for.
            expectedIssuer: 'https://op.example.org',
            expectedClientId: 'client-id',
        );

        $this->assertSame(1, $resolverCalls);
    }

    public function testThrowsWhenRefreshedJwksAlsoFailsVerification(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->exactly(2))->method('verifyWithKeySet')
            ->willThrowException(new Exception('Sig fail'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Logout token is not valid. Sig fail');

        $this->sut()->validate(
            jws: $jws,
            tokenName: 'Logout token',
            jwks: $this->jwks,
            refreshedJwksResolver: fn(): array => ['keys' => []],
        );
    }

    /**
     * Without a resolver the caller is telling the validator that the JWKS it
     * was handed is already the freshest one available, so a second attempt
     * with the same keys could only fail the same way.
     */
    public function testDoesNotRetryWhenNoRefreshedJwksResolverIsGiven(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->once())->method('verifyWithKeySet')
            ->willThrowException(new Exception('Sig fail'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('ID token is not valid. Sig fail');

        $this->sut()->validate($jws, 'ID token', $this->jwks);
    }

    /**
     * Nothing to compare the claim against means the check cannot run at all -
     * which is worth a warning rather than a silent pass.
     */
    public function testSkipsAndReportsIssuerCheckWithoutAnExpectedIssuer(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->never())->method('getIssuer');

        $this->loggerMock->expects($this->atLeastOnce())->method('warning')
            ->with($this->stringContains('issuer'));

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    public function testRejectsIssuerOtherThanTheExpectedOne(): void
    {
        $jws = $this->tokenMock();

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'ID token issuer (iss) claim "https://op.example.org" does not match ' .
            'expected issuer "https://other-op.example.org"',
        );

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedIssuer: 'https://other-op.example.org');
    }

    public function testSkipsAndReportsAudienceCheckWithoutAnExpectedClientId(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->never())->method('getAudience');

        $this->loggerMock->expects($this->atLeastOnce())->method('warning')
            ->with($this->stringContains('audience'));

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedIssuer: 'https://op.example.org');
    }

    /**
     * An absent 'aud' cannot contain the client ID, so it is rejected rather
     * than treated as nothing to check. Both token abstractions reject the
     * absent claim while being built, so this only guards the base class'
     * looser contract - but it is the fail-open shape that already produced
     * one bug here, so it is pinned down.
     */
    public function testRejectsAbsentAudience(): void
    {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')->willReturn('RS256');
        $jws->method('getAudience')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('audience (aud) claim does not contain expected client ID "client-id"');

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    public function testRejectsAudienceNotContainingTheClientId(): void
    {
        $jws = $this->tokenMock();

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Logout token audience (aud) claim does not contain expected client ID "other-client-id"',
        );

        $this->sut()->validate($jws, 'Logout token', $this->jwks, expectedClientId: 'other-client-id');
    }

    /**
     * With a single audience there is no ambiguity about who the token was
     * issued to, so 'azp' is not consulted at all.
     */
    public function testIgnoresAuthorizedPartyWithASingleAudience(): void
    {
        $jws = $this->tokenMock();
        $jws->expects($this->never())->method('getPayloadClaim');

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    public function testRejectsMissingAuthorizedPartyWithMultipleAudiences(): void
    {
        $jws = $this->tokenMock(audience: ['client-id', 'other-audience']);
        $jws->method('getPayloadClaim')->with(ClaimsEnum::Azp->value)->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'ID token authorized party (azp) claim is missing but multiple audiences are present',
        );

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    public function testRejectsAuthorizedPartyOtherThanTheClientIdWithMultipleAudiences(): void
    {
        $jws = $this->tokenMock(audience: ['client-id', 'other-audience']);
        $jws->method('getPayloadClaim')->with(ClaimsEnum::Azp->value)->willReturn('other-client-id');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage(
            'Logout token authorized party (azp) claim does not match expected client ID "client-id"',
        );

        $this->sut()->validate($jws, 'Logout token', $this->jwks, expectedClientId: 'client-id');
    }

    public function testAcceptsMatchingAuthorizedPartyWithMultipleAudiences(): void
    {
        $jws = $this->tokenMock(audience: ['client-id', 'other-audience']);
        $jws->method('getPayloadClaim')->with(ClaimsEnum::Azp->value)->willReturn('client-id');
        $jws->expects($this->once())->method('verifyWithKeySet');

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    /**
     * An 'azp' which is not a string cannot be this client's ID either. The
     * claim is read raw, so this is the validator's own responsibility.
     */
    public function testRejectsNonStringAuthorizedParty(): void
    {
        $jws = $this->tokenMock(audience: ['client-id', 'other-audience']);
        $jws->method('getPayloadClaim')->with(ClaimsEnum::Azp->value)->willReturn(['client-id']);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('authorized party (azp) claim does not match expected client ID');

        $this->sut()->validate($jws, 'ID token', $this->jwks, expectedClientId: 'client-id');
    }

    /**
     * The validator has no logger of its own to fall back on, so it must stay
     * usable without one.
     */
    public function testWorksWithoutALogger(): void
    {
        $jws = $this->createMock(ParsedJws::class);
        $jws->method('getAlgorithm')->willReturn(null);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('carries no "alg" header');

        (new TokenValidator())->validate($jws, 'ID token', $this->jwks);
    }
}
