<?php

declare(strict_types=1);

namespace Storh;

final class MemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int|null, tick: int}> */
    private array $items = array();

    private int $tick = 0;

    public function __construct(private readonly int $max_entries = 10000)
    {
        if ($this->max_entries < 1) {
            throw new StorageException('Memory cache max entries must be at least 1.');
        }
    }

    public function get(string $key): mixed
    {
        $item = $this->items[ $key ] ?? null;
        if (null === $item) {
            return null;
        }

        if (null !== $item['expires'] && $item['expires'] < time()) {
            unset($this->items[ $key ]);
            return null;
        }

        $this->items[ $key ]['tick'] = ++$this->tick;

        return $item['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl_seconds = null): void
    {
        $this->items[ $key ] = array(
            'value'   => $value,
            'expires' => null === $ttl_seconds ? null : time() + max(1, $ttl_seconds),
            'tick'    => ++$this->tick,
        );

        $this->evict();
    }

    public function delete(string $key): void
    {
        unset($this->items[ $key ]);
    }

    public function clear_prefix(string $prefix): void
    {
        foreach (array_keys($this->items) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->items[ $key ]);
            }
        }
    }

    private function evict(): void
    {
        while (count($this->items) > $this->max_entries) {
            $oldest_key = array_key_first($this->items);
            // @codeCoverageIgnoreStart
            if (! is_string($oldest_key)) {
                return;
            }
            // @codeCoverageIgnoreEnd
            $oldest_tick = $this->items[ $oldest_key ]['tick'];

            foreach ($this->items as $key => $item) {
                if ($item['tick'] < $oldest_tick) {
                    $oldest_key  = $key;
                    $oldest_tick = $item['tick'];
                }
            }

            unset($this->items[ $oldest_key ]);
        }
    }
}
