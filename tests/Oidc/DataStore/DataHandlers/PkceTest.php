<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\DataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Helpers\StringHelper;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;

#[CoversClass(Pkce::class)]
#[UsesClass(PhpSessionStore::class)]
#[UsesClass(StringHelper::class)]
final class PkceTest extends TestCase
{
    protected string $testCodeVerifier = 'mV3hZ7E7iWzljYMPqcjNHCdT32lVsZ6tI8ssdHyilSr48lLosV7soxlPEh8SekHejJElY3vj' .
    'Ap2MeLxjd9hP0MJrlS8L99TV5A49aSIVm2z7JD032BWA8AvGYDuEWjLw';

    protected string $testCodeChallenge = '3e8R0tTilJlPsoRSooW-To9J2hzvAeKImTmBb5XMztY';

    /**
     * @throws Exception
     */
    public function testGenerateCodeChallengeFromCodeVerifierValidation(): void
    {
        $this->expectException(Exception::class);

        (new Pkce())->generateCodeChallengeFromCodeVerifier('invalid');
    }

    /**
     * @throws Exception
     */
    public function testGenerateCodeChallengeFromCodeVerifier(): void
    {
        $this->assertSame(
            $this->testCodeVerifier,
            (new Pkce())->generateCodeChallengeFromCodeVerifier(
                $this->testCodeVerifier,
                PkceCodeChallengeMethodEnum::Plain,
            )
        );

        $this->assertSame(
            $this->testCodeChallenge,
            (new Pkce())->generateCodeChallengeFromCodeVerifier(
                $this->testCodeVerifier,
                PkceCodeChallengeMethodEnum::S256,
            )
        );
    }

    public function testRemoveCodeVerifierParameter(): void
    {
        $pkce = new Pkce();

        $codeVerifier = $pkce->getCodeVerifier();

        $this->assertSame($codeVerifier, $pkce->getCodeVerifier());

        $pkce->removeCodeVerifier();

        $this->assertNotSame($codeVerifier, $pkce->getCodeVerifier());
    }

    public function testGetExistingCodeVerifier(): void
    {
        $storeStub = $this->createStub(PhpSessionStore::class);
        $storeStub->method('exists')
            ->willReturn(true);
        $storeStub->method('get')
            ->willReturn($this->testCodeVerifier);

        $pkceDataHandler = new Pkce($storeStub);

        $this->assertSame($this->testCodeVerifier, $pkceDataHandler->getCodeVerifier());
    }

    /**
     * @throws Exception
     */
    public function testNewCodeVerifier(): void
    {
        $testCodeVerifierValue = 'testCodeVerifier';

        $storeStub = $this->createStub(PhpSessionStore::class);
        $storeStub->method('exists')
            ->willReturn(false);

        $pkce = new Pkce($storeStub);

        $codeVerifier = $pkce->getCodeVerifier();
        $this->assertNotSame($testCodeVerifierValue, $codeVerifier);
    }

    public function testConstructWithoutArguments(): void
    {
        $this->assertInstanceOf(DataHandlerInterface::class, new Pkce());
    }

    public function testConstructWithArguments(): void
    {
        $store = new PhpSessionStore();
        $this->assertInstanceOf(DataHandlerInterface::class, new Pkce($store));
    }

    public function testSetStore(): void
    {
        $store = new PhpSessionStore();
        $pkce = new Pkce();
        $pkce->setSessionStore($store);
        $this->assertInstanceOf(DataHandlerInterface::class, $pkce);
    }
}
