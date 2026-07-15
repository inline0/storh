---
title: "JSONC"
description: "storh reads JSON documents with comments and trailing commas."
path: "jsonc"
order: 11
section: "Documentation"
meta_title: "JSONC"
meta_description: "storh reads JSON documents with comments and trailing commas."
---

# JSONC

storh storage files are JSON objects, and reads accept JSONC: line comments,
block comments, and trailing commas. That makes record files safe to open and
annotate in an editor; storh still parses them.

```jsonc
{
  // Application-owned fields live under data.
  "id": "018bcfe5-6800-7000-8000-000000000000",
  "data": {
    "title": "Home",
  },
}
```

Reads try a strict `json_decode` first and only fall back to the JSONC
stripper when that fails, so canonical files never pay for comment handling.

## What storh writes

Writes are canonical, compact, single-line JSON with a trailing newline:

```text
{"id":"018bcfe5-6800-7000-8000-000000000000","data":{"title":"Home"}}
```

Comments and custom formatting survive until storh next rewrites that record.
A `put()` to the same id replaces the whole file with canonical output, so
treat comments as scratch notes, not durable metadata. Durable information
belongs in `data`.

Encoding preserves unicode and slashes unescaped and keeps float zero
fractions (`1.0` stays `1.0`), so a decode and re-encode of the same data is
byte-stable. Index manifests and other internal metadata files are JSON
objects with the same read tolerance.

`Jsonc::decode_object()` rejects non-object documents and malformed input.
`Jsonc::encode_object()` and `Jsonc::encode_compact_object()` write arrays as
object documents.
