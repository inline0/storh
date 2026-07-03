<?php

declare(strict_types=1);

namespace Storh;

final class DocPerFileStore implements FileStoreInterface
{
    /** @var callable(): string */
    private mixed $id_generator;

    private CacheInterface $cache;

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

        $this->id_generator = $id_generator ?? static fn(): string => UuidV7::generate();
        $this->cache        = $cache ?? Cache::null();
        AtomicFilesystem::cleanup_temp_files($this->collection_root());

        if (null !== $this->schema) {
            $this->indexes()->apply_schema($this->schema)->sync();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(array $data, ?string $id = null): StorageRecord
    {
        $id ??= ( $this->id_generator )();
        UuidV7::assert_valid($id);
        $this->schema?->validate($data);

        $old = $this->get($id);
        $this->indexes()->validate_unique($id, $data, $old?->data());

        $record = new StorageRecord($id, $data);
        AtomicFilesystem::write_atomic(
            $this->path_for_id($id),
            Jsonc::encode_object(
                array(
                    'id'   => $id,
                    'data' => $data,
                )
            )
        );

        $this->indexes()->update_record($id, $data, $old?->data());
        $this->cache_record($record);

        return $record;
    }

    /**
     * @param iterable<array<string, mixed>> $records
     * @return list<StorageRecord>
     */
    public function putMany(iterable $records): array
    {
        $stored = array();
        foreach ($records as $record) {
            $id   = isset($record['id']) && is_string($record['id']) ? $record['id'] : null;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;

            /** @var array<string, mixed> $data */
            $stored[] = $this->put($data, $id);
        }

        return $stored;
    }

    public function get(string $id): ?StorageRecord
    {
        UuidV7::assert_valid($id);
        $path = $this->path_for_id($id);

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
        $path = $this->path_for_id($id);
        $old  = $this->get($id);

        if (is_file($path) && ! @unlink($path)) {
            throw new StorageException('Could not delete storage record: ' . $id);
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
        return new DocStoreIndexManager($this);
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

        $count = 0;
        try {
            while (false !== ( $line = fgets($handle) )) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new StorageException('JSONL import rows must be objects.');
                }

                $id   = isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : null;
                $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
                /** @var array<string, mixed> $data */
                $this->put($data, $id);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
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

        return $this->collection_root() . '/data/' . substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id . '.jsonc';
    }

    public function collection_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->collection;
    }

    /**
     * @return list<string>
     */
    public function record_paths(): array
    {
        $root = $this->collection_root() . '/data';
        if (! is_dir($root)) {
            return array();
        }

        $paths    = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'jsonc' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    private function record_from_file(string $path, string $fallback_id): StorageRecord
    {
        $decoded = AtomicFilesystem::read_jsonc_object($path);
        $id      = isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : $fallback_id;
        $data    = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();

        UuidV7::assert_valid($id);

        /** @var array<string, mixed> $data */
        return new StorageRecord($id, $data);
    }

    private function cache_key(string $id): string
    {
        return 'doc:' . $this->collection . ':' . $id;
    }

    private function cached_record(string $id, string $path): StorageRecord|false|null
    {
        $cached = $this->cache->get($this->cache_key($id));
        if (! is_array($cached) || ! isset($cached['exists'], $cached['mtime'], $cached['size'])) {
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

    private function cache_record(StorageRecord $record): void
    {
        $path = $this->path_for_id($record->id());
        clearstatcache(true, $path);
        $this->cache->set(
            $this->cache_key($record->id()),
            array(
                'exists' => true,
                'mtime'  => is_file($path) ? (int) filemtime($path) : 0,
                'size'   => is_file($path) ? (int) filesize($path) : -1,
                'hash'   => is_file($path) && CacheValidation::HASH === $this->cache_validation ? (string) sha1_file($path) : '',
                'data'   => $record->data(),
            )
        );
    }

    private function cache_missing(string $id, string $path): void
    {
        clearstatcache(true, $path);
        $this->cache->set(
            $this->cache_key($id),
            array(
                'exists' => false,
                'mtime'  => 0,
                'size'   => -1,
                'hash'   => '',
            )
        );
    }
}
