<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Helpers;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\StringHelper;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringHelper::class)]
final class StringHelperTest extends TestCase
{
    public function testRandom(): void
    {
        $this->assertNotSame(StringHelper::random(), StringHelper::random());

        $desiredLength = 10;
        $this->assertSame($desiredLength, mb_strlen(StringHelper::random($desiredLength)));
    }

    public function testRandomThrowsWhenTheByteSourceFails(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Random bytes error (16).');

        StringHelper::random(16, function (int $length): string {
            throw new Exception(sprintf('Random bytes error (%d).', $length));
        });
    }

    /**
     * A byte source which returns something other than bytes is rejected
     * rather than concatenated into the result. Previously unreachable from a
     * test: the seam took a function *name*, and the one the suite passed threw
     * before ever returning.
     */
    public function testRandomThrowsWhenTheByteSourceReturnsSomethingElse(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Could not generate random bytes.');

        StringHelper::random(16, fn(int $length): int => $length);
    }

    /**
     * The requested length is produced even when a batch of bytes loses most
     * of its characters to the URL-unsafe filter, which is what the loop in
     * random() is for.
     */
    public function testRandomFillsTheRequestedLengthAcrossSeveralBatches(): void
    {
        $calls = 0;

        // Base64 of "\xff\xff" is "//8=", of which everything but the "8" is
        // dropped - so one character arrives per call.
        $value = StringHelper::random(4, function (int $length) use (&$calls): string {
            ++$calls;
            return "\xff\xff";
        });

        $this->assertSame(4, mb_strlen($value));
        $this->assertSame(4, $calls);
    }
}
