---
title: "CLI"
description: "Complete command reference for the bin/storh executable."
path: "cli"
order: 17
section: "Documentation"
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

```bash
vendor/bin/storh stats var/storh pages doc
vendor/bin/storh verify var/storh events log
vendor/bin/storh compact var/storh events log
vendor/bin/storh reindex var/storh pages
```

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
