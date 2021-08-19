<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Cache;

use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use Cicnavi\SimpleFileCache\SimpleFileCache;

class FileCache extends SimpleFileCache
{

    /**
     * FileCache constructor.
     * @param string $cacheName
     * @param string|null $storagePath
     * @throws CacheException
     */
    public function __construct(string $cacheName = 'oidc-client-php-cache', string $storagePath = null)
    {
        parent::__construct($cacheName, $storagePath);
    }
}
