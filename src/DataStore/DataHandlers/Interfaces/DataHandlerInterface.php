<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;

interface DataHandlerInterface
{
    public function setStore(DataStoreInterface $store): void;
}
