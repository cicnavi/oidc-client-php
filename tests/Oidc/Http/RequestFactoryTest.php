<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Http;

use Cicnavi\Oidc\Http\RequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RequestFactoryTest extends TestCase
{
    public function testCreateRequest(): void
    {
        $this->assertInstanceOf(
            RequestInterface::class,
            (new RequestFactory())->createRequest('GET', 'https://example.org')
        );
    }
}
