---
title: "Bulk Data"
description: "Bulk writes, JSONL import/export, partitioning, and retention."
path: "bulk-data"
order: 8
section: "Documentation"
meta_title: "Bulk Data"
meta_description: "Bulk writes, JSONL import/export, partitioning, and retention."
---

# Bulk Data

The `*Many` methods take arrays and return the created records or ids; the
`*Stream` methods take any iterable, hold nothing in memory, and return a
count. Use streams for ingest jobs where the input is larger than you want a
PHP array to be. Bulk methods take the collection lock once and, on the log
and queue, buffer many records per fsync, which makes their bulk writes
several times faster per record than one-at-a-time calls. `DocStore` bulk
writes still sync each record file; they save lock churn, not fsyncs.

DocStore bulk APIs:

```php
$records = $docs->putMany([
    ['slug' => 'home', 'kind' => 'page'],
    ['slug' => 'about', 'kind' => 'page'],
]);

$count = $docs->putStream($largeIterable);

$docs->exportJsonl(__DIR__ . '/pages.jsonl');
$docs->importJsonl(__DIR__ . '/pages.jsonl');
```

JSONL rows are one object per line, either `{"id": "...", "data": {...}}` or
a bare data object (an id is generated on import). Rows may carry explicit
ids, so export and import round-trip a collection exactly.

SegmentedLog bulk APIs:

```php
$log->appendMany([
    ['type' => 'page.saved'],
    ['type' => 'page.rendered'],
]);

$log->appendStream($largeIterable);
```

Queue bulk APIs:

```php
$queue->enqueueMany([
    ['task' => 'render', 'pageId' => $home->id()],
    ['task' => 'notify', 'pageId' => $home->id()],
]);

$jobs = $queue->claimMany(100);
$queue->completeMany(array_map(fn ($job) => $job->id(), $jobs));
```

Optional partitions place a log's segments under a per-day or per-month
directory (`events/partitions/2026-07-05/`), so old periods can be archived
or deleted as whole directories:

```php
$daily = new Storh\SegmentedLog($root, 'events', partition: 'daily');
$monthly = new Storh\SegmentedLog($root, 'events', partition: 'monthly');
```

Retention deletes log records older than a cutoff and compacts the reclaimed
space; `purgeDone` drops completed queue jobs from memory:

```php
$deleted = $log->retain()->olderThanDays(90)->compact();
$queue->purgeDone(olderThanSeconds: 86400);
```
