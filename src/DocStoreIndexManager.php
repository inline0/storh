<?php

declare(strict_types=1);

namespace Storh;

final class DocStoreIndexManager
{
    /** @var array<string, array{field: string, unique: bool, range: bool}> */
    private array $pending = array();

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

        $entries = 0;
        foreach ($this->store->stream() as $record) {
            $this->update_record($record->id(), $record->data(), null);
            $entries++;
        }

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
        if (! is_file($this->manifest_path())) {
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

        foreach ($this->definitions() as $definition) {
            $field = $definition['field'];
            if (! array_key_exists($field, $data) || ! $this->indexable($data[ $field ])) {
                continue;
            }

            $value = $data[ $field ];
            AtomicFilesystem::write_atomic(
                $this->eq_entry_path($field, $value, $id),
                Jsonc::encode_object(array( 'id' => $id, 'field' => $field, 'value' => $value ))
            );

            if ($definition['range']) {
                AtomicFilesystem::write_atomic(
                    $this->range_entry_path($field, $value, $id),
                    Jsonc::encode_object(array( 'id' => $id, 'field' => $field, 'value' => $value ))
                );
            }
        }
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

            @unlink($this->eq_entry_path($field, $data[ $field ], $id));
            if ($definition['range']) {
                @unlink($this->range_entry_path($field, $data[ $field ], $id));
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
        if (! is_dir($root)) {
            return array();
        }

        $ids = array();
        if (null !== $limit) {
            $iterator = new \DirectoryIterator($root);
            foreach ($iterator as $file) {
                if (! $file->isFile() || 'jsonc' !== $file->getExtension()) {
                    continue;
                }

                $ids[] = basename($file->getPathname(), '.jsonc');
                if (count($ids) >= $limit) {
                    return $ids;
                }
            }

            return $ids;
        }

        foreach (glob($root . '/*.jsonc') ?: array() as $path) {
            if (is_file($path)) {
                $ids[] = basename($path, '.jsonc');
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function ids_for_range_condition(QueryCondition $condition): array
    {
        $root = $this->range_field_root($condition->field());
        if (! is_dir($root)) {
            return array();
        }

        $ids      = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || 'jsonc' !== $file->getExtension()) {
                continue;
            }

            $entry = AtomicFilesystem::read_jsonc_object($file->getPathname());
            $id    = isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : '';
            $value = $entry['value'] ?? null;
            if ('' === $id) {
                continue;
            }

            $record = new StorageRecord($id, array($condition->field() => $value));
            if ($condition->matches($record)) {
                $ids[ $id ] = true;
            }
        }

        $result = array_keys($ids);
        sort($result);

        return $result;
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
        return $this->entries_root() . '/eq/' . $this->field_key($field) . '/' . $this->value_key($value);
    }

    private function eq_entry_path(string $field, mixed $value, string $id): string
    {
        return $this->eq_value_root($field, $value) . '/' . $id . '.jsonc';
    }

    private function range_field_root(string $field): string
    {
        return $this->entries_root() . '/range/' . $this->field_key($field);
    }

    private function range_entry_path(string $field, mixed $value, string $id): string
    {
        return $this->range_field_root($field) . '/' . $this->range_key($value) . '/' . $id . '.jsonc';
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

    private function indexable(mixed $value): bool
    {
        return null === $value || is_scalar($value);
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
