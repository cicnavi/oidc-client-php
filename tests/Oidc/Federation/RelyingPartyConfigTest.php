<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Federation;

use Cicnavi\Oidc\Federation\RelyingPartyConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SimpleSAML\OpenID\ValueAbstracts\ClaimBag;
use SimpleSAML\OpenID\ValueAbstracts\RedirectUriBag;
use SimpleSAML\OpenID\ValueAbstracts\ScopeBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag;

#[CoversClass(RelyingPartyConfig::class)]
final class RelyingPartyConfigTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\Stub $redirectUriBagMock;

    private \PHPUnit\Framework\MockObject\Stub $connectSignatureKeyPairBagMock;

    private \PHPUnit\Framework\MockObject\Stub $scopeBagMock;

    private \PHPUnit\Framework\MockObject\Stub $additionalClaimBagMock;

    private string $initiateLoginUri;

    private string $jwksUri;

    private string $signedJwksUri;

    private string $logoUri;

    protected function setUp(): void
    {
        $this->redirectUriBagMock = $this->createStub(RedirectUriBag::class);
        $this->connectSignatureKeyPairBagMock = $this->createStub(SignatureKeyPairConfigBag::class);
        $this->scopeBagMock = $this->createStub(ScopeBag::class);
        $this->additionalClaimBagMock = $this->createStub(ClaimBag::class);
        $this->initiateLoginUri = 'https://rp.example.org/initiate';
        $this->jwksUri = 'https://rp.example.org/jwks';
        $this->signedJwksUri = 'https://rp.example.org/signed-jwks';
        $this->logoUri = 'https://rp.example.org/logo.png';
    }

    protected function sut(
        ?RedirectUriBag $redirectUriBag = null,
        ?SignatureKeyPairConfigBag $connectSignatureKeyPairBag = null,
        ?ScopeBag $scopeBag = null,
        ?ClaimBag $additionalClaimBag = null,
        ?string $initiateLoginUri = null,
        ?string $jwksUri = null,
        ?string $signedJwksUri = null,
        ?string $logoUri = null,
    ): RelyingPartyConfig {
        $redirectUriBag ??= $this->redirectUriBagMock;
        $connectSignatureKeyPairBag ??= $this->connectSignatureKeyPairBagMock;
        $scopeBag ??= $this->scopeBagMock;
        $additionalClaimBag ??= $this->additionalClaimBagMock;
        $initiateLoginUri ??= $this->initiateLoginUri;
        $jwksUri ??= $this->jwksUri;
        $signedJwksUri ??= $this->signedJwksUri;
        $logoUri ??= $this->logoUri;

        return new RelyingPartyConfig(
            $redirectUriBag,
            $connectSignatureKeyPairBag,
            $scopeBag,
            $additionalClaimBag,
            $initiateLoginUri,
            $jwksUri,
            $signedJwksUri,
            $logoUri,
        );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(RelyingPartyConfig::class, $this->sut());
    }

    public function testGettersReturnProvidedValues(): void
    {
        $sut = $this->sut();
        $this->assertSame($this->redirectUriBagMock, $sut->getRedirectUriBag());
        $this->assertSame($this->connectSignatureKeyPairBagMock, $sut->getConnectSignatureKeyPairBag());
        $this->assertSame($this->scopeBagMock, $sut->getScopeBag());
        $this->assertSame($this->additionalClaimBagMock, $sut->getAdditionalClaimBag());
        $this->assertSame($this->initiateLoginUri, $sut->getInitiateLoginUri());
        $this->assertSame($this->jwksUri, $sut->getJwksUri());
        $this->assertSame($this->signedJwksUri, $sut->getSignedJwksUri());
        $this->assertSame($this->logoUri, $sut->getLogoUri());
    }
}
