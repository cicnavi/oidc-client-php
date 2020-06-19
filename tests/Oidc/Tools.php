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
            if ('.' === $file || '..' === $file) {
                continue;
            }
            if (is_dir("$dir/$file")) {
                self::rmdirRecursive("$dir/$file");
            } else {
                unlink("$dir/$file");
            }
        }

        rmdir($dir);
    }
}
