<?php

declare(strict_types=1);

namespace Storh;

final class DocStoreIndexManager
{
    /** @var array<string, array{field: string, unique: bool, range: bool}> */
    private array $pending = array();

    /** @var array<string, array{field: string, unique: bool, range: bool}>|null */
    private ?array $definitions_cache = null;

    public function __construct(private readonly DocPerFileStore $store)
    {
        $this->pending = $this->definitions();
    }

    public function field(string $field): DocStoreIndexFieldBuilder
    {
        $this->assert_field($field);
        $this->pending[ $field ] ??= array(
            'field'  => $field,
            'unique' => false,
            'range'  => false,
        );

        return new DocStoreIndexFieldBuilder($this, $field);
    }

    public function define_field(string $field, bool $unique = false, bool $range = false): self
    {
        $this->assert_field($field);
        $existing = $this->pending[ $field ] ?? array( 'field' => $field, 'unique' => false, 'range' => false );

        $this->pending[ $field ] = array(
            'field'  => $field,
            'unique' => $existing['unique'] || $unique,
            'range'  => $existing['range'] || $range,
        );

        return $this;
    }

    public function apply_schema(Schema $schema): self
    {
        foreach ($schema->fields() as $field) {
            if ($field->indexed()) {
                $this->define_field($field->name(), $field->unique(), $field->range());
            }
        }

        return $this;
    }

    public function sync(bool $rebuild = true): void
    {
        $before = $this->definitions();
        $after  = $this->pending;
        ksort($after);

        AtomicFilesystem::write_atomic(
            $this->manifest_path(),
            Jsonc::encode_object(array( 'fields' => array_values($after) ))
        );
        $this->definitions_cache = $after;

        if ($rebuild && $before !== $after) {
            $this->rebuild();
        }
    }

    /**
     * @return array{fields: int, entries: int}
     */
    public function rebuild(): array
    {
        $this->delete_directory($this->entries_root());
        AtomicFilesystem::ensure_directory($this->entries_root());

        $buckets = array();
        $range_buckets = array();
        $entries = 0;
        $definitions = $this->definitions();
        foreach ($this->store->stream() as $record) {
            $this->collect_rebuild_index_entries($buckets, $range_buckets, $definitions, $record->id(), $record->data());
            $entries++;

            if ($this->bucket_value_count($buckets) < 4096 && $this->range_bucket_bytes($range_buckets) < 1_048_576) {
                continue;
            }

            $this->merge_index_buckets($buckets);
            $this->append_range_buckets($range_buckets);
            $buckets = array();
            $range_buckets = array();
        }

        $this->merge_index_buckets($buckets);
        $this->append_range_buckets($range_buckets);

        return array(
            'fields'  => count($this->definitions()),
            'entries' => $entries,
        );
    }

