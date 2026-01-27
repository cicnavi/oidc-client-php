<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;

interface DataHandlerInterface
{
    public function setSessionStore(SessionStoreInterface $sessionStore): void;
}
