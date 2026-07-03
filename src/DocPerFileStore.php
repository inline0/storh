<?php

declare(strict_types=1);

namespace Storh;

final class DocPerFileStore implements FileStoreInterface
{
    private const WRITE_CACHE_LIMIT = 100000;

    /** @var callable(): string */
    private mixed $id_generator;

    private bool $trusted_generated_ids;

    private CacheInterface $cache;

    private bool $cache_enabled;

    private ?DocStoreIndexManager $index_manager = null;

    private string $collection_path;

    private string $data_path;

    private string $temp_prefix;

    private int $temp_counter = 0;

    /** @var array<string, true> */
    private array $known_directories = array();

    /** @var array<string, string>|null */
    private ?array $record_path_cache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $record_data_cache = null;

    private ?bool $index_manifest_exists = null;

    public function __construct(
        private readonly string $root,
        private readonly string $collection,
        ?callable $id_generator = null,
        ?CacheInterface $cache = null,
        private readonly ?Schema $schema = null,
        private readonly string $cache_validation = CacheValidation::HASH
    ) {
        CacheValidation::assert_valid($this->cache_validation);

        if (null !== $this->schema && $this->schema->collection_name() !== $this->collection) {
            throw new StorageException('Schema collection does not match DocStore collection.');
        }

        $this->trusted_generated_ids = null === $id_generator;
        $this->id_generator          = $id_generator ?? static fn(): string => UuidV7::generate();
        $this->cache                 = $cache ?? Cache::null();
        $this->cache_enabled = ! $this->cache instanceof NullCache;
        $this->collection_path = rtrim($this->root, '/\\') . '/' . $this->collection;
        $this->data_path       = $this->collection_path . '/data';
        $this->temp_prefix     = (string) getmypid();
        AtomicFilesystem::cleanup_temp_files($this->collection_root());
        if (! is_dir($this->data_root())) {
            $this->record_path_cache = array();
            $this->record_data_cache = array();
        }

        if (null !== $this->schema) {
            $this->indexes()->apply_schema($this->schema)->sync();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(array $data, ?string $id = null): StorageRecord
    {
        $generated = null === $id;
        $id ??= ( $this->id_generator )();
        $this->assert_record_id($id, $generated);
        $this->schema?->validate($data);

        $indexes = $this->active_indexes();
        $old = null === $indexes || $generated ? null : $this->get($id);
        if (null !== $indexes) {
            $indexes->validate_unique($id, $data, $old?->data());
        }

        $record = new StorageRecord($id, $data);
        $directory = $this->record_directory_for_id($id);
        $path = $directory . '/' . $id . '.jsonc';
        $this->write_record_file($directory, $path, $id, $data);
        $this->remember_written_record($id, $path, $data);

        if (null !== $indexes) {
            $indexes->update_record($id, $data, $old?->data());
        }
        $this->cache_record($record, $path);

        return $record;
    }

    /**
     * @param iterable<array<string, mixed>> $records
     * @return list<StorageRecord>
     */
    public function putMany(iterable $records): array
    {
        $stored = array();
        $indexes = $this->active_indexes();
        $has_indexes = null !== $indexes;

        foreach ($records as $record) {
            $id   = isset($record['id']) && is_string($record['id']) ? $record['id'] : null;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
            $generated = null === $id;
            $id ??= ( $this->id_generator )();
            $this->assert_record_id($id, $generated);

            /** @var array<string, mixed> $data */
            $this->schema?->validate($data);

            $old = $generated || ! $has_indexes ? null : $this->get($id);
            if ($has_indexes) {
                $indexes->validate_unique($id, $data, $old?->data());
            }

            $directory = $this->record_directory_for_id($id);
            $path = $directory . '/' . $id . '.jsonc';
            $this->write_record_file($directory, $path, $id, $data);
            $this->remember_written_record($id, $path, $data);

            if ($has_indexes) {
                $indexes->update_record($id, $data, $old?->data());
            }

            $storage_record = new StorageRecord($id, $data);
            $this->cache_record($storage_record, $path);
            $stored[] = $storage_record;
        }

        return $stored;
    }

    /**
     * @param iterable<array<string, mixed>> $records
     */
    public function putStream(iterable $records): int
    {
        $count = 0;
        $indexes = $this->active_indexes();
        $has_indexes = null !== $indexes;

        foreach ($records as $record) {
            $id   = isset($record['id']) && is_string($record['id']) ? $record['id'] : null;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
            $generated = null === $id;
            $id ??= ( $this->id_generator )();
            $this->assert_record_id($id, $generated);

            /** @var array<string, mixed> $data */
            $this->schema?->validate($data);

            $old = $generated || ! $has_indexes ? null : $this->get($id);
            if ($has_indexes) {
                $indexes->validate_unique($id, $data, $old?->data());
            }

            $directory = $this->record_directory_for_id($id);
            $path = $directory . '/' . $id . '.jsonc';
            $this->write_record_file($directory, $path, $id, $data);
            $this->remember_written_record($id, $path, $data, false);

            if ($has_indexes) {
                $indexes->update_record($id, $data, $old?->data());
            }

            if ($this->cache_enabled) {
                $this->cache_record(new StorageRecord($id, $data), $path);
            }

            $count++;
        }

        return $count;
    }

    public function get(string $id): ?StorageRecord
    {
        UuidV7::assert_valid($id);
        $path = $this->record_path_for_id($id);

        if (isset($this->record_data_cache[ $id ]) && is_file($path)) {
            return new StorageRecord($id, $this->record_data_cache[ $id ]);
        }

        $cached = $this->cached_record($id, $path);
        if ($cached instanceof StorageRecord || false === $cached) {
            return false === $cached ? null : $cached;
        }

        if (! is_file($path)) {
            $this->cache_missing($id, $path);
            return null;
        }

        $record = $this->record_from_file($path, $id);
        $this->cache_record($record);

        return $record;
    }

    public function delete(string $id): void
    {
        UuidV7::assert_valid($id);
        $path = $this->record_path_for_id($id);
        $old  = is_file($path) ? $this->get($id) : null;

        if (is_file($path) && ! @unlink($path)) {
            throw new StorageException('Could not delete storage record: ' . $id);
        }
        if (null !== $this->record_path_cache) {
            unset($this->record_path_cache[ $id ]);
        }
        if (null !== $this->record_data_cache) {
            unset($this->record_data_cache[ $id ]);
        }

        if (null !== $old) {
            $this->indexes()->remove_record($id, $old->data());
        }

        $this->cache_missing($id, $path);
    }

    /**
     * @return \Generator<int, StorageRecord>
     */
    public function stream(?RecordQuery $query = null): \Generator
    {
        $query ??= RecordQuery::all();
        $count = 0;

        if (null !== $this->record_path_cache && null !== $this->record_data_cache) {
            $ids = array_keys($this->record_path_cache);
            sort($ids);
            foreach ($ids as $id) {
                $data = $this->record_data_cache[ $id ] ?? null;
                if (null === $data) {
                    continue;
                }

                $record = new StorageRecord($id, $data);
                if (! $query->matches($record)) {
                    continue;
                }

                yield $record;
                $count++;

                if (null !== $query->limit_value() && $count >= $query->limit_value()) {
                    return;
                }
            }

            return;
        }

        foreach ($this->record_paths() as $path) {
            $id = basename($path, '.jsonc');

            try {
                $record = $this->record_from_file($path, $id);
            } catch (\Throwable $throwable) {
                if ($query->handle_error($id, $throwable)) {
                    continue;
                }

                throw $throwable;
            }

            if (! $query->matches($record)) {
                continue;
            }

            yield $record;
            $count++;

            if (null !== $query->limit_value() && $count >= $query->limit_value()) {
                return;
            }
        }
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function indexes(): DocStoreIndexManager
    {
        $this->index_manager ??= new DocStoreIndexManager($this);

        return $this->index_manager;
    }

    private function active_indexes(): ?DocStoreIndexManager
    {
        if (null !== $this->index_manager) {
            return array() === $this->index_manager->definitions() ? null : $this->index_manager;
        }

        if (! $this->has_index_manifest()) {
            return null;
        }

        $indexes = $this->indexes();

        return array() === $indexes->definitions() ? null : $indexes;
    }

    private function has_index_manifest(): bool
    {
        $this->index_manifest_exists ??= is_file($this->collection_root() . '/.storh/indexes/manifest.jsonc');

        return $this->index_manifest_exists;
    }

    /**
     * @return list<StorageRecord>
     */
    public function query_records(QueryBuilder $query): array
    {
        $ids     = $this->indexes()->candidate_ids($query);
        $records = array();
        $limit   = $query->limit_value();
        $can_stop_early = null !== $limit && ! $query->has_ordering();

        if (null !== $ids) {
            foreach ($ids as $id) {
                $record = $this->get($id);
                if (null !== $record && $query->matches($record)) {
                    $records[] = $record;
                    if ($can_stop_early && count($records) >= $limit) {
                        return $records;
                    }
                }
            }

            return $records;
        }

        foreach ($this->stream() as $record) {
            if ($query->matches($record)) {
                $records[] = $record;
                if ($can_stop_early && count($records) >= $limit) {
                    return $records;
                }
            }
        }

        return $records;
    }

    /**
     * @return array{store: string, plan: string, indexes: list<array<string, mixed>>, groups: int}
     */
    public function explain(?QueryBuilder $query = null): array
    {
        return $this->indexes()->explain($query ?? $this->query());
    }

    /**
     * @return array{fields: int, entries: int}
     */
    public function reindex(): array
    {
        return $this->indexes()->rebuild();
    }

    /**
     * @return array{records: int, bytes: int, corrupt: int, indexes: int}
     */
    public function stats(): array
    {
        $records = 0;
        $bytes   = 0;
        $corrupt = 0;

        foreach ($this->record_paths() as $path) {
            $bytes += is_file($path) ? (int) filesize($path) : 0;
            try {
                $this->record_from_file($path, basename($path, '.jsonc'));
                $records++;
            } catch (\Throwable) {
                $corrupt++;
            }
        }

        return array(
            'records' => $records,
            'bytes'   => $bytes,
            'corrupt' => $corrupt,
            'indexes' => count($this->indexes()->definitions()),
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>, stats: array<string, int>}
     */
    public function health(): array
    {
        $stats  = $this->stats();
        $errors = array();
        if ($stats['corrupt'] > 0) {
            $errors[] = 'Corrupt records: ' . $stats['corrupt'];
        }

        return array(
            'ok'     => array() === $errors,
            'errors' => $errors,
            'stats'  => $stats,
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>, stats: array<string, int>}
     */
    public function verify(): array
    {
        return $this->health();
    }

    /**
     * @return array{ok: bool, reindexed: array{fields: int, entries: int}}
     */
    public function repair(): array
    {
        AtomicFilesystem::cleanup_temp_files($this->collection_root());

        return array(
            'ok'        => true,
            'reindexed' => $this->reindex(),
        );
    }

    /**
     * @return array{ok: bool, records: int}
     */
    public function compact(): array
    {
        return array(
            'ok'      => true,
            'records' => $this->stats()['records'],
        );
    }

    public function importJsonl(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new StorageException('Could not open JSONL import file: ' . $path);
        }

        try {
            return $this->putStream($this->jsonl_records($handle));
        } finally {
            fclose($handle);
        }
    }

    public function exportJsonl(string $path): int
    {
        AtomicFilesystem::ensure_directory(dirname($path));
        $handle = @fopen($path, 'wb');
        if (false === $handle) {
            throw new StorageException('Could not open JSONL export file: ' . $path);
        }

        $count = 0;
        try {
            foreach ($this->stream() as $record) {
                AtomicFilesystem::write_all(
                    $handle,
                    json_encode(
                        array( 'id' => $record->id(), 'data' => $record->data() ),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ) . "\n",
                    $path
                );
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    public function path_for_id(string $id): string
    {
        UuidV7::assert_valid($id);

        $this->record_path_cache = null;
        $this->record_data_cache = null;

        return $this->record_path_for_id($id);
    }

    private function record_path_for_id(string $id): string
    {
        return $this->record_directory_for_id($id) . '/' . $id . '.jsonc';
    }

    private function record_directory_for_id(string $id): string
    {
        return $this->data_root() . '/' . substr($id, 24, 2);
    }

    private function assert_record_id(string $id, bool $generated): void
    {
        if ($generated && $this->trusted_generated_ids) {
            return;
        }

        UuidV7::assert_valid($id);
    }

    public function collection_root(): string
    {
        return $this->collection_path;
    }

    private function data_root(): string
    {
        return $this->data_path;
    }

    /**
     * @return list<string>
     */
    public function record_paths(): array
    {
        $root = $this->data_root();
        if (! is_dir($root)) {
            return array();
        }

        if (null !== $this->record_path_cache) {
            $paths = $this->record_path_cache;
            ksort($paths);

            return array_values($paths);
        }

        $paths    = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'jsonc' === $file->getExtension()) {
                $paths[ $file->getBasename('.jsonc') ] = $file->getPathname();
            }
        }

        ksort($paths);

        return array_values($paths);
    }

    private function record_from_file(string $path, string $expected_id): StorageRecord
    {
        $decoded = $this->read_record_object($path);
        $id      = isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : '';
        $data    = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();

        UuidV7::assert_valid($id);
        if ($id !== $expected_id) {
            throw new StorageException('Storage record id does not match its path.');
        }

        /** @var array<string, mixed> $data */
        return new StorageRecord($id, $data);
    }

    private function cache_key(string $id): string
    {
        return 'doc:' . $this->collection . ':' . $id;
    }

    private function cached_record(string $id, string $path): StorageRecord|false|null
    {
        if (! $this->cache_enabled) {
            return null;
        }

        $cached = $this->cache->get($this->cache_key($id));
        if (! is_array($cached) || ! isset($cached['exists'], $cached['mtime'], $cached['size'])) {
            return null;
        }

        if (isset($cached['path']) && $cached['path'] !== $path) {
            $this->cache->delete($this->cache_key($id));
            return null;
        }

        if (CacheValidation::TRUST === $this->cache_validation) {
            if (false === $cached['exists']) {
                return false;
            }

            $data = isset($cached['data']) && is_array($cached['data']) ? $cached['data'] : array();
            /** @var array<string, mixed> $data */
            return new StorageRecord($id, $data);
        }

        clearstatcache(true, $path);
        $exists = is_file($path);
        $mtime  = $exists ? (int) filemtime($path) : 0;
        $size   = $exists ? (int) filesize($path) : -1;
        $hash   = $exists && CacheValidation::HASH === $this->cache_validation ? (string) sha1_file($path) : '';

        if (
            $exists !== $cached['exists'] ||
            $mtime !== $cached['mtime'] ||
            $size !== $cached['size'] ||
            ( CacheValidation::HASH === $this->cache_validation && $exists && ( $cached['hash'] ?? '' ) !== $hash )
        ) {
            $this->cache->delete($this->cache_key($id));
            return null;
        }

        if (false === $cached['exists']) {
            return false;
        }

        $data = isset($cached['data']) && is_array($cached['data']) ? $cached['data'] : array();
        /** @var array<string, mixed> $data */
        return new StorageRecord($id, $data);
    }

    private function cache_record(StorageRecord $record, ?string $path = null): void
    {
        if (! $this->cache_enabled) {
            return;
        }

        $path ??= $this->record_path_for_id($record->id());
        clearstatcache(true, $path);
        $exists = is_file($path);
        $this->cache->set(
            $this->cache_key($record->id()),
            array(
                'exists' => true,
                'path'   => $path,
                'mtime'  => $exists ? (int) filemtime($path) : 0,
                'size'   => $exists ? (int) filesize($path) : -1,
                'hash'   => $exists && CacheValidation::HASH === $this->cache_validation ? (string) sha1_file($path) : '',
                'data'   => $record->data(),
            )
        );
    }

    private function cache_missing(string $id, string $path): void
    {
        if (! $this->cache_enabled) {
            return;
        }

        clearstatcache(true, $path);
        $this->cache->set(
            $this->cache_key($id),
            array(
                'exists' => false,
                'path'   => $path,
                'mtime'  => 0,
                'size'   => -1,
                'hash'   => '',
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write_record_file(string $directory, string $path, string $id, array $data): void
    {
        $contents = json_encode(
            array(
                'id'   => $id,
                'data' => $data,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";

        $this->ensure_known_directory($directory);
        $temp = $directory . '/.' . $id . '.jsonc.' . $this->temp_prefix . '.' . ++$this->temp_counter . '.tmp';
        $handle = @fopen($temp, 'wb');
        if (false === $handle) {
            throw new StorageException('Could not open temporary storage file for writing: ' . $temp);
        }

        try {
            AtomicFilesystem::write_all($handle, $contents, $temp);
        } finally {
            fclose($handle);
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new StorageException('Could not atomically replace storage file: ' . $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read_record_object(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new StorageException('Could not read storage file: ' . $path);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ( array() !== $decoded && array_is_list($decoded) )) {
                throw new StorageException('JSONC document must decode to an object.');
            }

            $object = array();
            foreach ($decoded as $key => $value) {
                if (! is_string($key)) {
                    throw new StorageException('JSONC document must decode to an object.');
                }

                $object[ $key ] = $value;
            }

            return $object;
        } catch (\JsonException) {
            return AtomicFilesystem::read_jsonc_object($path);
        }
    }

    private function ensure_known_directory(string $directory): void
    {
        if (isset($this->known_directories[ $directory ])) {
            return;
        }

        AtomicFilesystem::ensure_directory($directory);
        $this->known_directories[ $directory ] = true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function remember_written_record(string $id, string $path, array $data, bool $cache_data = true): void
    {
        if (null !== $this->record_path_cache) {
            if (count($this->record_path_cache) >= self::WRITE_CACHE_LIMIT) {
                $this->record_path_cache = null;
            } else {
                $this->record_path_cache[ $id ] = $path;
            }
        }

        if (! $cache_data) {
            $this->record_data_cache = null;
            return;
        }

        if (null !== $this->record_data_cache) {
            if (count($this->record_data_cache) >= self::WRITE_CACHE_LIMIT) {
                $this->record_data_cache = null;
            } else {
                $this->record_data_cache[ $id ] = $data;
            }
        }
    }

    /**
     * @param resource $handle
     * @return \Generator<int, array<string, mixed>>
     */
    private function jsonl_records(mixed $handle): \Generator
    {
        while (false !== ( $line = fgets($handle) )) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new StorageException('JSONL import rows must be objects.');
            }

            $record = array();
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $record[ $key ] = $value;
                }
            }

            yield $record;
        }
    }
}
