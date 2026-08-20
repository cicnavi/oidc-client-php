<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore;

use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;

/**
 * Session store backed by a plain array, for the lifetime of the object.
 *
 * For contexts where a PHP session is unavailable or unwanted: a CLI entry
 * point, a worker process, or a test suite. Nothing it holds outlives the
 * object, so it is not a stand-in for PhpSessionStore in a web application -
 * a login persisted here is gone by the next request.
 *
 * Its semantics deliberately match PhpSessionStore's, including that a value
 * stored as null reads back as absent (both are 'isset' semantics), so that
 * swapping one store for the other cannot change behaviour.
 *
 * @see \Cicnavi\Tests\Oidc\DataStore\ArraySessionStoreTest
 */
class ArraySessionStore implements SessionStoreInterface
{
    /**
     * @param mixed[] $data Initial contents, keyed as the store's keys. Handy
     * for arranging a test, or for seeding a worker from state carried in by
     * some other means.
     */
    public function __construct(protected array $data = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function exists(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}
