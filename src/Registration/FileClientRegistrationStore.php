<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Registration;

use Cicnavi\Oidc\Registration\Interfaces\ClientRegistrationStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Throwable;

/**
 * File-based client registration store. Each registration is persisted as a
 * JSON file in the storage directory. Intended as a simple default; for
 * production deployments consider providing a database-backed implementation
 * of ClientRegistrationStoreInterface, and make sure the storage directory
 * is durable (not subject to periodic cleanup like the system temp
 * directory) and properly protected, since it will contain client
 * credentials.
 * @see \Cicnavi\Tests\Oidc\Registration\FileClientRegistrationStoreTest
 */
class FileClientRegistrationStore implements ClientRegistrationStoreInterface
{
    public const DEFAULT_DIRECTORY_NAME = 'oidc-client-php-registrations';

    protected readonly string $storageDirectory;

    /**
     * @param ?string $storageDirectory Directory in which to store client
     * registration files. Defaults to a dedicated directory in the system
     * temp directory.
     * @throws OidcClientException If the storage directory could not be
     * prepared.
     */
    public function __construct(
        ?string $storageDirectory = null,
    ) {
        $this->storageDirectory = $storageDirectory ??
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::DEFAULT_DIRECTORY_NAME;

        if (
            (!is_dir($this->storageDirectory)) &&
            (!mkdir($this->storageDirectory, 0700, true)) &&
            (!is_dir($this->storageDirectory))
        ) {
            throw new OidcClientException(
                sprintf('Could not create client registration storage directory (%s).', $this->storageDirectory),
            );
        }

        if (!is_writable($this->storageDirectory)) {
            throw new OidcClientException(
                sprintf('Client registration storage directory is not writable (%s).', $this->storageDirectory),
            );
        }
    }

    public function getStorageDirectory(): string
    {
        return $this->storageDirectory;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): ?array
    {
        $filePath = $this->resolveFilePathForKey($key);

        if (!is_file($filePath)) {
            return null;
        }

        $fileContent = file_get_contents($filePath);
        if (!is_string($fileContent)) {
            throw new OidcClientException(
                sprintf('Could not read client registration file (%s).', $filePath),
            );
        }

        try {
            $claims = json_decode($fileContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                sprintf('Could not decode client registration file (%s). %s', $filePath, $throwable->getMessage()),
                $throwable->getCode(),
                $throwable,
            );
        }

        if (!is_array($claims)) {
            throw new OidcClientException(
                sprintf('Unexpected client registration file content (%s).', $filePath),
            );
        }

        return $claims;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, array $claims): void
    {
        $filePath = $this->resolveFilePathForKey($key);

        try {
            $fileContent = json_encode($claims, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'Could not encode client registration claims. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }

        if (file_put_contents($filePath, $fileContent, LOCK_EX) === false) {
            throw new OidcClientException(
                sprintf('Could not write client registration file (%s).', $filePath),
            );
        }

        // Contains client credentials, so restrict access to the owner.
        chmod($filePath, 0600);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        $filePath = $this->resolveFilePathForKey($key);

        if (is_file($filePath) && (!unlink($filePath))) {
            throw new OidcClientException(
                sprintf('Could not delete client registration file (%s).', $filePath),
            );
        }
    }

    protected function resolveFilePathForKey(string $key): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
