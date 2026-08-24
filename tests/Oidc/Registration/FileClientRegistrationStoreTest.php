<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Registration;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Registration\FileClientRegistrationStore;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileClientRegistrationStore::class)]
final class FileClientRegistrationStoreTest extends TestCase
{
    protected string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
        'oidc-client-php-registration-store-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*');
            foreach (is_array($files) ? $files : [] as $file) {
                unlink($file);
            }

            rmdir($this->storageDirectory);
        }
    }

    protected function sut(?string $storageDirectory = null): FileClientRegistrationStore
    {
        return new FileClientRegistrationStore($storageDirectory ?? $this->storageDirectory);
    }

    /**
     * Run something that is expected to fail on a filesystem call which emits
     * a PHP warning of its own before returning false. PHPUnit fails the run
     * on any PHP warning, and here the warning is the condition under test
     * rather than a defect, so it is swallowed for the duration of the call.
     */
    protected function withSuppressedDiagnostics(Closure $callable): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $callable();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Permission-denied paths can only be provoked where POSIX permissions
     * apply and this process is actually subject to them.
     *
     * Whether they are enforced is probed rather than inferred from the user
     * id: root bypasses them, ext-posix is optional so posix_geteuid() is not
     * always there to ask, and a process can also hold only some of the
     * capabilities that do the bypassing.
     */
    protected function skipUnlessSubjectToFilePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not applicable on Windows.');
        }

        $probeFilePath = tempnam(sys_get_temp_dir(), 'oidc-client-php-permission-probe-');
        $this->assertIsString($probeFilePath);

        $readable = true;

        try {
            chmod($probeFilePath, 0o000);
            $this->withSuppressedDiagnostics(function () use ($probeFilePath, &$readable): void {
                $readable = file_get_contents($probeFilePath) !== false;
            });
        } finally {
            chmod($probeFilePath, 0o600);
            unlink($probeFilePath);
        }

        if ($readable) {
            $this->markTestSkipped('File permissions are not enforced for this process (running as root?).');
        }
    }

    /**
     * @return string Path of the single registration file in the storage directory.
     */
    protected function soleRegistrationFilePath(): string
    {
        $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*.json');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);

        return $files[0];
    }

    public function testCanCreateInstanceAndStorageDirectory(): void
    {
        $sut = $this->sut();

        $this->assertInstanceOf(FileClientRegistrationStore::class, $sut);
        $this->assertSame($this->storageDirectory, $sut->getStorageDirectory());
        $this->assertDirectoryExists($this->storageDirectory);
    }

    public function testCanSetGetAndDeleteRegistration(): void
    {
        $sut = $this->sut();
        $claims = ['client_id' => 'client-id', 'client_secret' => 'client-secret'];

        $this->assertNull($sut->get('key'));

        $sut->set('key', $claims);
        $this->assertSame($claims, $sut->get('key'));

        $sut->delete('key');
        $this->assertNull($sut->get('key'));
    }

    public function testCanOverwriteRegistration(): void
    {
        $sut = $this->sut();

        $sut->set('key', ['client_id' => 'client-id']);
        $sut->set('key', ['client_id' => 'new-client-id']);

        $this->assertSame(['client_id' => 'new-client-id'], $sut->get('key'));
    }

    public function testRegistrationsAreIsolatedPerKey(): void
    {
        $sut = $this->sut();

        $sut->set('key', ['client_id' => 'client-id']);
        $sut->set('another-key', ['client_id' => 'another-client-id']);

        $this->assertSame(['client_id' => 'client-id'], $sut->get('key'));
        $this->assertSame(['client_id' => 'another-client-id'], $sut->get('another-key'));
    }

    public function testDeleteOfNonExistentEntryIsNotAnError(): void
    {
        $this->sut()->delete('non-existent-key');

        $this->expectNotToPerformAssertions();
    }

    public function testThrowsOnInvalidFileContent(): void
    {
        $sut = $this->sut();
        $sut->set('key', ['client_id' => 'client-id']);

        $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*.json');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        file_put_contents($files[0], 'not-json');

        $this->expectException(OidcClientException::class);

        $sut->get('key');
    }

    public function testDefaultStorageDirectoryIsDedicatedPerUser(): void
    {
        $sut = new FileClientRegistrationStore();

        $this->assertStringContainsString(
            FileClientRegistrationStore::DEFAULT_DIRECTORY_NAME,
            $sut->getStorageDirectory(),
        );

        if (function_exists('posix_geteuid')) {
            $this->assertStringEndsWith('-' . posix_geteuid(), $sut->getStorageDirectory());
        }
    }

    public function testSetWritesAtomicallyAndRestrictsFilePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not applicable on Windows.');
        }

        $this->sut()->set('key', ['client_id' => 'client-id']);

        // No temporary file leftovers, only the final registration file.
        $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.json', $files[0]);

        // Contains client credentials, so access is restricted to the owner.
        $this->assertSame(0o600, fileperms($files[0]) & 0o777);
    }

    public function testTightensPermissiveStorageDirectoryPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not applicable on Windows.');
        }

        mkdir($this->storageDirectory, 0777, true);
        chmod($this->storageDirectory, 0777);

        $this->sut();

        clearstatcache(true, $this->storageDirectory);
        $this->assertSame(0o700, fileperms($this->storageDirectory) & 0o777);
    }

    public function testRejectsSymlinkedStorageDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic link handling is not applicable on Windows.');
        }

        $realDirectory = $this->storageDirectory . '-real';
        mkdir($realDirectory, 0700, true);
        symlink($realDirectory, $this->storageDirectory);

        try {
            $this->expectException(OidcClientException::class);
            $this->expectExceptionMessage('symbolic link');
            $this->sut();
        } finally {
            unlink($this->storageDirectory);
            rmdir($realDirectory);
        }
    }

    public function testThrowsWhenStorageDirectoryCannotBeCreated(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'oidc-client-php-registration-store-test-');
        $this->assertIsString($file);

        try {
            $this->expectException(OidcClientException::class);
            $this->expectExceptionMessage('Could not create client registration storage directory');

            // A regular file stands where a parent directory would have to be,
            // so the storage directory can not be created.
            $this->withSuppressedDiagnostics(
                fn(): FileClientRegistrationStore => $this->sut($file . DIRECTORY_SEPARATOR . 'registrations'),
            );
        } finally {
            unlink($file);
        }
    }

    public function testThrowsOnFileContentThatIsNotAClaimSet(): void
    {
        $sut = $this->sut();
        $sut->set('key', ['client_id' => 'client-id']);

        // Valid JSON, but a scalar rather than the expected claim set.
        file_put_contents($this->soleRegistrationFilePath(), '123');

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Unexpected client registration file content');

        $sut->get('key');
    }

    public function testThrowsWhenClaimsCannotBeEncoded(): void
    {
        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Could not encode client registration claims');

        // Claims come from the OP's client registration response, so a value
        // which is not valid UTF-8 is not merely hypothetical.
        $this->sut()->set('key', ['client_name' => "\xB1\x31"]);
    }

    public function testThrowsWhenRegistrationFileCannotBeRead(): void
    {
        $this->skipUnlessSubjectToFilePermissions();

        $sut = $this->sut();
        $sut->set('key', ['client_id' => 'client-id']);

        $filePath = $this->soleRegistrationFilePath();
        chmod($filePath, 0o000);

        try {
            $this->expectException(OidcClientException::class);
            $this->expectExceptionMessage('Could not read client registration file');

            $this->withSuppressedDiagnostics(fn(): ?array => $sut->get('key'));
        } finally {
            chmod($filePath, 0o600);
        }
    }

    public function testThrowsWhenRegistrationFileCannotBeWritten(): void
    {
        $this->skipUnlessSubjectToFilePermissions();

        $sut = $this->sut();
        chmod($this->storageDirectory, 0o500);

        try {
            $this->expectException(OidcClientException::class);
            $this->expectExceptionMessage('Could not write client registration file');

            $this->withSuppressedDiagnostics(fn(): null => $sut->set('key', ['client_id' => 'client-id']));
        } finally {
            chmod($this->storageDirectory, 0o700);
        }
    }

    public function testCleansUpTemporaryFileWhenRegistrationFileCannotBeReplaced(): void
    {
        $sut = $this->sut();
        $sut->set('key', ['client_id' => 'client-id']);

        // Put a directory exactly where the registration file belongs, so the
        // atomic rename of the temporary file into place can not succeed.
        $filePath = $this->soleRegistrationFilePath();
        unlink($filePath);
        mkdir($filePath);

        try {
            $this->withSuppressedDiagnostics(fn(): null => $sut->set('key', ['client_id' => 'client-id']));
            $this->fail('Expected the registration file write to fail.');
        } catch (OidcClientException $oidcClientException) {
            $this->assertStringContainsString(
                'Could not write client registration file',
                $oidcClientException->getMessage(),
            );

            // The temporary file must not survive the failure.
            $this->assertSame([], glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*.tmp'));
        } finally {
            rmdir($filePath);
        }
    }

    public function testThrowsWhenRegistrationFileCannotBeDeleted(): void
    {
        $this->skipUnlessSubjectToFilePermissions();

        $sut = $this->sut();
        $sut->set('key', ['client_id' => 'client-id']);

        chmod($this->storageDirectory, 0o500);

        try {
            $this->expectException(OidcClientException::class);
            $this->expectExceptionMessage('Could not delete client registration file');

            $this->withSuppressedDiagnostics(fn(): null => $sut->delete('key'));
        } finally {
            chmod($this->storageDirectory, 0o700);
        }
    }

    public function testThrowsOnNonWritableStorageDirectory(): void
    {
        mkdir($this->storageDirectory, 0500, true);

        // Skip if running as root (root can write anywhere).
        if (is_writable($this->storageDirectory)) {
            chmod($this->storageDirectory, 0700);
            $this->markTestSkipped('Storage directory is writable regardless of permissions (running as root?).');
        }

        try {
            $this->expectException(OidcClientException::class);
            $this->sut();
        } finally {
            chmod($this->storageDirectory, 0700);
        }
    }
}
