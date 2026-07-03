<?php

declare(strict_types=1);

namespace Storh;

final class DocStoreIndexManager
{
    private const EQ_REBUILD_FLUSH_IDS = 65536;

    private const RANGE_REBUILD_CHUNK_ENTRIES = 16384;

    private const RANGE_SPARSE_STRIDE = 256;

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
        $range_chunks = array();
        $range_entries = 0;
        $entries = 0;
        $definitions = $this->definitions();
        foreach ($this->store->stream() as $record) {
            $this->collect_rebuild_index_entries($buckets, $range_buckets, $range_entries, $definitions, $record->id(), $record->data());
            $entries++;

            if ($this->bucket_id_count($buckets) >= self::EQ_REBUILD_FLUSH_IDS) {
                $this->merge_index_buckets($buckets);
                $buckets = array();
            }

            if ($range_entries >= self::RANGE_REBUILD_CHUNK_ENTRIES) {
                $this->flush_range_chunks($range_buckets, $range_chunks);
                $range_buckets = array();
                $range_entries = 0;
            }
        }

        $this->merge_index_buckets($buckets);
        $this->flush_range_chunks($range_buckets, $range_chunks);
        $this->write_range_chunks($range_chunks);

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

    public function candidate_count(QueryBuilder $query): ?int
    {
        $groups = $query->groups();
        if (1 !== count($groups) || 1 !== count($groups[0])) {
            return null;
        }

        $condition = $this->best_condition($groups[0]);
        if (null === $condition) {
            return null;
        }

        $limit = $query->limit_value();
        $count = match ($condition->operator()) {
            'eq' => $this->count_for_value($condition->field(), $condition->value(), $limit),
            'in' => is_array($condition->value())
                ? $this->count_for_values($condition->field(), $condition->value(), $limit)
                : 0,
            'gt', 'gte', 'lt', 'lte', 'between', 'prefix' => count($this->ids_for_range_condition($condition)),
            default => null,
        };

        if (null === $count) {
            return null;
        }

        return null === $limit ? $count : min($count, $limit);
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

        return $this->ids_from_value_file($root, $this->value_key($value), $limit);
    }

    /**
     * @param array<mixed> $values
     */
    private function count_for_values(string $field, array $values, ?int $limit = null): ?int
    {
        $count = 0;
        $seen = array();

        foreach ($values as $value) {
            if (! $this->indexable($value)) {
                continue;
            }

            $key = $this->value_key($value);
            if (isset($seen[ $key ])) {
                continue;
            }

            $seen[ $key ] = true;
            $value_count = $this->count_for_value($field, $value, null === $limit ? null : $limit - $count);
            if (null === $value_count) {
                return null;
            }

            $count += $value_count;
            if (null !== $limit && $count >= $limit) {
                return $limit;
            }
        }

        return $count;
    }

    private function count_for_value(string $field, mixed $value, ?int $limit = null): ?int
    {
        if (! $this->indexable($value)) {
            return 0;
        }

        $root = $this->eq_value_root($field, $value);
        if (is_file($root)) {
            return $this->count_ids_from_value_file($root, $this->value_key($value), $limit);
        }

        $definition = $this->definitions()[ $field ] ?? null;
        if (null !== $definition && $definition['range']) {
            return null;
        }

        return 0;
    }
    /**
     * @return list<string>
     */
    private function ids_for_range_condition(QueryCondition $condition): array
    {
        $ids = array();
        $this->collect_range_condition_ids($this->range_field_root($condition->field()), $condition, $ids, true);
        $this->collect_range_condition_ids($this->range_delta_field_root($condition->field()), $condition, $ids, false);

        $result = array_keys($ids);
        sort($result);

        return $result;
    }

    /**
     * @return array{0: string|null, 1: bool, 2: string|null, 3: bool}|null
     */
    private function range_key_window(QueryCondition $condition): ?array
    {
        $operator = $condition->operator();
        $value = $condition->value();

        if ('gt' === $operator || 'gte' === $operator) {
            $key = $this->ordered_range_key($value);

            return null === $key ? null : array( $key, 'gte' === $operator, null, true );
        }

        if ('lt' === $operator || 'lte' === $operator) {
            $key = $this->ordered_range_key($value);

            return null === $key ? null : array( null, true, $key, 'lte' === $operator );
        }

        if ('between' === $operator) {
            $lower = $this->ordered_range_key($value);
            $upper = $this->ordered_range_key($condition->second_value());

            return null === $lower || null === $upper ? null : array( $lower, true, $upper, true );
        }

        return null;
    }

    private function ordered_range_key(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return $this->range_key($value);
        }

