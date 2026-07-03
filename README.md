<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./docs/public/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./docs/public/logo-light.svg">
    <img alt="storh" src="./docs/public/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  File-first records for PHP: JSONC documents, append-only segmented logs, and atomic directory queues.
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
- an atomic directory queue
- UUIDv7 ids, prefix sharding, atomic writes, torn-write recovery, and compaction

The caller provides a base directory. storh does not discover application paths
or depend on a framework.

## Quick Start

```bash
composer require storh/storh
```

```php
use Storh\DocStore;
use Storh\Queue;
use Storh\RecordQuery;
use Storh\SegmentedLog;
use Storh\StorageRoot;

$root = StorageRoot::resolve(__DIR__ . '/var/storh', 'app');

$docs = new DocStore($root, 'pages');
$home = $docs->put([
    'kind' => 'page',
    'title' => 'Home',
]);

echo $docs->get($home->id())?->data()['title'];

$events = new SegmentedLog($root, 'events');
$events->put([
    'type' => 'page.saved',
    'pageId' => $home->id(),
]);

foreach ($events->stream(RecordQuery::all()->limit(100)) as $event) {
    // Process records lazily.
}

$queue = new Queue($root, 'jobs');
$queue->enqueue(['task' => 'render', 'pageId' => $home->id()]);

$job = $queue->claim();
if (null !== $job) {
    $queue->complete($job->id());
}
```

## Engines

`DocStore` writes each record as a JSONC object under a UUID-prefix-sharded
path. It is best for point reads and modest field scans.

`SegmentedLog` appends records to length and checksum guarded NDJSON segments.
It is best for append-heavy workflows, cursor pagination, time-range scans, and
compaction.

`Queue` stores jobs in `pending`, `processing`, and `done` lanes. Claims and
completions are atomic directory renames.

## Scaling & Limits

- Point access is effectively unbounded with sharding and a filesystem that can
  handle the file count.
- Segmented-log scan and range reads are comfortable into roughly 1M records.
- Per-file field scans degrade past roughly 10k records.
- storh is not for ad-hoc relational queries, joins, or analytical filtering
  over many fields.

## License

MIT © inline0.
