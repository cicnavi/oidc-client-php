<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;

interface DataHandlerInterface
{
    /**
     * @param DataStoreInterface $store
     */
    public function setStore(DataStoreInterface $store): void;
}
