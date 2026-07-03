<?php

declare(strict_types=1);

namespace Storh\Tests\Support;

final class TestFilesystem
{
    public static function remove_path(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
