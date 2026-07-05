<?php

declare(strict_types=1);

namespace Storh;

final class SqlMirror
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const HASH_ALGORITHM = 'xxh128';

    private const DELETE_CHUNK_SIZE = 500;

    private const RESERVED_COLUMNS = array( 'id', 'hash', 'data' );

    private readonly SqlMirrorConnection $connection;

    private readonly string $driver;

    /**
     * @var array<string, array{
     *     store: FileStoreInterface,
     *     table: string,
     *     columns: list<array{field: string, type: string, unique: bool, indexed: bool}>
     * }>
     */
    private array $collections = array();

    public function __construct(
        \PDO|\mysqli|SqlMirrorConnection $connection,
        private readonly string $prefix = 'storh_',
        ?string $driver = null
    ) {
        if (1 !== preg_match('/^[A-Za-z0-9_]*$/', $this->prefix)) {
            throw new StorageException('SQL mirror table prefix must match [A-Za-z0-9_]*.');
        }

        if ($connection instanceof \PDO) {
            $connection = new PdoSqlMirrorConnection($connection, $driver);
        } elseif (null !== $driver) {
            throw new StorageException('SQL mirror driver overrides only apply to PDO connections.');
        } elseif ($connection instanceof \mysqli) {
            $connection = new MysqliSqlMirrorConnection($connection);
        }

        $this->connection = $connection;
        $this->driver     = $connection->driver();
    }

    public function collection(FileStoreInterface $store, string $name, ?Schema $schema = null): self
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new StorageException('SQL mirror collection name must match [A-Za-z0-9_-]+.');
        }

        if (isset($this->collections[ $name ])) {
            throw new StorageException('SQL mirror collection is already registered: ' . $name);
        }

        $this->collections[ $name ] = array(
            'store'   => $store,
            'table'   => $this->prefix . str_replace('-', '_', $name),
            'columns' => null === $schema ? array() : $this->columns_from_schema($schema),
        );

        return $this;
    }

    public function table(string $name): string
    {
        return $this->registered($name)['table'];
    }

    public function install(): void
    {
        foreach ($this->collections as $collection) {
            $this->connection->execute($this->create_table_sql($collection));
            foreach ($this->create_index_sql($collection) as $sql) {
                $this->connection->execute($sql);
            }
        }
    }

    public function uninstall(): void
    {
        foreach ($this->collections as $collection) {
            $this->connection->execute('DROP TABLE IF EXISTS ' . $this->quote($collection['table']));
        }
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
     */
    public function push(?string $name = null): array
    {
        if (null !== $name) {
            $this->registered($name);
        }

        $names = null === $name ? array_keys($this->collections) : array( $name );

        $totals = array(
            'inserted'  => 0,
            'updated'   => 0,
            'deleted'   => 0,
            'unchanged' => 0,
        );

        foreach ($names as $collection_name) {
            $result = $this->push_collection($collection_name);
            $totals['inserted']  += $result['inserted'];
            $totals['updated']   += $result['updated'];
            $totals['deleted']   += $result['deleted'];
            $totals['unchanged'] += $result['unchanged'];
        }

        return $totals;
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
     */
    public function rebuild(?string $name = null): array
    {
        $entries = null === $name ? $this->collections : array( $name => $this->registered($name) );
        foreach ($entries as $collection) {
            $this->connection->execute('DELETE FROM ' . $this->quote($collection['table']));
        }

        return $this->push($name);
    }

    /**
     * @return array{written: int, unchanged: int}
     */
    public function pull(?string $name = null): array
    {
        if (null !== $name) {
            $this->registered($name);
        }

        $names = null === $name ? array_keys($this->collections) : array( $name );

        $written   = 0;
        $unchanged = 0;
        foreach ($names as $collection_name) {
            $collection = $this->collections[ $collection_name ];
            $sql = 'SELECT ' . $this->quote('id') . ', ' . $this->quote('data')
                . ' FROM ' . $this->quote($collection['table'])
                . ' ORDER BY ' . $this->quote('id');

            foreach ($this->connection->rows($sql) as $row) {
                if (! isset($row[0], $row[1]) || ! is_string($row[0]) || ! is_string($row[1])) {
                    throw new StorageException('SQL mirror pull read a malformed row from: ' . $collection['table']);
                }

                $id = $row[0];
                if (! UuidV7::is_valid($id)) {
                    throw new StorageException('SQL mirror pull requires UUIDv7 row ids, got: ' . $id);
                }

                $data     = $this->pull_row_data($collection['table'], $id, $row[1]);
                $existing = $collection['store']->get($id);
                if (null !== $existing && $this->record_hash($existing->data()) === $this->record_hash($data)) {
                    $unchanged++;
                    continue;
                }

                $collection['store']->put($data, $id);
                $written++;
            }
        }

        return array(
            'written'   => $written,
            'unchanged' => $unchanged,
        );
    }

    /**
     * @param list<string> $ids
     * @return array{upserted: int, deleted: int}
     */
    public function flush(string $name, array $ids): array
    {
        $collection = $this->registered($name);
        $upserted   = 0;
        $deleted    = 0;

        $this->transactional(
            function () use ($collection, $ids, &$upserted, &$deleted): void {
                $insert = $this->connection->statement($this->insert_sql($collection));
                $delete = $this->connection->statement(
                    'DELETE FROM ' . $this->quote($collection['table']) . ' WHERE ' . $this->quote('id') . ' = ?'
                );

                $seen = array();
                foreach ($ids as $id) {
                    if (isset($seen[ $id ])) {
                        continue;
                    }

                    $seen[ $id ] = true;
                    $record = $collection['store']->get($id);
                    $delete->execute(array( $id ));
                    if (null === $record) {
                        $deleted++;
                        continue;
                    }

                    $insert->execute($this->row_values($collection, $record->id(), $record->data()));
                    $upserted++;
                }
            }
        );

        return array(
            'upserted' => $upserted,
            'deleted'  => $deleted,
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>, stats: array<string, array<string, int>>}
     */
    public function verify(): array
    {
        $errors = array();
        $stats  = array();

        foreach ($this->collections as $name => $collection) {
            $mirror  = $this->mirror_hashes($collection['table']);
            $records = 0;
            $missing = 0;
            $stale   = 0;

            foreach ($collection['store']->stream() as $record) {
                $records++;
                $hash = $this->record_hash($record->data());
                $id   = $record->id();
                if (! isset($mirror[ $id ])) {
                    $missing++;
                } elseif ($mirror[ $id ] !== $hash) {
                    $stale++;
                }

                unset($mirror[ $id ]);
            }

            $orphaned = count($mirror);
            $stats[ $name ] = array(
                'records'  => $records,
                'rows'     => $records - $missing + $orphaned,
                'missing'  => $missing,
                'stale'    => $stale,
                'orphaned' => $orphaned,
            );

            if ($missing > 0) {
                $errors[] = $name . ': ' . $missing . ' record(s) missing from the mirror.';
            }
            if ($stale > 0) {
                $errors[] = $name . ': ' . $stale . ' stale mirror row(s).';
            }
            if ($orphaned > 0) {
                $errors[] = $name . ': ' . $orphaned . ' orphaned mirror row(s).';
            }
        }

        return array(
            'ok'     => array() === $errors,
            'errors' => $errors,
            'stats'  => $stats,
        );
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
     */
    private function push_collection(string $name): array
    {
        $collection = $this->registered($name);
        $inserted   = 0;
        $updated    = 0;
        $deleted    = 0;
        $unchanged  = 0;

        $this->transactional(
            function () use ($collection, &$inserted, &$updated, &$deleted, &$unchanged): void {
                $mirror = $this->mirror_hashes($collection['table']);
                $insert = $this->connection->statement($this->insert_sql($collection));
                $replace = $this->connection->statement(
                    'DELETE FROM ' . $this->quote($collection['table']) . ' WHERE ' . $this->quote('id') . ' = ?'
                );

                foreach ($collection['store']->stream() as $record) {
                    $id   = $record->id();
                    $data = $record->data();
                    $hash = $this->record_hash($data);

                    if (isset($mirror[ $id ])) {
                        $existing = $mirror[ $id ];
                        unset($mirror[ $id ]);
                        if ($existing === $hash) {
                            $unchanged++;
                            continue;
                        }

                        $replace->execute(array( $id ));
                        $insert->execute($this->row_values($collection, $id, $data));
                        $updated++;
                        continue;
                    }

                    $insert->execute($this->row_values($collection, $id, $data));
                    $inserted++;
                }

                $deleted = $this->delete_rows($collection['table'], array_keys($mirror));
            }
        );

        return array(
            'inserted'  => $inserted,
            'updated'   => $updated,
            'deleted'   => $deleted,
            'unchanged' => $unchanged,
        );
    }

    /**
     * @param list<string> $ids
     */
    private function delete_rows(string $table, array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $statement    = $this->connection->statement(
                'DELETE FROM ' . $this->quote($table) . ' WHERE ' . $this->quote('id') . ' IN (' . $placeholders . ')'
            );
            $statement->execute($chunk);
            $deleted += count($chunk);
        }

        return $deleted;
    }

    /**
     * @return array<string, string>
     */
    private function mirror_hashes(string $table): array
    {
        $sql = 'SELECT ' . $this->quote('id') . ', ' . $this->quote('hash') . ' FROM ' . $this->quote($table);

        $hashes = array();
        foreach ($this->connection->rows($sql) as $row) {
            if (isset($row[0], $row[1]) && is_string($row[0]) && is_string($row[1])) {
                $hashes[ $row[0] ] = $row[1];
            }
        }

        return $hashes;
    }

    /**
     * @param array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>} $collection
     * @param array<string, mixed> $data
     * @return list<int|float|string|null>
     */
    private function row_values(array $collection, string $id, array $data): array
    {
        $values = array( $id, $this->record_hash($data) );
        foreach ($collection['columns'] as $column) {
            $values[] = $this->column_value($column['type'], $data[ $column['field'] ] ?? null);
        }

        $values[] = json_encode($data, self::JSON_FLAGS);

        return $values;
    }

    private function column_value(string $type, mixed $value): int|float|string|null
    {
        return match ($type) {
            'string' => is_string($value) ? $value : null,
            'int' => is_int($value) ? $value : null,
            'float' => is_float($value) || is_int($value) ? (float) $value : null,
            'bool' => is_bool($value) ? (int) $value : null,
            default => throw new StorageException('Unsupported SQL mirror column type: ' . $type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pull_row_data(string $table, string $id, string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new StorageException('SQL mirror pull found invalid JSON for id ' . $id . ' in ' . $table, 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new StorageException('SQL mirror pull found a non-array data row for id ' . $id . ' in ' . $table);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record_hash(array $data): string
    {
        return hash(self::HASH_ALGORITHM, json_encode($data, self::JSON_FLAGS));
    }

    /**
     * @return list<array{field: string, type: string, unique: bool, indexed: bool}>
     */
    private function columns_from_schema(Schema $schema): array
    {
        $columns = array();
        foreach ($schema->fields() as $field) {
            $name = $field->name();
            $type = $field->type();

            if ('mixed' === $type) {
                if ($field->indexed()) {
                    throw new StorageException('SQL mirror cannot index mixed schema field: ' . $name);
                }

                continue;
            }

            if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) || in_array(strtolower($name), self::RESERVED_COLUMNS, true)) {
                throw new StorageException('SQL mirror cannot map schema field name: ' . $name);
            }

            $columns[] = array(
                'field'   => $name,
                'type'    => $type,
                'unique'  => $field->unique(),
                'indexed' => $field->indexed(),
            );
        }

        return $columns;
    }

    /**
     * @param array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>} $collection
     */
    private function create_table_sql(array $collection): string
    {
        $definitions   = array();
        $definitions[] = $this->quote('id') . ' ' . ( 'mysql' === $this->driver ? 'CHAR(36)' : 'TEXT' ) . ' NOT NULL PRIMARY KEY';
        $definitions[] = $this->quote('hash') . ' ' . ( 'mysql' === $this->driver ? 'CHAR(32)' : 'TEXT' ) . ' NOT NULL';

        foreach ($collection['columns'] as $column) {
            $definitions[] = $this->quote($column['field']) . ' ' . $this->column_type($column);
        }

        $definitions[] = $this->quote('data') . ' ' . ( 'mysql' === $this->driver ? 'LONGTEXT' : 'TEXT' ) . ' NOT NULL';

        if ('mysql' === $this->driver) {
            foreach ($collection['columns'] as $column) {
                if ($column['unique']) {
                    $definitions[] = 'UNIQUE KEY ' . $this->quote($this->index_name($collection['table'], $column['field'])) . ' (' . $this->quote($column['field']) . ')';
                } elseif ($column['indexed']) {
                    $definitions[] = 'KEY ' . $this->quote($this->index_name($collection['table'], $column['field'])) . ' (' . $this->quote($column['field']) . ')';
                }
            }
        }

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quote($collection['table']) . ' (' . implode(', ', $definitions) . ')';

        return 'mysql' === $this->driver ? $sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : $sql;
    }

    /**
     * @param array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>} $collection
     * @return list<string>
     */
    private function create_index_sql(array $collection): array
    {
        if ('sqlite' !== $this->driver) {
            return array();
        }

        $statements = array();
        foreach ($collection['columns'] as $column) {
            if (! $column['unique'] && ! $column['indexed']) {
                continue;
            }

            $statements[] = 'CREATE ' . ( $column['unique'] ? 'UNIQUE ' : '' ) . 'INDEX IF NOT EXISTS '
                . $this->quote($this->index_name($collection['table'], $column['field']))
                . ' ON ' . $this->quote($collection['table'])
                . ' (' . $this->quote($column['field']) . ')';
        }

        return $statements;
    }

    /**
     * @param array{field: string, type: string, unique: bool, indexed: bool} $column
     */
    private function column_type(array $column): string
    {
        if ('sqlite' === $this->driver) {
            return match ($column['type']) {
                'string' => 'TEXT',
                'int', 'bool' => 'INTEGER',
                'float' => 'REAL',
                default => throw new StorageException('Unsupported SQL mirror column type: ' . $column['type']),
            };
        }

        return match ($column['type']) {
            'string' => $column['unique'] || $column['indexed'] ? 'VARCHAR(191)' : 'TEXT',
            'int' => 'BIGINT',
            'bool' => 'TINYINT(1)',
            'float' => 'DOUBLE',
            default => throw new StorageException('Unsupported SQL mirror column type: ' . $column['type']),
        };
    }

    /**
     * @param array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>} $collection
     */
    private function insert_sql(array $collection): string
    {
        $columns = array( 'id', 'hash' );
        foreach ($collection['columns'] as $column) {
            $columns[] = $column['field'];
        }
        $columns[] = 'data';

        $quoted       = array_map(fn(string $column): string => $this->quote($column), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        return 'INSERT INTO ' . $this->quote($collection['table'])
            . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
    }

    private function index_name(string $table, string $field): string
    {
        return $table . '_' . strtolower($field) . '_idx';
    }

    private function quote(string $identifier): string
    {
        return 'mysql' === $this->driver
            ? '`' . $identifier . '`'
            : '"' . $identifier . '"';
    }

    /**
     * @return array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>}
     */
    private function registered(string $name): array
    {
        if (! isset($this->collections[ $name ])) {
            throw new StorageException('Unknown SQL mirror collection: ' . $name);
        }

        return $this->collections[ $name ];
    }

    private function transactional(callable $callback): void
    {
        $this->connection->begin();

        try {
            $callback();
            $this->connection->commit();
        } catch (\Throwable $throwable) {
            if ($this->connection->in_transaction()) {
                $this->connection->rollback();
            }

            throw $throwable;
        }
    }
}
