<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

class Tools
{
    public static function showMessage(string $message): void
    {
        fwrite(STDERR, print_r($message . "\n", true));
    }

    public static function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $file) {
            if ('.' === $file) {
                continue;
            }

            if ('..' === $file) {
                continue;
            }

            if (is_dir(sprintf('%s/%s', $dir, $file))) {
                self::rmdirRecursive(sprintf('%s/%s', $dir, $file));
            } else {
                unlink(sprintf('%s/%s', $dir, $file));
            }
        }

        rmdir($dir);
    }
}
