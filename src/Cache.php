<?php

declare(strict_types=1);

namespace Storh;

final class Cache
{
    public static function memory(int $maxEntries = 10000): CacheInterface
    {
        return new MemoryCache($maxEntries);
    }

    public static function apcu(string $prefix = 'storh'): CacheInterface
    {
        return new ApcuCache($prefix);
    }

    public static function null(): CacheInterface
    {
        return new NullCache();
    }
}
