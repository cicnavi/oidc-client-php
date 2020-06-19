<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Helpers;

use Cicnavi\Oidc\Helpers\StringHelper;
use Exception;
use PHPUnit\Framework\TestCase;

class StringHelperTest extends TestCase
{
    public function testRandom(): void
    {
        $this->assertNotEquals(StringHelper::random(), StringHelper::random());

        $desiredLength = 10;
        $this->assertEquals($desiredLength, mb_strlen(StringHelper::random($desiredLength)));
    }

    public function testRandomThrows(): void
    {
        $this->expectException(Exception::class);
        StringHelper::random(16, __NAMESPACE__ . '\StringHelperTest::randomBytes');
    }

    public function testRandomThrowsForInvalidCallback(): void
    {
        $this->expectException(Exception::class);
        StringHelper::random(16, 'invalid-callback');
    }

    /**
     * Mock function for generate_bytes to simulate exception throw.
     * @param $length
     * @throws Exception
     */
    public static function randomBytes(int $length): void
    {
        throw new Exception(sprintf('Random bytes error (%s).', $length));
    }
}
