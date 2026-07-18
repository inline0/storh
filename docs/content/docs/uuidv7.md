---
title: "UUIDv7"
description: "UUIDv7 ids make records sortable and time-addressable."
path: "uuidv7"
order: 60
section: "Core Concepts"
meta_title: "UUIDv7"
meta_description: "UUIDv7 ids make records sortable and time-addressable."
---

# UUIDv7

Every storh record id is a UUIDv7: a 48-bit millisecond timestamp followed by
random entropy, formatted as a standard 36-character UUID. Because the
timestamp leads, ids sort by creation time, and that one property powers most
of storh: cursor pagination (`after($id)`), time-range reads, segment skipping
in the log, and stable ordering everywhere.

```php
$id = Storh\UuidV7::generate();

Storh\UuidV7::is_valid($id);          // bool, accepts upper and lower case
Storh\UuidV7::assert_valid($id);      // throws StorageException instead
$timestampMs = Storh\UuidV7::timestamp_ms($id);
```

Generation is monotonic within a process: ids created in the same millisecond
increment the previous entropy instead of re-rolling it, so rapid write bursts
still sort in creation order.

## Time addressing

For time windows, storh derives the smallest and largest possible UUIDv7 for a
millisecond timestamp and compares ids against those bounds:

```php
$query = Storh\RecordQuery::all()->time_range_ms(
    1_700_000_000_000,
    1_700_000_060_000,
);

$lower = Storh\UuidV7::min_for_timestamp_ms(1_700_000_000_000);
$upper = Storh\UuidV7::max_for_timestamp_ms(1_700_000_060_000);
```

This is why time-range reads need no timestamp field in the record data: the
id carries the creation time.

## Supplying your own ids

All engines accept an explicit id per write (`$docs->put($data, $id)`) and an
`id_generator` callable in their constructors. Ids from either path are
validated as UUIDv7, so custom generators must produce them; deterministic
generators are how the test suite creates reproducible fixtures.
`UuidV7::reset_for_tests()` clears the process-level monotonic state between
test cases.
