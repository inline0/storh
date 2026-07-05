<?php

declare(strict_types=1);

namespace Storh;

final class SqlMirror
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const HASH_ALGORITHM = 'xxh128';

    private const DELETE_CHUNK_SIZE = 500;

    private const RESERVED_COLUMNS = array( 'id', 'hash', 'data' );

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
        private readonly \PDO $pdo,
        private readonly string $prefix = 'storh_',
        ?string $driver = null
    ) {
        $detected = $driver ?? $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (! is_string($detected) || ! in_array($detected, array( 'sqlite', 'mysql' ), true)) {
            throw new StorageException('Unsupported SQL mirror driver: ' . (is_string($detected) ? $detected : gettype($detected)));
        }

        if (1 !== preg_match('/^[A-Za-z0-9_]*$/', $this->prefix)) {
            throw new StorageException('SQL mirror table prefix must match [A-Za-z0-9_]*.');
        }

        $this->driver = $detected;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
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
            $this->execute($this->create_table_sql($collection));
            foreach ($this->create_index_sql($collection) as $sql) {
                $this->execute($sql);
            }
        }
    }

    public function uninstall(): void
    {
        foreach ($this->collections as $collection) {
            $this->execute('DROP TABLE IF EXISTS ' . $this->quote($collection['table']));
        }
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
     */
    public function push(): array
    {
        $totals = array(
            'inserted'  => 0,
            'updated'   => 0,
            'deleted'   => 0,
            'unchanged' => 0,
        );

        foreach (array_keys($this->collections) as $name) {
            $result = $this->push_collection($name);
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
    public function rebuild(): array
    {
        foreach ($this->collections as $collection) {
            $this->execute('DELETE FROM ' . $this->quote($collection['table']));
        }

        return $this->push();
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
                $upsert = $this->prepare($this->upsert_sql($collection));
                $delete = $this->prepare(
                    'DELETE FROM ' . $this->quote($collection['table']) . ' WHERE ' . $this->quote('id') . ' = ?'
                );

                $seen = array();
                foreach ($ids as $id) {
                    if (isset($seen[ $id ])) {
                        continue;
                    }

                    $seen[ $id ] = true;
                    $record = $collection['store']->get($id);
                    if (null === $record) {
                        $delete->execute(array( $id ));
                        $deleted++;
                        continue;
                    }

                    $upsert->execute($this->row_values($collection, $record->id(), $record->data()));
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
                $upsert = $this->prepare($this->upsert_sql($collection));

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

                        $upsert->execute($this->row_values($collection, $id, $data));
                        $updated++;
                        continue;
                    }

                    $upsert->execute($this->row_values($collection, $id, $data));
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
            $statement    = $this->prepare(
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
        $statement = $this->prepare(
            'SELECT ' . $this->quote('id') . ', ' . $this->quote('hash') . ' FROM ' . $this->quote($table)
        );
        $statement->execute();

        $hashes = array();
        while (false !== ( $row = $statement->fetch(\PDO::FETCH_NUM) )) {
            if (is_array($row) && isset($row[0], $row[1]) && is_string($row[0]) && is_string($row[1])) {
                $hashes[ $row[0] ] = $row[1];
            }
        }

        return $hashes;
    }

    /**
     * @param array{store: FileStoreInterface, table: string, columns: list<array{field: string, type: string, unique: bool, indexed: bool}>} $collection
     * @param array<string, mixed> $data
     * @return list<mixed>
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
    private function upsert_sql(array $collection): string
    {
        $columns = array( 'id', 'hash' );
        foreach ($collection['columns'] as $column) {
            $columns[] = $column['field'];
        }
        $columns[] = 'data';

        $quoted       = array_map(fn(string $column): string => $this->quote($column), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert       = 'INSERT INTO ' . $this->quote($collection['table'])
            . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';

        $updates = array();
        foreach ($columns as $column) {
            if ('id' === $column) {
                continue;
            }

            $updates[] = 'sqlite' === $this->driver
                ? $this->quote($column) . ' = excluded.' . $this->quote($column)
                : $this->quote($column) . ' = VALUES(' . $this->quote($column) . ')';
        }

        if ('sqlite' === $this->driver) {
            return $insert . ' ON CONFLICT(' . $this->quote('id') . ') DO UPDATE SET ' . implode(', ', $updates);
        }

        return $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
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

    private function execute(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        // @codeCoverageIgnoreStart
        if (false === $statement) {
            throw new StorageException('SQL mirror statement failed: ' . $sql);
        }
        // @codeCoverageIgnoreEnd

        return $statement;
    }

    private function transactional(callable $callback): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $callback();
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($throwable instanceof \PDOException) {
                throw new StorageException('SQL mirror write failed: ' . $throwable->getMessage(), 0, $throwable);
            }

            throw $throwable;
        }
    }
}
