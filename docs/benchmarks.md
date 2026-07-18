---
title: "Benchmarks"
description: "Benchmark harness and comparison reports."
path: "benchmarks"
order: 150
section: "Performance & Scale"
meta_title: "Benchmarks"
meta_description: "Benchmark harness and comparison reports."
---

# Benchmarks

The benchmark harness writes stable JSON and a human-readable report.

```bash
composer bench
composer bench -- --dataset=100000 --engine=doc
composer bench -- --dataset=100000 --engine=log
composer bench -- --dataset=100000 --engine=queue
composer bench -- --dataset=100000 --engine=cache --cache-validation=trust
composer bench -- --dataset=100000 --engine=filter
composer bench -- --dataset=1000000 --engine=uuid
composer bench:jit -- --dataset=1000000 --engine=uuid
composer bench:repeat -- --dataset=100000 --engine=doc --repeat=5
composer bench:repeat -- --dataset=1000000 --engine=filter --repeat=5 --memory-limit=512M
composer bench:range -- --datasets=1000,10000,50000,100000
composer bench:range -- --datasets=100000 --engines=cache --memory-limit=512M
composer bench:compare build/bench-main.json build/bench-current.json
composer bench:gate -- build/bench-main.json build/bench-current.json --threshold=10 --metric=doc.put --metric=log.stream
composer bench:ci
```

## Reference results

Medians of three full runs of the harness, 50,000 records per engine, on an
Apple M1 Pro (16 GB, macOS 26.5, APFS) with PHP 8.5 CLI, opcache and JIT off:

```bash
composer bench:repeat -- --dataset=50000 --engine=all --repeat=3 --memory-limit=512M
```

Every write is flushed and fsynced before storh reports it stored, so write
rates are filesystem-bound. Reads run against the OS page cache. Rates are
derived from the medians; run `composer bench` for your own hardware.

| DocStore, 50k records                          | median          |
| ---------------------------------------------- | --------------- |
| `put()`, one durable file per record           | 3.3k records/s  |
| `putStream()` bulk ingest                      | 3.7k records/s  |
| `importJsonl()`                                | 3.6k records/s  |
| `get()` point read, STAT-validated             | 5.6 µs          |
| reopen an existing store                       | 0.26 ms         |
| indexed equality query, `limit(100)`           | 1.1 ms          |
| indexed `count()` across the collection        | 41 µs           |
| index build, 2 equality + 1 range field        | 52k records/s   |
| full `stream()` with STAT re-validation        | 103k records/s  |
| `exportJsonl()`                                | 104k records/s  |

| SegmentedLog, 50k records, 16 KB segments      | median          |
| ---------------------------------------------- | --------------- |
| `put()`, fsync per append                      | 16k appends/s   |
| `appendStream()` bulk ingest                   | 56k records/s   |
| cursor read, 100 records from the midpoint     | 2.1 ms          |
| time-range read                                | 1.1 ms          |
| equality `count()`                             | 11 µs           |
| `compact()` all sealed segments                | 69k records/s   |
| reopen with torn-tail recovery                 | 218k records/s  |

The benchmark seals a segment every 16 KB to stress segment rolls; the
default segment size is 1 MiB.

| Queue, 50k jobs                                | median          |
| ---------------------------------------------- | --------------- |
| `enqueue()`, fsync per event                   | 22k jobs/s      |
| `claim()`                                      | 23k jobs/s      |
| `complete()`                                   | 23k jobs/s      |
| `enqueueMany()`                                | 209k jobs/s     |
| `claimMany()`                                  | 467k jobs/s     |
| `completeMany()`                               | 512k jobs/s     |

| SQL Mirror, SQLite, 50k records                | median          |
| ---------------------------------------------- | --------------- |
| initial `push()`                               | 61k rows/s      |
| `push()` with nothing changed                  | 86k records/s   |
| `flush()`, 100 ids                             | 7.1 ms          |
| indexed SQL `COUNT` over the mirror            | 6.9 ms          |
| `rebuild()`                                    | 65k rows/s      |
| `pull()` restore, one durable file per record  | 3.2k records/s  |

| Micro                                          | median          |
| ---------------------------------------------- | --------------- |
| cached `get()`, cold then warm (MemoryCache, STAT) | 83 µs / 6.3 µs |
| UUIDv7 generate                                | 1.3 µs          |
| UUIDv7 validate                                | 0.30 µs         |
| in-memory predicate filtering                  | 4.4M rows/s     |

Covered targets:

- DocStore put, streaming put, JSONL export/import, get, delete, stream, and index build
- equality-indexed, compound-indexed, range-indexed, ascending and descending ordered range, indexed count, ID lookup, and non-indexed DocStore queries
- SegmentedLog append, streaming append, cursor reads, time-range reads, compacted time-range reads, QueryBuilder cursor and ID lookups, counts, bulk-append counts, stats, compaction
- Queue enqueue, bulk enqueue, claim, bulk claim, complete, bulk complete, requeue
- RecordQuery and QueryCondition predicate filtering over in-memory rows
- UUIDv7 monotonic generation, timestamp-spread generation, validation, and timestamp extraction
- torn trailing line recovery
- cold versus warm cache reads with hash, stat, or trusted validation

Default output path:

```text
build/bench-current.json
```

Range runs write one JSON file per dataset and engine under
`build/bench-ranges/`.

`bench:gate` compares two benchmark JSON files and exits non-zero when any
tracked metric is slower than the configured threshold. If no `--metric` values
are passed, it gates every metric present in the baseline file.

`bench:ci` is the GitHub Actions smoke regression gate. It runs a 1k all-engine
benchmark, compares selected write/read/cache/UUID metrics with the tracked
`bench/baselines/ci-1k-all.json` baseline, and uses a wide default threshold to
avoid normal runner noise while still failing large performance regressions.

`bench:jit` runs the same harness with CLI opcache and tracing JIT enabled,
and disables Xdebug for the benchmark process so PHP can actually turn JIT on.
The generated JSON includes runtime flags that show whether opcache and JIT
were active for that run.
