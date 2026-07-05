# storh

File-first records for PHP: a JSONC document store, an append-only segmented
log, and a log-backed queue. Zero dependencies beyond `php ^8.2` and
`ext-json`. Callers pass a base directory; storh never discovers application
paths and has no framework coupling.

## Quick Reference

```bash
# Required final gate: analyse + cs + test
composer verify

# Individually
composer analyse                  # PHPStan, level 10 over src/
composer cs                       # PHPCS, PSR-12
composer cs:fix                   # phpcbf
composer test                     # PHPUnit, unit + integration suites
composer test:unit                # tests/Unit only
composer test:integration         # tests/Integration only
composer test:coverage:gate       # coverage floor: 85% lines, 70% methods

# Benchmarks (bench/ harness, JSON results in build/)
composer bench                    # local run, defaults to a 1k dataset
composer bench -- --dataset=100000 --engine=doc
composer bench:ci                 # regression gate vs bench/baselines/ci-1k-all.json
composer bench:compare build/a.json build/b.json

# Maintenance CLI
vendor/bin/storh stats <root> [collection] [doc|log|queue]
vendor/bin/storh verify <root> [collection] [doc|log|queue]
vendor/bin/storh compact <root> [collection] [doc|log]
vendor/bin/storh reindex <root> [collection]
```

## Non-Negotiable Testing Rule

Run `composer verify` from the repo root after every meaningful change and
before treating work as done. Performance-sensitive changes additionally run
`composer bench:ci`; it executes the 1k benchmark and gates the tracked
metrics against `bench/baselines/ci-1k-all.json`. That baseline is recorded
on a GitHub Actions runner; refresh it from a CI benchmark job log, never
from a development machine, or the gate loses its headroom against runner
variance.

## Architecture

Three engines share one on-disk layout under the caller's root. The short
names are class aliases: `DocStore` is `DocPerFileStore`, `SegmentedLog` is
`SegmentedLogStore`, `Queue` is `LogQueue`.

- Document store (`src/DocPerFileStore.php`): one JSONC file per record at
  `<collection>/data/<uuid-tail-shard>/<uuid>.jsonc`. Point reads, fluent
  queries (`QueryBuilder`), file-backed secondary indexes
  (`DocStoreIndexManager`: equality, unique, range, automatic two-field
  compound buckets), optional `Schema` validation, JSONL import/export, and
  layered read caches validated per `CacheValidation` mode (STAT, HASH, TRUST).
- Segmented log (`src/SegmentedLogStore.php`): append-only NDJSON segments
  with length and xxh32 checksummed lines, a `manifest.jsonc`, sparse
  per-segment seek indexes, an in-memory state index rebuilt by replay,
  cursor and time-range reads, compaction, and retention helpers.
- Queue (`src/LogQueue.php`): a single append-only event log (`enqueue`,
  `claim`, `complete`, `requeue`, `purge`); pending, processing, and done
  state lives in memory and is rebuilt from the durable log on open and
  before each mutation, which is what makes multi-process claims safe.

Shared plumbing: `AtomicFilesystem` (temp file + fsync + atomic rename
publishes, orphaned temp cleanup), `Jsonc` (decode with comments and trailing
commas, canonical encode), `UuidV7` (monotonic ids, so record ids sort by
creation time), `StorageRoot` (root/namespace path resolution), and the
`MemoryCache`/`ApcuCache`/`NullCache` backends. `SqlMirror` optionally pushes
collections into SQLite or MySQL as derived, rebuildable tables (files stay
canonical; connects via PDO or mysqli, both suggested, never required).

Durability invariants are the point of this library: torn tails truncate on
reopen, log and queue writes serialize behind filesystem locks, and writes
sync before they publish. Do not weaken sync, lock, or checksum behavior for
speed; a perf change must keep the durability tests and the bench gate green.

## Layout

- `src/` is a flat PSR-4 `Storh\` namespace of `final` classes.
- `tests/Unit/` covers components: storage behavior, cache correctness,
  durability and concurrency, property-based checks.
- `tests/Integration/` covers end-to-end flows on the real filesystem across
  engines and across store instances (reopen, compaction, worker handoff,
  the CLI binary).
- `tests/Support/` holds temp-dir cleanup and a fault-injection stream wrapper.
- `bench/` is the benchmark harness; `bench/baselines/` feeds `composer bench:ci`.
- `bin/storh` is the maintenance CLI.
- `docs/` is the Next.js docs site; authored content lives in
  `docs/content/docs/*.mdx`. Update it together with README.md when the
  documented API surface changes.
- `tools/coverage-gate.php` enforces the coverage floor in CI.

## Conventions

PHP 8.2+, `declare(strict_types=1)`, `final` classes, PSR-12 via `phpcs.xml`
(the PSR-1 CamelCaps method sniff is excluded: internals use snake_case
method names, while the documented fluent and bulk API surface is camelCase).
PHPStan must stay green at level 10 over `src/` with
`treatPhpDocTypesAsCertain: false`.

Comment policy: PHPDoc on public APIs and wherever array shapes need typing;
inline comments explain why, not what; no decorative separator comments; no
em dashes.
