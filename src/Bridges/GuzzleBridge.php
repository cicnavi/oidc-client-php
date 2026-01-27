<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Bridges;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

class GuzzleBridge
{
    /**
     * @param callable|float|bool|\Iterator|int|string|StreamInterface|null $resource Entity body data
     * @param array{size?: int, metadata?: mixed[]} $options Additional options
     */
    public function psr7StreamFor(
        callable|float|StreamInterface|bool|\Iterator|int|string|null $resource = '',
        array $options = [],
    ): StreamInterface {
        return Utils::streamFor($resource, $options);
    }
}
