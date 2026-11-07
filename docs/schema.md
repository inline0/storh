---
title: "Schema"
description: "Optional collection schemas for validation and index declaration."
path: "schema"
order: 50
section: "Core Concepts"
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
construction throws a `StorageException` ("Schema collection does not match
DocStore collection.") otherwise. Passing the schema to the constructor also
syncs the declared indexes immediately. Only `DocStore` takes a schema;
`SegmentedLog` and `Queue` do not validate their payloads.

## A complete example

The schema below types seven fields, indexes four of them in three different
ways, and requires three. `title` is never given a type, so `required()`
declares it as `mixed`: it must be present but may hold any value.

```php
use Storh\DocStore;
use Storh\Schema;
use Storh\StorageException;

$schema = Schema::collection('articles')
    ->string('slug')->unique()
    ->string('status')->index()
    ->string('author')->index()
    ->int('publishedAt')->range()
    ->mixed('tags')
    ->float('rating')
    ->bool('featured')
    ->required(['slug', 'status', 'title']);

$articles = new DocStore($root, 'articles', schema: $schema);

$articles->put([
    'slug'        => 'hello-world',
    'status'      => 'published',
    'author'      => 'ada',
    'title'       => 'Hello world',
    'publishedAt' => 1767225600,
    'rating'      => 4,
    'featured'    => true,
    'tags'        => ['intro', 'php'],
]);
```

`rating` is a `float` field and accepts the integer `4`. `tags` is `mixed`,
so the array passes untouched. The next writes show what the schema rejects.
Each one throws before its record file is written:

```php
try {
    $articles->put(['slug' => 'x', 'title' => 'X']);
} catch (StorageException $e) {
    $e->getMessage(); // Missing required schema field: status
}

try {
    $articles->put([
        'slug' => 'y',
        'status' => 'draft',
        'title' => 'Y',
        'publishedAt' => '2026-01-01',
    ]);
} catch (StorageException $e) {
    $e->getMessage(); // Schema field publishedAt must be int.
}

try {
    $articles->put(['slug' => 'hello-world', 'status' => 'draft', 'title' => 'D']);
} catch (StorageException $e) {
    $e->getMessage(); // Unique index violation on field: slug
}
```

The indexed fields serve the [query builder](/docs/query-builder) without a
full scan. `explain()` reports `index_scan` for each of these:

```php
$articles->query()->where('slug')->eq('hello-world')->first();

$articles->query()
    ->where('publishedAt')->gte(1767225600)
    ->orderBy('publishedAt', 'desc')
    ->limit(10)
    ->get();

$articles->query()
    ->where('status')->eq('published')
    ->where('author')->eq('ada')
    ->get();
```

## Building a schema

`Schema::collection($name)` starts a schema; an empty or whitespace-only name
throws. The type methods `string()`, `int()`, `float()`, `bool()` and
`mixed()` each declare one field and return a `SchemaFieldBuilder` for it. The
builder offers:

- `index()`, `unique()`, `range()`: set an index flag on the current field
  and return the `Schema`, so the chain continues with the next field.
- `required()`: with no argument, marks the current field required and
  returns the `Schema`. With a list of names it behaves like
  `Schema::required()`.
- `required_fields(array $fields)`: the same as `Schema::required()`.
- `string()`, `int()`, `float()`, `bool()`: declare the next field directly,
  for fields that carry no flag.

The builder has no `mixed()` method. Declare a `mixed` field at the start of
the chain or after a call that returns the `Schema` (a flag or `required()`),
as the complete example does after `->range()`.

`Schema::required(array $fields)` marks fields required and returns the
`Schema`. A name that has no type yet becomes a `mixed` field. Declaring a
field again keeps its required and index flags and replaces only its type, so
`required()` and the type methods can come in either order. Empty or
whitespace-only field names throw.

`fields()` returns the declared fields keyed by name as `SchemaField` objects
with `name()`, `type()`, `required()`, `indexed()`, `unique()` and `range()`.
`indexed()` is true for any field with `index()`, `unique()` or `range()`.

## Field types

The types and the values each one accepts, taken from `Schema::validate()`.
The SQL columns are the ones a [SQL mirror](/docs/sql-mirror) creates for the
field.

| Type     | Accepts                    | SQLite column | MySQL column                           |
| -------- | -------------------------- | ------------- | -------------------------------------- |
| `string` | `is_string()`              | `TEXT`        | `TEXT`, or `VARCHAR(191)` when indexed |
| `int`    | `is_int()`                 | `INTEGER`     | `BIGINT`                               |
| `float`  | `is_float()` or `is_int()` | `REAL`        | `DOUBLE`                               |
| `bool`   | `is_bool()`                | `INTEGER`     | `TINYINT(1)`                           |
| `mixed`  | any value                  | not mapped    | not mapped                             |

The checks are strict PHP type checks with no coercion: `'42'` fails an `int`
field, `1` fails a `bool` field, and `4.0` fails an `int` field. `float` is
the one type that widens, accepting integers. Arrays and objects are only
accepted by `mixed` fields.

## Validation rules

- `required` fields must be present in the written data. Presence is what is
  checked: an explicit `null` satisfies a required field.
- Typed fields are checked only when present and non-null. A `string` field
  rejects an `int` value; a `float` field accepts both `float` and `int`.
- `mixed` fields accept anything and are useful to mark a field as required
  or indexed without constraining its type.
- Fields not declared in the schema are unrestricted.
- Only top-level keys are checked. Nested arrays are opaque to the schema;
  declare them `mixed`.

A failed validation throws a `StorageException` before the record is written.
The messages are `Missing required schema field: <field>` and
`Schema field <field> must be <type>.`

Validation runs on every write path: `put()`, `putMany()`, `putStream()` and
`importJsonl()`. The batch methods validate each record just before writing
it, so a batch that fails on its fifth record has already stored the first
four. Validate up front with `$schema->validate($data)` when a batch must be
all or nothing; it throws the same exceptions and writes nothing. See
[bulk data](/docs/bulk-data) for the batch methods.

## Index flags

Flags per field: `index()` (equality index), `unique()` (uniqueness enforced
at write time), `range()` (ordered index for `gt`/`lt`/`between`/`prefix` and
ordered range queries), and `required()`.

The schema hands every flagged field to the collection's index manager
(`indexes()->apply_schema($schema)`), so these two stores keep the same
indexes:

```php
$withSchema = new DocStore($root, 'articles', schema: Schema::collection('articles')
    ->string('slug')->unique()
    ->int('publishedAt')->range());

$withoutSchema = new DocStore($root, 'articles');
$withoutSchema->indexes()
    ->field('slug')->unique()
    ->field('publishedAt')->range()
    ->sync();
```

Uniqueness is checked against the existing index before the record is
written. A uniqueness violation throws
`Unique index violation on field: <field>`. Updating a record with its own
unique value is allowed. Records that omit the field are not checked, but an
explicit `null` is indexed like any other scalar, so only one record can hold
`null` in a unique field. Array values are never indexed and never checked.

What each index type costs and serves, and how `explain()` reports it, is on
the [indexes](/docs/indexes) page. Plain streaming filters with `RecordQuery`
read records directly and are covered in [queries](/docs/queries).

## Schema changes

Indexes are synced when the store is constructed. Index definitions live in
the collection's index manifest, and the schema's flags are merged into the
definitions already stored there. A schema that adds an index, or adds
`unique()` or `range()` to an indexed field, changes the manifest, and the
affected indexes are rebuilt from the existing records.

Removing a field or a flag from the schema does not remove its index: the
stored definition stays, and a field that was `unique()` stays unique. The
index API only adds definitions.

Validation rule changes apply to future writes only and never rewrite stored
records. A record written before a field became required keeps working on
reads; the rule applies the next time that record is written.

The same `Schema` object can be reused to map typed columns in a
[SQL mirror](/docs/sql-mirror), including for collections whose store does
not itself use the schema. The mirror skips `mixed` fields, refuses an
indexed `mixed` field, and refuses field names that do not match
`^[A-Za-z][A-Za-z0-9_]*$` or that collide with its own `id`, `hash` and
`data` columns.
