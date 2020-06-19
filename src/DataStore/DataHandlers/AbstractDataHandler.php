<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\DataHandlerInterface;
use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionDataStore;

abstract class AbstractDataHandler implements DataHandlerInterface
{
    /**
     * @var DataStoreInterface
     */
    protected DataStoreInterface $store;

    public function __construct(?DataStoreInterface $store = null)
    {
        // PHP Session Store is the default store.
        $this->store = $store ?? new PhpSessionDataStore();
    }

    /**
     * @inheritDoc
     */
    public function setStore(DataStoreInterface $store): void
    {
        $this->store = $store;
    }
}
