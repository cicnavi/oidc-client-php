<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\StateNonceDataHandlerInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\StringHelper;

class StateNonce extends AbstractDataHandler implements StateNonceDataHandlerInterface
{
    /**
     * @var string State key used for session storage.
     */
    public const STATE_KEY = 'OIDC_STATE_PARAMETER';

    /**
     * @var string Nonce key used for session storage.
     */
    public const NONCE_KEY = 'OIDC_NONCE_PARAMETER';

    /**
     * @var string[]
     */
    protected static array $validParameterKeys = [
        self::STATE_KEY,
        self::NONCE_KEY
    ];

    /**
     * @inheritDoc
     */
    public function get(string $key, int $length = 40): string
    {
        $this->validateParameterKey($key);

        if (($value = $this->store->get($key)) !== null) {
            return $value;
        }

        $value = StringHelper::random($length);

        $this->store->put($key, $value);

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function verify(string $key, string $value): void
    {
        $this->validateParameterKey($key);

        if (! $this->store->exists($key)) {
            throw new OidcClientException(sprintf('Value not present in store for key %s', $key));
        }

        if (! hash_equals($this->get($key), $value)) {
            throw new OidcClientException(sprintf('Parameter not valid (%s)', $key));
        }

        // It is verified, so we can safely delete it, and in future requests use another.
        // For state parameter this will also prevent users to use the 'back' button in browsers and request
        // another token with the same auth code (saves a request to auth server).
        $this->remove($key);
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): void
    {
        $this->validateParameterKey($key);

        $this->store->delete($key);
    }

    /**
     * @param string $key
     * @throws OidcClientException If the given key is not valid.
     */
    protected function validateParameterKey(string $key): void
    {
        if (! in_array($key, self::$validParameterKeys)) {
            throw new OidcClientException(sprintf('Parameter key is not valid. (%s)', $key));
        }
    }
}
