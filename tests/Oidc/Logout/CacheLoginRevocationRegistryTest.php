<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Logout;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Logout\CacheLoginRevocationRegistry;
use DateInterval;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(CacheLoginRevocationRegistry::class)]
final class CacheLoginRevocationRegistryTest extends TestCase
{
    private const ISSUER = 'https://op.example.com';

    /**
     * @var array<string,mixed>
     */
    private array $cacheStorage = [];

    private MockObject $cacheMock;

    private MockObject $loggerMock;

    protected function setUp(): void
    {
        $this->cacheStorage = [];

        $this->cacheMock = $this->createMock(CacheInterface::class);
        $this->cacheMock->method('set')
            ->willReturnCallback(function (string $key, mixed $value): bool {
                $this->cacheStorage[$key] = $value;
                return true;
            });
        $this->cacheMock->method('get')
            ->willReturnCallback(fn(string $key): mixed => $this->cacheStorage[$key] ?? null);

        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    protected function sut(
        ?CacheInterface $cache = null,
        ?DateInterval $revocationDuration = null,
        ?LoggerInterface $logger = null,
    ): CacheLoginRevocationRegistry {
        /** @var CacheInterface $cache */
        $cache ??= $this->cacheMock;
        $revocationDuration ??= new DateInterval('P1D');
        $logger ??= $this->loggerMock;

        return new CacheLoginRevocationRegistry($cache, $revocationDuration, $logger);
    }

    public function testCanRevokeSession(): void
    {
        $sut = $this->sut();

        $this->assertFalse($sut->isSessionRevoked(self::ISSUER, 'sid-123', time() - 60));

        $sut->revokeSession(self::ISSUER, 'sid-123');

        $this->assertTrue($sut->isSessionRevoked(self::ISSUER, 'sid-123', time() - 60));
    }

    public function testCanRevokeSubject(): void
    {
        $sut = $this->sut();

        $this->assertFalse($sut->isSubjectRevoked(self::ISSUER, 'sub-123', time() - 60));

        $sut->revokeSubject(self::ISSUER, 'sub-123');

        $this->assertTrue($sut->isSubjectRevoked(self::ISSUER, 'sub-123', time() - 60));
    }

    public function testLoginEstablishedAfterRevocationIsNotRevoked(): void
    {
        $sut = $this->sut();

        $sut->revokeSession(self::ISSUER, 'sid-123');
        $sut->revokeSubject(self::ISSUER, 'sub-123');

        $this->assertFalse($sut->isSessionRevoked(self::ISSUER, 'sid-123', time() + 60));
        $this->assertFalse($sut->isSubjectRevoked(self::ISSUER, 'sub-123', time() + 60));
    }

    public function testSessionAndSubjectRevocationsAreSeparate(): void
    {
        $sut = $this->sut();

        $sut->revokeSession(self::ISSUER, 'value-123');

        $this->assertFalse($sut->isSubjectRevoked(self::ISSUER, 'value-123', time() - 60));
    }

    public function testRevocationsAreScopedToIssuer(): void
    {
        $sut = $this->sut();

        $sut->revokeSession(self::ISSUER, 'sid-123');

        $this->assertFalse($sut->isSessionRevoked('https://other-op.example.com', 'sid-123', time() - 60));
    }

    public function testRevokeThrowsWhenCacheSetFails(): void
    {
        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->method('set')->willReturn(false);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Could not record login revocation.');

        $this->sut(cache: $cacheMock)->revokeSession(self::ISSUER, 'sid-123');
    }

    public function testRevokeThrowsWhenCacheSetThrows(): void
    {
        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->method('set')->willThrowException(new Exception('Cache error.'));

        $this->loggerMock->expects($this->once())->method('error');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Cache error.');

        $this->sut(cache: $cacheMock)->revokeSubject(self::ISSUER, 'sub-123');
    }

    public function testIsRevokedReturnsFalseWhenCacheGetThrows(): void
    {
        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->method('get')->willThrowException(new Exception('Cache error.'));

        $this->loggerMock->expects($this->once())->method('error');

        $this->assertFalse($this->sut(cache: $cacheMock)->isSessionRevoked(self::ISSUER, 'sid-123', time()));
    }

    public function testIsRevokedReturnsFalseForNonNumericCacheValue(): void
    {
        $cacheMock = $this->createMock(CacheInterface::class);
        $cacheMock->method('get')->willReturn('not-a-timestamp');

        $this->assertFalse($this->sut(cache: $cacheMock)->isSessionRevoked(self::ISSUER, 'sid-123', time()));
    }
}
