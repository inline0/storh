---
title: "Schema"
description: "Optional collection schemas for validation and index declaration."
path: "schema"
order: 6
section: "Documentation"
meta_title: "Schema"
meta_description: "Optional collection schemas for validation and index declaration."
---

# Schema

Schemas are optional. A schema does two independent jobs: it validates every
write, and it declares which fields get [indexes](/docs/indexes). Collections
without a schema accept any array data.

```php
$schema = Schema::collection('posts')
    ->string('slug')->unique()
    ->string('status')->index()
    ->int('publishedAt')->range()
    ->bool('featured')
    ->required(['slug', 'status']);

$posts = new DocStore($root, 'posts', schema: $schema);
```

The schema's collection name must match the store's collection name;
construction throws otherwise. Passing the schema to the constructor also
syncs the declared indexes immediately.

## Validation rules

- `required` fields must be present in the written data. Presence is what is
  checked: an explicit `null` satisfies a required field.
- Typed fields are checked only when present and non-null. A `string` field
  rejects an `int` value; a `float` field accepts both `float` and `int`.
- `mixed` fields accept anything and are useful to mark a field as required
  or indexed without constraining its type.
- Fields not declared in the schema are unrestricted.

A failed validation throws a `StorageException` before anything is written.

## Field types and flags

Supported types: `string`, `int`, `float`, `bool`, `mixed`.

Flags per field: `index()` (equality index), `unique()` (uniqueness enforced
at write time), `range()` (ordered index for `gt`/`lt`/`between`/`prefix` and
ordered range queries), and `required()`.

## Schema changes

Indexes are synced when the store is constructed. If a schema change alters
the declared index set, the index manifest changes and the affected indexes
are rebuilt from the existing records; validation rule changes apply to
future writes only and never rewrite stored records.

The same `Schema` object can be reused to map typed columns in a
[SQL mirror](/docs/sql-mirror), including for collections whose store does
not itself use the schema.
