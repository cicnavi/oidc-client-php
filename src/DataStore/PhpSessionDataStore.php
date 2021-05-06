<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\DataStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\HttpHelper;

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
     */
    protected function startSession(): void
    {
        // phpunit testing won't work without this
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];
            return;
        }

        if (session_status() == PHP_SESSION_ACTIVE) {
            return;
        }

        $this->validatePhpSession();

        $cookieParams = HttpHelper::normalizeSessionCookieParams(session_get_cookie_params());

        if (! session_set_cookie_params($cookieParams)) {
            throw new OidcClientException('Could not set session cookie params.');
        }

        if (! session_start()) {
            throw new OidcClientException('Could not start PHP session.');
        }
    }

    /**
     * @throws OidcClientException
     */
    protected function validatePhpSession(): void
    {
        if (headers_sent()) {
            throw new OidcClientException('Session start error - headers already sent.');
        }

        if (session_status() == PHP_SESSION_DISABLED) {
            throw new OidcClientException('Can not use PHP Session since PHP sessions are disabled.');
        }
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
    public function exists(string $key): bool
    {
        return isset($_SESSION[$key]);
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
