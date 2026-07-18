---
title: "Query Builder"
description: "Fluent Prisma/Drizzle-style querying over storh records."
path: "query-builder"
order: 80
section: "Querying"
meta_title: "Query Builder"
meta_description: "Fluent Prisma/Drizzle-style querying over storh records."
---

# Query Builder

`DocStore` and `SegmentedLog` expose `query()`. `DocStore` can use secondary
indexes; `SegmentedLog` applies the same predicates over streamed records.

```php
$records = $docs
    ->query()
    ->where('kind')->eq('page')
    ->where('status')->in(['draft', 'published'])
    ->where('publishedAt')->between($from, $until)
    ->orderBy('publishedAt', 'desc')
    ->limit(50)
    ->get();
```

Useful terminal methods:

```php
$docs->query()->where('slug')->eq('home')->first();
$docs->query()->where('kind')->eq('page')->count();
$docs->query()->cursor($lastId)->page(100)->get();
$docs->query()->where('slug')->eq('home')->explain();
```

On `DocStore`, two `eq` conditions on non-unique equality-indexed fields can
use an automatic compound bucket. Single equality, `in`, and range operators
continue to use their configured field indexes.

Operators:

- `eq`, `neq`
- `in`, `notIn`
- `gt`, `gte`, `lt`, `lte`, `between`
- `exists`, `missing`
- `prefix`
- `andWhere`, `orWhere`
- `orderBy`, `limit`, `cursor`, `page`

`andWhere` and `orWhere` use callbacks because `and` and `or` are reserved PHP
keywords:

```php
$docs->query()
    ->where('kind')->eq('page')
    ->orWhere(fn ($q) => $q->where('kind')->eq('post')->where('featured')->eq(true))
    ->get();
```

Chained `where()` calls combine with AND; each `orWhere()` branch adds an
alternative.

## What executes how

Every condition works on every collection; indexes only change the cost.
On `DocStore`, `eq` and `in` resolve through equality indexes, the range
operators and `prefix` resolve through range indexes, and `orderBy` with
`limit` on a range-indexed field reads in index order. Everything else
filters a record scan. `orderBy` on a field the planner cannot serve from an index sorts the
matched records in PHP, so on large collections pair it with indexed
conditions. `count()` uses index entry counts on `DocStore` and in-memory
counters on `SegmentedLog` when the filter is a single equality.

`cursor($id)` restricts results to records after the id, which combines with
`page()` for stable pagination ordered by record id. For plain streaming
filters without the builder, see [Queries](/docs/queries).
