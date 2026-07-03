<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\Registration;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Registration\FileClientRegistrationStore;
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
