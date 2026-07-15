---
title: "Caching"
description: "Read-through memory and optional APCu caching."
path: "caching"
order: 7
section: "Documentation"
meta_title: "Caching"
meta_description: "Read-through memory and optional APCu caching."
---

# Caching

storh ships with small cache backends and no runtime dependency:

```php
use Storh\Cache;
use Storh\CacheValidation;

$docs = new Storh\DocStore($root, 'pages', cache: Cache::memory(maxEntries: 10000));
$docs = new Storh\DocStore($root, 'pages', cache: Cache::apcu('storh.pages'));
$docs = new Storh\DocStore(
    $root,
    'pages',
    cache: Cache::memory(maxEntries: 100000, maxBytes: 96 * 1024 * 1024),
    cache_validation: CacheValidation::TRUST
);
```

Cache layers:

- decoded DocStore records
- missing-record negative lookups
- segmented-log manifests
- sparse segment indexes

Validation modes:

- `CacheValidation::STAT`: validate existence, mtime, and size.
- `CacheValidation::HASH`: validate existence, mtime, size, and content hash.
- `CacheValidation::TRUST`: skip filesystem validation on hits and trust the
  current storh instance or a shared cache backend to publish explicit storh
  writes.

`STAT` is the default fast validation mode. Use `HASH` when every cache hit must
also validate file contents, including same-size writes that preserve mtime.
`STAT` cannot detect an out-of-band content change that preserves both mtime and
size. `TRUST` is the fastest mode for single-process or shared-cache workloads;
it intentionally does not detect files changed outside storh.

`Cache::memory()` is bounded by entry count and by bytes. When `maxBytes` is not
provided, it derives a budget from PHP's `memory_limit`.

`Cache::apcu()` is optional. If APCu is unavailable or disabled for the current
SAPI, it behaves as a no-op backend.

## When to attach one

`DocStore` always keeps small per-instance caches for records it has already
read or written, validated per the configured mode. Attaching a backend adds
a cache that outlives the instance: `Cache::memory()` helps long-running
processes that reopen stores, and `Cache::apcu()` shares decoded records
across PHP-FPM requests. The [benchmarks](/docs/benchmarks) measure the
memory backend at about 83 µs per cold read versus 6 µs per warm,
STAT-validated hit.
