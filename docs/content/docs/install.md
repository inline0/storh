---
title: "Install"
description: "Install storh with Composer."
path: "install"
order: 20
meta_title: "Install"
meta_description: "Install storh with Composer."
---

# Install

```bash
composer require storh/storh
```

## Requirements

- PHP 8.2 or later
- `ext-json`
- a local or mounted filesystem with atomic rename semantics inside a
  directory (any normal local disk qualifies)

storh has no Composer runtime dependencies. Two extensions unlock optional
features when present: `ext-pdo` or `ext-mysqli` for
[SQL mirrors](/docs/sql-mirror), and `ext-apcu` for the shared
`Cache::apcu()` backend.

## Pick a storage root

Use a directory your PHP process can create, read, write, rename within, and
delete from:

```php
use Storh\StorageRoot;

$root = StorageRoot::resolve(__DIR__ . '/var/storage', 'app');
```

`StorageRoot` only normalizes the supplied path and sanitizes the namespace
into a safe directory name. It does not inspect the host application, read
global constants, or require a framework adapter. Engines create their
collection directories under the root on first use.

## Check it works

```php
$docs = new Storh\DocStore($root, 'smoke');
$record = $docs->put(['ok' => true]);

var_dump($docs->get($record->id())?->data()); // ['ok' => true]
```

Continue with the [Quick Start](/docs/quick-start) or pick an engine in
[Choosing an engine](/docs/engines#choosing-an-engine).
