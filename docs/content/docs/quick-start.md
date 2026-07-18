---
title: "Quick Start"
description: "Create records, append log entries, and claim queued work."
path: "quick-start"
order: 30
section: "Introduction"
meta_title: "Quick Start"
meta_description: "Create records, append log entries, and claim queued work."
---

# Quick Start

```php
use Storh\DocStore;
use Storh\Queue;
use Storh\RecordQuery;
use Storh\SegmentedLog;
use Storh\StorageRoot;

$root = StorageRoot::resolve(__DIR__ . '/var/storh', 'demo');

$docs = new DocStore($root, 'pages');
$home = $docs->put([
    'kind' => 'page',
    'title' => 'Home',
]);

$loaded = $docs->get($home->id());

$log = new SegmentedLog($root, 'events');
$log->put([
    'type' => 'page.saved',
    'pageId' => $home->id(),
]);

foreach ($log->stream(RecordQuery::all()->after($home->id())->limit(50)) as $record) {
    // Process records lazily.
}

$queue = new Queue($root, 'jobs');
$jobId = $queue->enqueue(['task' => 'render', 'pageId' => $home->id()]);

$job = $queue->claim();
if (null !== $job) {
    $queue->complete($job->id());
}
```

All three engines store arrays as record payloads and return `StorageRecord`
objects with `id()` and `data()` accessors.

Both stores also expose a fluent query builder:

```php
$pages = $docs
    ->query()
    ->where('kind')->eq('page')
    ->orderBy('title')
    ->limit(20)
    ->get();
```

Where to next:

- [Choosing an engine](/docs/engines#choosing-an-engine) for which store fits
  which workload
- [Indexes](/docs/indexes) before filtering collections beyond a few thousand
  records
- [SQL Mirror](/docs/sql-mirror) for joins, search, and reporting in SQL
