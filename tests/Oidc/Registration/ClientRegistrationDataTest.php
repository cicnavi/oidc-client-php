<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Registration;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Registration\ClientRegistrationData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientRegistrationData::class)]
final class ClientRegistrationDataTest extends TestCase
{
    /**
     * @var mixed[]
     */
    protected array $claims = [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'client_id_issued_at' => 1700000000,
        'client_secret_expires_at' => 1800000000,
        'registration_access_token' => 'registration-access-token',
        'registration_client_uri' => 'https://op.example.org/register/client-id',
    ];

    /**
     * @param ?mixed[] $claims
     */
    protected function sut(?array $claims = null): ClientRegistrationData
    {
        return new ClientRegistrationData($claims ?? $this->claims);
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(ClientRegistrationData::class, $this->sut());
    }

    public function testCanGetClaimValues(): void
    {
        $sut = $this->sut();

        $this->assertSame('client-id', $sut->getClientId());
        $this->assertSame('client-secret', $sut->getClientSecret());
        $this->assertSame(1700000000, $sut->getClientIdIssuedAt());
        $this->assertSame(1800000000, $sut->getClientSecretExpiresAt());
        $this->assertSame('registration-access-token', $sut->getRegistrationAccessToken());
        $this->assertSame('https://op.example.org/register/client-id', $sut->getRegistrationClientUri());
        $this->assertSame($this->claims, $sut->getClaims());
        $this->assertSame($this->claims, $sut->jsonSerialize());
    }

    public function testCanCreateInstanceWithMinimalClaims(): void
    {
        $sut = $this->sut(['client_id' => 'client-id']);

        $this->assertSame('client-id', $sut->getClientId());
        $this->assertNull($sut->getClientSecret());
        $this->assertNull($sut->getClientIdIssuedAt());
        $this->assertSame(0, $sut->getClientSecretExpiresAt());
        $this->assertNull($sut->getRegistrationAccessToken());
        $this->assertNull($sut->getRegistrationClientUri());
    }

    public function testThrowsOnMissingClientId(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client_id');

        $this->sut(['client_secret' => 'client-secret']);
    }

    public function testThrowsOnEmptyClientId(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client_id');

        $this->sut(['client_id' => '']);
    }

    public function testThrowsOnInvalidClientSecret(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('client_secret');

        $this->sut(['client_id' => 'client-id', 'client_secret' => '']);
    }

    public function testClientSecretIsNotExpiredWhenNoSecretIssued(): void
    {
        $sut = $this->sut(['client_id' => 'client-id', 'client_secret_expires_at' => 1]);

        $this->assertFalse($sut->isClientSecretExpired());
    }

    public function testClientSecretIsNotExpiredWhenExpiryIsZero(): void
    {
        $sut = $this->sut(['client_id' => 'client-id', 'client_secret' => 'client-secret']);

        $this->assertFalse($sut->isClientSecretExpired());
    }

    public function testClientSecretExpiry(): void
    {
        $sut = $this->sut([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'client_secret_expires_at' => 1800000000,
        ]);

        $this->assertFalse($sut->isClientSecretExpired(1799999999));
        $this->assertTrue($sut->isClientSecretExpired(1800000000));
        $this->assertTrue($sut->isClientSecretExpired(1800000001));
    }

    public function testCanHandleNumericStringTimestamps(): void
    {
        $sut = $this->sut([
            'client_id' => 'client-id',
            'client_id_issued_at' => '1700000000',
            'client_secret_expires_at' => '1800000000',
        ]);

        $this->assertSame(1700000000, $sut->getClientIdIssuedAt());
        $this->assertSame(1800000000, $sut->getClientSecretExpiresAt());
    }
}
