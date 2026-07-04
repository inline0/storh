# Changelog

All notable changes to storh are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.0.1]: https://github.com/inline0/storh/releases/tag/v0.0.1
