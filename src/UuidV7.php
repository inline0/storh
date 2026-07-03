<?php

declare(strict_types=1);

namespace Storh;

final class UuidV7
{
    private static int $last_timestamp_ms = -1;

    private static string $last_entropy = '';

    public static function generate(?int $timestamp_ms = null): string
    {
        $timestamp_ms ??= self::now_ms();

        if ($timestamp_ms < 0 || $timestamp_ms > 0xffffffffffff) {
            throw new StorageException('UUIDv7 timestamp is outside the 48-bit range.');
        }

        if ($timestamp_ms === self::$last_timestamp_ms && '' !== self::$last_entropy) {
            self::$last_entropy = self::increment_entropy(self::$last_entropy);
        } else {
            self::$last_timestamp_ms = $timestamp_ms;
            self::$last_entropy      = random_bytes(10);
        }

        $time_hex    = str_pad(dechex($timestamp_ms), 12, '0', STR_PAD_LEFT);
        $entropy_hex = bin2hex(self::$last_entropy);
        $rand_a      = hexdec(substr($entropy_hex, 0, 4)) & 0x0fff;
        $variant     = ( hexdec(substr($entropy_hex, 4, 4)) & 0x3fff ) | 0x8000;
        $tail        = substr($entropy_hex, 8, 12);

        $hex = $time_hex
            . str_pad(dechex(0x7000 | $rand_a), 4, '0', STR_PAD_LEFT)
            . str_pad(dechex($variant), 4, '0', STR_PAD_LEFT)
            . $tail;

        return self::format_hex($hex);
    }

    public static function is_valid(string $uuid): bool
    {
        return 1 === preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            strtolower($uuid)
        );
    }

    public static function timestamp_ms(string $uuid): int
    {
        self::assert_valid($uuid);

        return (int) hexdec(substr(str_replace('-', '', strtolower($uuid)), 0, 12));
    }

    public static function min_for_timestamp_ms(int $timestamp_ms): string
    {
        return self::format_hex(str_pad(dechex($timestamp_ms), 12, '0', STR_PAD_LEFT) . '70008000000000000000');
    }

    public static function max_for_timestamp_ms(int $timestamp_ms): string
    {
        return self::format_hex(str_pad(dechex($timestamp_ms), 12, '0', STR_PAD_LEFT) . '7fffbfffffffffffffff');
    }

    public static function assert_valid(string $uuid): void
    {
        if (! self::is_valid($uuid)) {
            throw new StorageException('Invalid UUIDv7 value.');
        }
    }

    public static function reset_for_tests(): void
    {
        self::$last_timestamp_ms = -1;
        self::$last_entropy      = '';
    }

    private static function now_ms(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private static function increment_entropy(string $entropy): string
    {
        for ($index = strlen($entropy) - 1; $index >= 0; $index--) {
            $byte = ord($entropy[ $index ]);
            if ($byte < 255) {
                $entropy[ $index ] = chr($byte + 1);
                return $entropy;
            }

            $entropy[ $index ] = "\0";
        }

        return $entropy;
    }

    private static function format_hex(string $hex): string
    {
        $hex = strtolower($hex);

        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }
}
