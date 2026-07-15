---
title: "Engines"
description: "Choose between DocStore, SegmentedLog, and Queue."
path: "engines"
order: 3
section: "Documentation"
meta_title: "Engines"
meta_description: "Choose between DocStore, SegmentedLog, and Queue."
---

# Engines

## Choosing an engine

All three engines store the same thing, a UUIDv7 id plus an array of data,
but lay it out differently on disk. That layout decides what each engine is
good at:

|            | DocStore | SegmentedLog | Queue |
| ---------- | -------- | ------------ | ----- |
| On disk    | one JSONC file per record, UUID-tail sharded | append-only NDJSON segments plus a manifest | one append-only event log |
| Writing    | atomic file replace per record | checksummed appends; overwrites and deletes append new entries, reclaimed by `compact()` | checksummed append per queue event |
| Reading    | `get()` by id, indexed queries | cursor and time-range scans, `get()` via the state index | `claim()` in enqueue order |
| Sweet spot | point reads and indexed lookups at any collection size | append-heavy streams into roughly 1M records | durable work dispatch between processes |
| Weak spot  | non-indexed field scans past roughly 10k records | frequent single-record rewrites (old versions occupy space until compaction) | not a general record store; payloads are dropped once a job completes |

`DocStore` and `SegmentedLog` share the same fluent
[query API](/docs/query-builder): the same `where()` chain runs on either
engine, and only the execution differs. `DocStore` can answer from its
secondary indexes, while `SegmentedLog` filters while scanning segments.
`Queue` is claim-based, not queryable: workers `claim()` the next job instead
of filtering jobs.

Rules of thumb:

- Records you fetch, update, or delete individually and query by field:
  `DocStore`. Add [indexes](/docs/indexes) for the fields you filter on.
- Events or versions you mostly append and read back in order or by time
  window: `SegmentedLog`.
- Jobs handed from producers to worker processes: `Queue`. Claims and
  completions are multi-process safe, and `claimMany()`/`completeMany()`
  amortize lock and fsync cost.
- Joins, search, or reporting over any collection: add a
  [SQL mirror](/docs/sql-mirror). Files stay canonical; the mirror is a
  rebuildable projection in SQLite or MySQL.

Engines compose: they only share the root directory, so one application can
keep content in a `DocStore`, an audit trail in a `SegmentedLog`, and
background work in a `Queue` under the same storage root.

## DocStore

`DocStore` is for point reads and simple scans over durable records. It writes
one JSONC file per record:

```php
$store = new Storh\DocStore($root, 'articles');
$record = $store->put(['slug' => 'hello', 'published' => true]);
$same = $store->get($record->id());
```

Record files are sharded by UUID tail characters so UUIDv7 write bursts spread
across directories:

```text
articles/data/2d/018bcfe5-6800-7000-8000-2d5dba6274da.jsonc
```

## SegmentedLog

`SegmentedLog` is for append-heavy records, cursor reads, and time-range reads.
Records are written to active NDJSON segments. Full segments roll to sealed
segments with sparse indexes.

```php
$events = new Storh\SegmentedLog($root, 'events');
$events->put(['type' => 'user.created']);
$events->compact();
```

## Queue

`Queue` is for high-throughput durable work queues. Enqueue, claim, complete,
requeue, and purge write append-only events to `queue.log`; queue state is
replayed into memory when the queue opens. Batch methods amortize lock and
flush cost across many jobs. The log grows with every event; `stats()`
reports its current byte size.

```php
$queue = new Storh\Queue($root, 'mail');
$queue->enqueue(['to' => 'person@example.test']);
$job = $queue->claim();
```

## Durability and Concurrency

storh publishes file writes with atomic rename inside the target directory.
Reopen and `repair()` remove abandoned temp files whose owner process is gone,
while leaving live writer temp files alone. Writer markers make that check
constant-time, so opening a store sweeps the collection only after a crash.

`SegmentedLog` and `Queue` write length and checksum guarded log lines. Reopen
or repair truncates torn tails to the last committed event and rebuilds in-memory
state from durable log contents. Log and queue writes are serialized with
filesystem locks.

`DocStore` mutations are serialized with a collection-level write lock, so
record files and secondary indexes update as one consistency boundary across
processes. Concurrent writes to distinct record ids are safe under the same
filesystem atomic-rename assumptions. Concurrent writes to the same id are
last-rename-wins.

storh flushes and fsyncs file handles before rename or append completion, and
attempts to fsync parent directories after atomic renames. Power-loss durability
still depends on the underlying filesystem and mount options honoring those
syncs.
