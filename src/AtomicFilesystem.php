<?php

declare(strict_types=1);

namespace Storh;

final class AtomicFilesystem
{
    public static function ensure_directory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new StorageException('Could not create storage directory: ' . $directory);
        }
    }

    public static function write_atomic(string $path, string $contents): void
    {
        self::ensure_directory(dirname($path));
        $temp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $handle = @fopen($temp, 'wb');
        if (false === $handle) {
            throw new StorageException('Could not open temporary storage file for writing: ' . $temp);
        }

        try {
            self::write_all($handle, $contents, $temp);
            fflush($handle);
        } finally {
            fclose($handle);
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new StorageException('Could not atomically replace storage file: ' . $path);
        }
    }

    /**
     * @param resource $handle
     */
    public static function write_all(mixed $handle, string $contents, string $path): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if (false === $written || 0 === $written) {
                throw new StorageException('Could not write storage file: ' . $path);
            }
            $offset += $written;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_jsonc_object(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new StorageException('Could not read storage file: ' . $path);
        }

        try {
            return Jsonc::decode_object($contents);
        } catch (\JsonException $exception) {
            throw new StorageException('Invalid JSONC storage file: ' . $path, 0, $exception);
        }
    }

    public static function cleanup_temp_files(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob(rtrim($directory, '/\\') . '/.*.tmp') ?: array() as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