        if (is_string($value) || is_bool($value)) {
            return $this->range_key($value);
        }

        return null;
    }

    /**
     * @param array{0: string|null, 1: bool, 2: string|null, 3: bool} $window
     */
    private function range_key_matches_window(string $key, array $window): bool
    {
        [ $lower, $lower_inclusive, $upper, $upper_inclusive ] = $window;

        if (null !== $lower) {
            $comparison = strcmp($key, $lower);
            if ($comparison < 0 || (0 === $comparison && ! $lower_inclusive)) {
                return false;
            }
        }

        if (null !== $upper) {
            $comparison = strcmp($key, $upper);
            if ($comparison > 0 || (0 === $comparison && ! $upper_inclusive)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{0: string|null, 1: bool, 2: string|null, 3: bool} $window
     */
    private function range_key_after_window(string $key, array $window): bool
    {
        $upper = $window[2];
        if (null === $upper) {
            return false;
        }

        $comparison = strcmp($key, $upper);

        return $comparison > 0 || (0 === $comparison && ! $window[3]);
    }

    /**
     * @param array{0: string|null, 1: bool, 2: string|null, 3: bool} $window
     */
    private function range_seek_offset(string $field, array $window): int
    {
        $lower = $window[0];
        if (null === $lower) {
            return 0;
        }

        $path = $this->range_sparse_index_path($field);
        if (! is_file($path)) {
            return 0;
        }

        $decoded = AtomicFilesystem::read_jsonc_object($path);
        $items = isset($decoded['checkpoints']) && is_array($decoded['checkpoints']) ? $decoded['checkpoints'] : array();
        $offset = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['key'] ?? null;
            $candidate_offset = $item['offset'] ?? null;
            if (! is_string($key) || ! is_int($candidate_offset)) {
                continue;
            }

            if (strcmp($key, $lower) >= 0) {
                break;
            }

            $offset = $candidate_offset;
        }

        return $offset;
    }

    private function range_line_key(string $line): ?string
    {
        $prefix = '{"key":"';
        if (! str_starts_with($line, $prefix)) {
            return null;
        }

        $start = strlen($prefix);
        $end = strpos($line, '"', $start);

        return false === $end ? null : substr($line, $start, $end - $start);
    }

    /**
     * @param array<string, true> $ids
     */
    private function collect_range_condition_ids(string $path, QueryCondition $condition, array &$ids, bool $sorted): void
    {
        if (! is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        $operator = $condition->operator();
        $expected = $condition->value();
        $second_expected = $condition->second_value();
        $key_window = $this->range_key_window($condition);

        try {
            if ($sorted && null !== $key_window) {
                fseek($handle, $this->range_seek_offset($condition->field(), $key_window));
            }

            while (false !== ( $line = fgets($handle) )) {
                $line_key = null;
                if (null !== $key_window) {
                    $line_key = $this->range_line_key($line);
                    if (null !== $line_key) {
                        if ($sorted && $this->range_key_after_window($line_key, $key_window)) {
                            break;
                        }

                        if (! $this->range_key_matches_window($line_key, $key_window)) {
                            continue;
                        }
                    }
                }

                $value_object = $this->decode_index_line($line);
                if (array() === $value_object) {
                    continue;
                }

                $value = $value_object['value'] ?? null;
                if (! $this->range_value_matches($operator, $value, $expected, $second_expected)) {
                    continue;
                }

                $this->apply_range_entry_ids($value_object, $ids);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, true> $ids
     * @param array{0: string|null, 1: bool, 2: string|null, 3: bool} $window
     */
    private function collect_range_value_ids(
        string $path,
        string $field,
        string $key,
        mixed $value,
        array &$ids,
        bool $sorted,
        array $window
    ): void {
        if (! is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            if ($sorted) {
                fseek($handle, $this->range_seek_offset($field, $window));
            }

            while (false !== ( $line = fgets($handle) )) {
                $line_key = $this->range_line_key($line);
                if (null !== $line_key) {
                    if ($sorted && $this->range_key_after_window($line_key, $window)) {
                        break;
                    }

                    if ($line_key !== $key) {
                        continue;
                    }
                }

                $entry = $this->decode_index_line($line);
                if (($entry['key'] ?? null) !== $key || ($entry['value'] ?? null) !== $value) {
                    continue;
                }

                $this->apply_range_entry_ids($entry, $ids);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, true> $ids
     */
    private function apply_range_entry_ids(array $entry, array &$ids): void
    {
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

    /**
     * @return list<string>
     */
    private function ids_for_range_value(string $field, mixed $value, ?int $limit = null): array
    {
        $key = $this->range_key($value);
        $ids = array();
        $window = array( $key, true, $key, true );
        $this->collect_range_value_ids($this->range_field_root($field), $field, $key, $value, $ids, true, $window);
        $this->collect_range_value_ids($this->range_delta_field_root($field), $field, $key, $value, $ids, false, $window);

        $result = array_keys($ids);
        sort($result);

        return null === $limit ? $result : array_slice($result, 0, $limit);
    }

    private function range_value_matches(string $operator, mixed $value, mixed $expected, mixed $second_expected): bool
    {
        return match ($operator) {
            'gt' => QueryCondition::compare($value, $expected) > 0,
            'gte' => QueryCondition::compare($value, $expected) >= 0,
            'lt' => QueryCondition::compare($value, $expected) < 0,
            'lte' => QueryCondition::compare($value, $expected) <= 0,
            'between' => QueryCondition::compare($value, $expected) >= 0
                && QueryCondition::compare($value, $second_expected) <= 0,
            'prefix' => is_string($value) && is_string($expected) && str_starts_with($value, $expected),
            default => false,
        };
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
        return $this->entries_root() . '/eq/' . $this->field_key($field) . '/' . $this->value_key($value) . '.jsonc';
    }

    private function eq_entry_path(string $field, mixed $value): string
    {
        return $this->eq_value_root($field, $value);
    }

    private function range_field_root(string $field): string
    {
        return $this->entries_root() . '/range/' . $this->field_key($field) . '.jsonl';
    }

    private function range_delta_field_root(string $field): string
    {
        return $this->entries_root() . '/range/' . $this->field_key($field) . '.delta.jsonl';
    }

    private function range_sparse_index_path(string $field): string
    {
        return $this->entries_root() . '/range/' . $this->field_key($field) . '.idx.jsonc';
    }

    private function range_chunk_path(string $field, int $index): string
    {
        return $this->entries_root() . '/range/.chunks/' . $this->field_key($field) . '-' . $index . '.jsonl';
    }

    private function field_key(string $field): string
    {
        return bin2hex($field);
    }

    private function value_key(mixed $value): string
    {
        return hash('sha256', serialize($value));
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
     * @param array<string, array{path: string, field: string, key: string, value: mixed, ids: array<string, true>}> $buckets
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
     * @param array<string, array{path: string, field: string, key: string, value: mixed, ids: array<string, true>}> $buckets
     * @param array<string, list<string>> $range_buckets
     * @param array<string, array{field: string, unique: bool, range: bool}> $definitions
     * @param array<string, mixed> $data
     */
    private function collect_rebuild_index_entries(
        array &$buckets,
        array &$range_buckets,
        int &$range_entries,
        array $definitions,
        string $id,
        array $data
    ): void {
        foreach ($definitions as $definition) {
            $field = $definition['field'];
            if (! array_key_exists($field, $data) || ! $this->indexable($data[ $field ])) {
                continue;
            }

            $value = $data[ $field ];
            if ($definition['range']) {
                $this->collect_range_entry($range_buckets, $range_entries, $field, $value, $id);
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
     * @param array<string, list<string>> $range_buckets
     */
    private function collect_range_entry(array &$range_buckets, int &$range_entries, string $field, mixed $value, string $id): void
    {
        $key = $this->range_key($value);
        $value_key = $this->value_key($value);
        $range_buckets[ $field ][] = $key . "\t" . $value_key . "\t" . $this->encode_index_object(
            array(
                'key'    => $key,
                'value'  => $value,
                'ids'    => array( $id ),
                'remove' => array(),
            )
        );
        $range_entries++;
    }

    /**
     * @param array<string, list<string>> $range_buckets
     * @param array<string, list<string>> $range_chunks
     */
    private function flush_range_chunks(array $range_buckets, array &$range_chunks): void
    {
        foreach ($range_buckets as $field => $entries) {
            sort($entries, SORT_STRING);
            $chunk = $this->range_chunk_path($field, count($range_chunks[ $field ] ?? array()));
            AtomicFilesystem::write_atomic($chunk, implode('', $entries));
            $range_chunks[ $field ][] = $chunk;
        }
    }

    /**
     * @param array<string, list<string>> $range_chunks
     */
    private function write_range_chunks(array $range_chunks): void
    {
        foreach ($range_chunks as $field => $chunks) {
            $this->merge_range_chunks($field, $chunks);
        }
    }

    /**
     * @param list<string> $chunks
     */
    private function merge_range_chunks(string $field, array $chunks): void
    {
        $handles = array();
        $heads = array();

        foreach ($chunks as $index => $chunk) {
            $handle = @fopen($chunk, 'rb');
            if (false === $handle) {
                throw new StorageException('Could not read range index chunk: ' . $chunk);
            }

            $handles[ $index ] = $handle;
            $line = fgets($handle);
            if (false !== $line) {
                $heads[ $index ] = $line;
            }
        }

        $contents = '';
        $offset = 0;
        $line_count = 0;
        $checkpoints = array();

        try {
            while (array() !== $heads) {
                $selected = $this->smallest_range_chunk_line($heads);
                $line = $heads[ $selected ];
                [ $key, $encoded ] = $this->range_chunk_line_parts($line);

                if (0 === $line_count % self::RANGE_SPARSE_STRIDE) {
                    $checkpoints[] = array(
                        'key'    => $key,
                        'offset' => $offset,
                    );
                }

                $contents .= $encoded;
                $offset += strlen($encoded);
                $line_count++;

                $next = fgets($handles[ $selected ]);
                if (false === $next) {
                    unset($heads[ $selected ]);
                } else {
                    $heads[ $selected ] = $next;
                }
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }

        AtomicFilesystem::write_atomic($this->range_field_root($field), $contents);
        AtomicFilesystem::write_atomic(
            $this->range_sparse_index_path($field),
            $this->encode_index_object(
                array(
                    'stride'      => self::RANGE_SPARSE_STRIDE,
                    'checkpoints' => $checkpoints,
                )
            )
        );

        foreach ($chunks as $chunk) {
            @unlink($chunk);
        }
    }

    /**
     * @param array<int, string> $heads
     */
    private function smallest_range_chunk_line(array $heads): int
    {
        $selected = array_key_first($heads);
        if (null === $selected) {
            throw new StorageException('Cannot merge empty range index chunk set.');
        }

        foreach ($heads as $index => $line) {
            if (strcmp($line, $heads[ $selected ]) < 0) {
                $selected = $index;
            }
        }

        return $selected;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range_chunk_line_parts(string $line): array
    {
        $first = strpos($line, "\t");
        $second = false === $first ? false : strpos($line, "\t", $first + 1);
        if (false === $first || false === $second) {
            throw new StorageException('Malformed range index chunk.');
        }

        return array(
            substr($line, 0, $first),
            substr($line, $second + 1),
        );
    }

    /**
     * @param array<string, array{path: string, field: string, key: string, value: mixed, ids: array<string, true>}> $buckets
     */
    private function collect_index_entry(array &$buckets, string $path, string $field, string $value_key, mixed $value, string $id): void
    {
        $buckets[ $path ] ??= array(
            'path'  => $path,
            'field' => $field,
            'key'   => $value_key,
            'value' => $value,
            'ids'   => array(),
        );

        $buckets[ $path ]['ids'][ $id ] = true;
    }

    /**
     * @param array<string, array{path: string, field: string, key: string, value: mixed, ids: array<string, true>}> $buckets
     */
    private function merge_index_buckets(array $buckets): void
    {
        foreach ($buckets as $bucket) {
            $ids = is_file($bucket['path'])
                ? array_fill_keys($this->ids_from_value_entry(AtomicFilesystem::read_jsonc_object($bucket['path'])), true)
                : array();

            foreach ($bucket['ids'] as $id => $_) {
                $ids[ $id ] = true;
            }

            $this->write_value_index($bucket['path'], $bucket['field'], $bucket['key'], $bucket['value'], $ids);
        }
    }

    /**
     * @param array<string, true> $ids
     */
    private function write_value_index(string $path, string $field, string $key, mixed $value, array $ids): void
    {
        $encoded_ids = array_keys($ids);
        sort($encoded_ids);

        AtomicFilesystem::write_atomic(
            $path,
            $this->encode_index_object(
                array(
                    'field' => $field,
                    'key'   => $key,
                    'value' => $value,
                    'count' => count($encoded_ids),
                    'ids'   => $encoded_ids,
                )
            )
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

        if (($entry['key'] ?? null) !== $value_key) {
            return;
        }

        $ids = array_fill_keys($this->ids_from_value_entry($entry), true);
        unset($ids[ $id ]);
        if (array() === $ids) {
            @unlink($path);
            return;
        }

        $this->write_value_index($path, $field, $value_key, $entry['value'] ?? null, $ids);
    }

    /**
     * @return list<string>
     */
    private function ids_from_value_file(string $path, string $expected_key, ?int $limit = null): array
    {
        if (null !== $limit) {
            return $this->limited_ids_from_value_file($path, $expected_key, $limit);
        }

        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new StorageException('Could not read equality index: ' . $path);
        }

        $key_marker = '"key":"' . $expected_key . '"';
        $key_offset = strpos($contents, $key_marker);
        if (false === $key_offset) {
            return array();
        }

        $ids_marker = ',"ids":[';
        $ids_offset = strpos($contents, $ids_marker, $key_offset + strlen($key_marker));
        if (false === $ids_offset) {
            throw new StorageException('Malformed equality index: ' . $path);
        }

        $ids = array();
        $position = $ids_offset + strlen($ids_marker);
        while (true) {
            $open = strpos($contents, '"', $position);
            $end = strpos($contents, ']', $position);
            if (false === $open || (false !== $end && $end < $open)) {
                break;
            }

            $close = strpos($contents, '"', $open + 1);
            if (false === $close) {
                throw new StorageException('Malformed equality index: ' . $path);
            }

            $id = substr($contents, $open + 1, $close - $open - 1);
            if (UuidV7::is_valid($id)) {
                $ids[] = $id;
            }

            $position = $close + 1;
        }

        return $ids;
    }

    private function count_ids_from_value_file(string $path, string $expected_key, ?int $limit = null): int
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new StorageException('Could not read equality index: ' . $path);
        }

        $key_marker = '"key":"' . $expected_key . '"';
        $key_offset = strpos($contents, $key_marker);
        if (false === $key_offset) {
            return 0;
        }

        $ids_marker = ',"ids":[';
        $ids_offset = strpos($contents, $ids_marker, $key_offset + strlen($key_marker));
        if (false === $ids_offset) {
            throw new StorageException('Malformed equality index: ' . $path);
        }

        $count_marker = ',"count":';
        $count_offset = strpos($contents, $count_marker, $key_offset + strlen($key_marker));
        if (false === $count_offset || $count_offset > $ids_offset) {
            throw new StorageException('Malformed equality index: ' . $path);
        }

        $start = $count_offset + strlen($count_marker);
        $end = $start;
        $length = strlen($contents);
        while ($end < $length && ctype_digit($contents[ $end ])) {
            $end++;
        }

        if ($end === $start) {
            throw new StorageException('Malformed equality index: ' . $path);
        }

        $count = (int) substr($contents, $start, $end - $start);

        return null === $limit ? $count : min($count, $limit);
    }

    /**
     * @return list<string>
     */
    private function limited_ids_from_value_file(string $path, string $expected_key, int $limit): array
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new StorageException('Could not read equality index: ' . $path);
        }

        $key_marker = '"key":"' . $expected_key . '"';
        $ids_marker = ',"ids":[';
        $buffer = '';
        $key_offset = null;
        $position = null;
        $ids = array();
        $count = 0;
        $needs_more = false;

        try {
            while (! feof($handle) && $count < $limit) {
                $chunk = fread($handle, 8192);
                if (false === $chunk) {
                    throw new StorageException('Could not read equality index: ' . $path);
                }

                $buffer .= $chunk;
                if (null === $key_offset) {
                    $offset = strpos($buffer, $key_marker);
                    if (false === $offset) {
                        continue;
                    }

                    $key_offset = $offset;
                }

                if (null === $position) {
                    $offset = strpos($buffer, $ids_marker, $key_offset + strlen($key_marker));
                    if (false === $offset) {
                        continue;
                    }

                    $position = $offset + strlen($ids_marker);
                }

                $needs_more = false;
                while ($count < $limit) {
                    $open = strpos($buffer, '"', $position);
                    $end = strpos($buffer, ']', $position);
                    if (false === $open) {
                        if (false !== $end) {
                            return $ids;
                        }

                        $needs_more = true;
                        break;
                    }

                    if (false !== $end && $end < $open) {
                        return $ids;
                    }

                    $close = strpos($buffer, '"', $open + 1);
                    if (false === $close) {
                        $needs_more = true;
                        break;
                    }

                    $id = substr($buffer, $open + 1, $close - $open - 1);
                    if (UuidV7::is_valid($id)) {
                        $ids[] = $id;
                        $count++;
                    }

                    $position = $close + 1;
                }
            }
        } finally {
            fclose($handle);
        }

        if (null === $key_offset) {
            return array();
        }

        if (null === $position || $needs_more) {
            throw new StorageException('Malformed equality index: ' . $path);
        }

        return $ids;
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
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ) . "\n";
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

        $path = $this->range_delta_field_root($field);
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
     * @param array<string, array{path: string, field: string, key: string, value: mixed, ids: array<string, true>}> $buckets
     */
    private function bucket_id_count(array $buckets): int
    {
        $count = 0;
        foreach ($buckets as $bucket) {
            $count += count($bucket['ids']);
        }

        return $count;
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
