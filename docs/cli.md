---
title: "CLI"
description: "Complete command reference for the bin/storh executable."
path: "cli"
order: 180
section: "Reference"
meta_title: "CLI"
meta_description: "Complete command reference for the bin/storh executable."
---

# CLI

```bash
vendor/bin/storh stats <root> [collection] [doc|log|queue]
vendor/bin/storh verify <root> [collection] [doc|log|queue]
vendor/bin/storh compact <root> [collection] [doc|log]
vendor/bin/storh reindex <root> [collection]
```

`<root>` is the storage root the engines were opened with, which is the path
`StorageRoot::resolve()` returns: the base directory plus the namespace. A store
opened under `StorageRoot::resolve(__DIR__ . '/var/storh', 'app')` lives under
`var/storh/app`:

```bash
vendor/bin/storh stats var/storh/app pages doc
vendor/bin/storh verify var/storh/app events log
vendor/bin/storh compact var/storh/app events log
vendor/bin/storh reindex var/storh/app pages
```

Every command requires an existing root and collection directory. A missing
target prints a clear error and exits 1 without creating storage directories.
Passing the base directory alone (`var/storh`) therefore fails when the
collection lives under its namespace (`var/storh/app`).

`vendor/bin/storh` needs storh 0.5.2 or later when storh is installed as a
dependency. Earlier versions only looked for the Composer autoloader in the
package's own `vendor/` directory and failed with a `require` error.

The CLI prints stable JSON so it can be used from cron jobs and deployment
health checks. The collection defaults to `default`; the engine defaults to
`doc` for `stats`, `verify`, and `reindex`, and to `log` for `compact`.

## Maintenance methods behind the commands

All engines expose the maintenance methods the CLI drives:

```php
$store->stats();
$store->health();
$store->verify();
$store->repair();
```

DocStore adds:

```php
$docs->reindex();
$docs->compact(); // no-op report for API symmetry
```

SegmentedLog adds:

```php
$log->compact();
$log->recover();
$log->state_index();
```

For DocStore, `verify()` checks that records parse cleanly and that equality,
range, and compound indexes match a full record scan. `repair()` quarantines
corrupt record files under `.storh/corrupt/` and rebuilds indexes from valid
records.

For SegmentedLog, `verify()` decodes segment lines and compares the current
derived state index with a fresh segment replay. `repair()` rebuilds the derived
state and truncates torn trailing lines.

For Queue, `verify()` decodes every durable event and compares pending,
processing, done, payload, and claim-order state with a fresh `queue.log` replay.
`repair()` rebuilds queue state from the log and requeues timed-out processing
jobs.
