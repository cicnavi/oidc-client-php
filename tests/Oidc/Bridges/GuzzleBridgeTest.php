<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Bridges;

use Cicnavi\Oidc\Bridges\GuzzleBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

#[CoversClass(GuzzleBridge::class)]
final class GuzzleBridgeTest extends TestCase
{
    protected function sut(): GuzzleBridge
    {
        return new GuzzleBridge();
    }

    public function testPsr7StreamFor(): void
    {
        $this->assertInstanceOf(StreamInterface::class, $this->sut()->psr7StreamFor());
    }
}
