# Changelog

All notable changes to storh are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Opening a store is now constant-time at any collection size. Writing
  instances register a per-process marker under `.storh/writers/` and remove
  it on clean shutdown; the orphaned temp-file sweep on open only runs when
  a marker's owner process is gone, which is the only case that can leave
  temp files behind. Reopening a 50k-record collection drops from about
  207 ms to 0.04 ms, and the first open after upgrading sweeps once to
  clean any pre-marker leftovers. `repair()` still always sweeps.

### Fixed

- Failed atomic writes now remove their own temporary files instead of
  leaving them for the next sweep.
- A store instance inherited by a forked child process no longer unregisters
  the parent's writer marker in the child's shutdown.

## [0.4.0] - 2026-07-05

### Added

- `SqlMirror::pull()`: write mirror rows back into the registered stores, in
  id order, for restore and seeding flows. Pulled writes go through normal
  store machinery (schema validation, unique indexes, durable writes), rows
  must carry UUIDv7 ids and object data, matching records are skipped, and
  local records are never deleted.
- A `pull` metric in the mirror benchmark engine, and `mirror.push` and
  `mirror.rebuild` in the CI benchmark gate.

### Changed

- The CI benchmark baseline is now recorded from an actual GitHub Actions
  runner instead of a development machine, so the regression gate measures
  runner-vs-runner and stops flaking on slow runners.

### Fixed

- `QueryCondition::compare()` encodes both sides of a mixed-type comparison
  under the same key. Comparing values of different types previously
  returned a constant regardless of the operands, which made mixed-type
  ordering asymmetric and `gt`/`lt` filters on mixed-type fields one-sided.

## [0.3.0] - 2026-07-05

### Added

- `SqlMirror`: push DocStore and SegmentedLog collections into SQLite or
  MySQL as derived, rebuildable tables for joins, search, and reporting.
  Reconcile-based `push()` (whole mirror or one collection) with
  per-collection transactions, `flush()` for read-your-writes, `verify()`
  drift reports, `rebuild()`, and schema-mapped typed columns with unique
  and index flags. Connects through PDO (sqlite/mysql) or mysqli, including
  handles with mysqli error reporting disabled; `ext-pdo`/`ext-mysqli` are
  required only when the mirror is used, so the core package stays
  dependency-free. A dedicated CI job runs the mirror suite against a real
  MySQL server, and `composer bench -- --engine=mirror` benchmarks push,
  reconcile, flush, query, and rebuild.

## [0.0.2] - 2026-07-05

### Added

- Integration test suite covering cross-instance document, segmented log,
  queue, and CLI workflows, wired into a dedicated PHPUnit `integration`
  suite and `composer test:integration`.

### Changed

- Segment scans, compaction reads, state-index recovery, and queue log replay
  track byte offsets directly instead of calling `ftell()` per line.
- Unindexed document deletes no longer read the record file before unlinking
  it, so deleting a record without configured indexes skips a full read and
  succeeds even when the record file is corrupt.
- Segmented log `verify()` streams records instead of materializing the whole
  log in memory.
- Removed dead per-id state cache invalidation from segmented log write paths;
  those cache keys were never populated or read.

### Fixed

- Documented the segmented log line checksum as xxh32 (the docs previously
  said crc32b) and removed the nonexistent segmented-log state entry layer
  from the caching docs.

## [0.0.1] - 2026-07-04

### Added

- File-first JSONC document store with UUIDv7 ids, UUID-tail sharding, atomic
  writes, maintenance APIs, JSONL import/export, and bulk streaming writes.
- Append-only segmented log with cursor reads, time-range reads, sparse indexes,
  compaction, repair, and retention helpers.
- Append-only log-backed queue with bulk enqueue, claim, complete, requeue,
  purge, stats, verify, and repair operations.
- Fluent query builder for document and segmented-log records.
- File-backed document indexes for equality, unique, range, and automatic
  two-field equality buckets.
- Optional schemas for write validation and index declaration.
- Memory, APCu, and null cache backends with stat, hash, and trust validation
  modes.
- Benchmark harness covering document, log, queue, cache, filter, UUID, and
  recovery workloads.
- CLI for stats, verify, compaction, and document reindex operations.
- Documentation site, README examples, security policy, and MIT license.

[Unreleased]: https://github.com/inline0/storh/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/inline0/storh/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/inline0/storh/compare/v0.0.2...v0.3.0
[0.0.2]: https://github.com/inline0/storh/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/inline0/storh/releases/tag/v0.0.1
