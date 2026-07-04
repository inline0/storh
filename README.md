<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./docs/public/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./docs/public/logo-light.svg">
    <img alt="storh" src="./docs/public/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  File-first records for PHP: JSONC documents, append-only segmented logs, and log-backed queues.
</p>

<p align="center">
  <a href="https://github.com/inline0/storh/actions/workflows/ci.yml"><img src="https://github.com/inline0/storh/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/storh/storh"><img src="https://img.shields.io/packagist/v/storh/storh.svg" alt="Packagist"></a>
  <a href="https://github.com/inline0/storh/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="license"></a>
</p>

---

## What is storh?

storh is a standalone, framework-agnostic storage layer for PHP applications
that want durable local records without a database server. It provides:

- a JSONC document store with one file per record
- an append-only segmented log with cursor and time-range reads
- an append-only log-backed queue
- Prisma/Drizzle-style fluent querying, secondary indexes, schema validation,
  caching, bulk JSONL import/export, maintenance APIs, benchmarks, and a CLI
- UUIDv7 ids, UUID-tail sharding, atomic writes, torn-write recovery, retention,
  and compaction

The caller provides a base directory. storh does not discover application paths
or depend on a framework.

## Quick Start

```bash
composer require storh/storh
```

```php
use Storh\Cache;
use Storh\DocStore;
use Storh\Queue;
use Storh\Schema;
use Storh\SegmentedLog;
use Storh\StorageRoot;

$root = StorageRoot::resolve(__DIR__ . '/var/storh', 'app');

$schema = Schema::collection('pages')
    ->string('slug')->unique()
    ->string('kind')->index()
    ->int('publishedAt')->range()
    ->required(['slug', 'kind']);

$docs = new DocStore($root, 'pages', cache: Cache::memory(), schema: $schema);
$home = $docs->put([
    'slug' => 'home',
    'kind' => 'page',
    'title' => 'Home',
    'publishedAt' => time(),
]);

echo $docs->get($home->id())?->data()['title'];

$pages = $docs
    ->query()
    ->where('kind')->eq('page')
    ->where('publishedAt')->gte(time() - 86400)
    ->orderBy('publishedAt', 'desc')
    ->limit(50)
    ->get();

$events = new SegmentedLog($root, 'events');
$events->appendMany([[
    'type' => 'page.saved',
    'pageId' => $home->id(),
]]);

$queue = new Queue($root, 'jobs');
$queue->enqueue(['task' => 'render', 'pageId' => $home->id()]);

$job = $queue->claim();
if (null !== $job) {
    $queue->complete($job->id());
}
```

## Engines

`DocStore` writes each record as a JSONC object under a UUID-tail-sharded
path. It is best for point reads and modest field scans. `putStream()` ingests
large iterables without retaining returned record objects.

`SegmentedLog` appends records to length and checksum guarded NDJSON segments.
It is best for append-heavy workflows, cursor pagination, time-range scans, and
compaction. `appendStream()` ingests large iterables without retaining returned
record objects.

`Queue` stores job events in an append-only log and keeps pending, processing,
and done state in memory. Claims, completions, requeues, and purges append
bounded events instead of creating one file per job. Bulk enqueue, claim, and
complete methods reduce lock and flush cost for large queues.

## Durability & Concurrency

storh writes data files in place-specific temporary files and publishes them
with an atomic rename inside the target directory. Reopen and `repair()` clean
abandoned temp files whose owner process is gone, while leaving live writer temp
files alone.

`SegmentedLog` and `Queue` use length and checksum guarded log lines. On reopen
or repair, torn tails are truncated to the last committed event and in-memory
state is rebuilt from durable log contents. `SegmentedLog::verify()` compares
the current derived state index with a fresh segment replay, and
`Queue::verify()` compares in-memory queue state with a full durable log replay,
so stale or externally modified state is reported. Log and queue writes are
serialized with filesystem locks.

`SegmentedLog` compaction writes new `compact-*` segment files before swapping
the manifest. If a process exits before that swap, reopen discards unreferenced
compaction output and keeps replaying the old manifest segments. Completed
compactions leave old sealed segments in place so readers that already opened
them stay valid.

`DocStore` mutations are serialized with a collection-level write lock, so
record files and secondary indexes update as one consistency boundary across
processes. Concurrent writes to distinct record ids are safe under the same
filesystem atomic-rename assumptions. Concurrent writes to the same id are
last-rename-wins.

`DocStore::verify()` checks record parseability and secondary-index drift
against a full record scan. `repair()` moves corrupt record files into
`.storh/corrupt/` for inspection and rebuilds indexes from the remaining valid
records.

storh flushes and fsyncs file handles before rename or append completion, and
attempts to fsync parent directories after atomic renames. Power-loss durability
still depends on the underlying filesystem and mount options honoring those
syncs.

## Cache Validation

`STAT` cache validation checks record existence, mtime, and size. `HASH` also
checks file contents and catches same-size rewrites that preserve mtime. `TRUST`
skips filesystem validation and is intended for single-process or shared-cache
workloads where explicit storh writes publish the newest value. Files changed
outside storh are not detected by `TRUST`, and same-stat content edits require
`HASH`.

## Querying and Indexes

`DocStore` exposes a fluent query builder:

```php
$docs
    ->query()
    ->where('slug')->prefix('ho')
    ->orWhere(fn ($q) => $q->where('kind')->eq('post'))
    ->orderBy('id')
    ->page(100)
    ->get();
```

Indexes are file-backed and rebuildable:

```php
$docs->indexes()
    ->field('slug')->unique()
    ->field('kind')
    ->field('publishedAt')->range()
    ->sync();

$docs->reindex();
$docs->query()->where('slug')->eq('home')->explain();
```

Two `eq` predicates on non-unique equality-indexed fields use automatic
compound buckets, so common filters like `kind = page AND bucket = 4` avoid
intersecting large single-field result sets.

## Operations

```bash
vendor/bin/storh stats var/storh pages doc
vendor/bin/storh verify var/storh events log
vendor/bin/storh compact var/storh events log
vendor/bin/storh reindex var/storh pages

composer bench
composer bench -- --dataset=100000 --engine=doc
composer bench -- --dataset=100000 --engine=cache --cache-validation=trust
composer bench -- --dataset=100000 --engine=filter
composer bench:repeat -- --dataset=1000000 --engine=filter --repeat=5 --memory-limit=512M
composer bench:range -- --datasets=1000,10000,50000,100000
composer bench:compare build/bench-main.json build/bench-current.json
composer bench:gate -- build/bench-main.json build/bench-current.json --threshold=10 --metric=doc.put --metric=log.stream
composer bench:ci
```

## API Stability

For 0.0.1, the documented API is the surface shown in the README and docs.
Some classes expose extra public methods so storh engines can cooperate
internally; treat those as implementation details unless they are documented.
That keeps future performance work focused on internal storage, indexing,
caching, and query-planner improvements without changing user-facing calls.

## Scaling & Limits

- Point access is effectively unbounded with sharding and a filesystem that can
  handle the file count.
- Segmented-log scan and range reads are comfortable into roughly 1M records.
- Per-file field scans degrade past roughly 10k records.
- storh is not for ad-hoc relational queries, joins, or analytical filtering
  over many fields.

## License

MIT © inline0.
