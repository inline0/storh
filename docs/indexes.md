---
title: "Indexes"
description: "File-backed equality, unique, and range indexes for DocStore."
path: "indexes"
order: 90
section: "Querying"
meta_title: "Indexes"
meta_description: "File-backed equality, unique, and range indexes for DocStore."
---

# Indexes

`DocStore` indexes are stored beside the collection under `.storh/indexes`.
They are ordinary files, derived entirely from the records, and can be
rebuilt at any time. Declare them fluently or through a
[schema](/docs/schema):

```php
$docs->indexes()
    ->field('slug')->unique()
    ->field('kind')
    ->field('publishedAt')->range()
    ->sync();
```

Index types:

- equality indexes for fields like `kind` or `status`: one file per distinct
  value, so a point lookup reads only the matching value file
- unique indexes for fields like `slug`: enforced at write time, a duplicate
  value fails the `put()` before anything is written
- range indexes for scalar values: a sorted file with sparse seek
  checkpoints, serving `gt`, `gte`, `lt`, `lte`, `between`, and `prefix`

Range indexes can also answer `orderBy` on the same field with a `limit`
straight from index order, ascending or descending, without materializing and
sorting the whole result.

When a query combines two `eq` predicates on non-unique equality-indexed fields,
storh can use an automatic compound bucket for that field pair. This keeps
selective two-field filters fast without requiring another index declaration.

## Consistency and repair

Writes update index entries after the record file is atomically replaced,
under the collection write lock, so records and indexes move together.
`verify()` compares every index against a full record scan and reports drift;
`reindex()` rebuilds every configured index from the record files; `repair()`
quarantines corrupt records and then rebuilds.

```php
$docs->reindex();

$plan = $docs
    ->query()
    ->where('slug')->eq('home')
    ->explain();
```

`explain()` returns `index_scan` when the planner can use a configured index and
`full_scan` otherwise.
