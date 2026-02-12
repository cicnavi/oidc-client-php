<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Federation;

use Cicnavi\Oidc\Federation\EntityConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SimpleSAML\OpenID\ValueAbstracts\ClaimBag;
use SimpleSAML\OpenID\ValueAbstracts\KeyedStringBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\TrustAnchorConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag;

#[CoversClass(EntityConfig::class)]
final class EntityConfigTest extends TestCase
{
    private string $entityId;

    private MockObject $trustAnchorConfigBagMock;

    private MockObject $authorityHintBagMock;

    private MockObject $federationSignatureKeyPairConfigBagMock;

    private MockObject $staticTrustMarkBagMock;

    private MockObject $dynamicTrustMarkBagMock;

    private MockObject $additionalClaimBag;

    protected function setUp(): void
    {
        $this->entityId = 'entity-id';
        $this->trustAnchorConfigBagMock = $this->createMock(TrustAnchorConfigBag::class);
        $this->authorityHintBagMock = $this->createMock(UniqueStringBag::class);
        $this->federationSignatureKeyPairConfigBagMock = $this->createMock(SignatureKeyPairConfigBag::class);
        $this->staticTrustMarkBagMock = $this->createMock(UniqueStringBag::class);
        $this->dynamicTrustMarkBagMock = $this->createMock(KeyedStringBag::class);
        $this->additionalClaimBag = $this->createMock(ClaimBag::class);
    }

    protected function sut(
        ?string $entityId = null,
        ?TrustAnchorConfigBag $trustAnchorConfigBag = null,
        ?UniqueStringBag $authorityHintBag = null,
        ?SignatureKeyPairConfigBag $federationSignatureKeyPairConfigBag = null,
        ?UniqueStringBag $staticTrustMarkBag = null,
        ?KeyedStringBag $dynamicTrustMarkBag = null,
        ?ClaimBag $additionalClaimBag = null,
    ): EntityConfig {
        $entityId ??= $this->entityId;
        $trustAnchorConfigBag ??= $this->trustAnchorConfigBagMock;
        $authorityHintBag ??= $this->authorityHintBagMock;
        $federationSignatureKeyPairConfigBag ??= $this->federationSignatureKeyPairConfigBagMock;
        $staticTrustMarkBag ??= $this->staticTrustMarkBagMock;
        $dynamicTrustMarkBag ??= $this->dynamicTrustMarkBagMock;
        $additionalClaimBag ??= $this->additionalClaimBag;

        return new EntityConfig(
            $entityId,
            $trustAnchorConfigBag,
            $authorityHintBag,
            $federationSignatureKeyPairConfigBag,
            $staticTrustMarkBag,
            $dynamicTrustMarkBag,
            $additionalClaimBag,
        );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(EntityConfig::class, $this->sut());
    }

    public function testGettersReturnProvidedValues(): void
    {
        $sut = $this->sut();

        $this->assertSame($this->entityId, $sut->getEntityId());
        $this->assertSame($this->trustAnchorConfigBagMock, $sut->getTrustAnchorBag());
        $this->assertSame($this->authorityHintBagMock, $sut->getAuthorityHintBag());
        $this->assertSame(
            $this->federationSignatureKeyPairConfigBagMock,
            $sut->getFederationSignatureKeyPairConfigBag(),
        );
        $this->assertSame($this->staticTrustMarkBagMock, $sut->getStaticTrustMarkBag());
        $this->assertSame($this->dynamicTrustMarkBagMock, $sut->getDynamicTrustMarkBag());
        $this->assertSame($this->additionalClaimBag, $sut->getAdditionalClaimBag());
    }

    public function testOptionalParametersDefaultToEmptyInstances(): void
    {
        // Construct with only required arguments - other params have defaults
        $entity = new EntityConfig(
            'default-entity',
            $this->createMock(TrustAnchorConfigBag::class),
            $this->createMock(UniqueStringBag::class),
        );

        $this->assertInstanceOf(SignatureKeyPairConfigBag::class, $entity->getFederationSignatureKeyPairConfigBag());
        $this->assertInstanceOf(UniqueStringBag::class, $entity->getStaticTrustMarkBag());
        $this->assertInstanceOf(KeyedStringBag::class, $entity->getDynamicTrustMarkBag());
        $this->assertInstanceOf(ClaimBag::class, $entity->getAdditionalClaimBag());
    }
}
