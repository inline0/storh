<?php

declare(strict_types=1);

namespace Storh;

/**
 * @codeCoverageIgnore Optional backend depends on the host PHP SAPI APCu mode.
 */
final class ApcuCache implements CacheInterface
{
    public function __construct(private readonly string $prefix = 'storh')
    {
    }

    public function get(string $key): mixed
    {
        if (! $this->available()) {
            return null;
        }

        $success = false;
        $value   = apcu_fetch($this->key($key), $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, ?int $ttl_seconds = null): void
    {
        if (! $this->available()) {
            return;
        }

        apcu_store($this->key($key), $value, $ttl_seconds ?? 0);
    }

    public function delete(string $key): void
    {
        if ($this->available()) {
            apcu_delete($this->key($key));
        }
    }

    public function clear_prefix(string $prefix): void
    {
        if (! $this->available() || ! class_exists(\APCUIterator::class)) {
            return;
        }

        apcu_delete(new \APCUIterator('/^' . preg_quote($this->key($prefix), '/') . '/'));
    }

    private function key(string $key): string
    {
        return $this->prefix . ':' . $key;
    }

    private function available(): bool
    {
        return function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL);
    }
}
