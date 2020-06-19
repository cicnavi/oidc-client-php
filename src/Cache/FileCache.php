<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Cache;

use Cicnavi\SimpleFileCache\Exceptions\CacheException;
use Cicnavi\SimpleFileCache\SimpleFileCache;

class FileCache extends SimpleFileCache
{

    /**
     * FileCache constructor.
     * @param string $storagePath
     * @param string $cacheName
     * @throws CacheException
     */
    public function __construct(string $storagePath, string $cacheName = 'oidc-cache')
    {
        parent::__construct($storagePath, $cacheName);
    }
}