    /**
     * @return array<string, array{field: string, unique: bool, range: bool}>
     */
    public function definitions(): array
    {
        if (null !== $this->definitions_cache) {
            return $this->definitions_cache;
        }

        if (! is_file($this->manifest_path())) {
            $this->definitions_cache = array();

            return array();
        }

        $decoded = AtomicFilesystem::read_jsonc_object($this->manifest_path());
        $fields  = array();
        $items   = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : array();
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['field']) || ! is_string($item['field'])) {
                continue;
            }

            $fields[ $item['field'] ] = array(
                'field'  => $item['field'],
                'unique' => true === ( $item['unique'] ?? false ),
                'range'  => true === ( $item['range'] ?? false ),
            );
        }

        ksort($fields);
        $this->definitions_cache = $fields;

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $old_data
     */
    public function validate_unique(?string $id, array $data, ?array $old_data = null): void
    {
        foreach ($this->definitions() as $definition) {
            if (! $definition['unique'] || ! array_key_exists($definition['field'], $data)) {
                continue;
            }

            $value = $data[ $definition['field'] ];
            if (! $this->indexable($value)) {
                continue;
            }

            $ids = $this->ids_for_value($definition['field'], $value);
            foreach ($ids as $existing_id) {
                if ($existing_id !== $id) {
                    throw new StorageException('Unique index violation on field: ' . $definition['field']);
                }
            }

            if (
                null !== $old_data &&
                array_key_exists($definition['field'], $old_data) &&
                $old_data[ $definition['field'] ] !== $value
            ) {
                continue;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $old_data
     */
    public function update_record(string $id, array $data, ?array $old_data): void
    {
        if (null !== $old_data) {
            $this->remove_record($id, $old_data);
        }

        $buckets = array();
        $this->collect_index_entries($buckets, $this->definitions(), $id, $data);
        $this->merge_index_buckets($buckets);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function remove_record(string $id, array $data): void
    {
        foreach ($this->definitions() as $definition) {
            $field = $definition['field'];
            if (! array_key_exists($field, $data) || ! $this->indexable($data[ $field ])) {
                continue;
            }

            if ($definition['range']) {
                $this->append_range_entry($field, $data[ $field ], array(), array( $id ));
            } else {
                $this->remove_id_from_entry($this->eq_entry_path($field, $data[ $field ]), $this->value_key($data[ $field ]), $id);
            }
        }
    }

    /**
     * @return null|list<string>
     */
    public function candidate_ids(QueryBuilder $query): ?array
    {
        $all_candidates = array();
        $groups = $query->groups();
        $limit = $this->candidate_limit($query, $groups);

        foreach ($groups as $group) {
            $group_ids = $this->candidate_ids_for_group($group, $limit);
            if (null === $group_ids) {
                return null;
            }

            foreach ($group_ids as $id) {
                $all_candidates[ $id ] = true;
            }

            if (null !== $limit && count($all_candidates) >= $limit) {
                break;
            }
        }

        $ids = array_keys($all_candidates);
        if (null === $limit) {
            sort($ids);
        }

        return $ids;
    }

    /**
     * @return array{store: string, plan: string, indexes: list<array<string, mixed>>, groups: int}
     */
    public function explain(QueryBuilder $query): array
    {
        $indexes = array();
        $plan    = 'full_scan';

        foreach ($query->groups() as $group) {
            $condition = $this->best_condition($group);
            if (null !== $condition) {
                $plan      = 'index_scan';
                $indexes[] = array(
                    'field'    => $condition->field(),
                    'operator' => $condition->operator(),
                );
            }
        }

        return array(
            'store'   => DocPerFileStore::class,
            'plan'    => $plan,
            'indexes' => $indexes,
            'groups'  => count($query->groups()),
        );
    }

    /**
     * @param list<list<QueryCondition>> $groups
     */
    private function candidate_limit(QueryBuilder $query, array $groups): ?int
    {
        if ($query->has_ordering() || null !== $query->cursor_id() || null === $query->limit_value()) {
            return null;
        }

        if (1 !== count($groups)) {
            return null;
        }

        $group = $groups[0];
        if (1 !== count($group)) {
            return null;
        }

        return $query->limit_value();
    }

    /**
     * @param list<QueryCondition> $group
     * @return null|list<string>
     */
    private function candidate_ids_for_group(array $group, ?int $limit = null): ?array
    {
        $condition = $this->best_condition($group);
        if (null === $condition) {
            return null;
        }

        if ('eq' === $condition->operator()) {
            return $this->ids_for_value($condition->field(), $condition->value(), $limit);
        }

        if ('in' === $condition->operator() && is_array($condition->value())) {
            $ids = array();
            foreach ($condition->value() as $value) {
                foreach ($this->ids_for_value($condition->field(), $value, $limit) as $id) {
                    $ids[ $id ] = true;
                    if (null !== $limit && count($ids) >= $limit) {
                        return array_keys($ids);
                    }
                }
            }

            return array_keys($ids);
        }

        return $this->ids_for_range_condition($condition);
    }

    /**
     * @param list<QueryCondition> $group
     */
    private function best_condition(array $group): ?QueryCondition
    {
        $definitions = $this->definitions();
        foreach ($group as $condition) {
            $definition = $definitions[ $condition->field() ] ?? null;
            if (null === $definition) {
                continue;
            }

            if (in_array($condition->operator(), array( 'eq', 'in' ), true)) {
                return $condition;
            }

            if (
                $definition['range'] && in_array(
                    $condition->operator(),
                    array( 'gt', 'gte', 'lt', 'lte', 'between', 'prefix' ),
                    true
                )
            ) {
                return $condition;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function ids_for_value(string $field, mixed $value, ?int $limit = null): array
    {
        if (! $this->indexable($value)) {
            return array();
        }

        $root = $this->eq_value_root($field, $value);
        if (! is_file($root)) {
            $definition = $this->definitions()[ $field ] ?? null;
            if (null !== $definition && $definition['range']) {
                return $this->ids_for_range_value($field, $value, $limit);
            }

            return array();
        }

        $entry = AtomicFilesystem::read_jsonc_object($root);
        $ids = $this->ids_for_index_key($entry, $this->value_key($value), $limit);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function ids_for_range_condition(QueryCondition $condition): array
    {
        $root = $this->range_field_root($condition->field());
        if (! is_file($root)) {
            return array();
        }

        $ids = array();
        $handle = @fopen($root, 'rb');
        if (false === $handle) {
            return array();
        }

        try {
            while (false !== ( $line = fgets($handle) )) {
                $value_object = $this->decode_index_line($line);
                if (array() === $value_object) {
                    continue;
                }

                $value = $value_object['value'] ?? null;
                $key = isset($value_object['key']) && is_string($value_object['key']) ? $value_object['key'] : $this->range_key($value);
                $record = new StorageRecord(UuidV7::min_for_timestamp_ms(0), array($condition->field() => $value));
                if (! $condition->matches($record)) {
                    continue;
                }

                foreach ($this->ids_from_value_entry($value_object) as $id) {
                    $ids[ $key ][ $id ] = true;
                }

                $removed = isset($value_object['remove']) && is_array($value_object['remove']) ? $value_object['remove'] : array();
                foreach ($removed as $id) {
                    if (is_string($id)) {
                        unset($ids[ $key ][ $id ]);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $result = array();
        foreach ($ids as $value_ids) {
            foreach ($value_ids as $id => $_) {
                $result[ $id ] = true;
            }
        }

        $result = array_keys($result);
        sort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function ids_for_range_value(string $field, mixed $value, ?int $limit = null): array
    {
        $path = $this->range_field_root($field);
        if (! is_file($path)) {
            return array();
        }

        $key = $this->range_key($value);
        $ids = array();
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return array();
        }

        try {
            while (false !== ( $line = fgets($handle) )) {
                $entry = $this->decode_index_line($line);
                if (($entry['key'] ?? null) !== $key || ($entry['value'] ?? null) !== $value) {
                    continue;
                }

                foreach ($this->ids_from_value_entry($entry) as $id) {
                    $ids[ $id ] = true;
                }

                $removed = isset($entry['remove']) && is_array($entry['remove']) ? $entry['remove'] : array();
                foreach ($removed as $id) {
                    if (is_string($id)) {
                        unset($ids[ $id ]);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $result = array_keys($ids);
        sort($result);

        return null === $limit ? $result : array_slice($result, 0, $limit);
    }

    private function manifest_path(): string
    {
        return $this->index_root() . '/manifest.jsonc';
    }

    private function index_root(): string
    {
        return $this->store->collection_root() . '/.storh/indexes';
    }

    private function entries_root(): string
    {
        return $this->index_root() . '/entries';
    }

    private function eq_value_root(string $field, mixed $value): string
    {
        return $this->entries_root() . '/eq/' . $this->field_key($field) . '.jsonc';
    }

    private function eq_entry_path(string $field, mixed $value): string
    {
        return $this->eq_value_root($field, $value);
    }

    private function range_field_root(string $field): string
    {
        return $this->entries_root() . '/range/' . $this->field_key($field) . '.jsonl';
    }

    private function range_entry_path(string $field, mixed $value): string
    {
        return $this->range_field_root($field);
    }

    private function field_key(string $field): string
    {
        return bin2hex($field);
    }

    private function value_key(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function range_key(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return 'n-' . str_replace(array( '-', '.' ), array( 'm', 'd' ), sprintf('%024.6F', (float) $value));
        }

        if (is_string($value)) {
            return 's-' . bin2hex($value);
        }

        if (is_bool($value)) {
            return 'b-' . ( $value ? '1' : '0' );
        }

        return 'z-' . $this->value_key($value);
    }

    /**
     * @param array<string, array{path: string, field: string, values: array<string, array{value: mixed, ids: array<string, true>}>}> $buckets
     * @param array<string, array{field: string, unique: bool, range: bool}> $definitions
     * @param array<string, mixed> $data
     */
    private function collect_index_entries(array &$buckets, array $definitions, string $id, array $data): void
    {
        foreach ($definitions as $definition) {
            $field = $definition['field'];
            if (! array_key_exists($field, $data) || ! $this->indexable($data[ $field ])) {
                continue;
            }

            $value = $data[ $field ];
            if ($definition['range']) {
                $this->append_range_entry($field, $value, array( $id ), array());
                continue;
            }

            $this->collect_index_entry(
                $buckets,
                $this->eq_entry_path($field, $value),
                $field,
                $this->value_key($value),
                $value,
                $id
            );
        }
    }

    /**
     * @param array<string, array{path: string, field: string, values: array<string, array{value: mixed, ids: array<string, true>}>}> $buckets
     * @param array<string, string> $range_buckets
     * @param array<string, array{field: string, unique: bool, range: bool}> $definitions
     * @param array<string, mixed> $data
     */
    private function collect_rebuild_index_entries(array &$buckets, array &$range_buckets, array $definitions, string $id, array $data): void
    {
        foreach ($definitions as $definition) {
            $field = $definition['field'];
            if (! array_key_exists($field, $data) || ! $this->indexable($data[ $field ])) {
                continue;
            }

            $value = $data[ $field ];
            if ($definition['range']) {
                $this->collect_range_entry($range_buckets, $field, $value, $id);
                continue;
            }

            $this->collect_index_entry(
                $buckets,
                $this->eq_entry_path($field, $value),
                $field,
                $this->value_key($value),
                $value,
                $id
            );
        }
    }

    /**
     * @param array<string, string> $range_buckets
     */
    private function collect_range_entry(array &$range_buckets, string $field, mixed $value, string $id): void
    {
        $path = $this->range_entry_path($field, $value);
        $range_buckets[ $path ] = ( $range_buckets[ $path ] ?? '' ) . $this->encode_index_object(
            array(
                'key'    => $this->range_key($value),
                'value'  => $value,
                'ids'    => array( $id ),
                'remove' => array(),
            )
        );
    }

    /**
     * @param array<string, string> $range_buckets
     */
    private function append_range_buckets(array $range_buckets): void
    {
        foreach ($range_buckets as $path => $contents) {
            AtomicFilesystem::ensure_directory(dirname($path));
            if (false === @file_put_contents($path, $contents, FILE_APPEND)) {
                throw new StorageException('Could not write range index: ' . $path);
            }
        }
    }

    /**
     * @param array<string, array{path: string, field: string, values: array<string, array{value: mixed, ids: array<string, true>}>}> $buckets
     */
    private function collect_index_entry(array &$buckets, string $path, string $field, string $value_key, mixed $value, string $id): void
    {
        $buckets[ $path ] ??= array(
            'path'   => $path,
            'field'  => $field,
            'values' => array(),
        );

        $buckets[ $path ]['values'][ $value_key ] ??= array(
            'value' => $value,
            'ids'   => array(),
        );
        $buckets[ $path ]['values'][ $value_key ]['ids'][ $id ] = true;
    }

    /**
     * @param array<string, array{path: string, field: string, values: array<string, array{value: mixed, ids: array<string, true>}>}> $buckets
     */
    private function merge_index_buckets(array $buckets): void
    {
        foreach ($buckets as $bucket) {
            $values = is_file($bucket['path'])
                ? $this->values_from_field_entry(AtomicFilesystem::read_jsonc_object($bucket['path']))
                : array();

            foreach ($bucket['values'] as $key => $value_entry) {
                $values[ $key ] ??= array(
                    'value' => $value_entry['value'],
                    'ids'   => array(),
                );

                foreach ($value_entry['ids'] as $id => $_) {
                    $values[ $key ]['ids'][ $id ] = true;
                }
            }

            $this->write_field_index($bucket['path'], $bucket['field'], $values);
        }
    }

    /**
     * @param array<string, array{value: mixed, ids: array<string, true>}> $values
     */
    private function write_field_index(string $path, string $field, array $values): void
    {
        ksort($values);
        $encoded = array();
        foreach ($values as $key => $value_entry) {
            $ids = array_keys($value_entry['ids']);
            sort($ids);

            $encoded[ $key ] = array(
                'value' => $value_entry['value'],
                'ids'   => $ids,
            );
        }

        AtomicFilesystem::write_atomic(
            $path,
            $this->encode_index_object(array( 'field' => $field, 'values' => $encoded ))
        );
    }

    private function remove_id_from_entry(string $path, string $value_key, string $id): void
    {
        if (! is_file($path)) {
            return;
        }

        $entry = AtomicFilesystem::read_jsonc_object($path);
        $field = isset($entry['field']) && is_string($entry['field']) ? $entry['field'] : '';
        if ('' === $field) {
            return;
        }

        $values = $this->values_from_field_entry($entry);
        if (! isset($values[ $value_key ])) {
            return;
        }

        unset($values[ $value_key ]['ids'][ $id ]);
        if (array() === $values[ $value_key ]['ids']) {
            unset($values[ $value_key ]);
        }

        if (array() === $values) {
            @unlink($path);
            return;
        }

        $this->write_field_index($path, $field, $values);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, array{value: mixed, ids: array<string, true>}>
     */
    private function values_from_field_entry(array $entry): array
    {
        $values = array();
        $items = isset($entry['values']) && is_array($entry['values']) ? $entry['values'] : array();
        foreach ($items as $key => $value_entry) {
            if (! is_string($key) || ! is_array($value_entry)) {
                continue;
            }

            $value_object = $this->string_keyed($value_entry);
            $values[ $key ] = array(
                'value' => $value_object['value'] ?? null,
                'ids'   => array_fill_keys($this->ids_from_value_entry($value_object), true),
            );
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function ids_for_index_key(array $entry, string $key, ?int $limit = null): array
    {
        $values = isset($entry['values']) && is_array($entry['values']) ? $entry['values'] : array();
        $value_entry = $values[ $key ] ?? null;
        if (! is_array($value_entry)) {
            return array();
        }

        return $this->ids_from_value_entry($this->string_keyed($value_entry), $limit);
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function ids_from_value_entry(array $entry, ?int $limit = null): array
    {
        $ids = array();
        $items = isset($entry['ids']) && is_array($entry['ids']) ? $entry['ids'] : array();
        foreach ($items as $id) {
            if (is_string($id) && UuidV7::is_valid($id)) {
                $ids[] = $id;
                if (null !== $limit && count($ids) >= $limit) {
                    return $ids;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode_index_object(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<string> $ids
     * @param list<string> $remove
     */
    private function append_range_entry(string $field, mixed $value, array $ids, array $remove): void
    {
        if (array() === $ids && array() === $remove) {
            return;
        }

        $path = $this->range_entry_path($field, $value);
        AtomicFilesystem::ensure_directory(dirname($path));
        $line = $this->encode_index_object(
            array(
                'key'    => $this->range_key($value),
                'value'  => $value,
                'ids'    => $ids,
                'remove' => $remove,
            )
        );

        if (false === @file_put_contents($path, $line, FILE_APPEND)) {
            throw new StorageException('Could not write range index: ' . $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_index_line(string $line): array
    {
        try {
            $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return array();
        }

        if (! is_array($decoded) || ( array() !== $decoded && array_is_list($decoded) )) {
            return array();
        }

        return $this->string_keyed($decoded);
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function string_keyed(array $value): array
    {
        $object = array();
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[ $key ] = $item;
            }
        }

        return $object;
    }

    private function indexable(mixed $value): bool
    {
        return null === $value || is_scalar($value);
    }

    /**
     * @param array<string, array{path: string, field: string, values: array<string, array{value: mixed, ids: array<string, true>}>}> $buckets
     */
    private function bucket_value_count(array $buckets): int
    {
        $count = 0;
        foreach ($buckets as $bucket) {
            $count += count($bucket['values']);
        }

        return $count;
    }

    /**
     * @param array<string, string> $range_buckets
     */
    private function range_bucket_bytes(array $range_buckets): int
    {
        $bytes = 0;
        foreach ($range_buckets as $contents) {
            $bytes += strlen($contents);
        }

        return $bytes;
    }

    private function assert_field(string $field): void
    {
        if ('' === trim($field)) {
            throw new StorageException('Index field cannot be empty.');
        }
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
