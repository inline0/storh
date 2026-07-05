# Changelog

All notable changes to storh are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/inline0/storh/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/inline0/storh/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/inline0/storh/releases/tag/v0.0.1
