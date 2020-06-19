<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;

class PhpSessionDataStore implements DataStoreInterface
{
    /**
     * PhpSessionDataStore constructor.
     * @throws OidcClientException If PHP session could not be started.
     */
    public function __construct()
    {
        $this->startSession();
    }

    /**
     * @throws OidcClientException If PHP session could not be started.
     *
     * @codeCoverageIgnore
     */
    protected function startSession(): void
    {
        // phpunit testing won't work without this
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];
            return;
        }

        if (headers_sent()) {
            throw new OidcClientException('Session start error - headers already sent.');
        }

        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @inheritDoc
     */
    public function exists(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        if ($this->exists($key)) {
            return $_SESSION[$key];
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
