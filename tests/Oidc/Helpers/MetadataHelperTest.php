<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\MetadataHelper;
use Cicnavi\Oidc\Interfaces\MetadataInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataHelper::class)]
final class MetadataHelperTest extends TestCase
{
    private function metadata(string $key, mixed $value, bool $throw = false): MetadataInterface
    {
        $metadata = $this->createMock(MetadataInterface::class);

        if ($throw) {
            $metadata->method('get')->willThrowException(new OidcClientException('Not advertised.'));
        } else {
            $metadata->method('get')->with($key)->willReturn($value);
        }

        return $metadata;
    }

    public function testOptionalStringReturnsNonEmptyString(): void
    {
        $metadata = $this->metadata('issuer', 'https://op.example.org');

        $this->assertSame('https://op.example.org', MetadataHelper::optionalString($metadata, 'issuer'));
    }

    public function testOptionalStringReturnsNullForEmptyString(): void
    {
        $metadata = $this->metadata('issuer', '');

        $this->assertNull(MetadataHelper::optionalString($metadata, 'issuer'));
    }

    public function testOptionalStringReturnsNullForNonString(): void
    {
        $metadata = $this->metadata('issuer', ['not', 'a', 'string']);

        $this->assertNull(MetadataHelper::optionalString($metadata, 'issuer'));
    }

    public function testOptionalStringReturnsNullWhenKeyNotAdvertised(): void
    {
        $metadata = $this->metadata('issuer', null, throw: true);

        $this->assertNull(MetadataHelper::optionalString($metadata, 'issuer'));
    }
}
