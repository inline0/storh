---
title: "Queries"
description: "Stream records with cursors, time ranges, limits, and field equality filters."
path: "queries"
order: 13
section: "Documentation"
meta_title: "Queries"
meta_description: "Stream records with cursors, time ranges, limits, and field equality filters."
---

# Queries

`RecordQuery` is the streaming filter used with `stream()`. It covers the
append-flavored access patterns: resume after a cursor, read a time window,
filter on field equality, and stop at a limit. For richer predicates,
ordering, and index-backed lookups, use the
[query builder](/docs/query-builder); for everything a plain `foreach` over a
stream can do, `RecordQuery` is the cheaper tool.

`RecordQuery` is immutable. Each modifier returns a cloned query:

```php
$query = Storh\RecordQuery::all()
    ->after($lastSeenId)
    ->time_range_ms($fromMs, $untilMs)
    ->where_equal('kind', 'page')
    ->limit(100);

foreach ($store->stream($query) as $record) {
    // Records are yielded lazily.
}
```

`after()` and `time_range_ms()` use UUID ordering, so they need no timestamp
field in the record data. On `SegmentedLog` they also skip work: segments
whose id range falls outside the window are never opened, and sparse
per-segment indexes seek close to the first matching record instead of
scanning from the top.

`where_equal()` compares a top-level data field with `===` and accepts scalar
values or `null`. A `null` filter matches records where the field is present
and null; records without the field do not match.

## Cursor pagination

Ids sort by creation time, so the last id of one page is the cursor for the
next:

```php
$page = iterator_to_array($store->stream(
    Storh\RecordQuery::all()->after($lastSeenId)->limit(100)
), false);

$lastSeenId = [] === $page ? $lastSeenId : end($page)->id();
```

## Surviving corrupt records

A scan throws on the first unreadable record by default. Use
`continue_on_error()` when the scan should skip it and report the failure
instead:

```php
$query = Storh\RecordQuery::all()->continue_on_error(
    static function (string $location, Throwable $error): void {
        error_log($location . ': ' . $error->getMessage());
    },
);
```

The location is the record id for `DocStore` and `segment-file:byte-offset`
for `SegmentedLog`. `repair()` is the tool for actually cleaning corrupt
records up; see [CLI](/docs/cli).
