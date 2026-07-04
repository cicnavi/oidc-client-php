<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\Registration;

use Cicnavi\Oidc\Registration\Interfaces\ClientRegistrationStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Throwable;

/**
 * File-based client registration store. Each registration is persisted as a
 * JSON file in the storage directory. The storage directory is validated to
 * be private to the current user (not a symbolic link, owned by the current
 * user, no group / other permissions), and files are written atomically,
 * since a directory in a shared location (like the system temp directory)
 * could otherwise be tampered with by other local users. Intended as a
 * simple default; for production deployments consider providing a
 * database-backed implementation of ClientRegistrationStoreInterface, and
 * make sure the storage directory is durable (not subject to periodic
 * cleanup like the system temp directory) and properly protected, since it
 * will contain client credentials.
 * @see \Cicnavi\Tests\Oidc\Registration\FileClientRegistrationStoreTest
 */
class FileClientRegistrationStore implements ClientRegistrationStoreInterface
{
    public const DEFAULT_DIRECTORY_NAME = 'oidc-client-php-registrations';

    protected readonly string $storageDirectory;

    /**
     * @param ?string $storageDirectory Directory in which to store client
     * registration files. Defaults to a dedicated per-user directory in the
     * system temp directory.
     * @throws OidcClientException If the storage directory could not be
     * prepared, or can not be considered private to the current user.
     */
    public function __construct(
        ?string $storageDirectory = null,
    ) {
        $this->storageDirectory = $storageDirectory ?? $this->buildDefaultStorageDirectory();

        $this->prepareStorageDirectory();
    }

    public function getStorageDirectory(): string
    {
        return $this->storageDirectory;
    }

    /**
     * Default storage directory - dedicated per (effective) user, so
     * different local users using the default location get their own
     * (ownership-validated) directory instead of clashing on a shared one.
     */
    protected function buildDefaultStorageDirectory(): string
    {
        $userId = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::DEFAULT_DIRECTORY_NAME .
        (is_int($userId) ? '-' . $userId : '');
    }

    /**
     * @throws OidcClientException If the storage directory could not be
     * prepared, or can not be considered private to the current user.
     */
    protected function prepareStorageDirectory(): void
    {
        if (
            (!is_dir($this->storageDirectory)) &&
            (!mkdir($this->storageDirectory, 0o700, true)) &&
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

        $this->validateStorageDirectoryPrivacy();
    }

    /**
     * Ensure the storage directory is private to the current user: not a
     * symbolic link, owned by the current (effective) user, and not
     * accessible to group / others (tightened when possible). The directory
     * will contain client credentials, and a pre-existing directory in a
     * shared location (like the system temp directory) could otherwise be
     * controlled by another local user. Not applicable on Windows, where
     * POSIX ownership and permission semantics are not available.
     *
     * @throws OidcClientException If the storage directory can not be
     * considered private to the current user.
     */
    protected function validateStorageDirectoryPrivacy(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $this->storageDirectory);

        if (is_link($this->storageDirectory)) {
            throw new OidcClientException(
                sprintf(
                    'Client registration storage directory (%s) is a symbolic link, refusing to use it.',
                    $this->storageDirectory,
                ),
            );
        }

        $userId = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $owner = fileowner($this->storageDirectory);

        if (!is_int($owner) || (is_int($userId) && $owner !== $userId)) {
            throw new OidcClientException(
                sprintf(
                    'Client registration storage directory (%s) is not owned by the current user, ' .
                    'refusing to use it.',
                    $this->storageDirectory,
                ),
            );
        }

        $permissions = fileperms($this->storageDirectory);

        if (!is_int($permissions)) {
            throw new OidcClientException(
                sprintf(
                    'Could not check client registration storage directory permissions (%s).',
                    $this->storageDirectory,
                ),
            );
        }

        // No group / other access - the directory will contain client credentials.
        if ((($permissions & 0o077) !== 0) && (!chmod($this->storageDirectory, 0o700))) {
            throw new OidcClientException(
                sprintf(
                    'Client registration storage directory (%s) is accessible to other users, and its ' .
                    'permissions could not be restricted.',
                    $this->storageDirectory,
                ),
            );
        }
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

        // Write to a temporary file with an unpredictable name first, then
        // atomically move it into place. This prevents partial reads, and
        // prevents writing through a symbolic link planted at the
        // (predictable) file path - rename() replaces such a link instead of
        // following it.
        try {
            $temporaryFilePath = $filePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        } catch (Throwable $throwable) {
            throw new OidcClientException(
                'Could not generate temporary client registration file name. ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable,
            );
        }

        if (file_put_contents($temporaryFilePath, $fileContent) === false) {
            throw new OidcClientException(
                sprintf('Could not write client registration file (%s).', $temporaryFilePath),
            );
        }

        // Contains client credentials, so restrict access to the owner
        // before moving into place.
        if ((!chmod($temporaryFilePath, 0o600)) || (!rename($temporaryFilePath, $filePath))) {
            unlink($temporaryFilePath);
            throw new OidcClientException(
                sprintf('Could not write client registration file (%s).', $filePath),
            );
        }
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
