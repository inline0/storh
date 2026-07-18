---
title: "Atomicity & Recovery"
description: "How storh handles atomic writes, torn records, and compaction leftovers."
path: "atomicity"
order: 130
section: "Data Operations"
meta_title: "Atomicity & Recovery"
meta_description: "How storh handles atomic writes, torn records, and compaction leftovers."
---

# Atomicity & Recovery

`AtomicFilesystem::write_atomic()` writes to a temporary file, flushes and
fsyncs that file handle, renames it over the target path, and attempts to fsync
the parent directory. Failed writes remove their own temporary file.

Writing store instances register a per-process marker under
`.storh/writers/` and remove it on clean shutdown. Opening a store checks
those markers: when every marker's owner is alive (or none exist), the open
is constant-time; a marker whose owner process is gone means a writer
crashed, so the open sweeps the collection for orphaned temporary files and
clears the stale marker. `repair()` always sweeps.

DocStore repair quarantines corrupt or truncated record files under
`.storh/corrupt/` and rebuilds secondary indexes from the remaining valid
records. DocStore verification also compares index files against a full record
scan so missing or stale index entries are reported before they can be treated
as healthy.

Append-only engines flush and fsync their log file handles before considering
events committed in memory. Power-loss durability still depends on the
underlying filesystem and mount options honoring file and directory syncs.

The segmented log writes each line as:

```text
<byte-length>\t<xxh32>\t<json>\n
```

Reads verify the byte length and checksum. Recovery scans each segment and
truncates the file at the last complete valid line, which removes torn trailing
writes. Verification compares the active derived state index with an independent
segment replay, so externally changed or stale state is reported before repair
rebuilds it.

Queue verification decodes every durable queue event and compares the current
pending, processing, done, payload, and claim-order state with an independent
log replay. Repair rebuilds that state from `queue.log` and truncates torn
trailing events. Claims and completions hold the queue lock while syncing from
the durable log, so multiple worker processes do not claim the same pending job.

Compaction writes new `compact-*` segment files and then updates the manifest.
If a process exits before that manifest swap, reopen removes unreferenced
compaction output and continues from the old manifest. Completed compactions
intentionally leave old sealed segments in place so readers already streaming
from them stay valid.
