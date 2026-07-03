<?php

declare(strict_types=1);

namespace Storh;

final class DocPerFileStore implements FileStoreInterface
{
    /** @var callable(): string */
    private mixed $id_generator;

    public function __construct(
        private readonly string $root,
        private readonly string $collection,
        ?callable $id_generator = null
    ) {
        $this->id_generator = $id_generator ?? static fn(): string => UuidV7::generate();
        AtomicFilesystem::cleanup_temp_files($this->collection_root());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(array $data, ?string $id = null): StorageRecord
    {
        $id ??= ( $this->id_generator )();
        UuidV7::assert_valid($id);

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

        return $record;
    }

    public function get(string $id): ?StorageRecord
    {
        UuidV7::assert_valid($id);
        $path = $this->path_for_id($id);

        if (! is_file($path)) {
            return null;
        }

        return $this->record_from_file($path, $id);
    }

    public function delete(string $id): void
    {
        UuidV7::assert_valid($id);
        $path = $this->path_for_id($id);

        if (is_file($path) && ! @unlink($path)) {
            throw new StorageException('Could not delete storage record: ' . $id);
        }
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

    public function path_for_id(string $id): string
    {
        UuidV7::assert_valid($id);

        return $this->collection_root() . '/data/' . substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id . '.jsonc';
    }

    private function collection_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->collection;
    }

    /**
     * @return list<string>
     */
    private function record_paths(): array
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
}
