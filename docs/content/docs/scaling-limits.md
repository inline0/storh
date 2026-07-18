---
title: "Scaling & Limits"
description: "Practical limits for file-first storage."
path: "scaling-limits"
order: 170
section: "Performance & Scale"
meta_title: "Scaling & Limits"
meta_description: "Practical limits for file-first storage."
---

# Scaling & Limits

storh is file-first storage, not a relational query engine. The practical
boundaries, with reference numbers from the [benchmarks](/docs/benchmarks)
(50k records, Apple M1 Pro):

- Point access is effectively unbounded when the filesystem can handle the
  file count and the collection uses UUID-tail sharding. A point read is a
  few microseconds; a durable single-record write is fsync-bound at a few
  thousand per second.
- Segmented-log scans, cursor reads, and time-range reads are comfortable
  into roughly 1M records when segment sizes are configured for the workload.
  Bulk appends run at tens of thousands of records per second; torn-tail
  recovery replays hundreds of thousands of records per second on reopen.
- Queue operations append compact events to one durable log, so enqueue,
  claim, complete, requeue, and purge avoid one-file-per-job directory churn.
  Bulk claim and complete amortize locking and fsync across whole batches.
- Per-file field scans in `DocStore` degrade past roughly 10k records because
  every matching scan must walk individual files. Declare
  [indexes](/docs/indexes) for the fields you filter on; indexed equality
  queries answer in about a millisecond regardless of collection size.
- storh is not for ad-hoc relational queries, joins, secondary-index-heavy
  filtering, or analytical scans over many fields. Push collections into a
  [SQL mirror](/docs/sql-mirror) when you need those read patterns; keep the
  system of record in a real database when you need multi-record
  transactions.

Use `DocStore` when point reads dominate, `SegmentedLog` when append and ordered
reads dominate, and `Queue` for simple durable work dispatch. See
[Choosing an engine](/docs/engines#choosing-an-engine) for a side-by-side
comparison.
