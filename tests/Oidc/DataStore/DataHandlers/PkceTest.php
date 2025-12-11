<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\DataHandlerInterface;
use Cicnavi\Oidc\DataStore\DataHandlers\Pkce;
use Cicnavi\Oidc\DataStore\PhpSessionDataStore;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class PkceTest
 * @package Cicnavi\Tests\Store\DataHandlers
 *
 * @covers \Cicnavi\Oidc\DataStore\DataHandlers\Pkce
 */
final class PkceTest extends TestCase
{
    protected string $testCodeVerifier = 'mV3hZ7E7iWzljYMPqcjNHCdT32lVsZ6tI8ssdHyilSr48lLosV7soxlPEh8SekHejJElY3vj' .
    'Ap2MeLxjd9hP0MJrlS8L99TV5A49aSIVm2z7JD032BWA8AvGYDuEWjLw';

    protected string $testCodeChallenge = '3e8R0tTilJlPsoRSooW-To9J2hzvAeKImTmBb5XMztY';

    /**
     * @throws Exception
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     */
    public function testValidatePkceCodeChallengeMethod(): void
    {
        $pkce = new Pkce();
        $pkce->validatePkceCodeChallengeMethod('plain');

        $this->expectException(Exception::class);

        $pkce->validatePkceCodeChallengeMethod('invalid');
    }

    /**
     * @throws Exception
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     */
    public function testGenerateCodeChallengeFromCodeVerifierValidation(): void
    {
        $this->expectException(Exception::class);

        (new Pkce())->generateCodeChallengeFromCodeVerifier('invalid');
    }

    /**
     * @throws Exception
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     */
    public function testGenerateCodeChallengeFromCodeVerifier(): void
    {
        $this->assertSame(
            $this->testCodeVerifier,
            (new Pkce())->generateCodeChallengeFromCodeVerifier($this->testCodeVerifier, 'plain')
        );

        $this->assertSame(
            $this->testCodeChallenge,
            (new Pkce())->generateCodeChallengeFromCodeVerifier($this->testCodeVerifier, 'S256')
        );
    }

    /**
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     */
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
        $storeStub = $this->createStub(PhpSessionDataStore::class);
        $storeStub->method('exists')
            ->willReturn(true);
        $storeStub->method('get')
            ->willReturn($this->testCodeVerifier);

        $pkceDataHandler = new Pkce($storeStub);

        $this->assertSame($this->testCodeVerifier, $pkceDataHandler->getCodeVerifier());
    }

    /**
     * @throws Exception
     *
     * @uses \Cicnavi\Oidc\Helpers\StringHelper
     */
    public function testNewCodeVerifier(): void
    {
        $testCodeVerifierValue = 'testCodeVerifier';

        $storeStub = $this->createStub(PhpSessionDataStore::class);
        $storeStub->method('exists')
            ->willReturn(false);

        $pkce = new Pkce($storeStub);

        $codeVerifier = $pkce->getCodeVerifier();
        $this->assertNotSame($testCodeVerifierValue, $codeVerifier);
    }

    /**
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     * @covers \Cicnavi\Oidc\DataStore\DataHandlers\AbstractDataHandler
     */
    public function testConstructWithoutArguments(): void
    {
        $this->assertInstanceOf(DataHandlerInterface::class, new Pkce());
    }

    /**
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     * @covers \Cicnavi\Oidc\DataStore\DataHandlers\AbstractDataHandler
     */
    public function testConstructWithArguments(): void
    {
        $store = new PhpSessionDataStore();
        $this->assertInstanceOf(DataHandlerInterface::class, new Pkce($store));
    }

    /**
     * @uses \Cicnavi\Oidc\DataStore\PhpSessionDataStore
     * @covers \Cicnavi\Oidc\DataStore\DataHandlers\AbstractDataHandler
     */
    public function testSetStore(): void
    {
        $store = new PhpSessionDataStore();
        $pkce = new Pkce();
        $pkce->setStore($store);
        $this->assertInstanceOf(DataHandlerInterface::class, $pkce);
    }
}
