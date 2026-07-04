<?php

declare(strict_types=1);

namespace Storh\Tests\Support;

final class FaultyStreamWrapper
{
    public const SCHEME = 'storhfault';

    public mixed $context;

    private string $mode = '';

    private int $position = 0;

    public static function register(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function path(string $mode): string
    {
        return self::SCHEME . '://' . $mode;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $host = parse_url($path, PHP_URL_HOST);
        $this->mode = is_string($host) ? $host : '';
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ('read-fail' === $this->mode) {
            return false;
        }

        return '';
    }

    public function stream_write(string $data): int
    {
        return 'write-fail' === $this->mode ? 0 : strlen($data);
    }

    public function stream_flush(): bool
    {
        return 'flush-fail' !== $this->mode;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if (SEEK_END === $whence) {
            $this->position = 10 + $offset;
            return true;
        }

        if (SEEK_CUR === $whence) {
            $this->position += $offset;
            return true;
        }

        $this->position = $offset;

        return true;
    }

    /**
     * @return array<string|int, int>
     */
    public function stream_stat(): array
    {
        return $this->stat();
    }

    /**
     * @return array<string|int, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return $this->stat();
    }

    /**
     * @return array<string|int, int>
     */
    private function stat(): array
    {
        $mode = 0100000 | 0644;

        return array(
            2 => $mode,
            7 => 10,
            'mode' => $mode,
            'size' => 10,
        );
    }
}
