<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\DataHandlerInterface;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\DataStore\PhpSessionStore;

abstract class AbstractDataHandler implements DataHandlerInterface
{
    protected SessionStoreInterface $sessionStore;

    public function __construct(?SessionStoreInterface $sessionStore = null)
    {
        // PHP Session Store is the default store.
        $this->sessionStore = $sessionStore ?? new PhpSessionStore();
    }

    /**
     * @inheritDoc
     */
    public function setSessionStore(SessionStoreInterface $sessionStore): void
    {
        $this->sessionStore = $sessionStore;
    }
}
