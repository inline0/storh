<?php

declare(strict_types=1);

namespace Storh;

final class SegmentedLogStore implements FileStoreInterface
{
    /** @var callable(): string */
    private mixed $id_generator;

    public function __construct(
        private readonly string $root,
        private readonly string $collection,
        private readonly int $max_segment_bytes = 1048576,
        private readonly int $sparse_index_interval = 64,
        ?callable $id_generator = null
    ) {
        if ($this->max_segment_bytes < 256) {
            throw new StorageException('Segment size must be at least 256 bytes.');
        }

        if ($this->sparse_index_interval < 1) {
            throw new StorageException('Sparse index interval must be at least 1.');
        }

        $this->id_generator = $id_generator ?? static fn(): string => UuidV7::generate();
        $this->initialize();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(array $data, ?string $id = null): StorageRecord
    {
        $id ??= ( $this->id_generator )();
        UuidV7::assert_valid($id);

        $this->with_lock(
            function () use ($id, $data): void {
                $this->append_envelope(
                    array(
                        'op'   => 'put',
                        'id'   => $id,
                        'data' => $data,
                    )
                );
            }
        );

        return new StorageRecord($id, $data);
    }

    public function get(string $id): ?StorageRecord
    {
        UuidV7::assert_valid($id);
        $entry = $this->state_entry($id);

        if (null === $entry || $entry['deleted']) {
            return null;
        }

        $last_exception = null;
        foreach ($this->state_locations($entry) as $location) {
            try {
                $envelope = $this->read_envelope_at($location['file'], $location['offset']);
            } catch (StorageException $exception) {
                $last_exception = $exception;
                continue;
            }

            if ($id === $envelope['id'] && 'put' === $envelope['op']) {
                return $this->record_from_envelope($envelope);
            }

            $last_exception = new StorageException('Segmented log state index points at the wrong record.');
        }

        throw $last_exception ?? new StorageException('Segmented log state index points at the wrong record.');
    }

    public function delete(string $id): void
    {
        UuidV7::assert_valid($id);

        $this->with_lock(
            function () use ($id): void {
                $this->append_envelope(
                    array(
                        'op' => 'delete',
                        'id' => $id,
                    )
                );
            }
        );
    }

    /**
     * @return \Generator<int, StorageRecord>
     */
    public function stream(?RecordQuery $query = null): \Generator
    {
        $query ??= RecordQuery::all();
        $count  = 0;

        foreach ($this->query_segments($query) as $segment) {
            $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
            if ('' === $file) {
                continue;
            }

            $offset = $this->seek_offset_for($segment, $query->after_id());
            $query->notify_segment_open($file);

            $handle = @fopen($this->segment_path($file), 'rb');
            if (false === $handle) {
                throw new StorageException('Could not open segment: ' . $file);
            }

            try {
                if ($offset > 0) {
                    fseek($handle, $offset);
                }

                while (true) {
                    $line_offset = ftell($handle);
                    $line        = fgets($handle);
                    if (false === $line || false === $line_offset) {
                        break;
                    }

                    try {
                        $envelope = $this->decode_line($line);
                    } catch (\Throwable $throwable) {
                        if ($query->handle_error($file . ':' . $line_offset, $throwable)) {
                            continue;
                        }

                        throw $throwable;
                    }

                    $id    = $envelope['id'];
                    $entry = $this->state_entry($id);
                    if (null === $entry || $entry['deleted'] || ! $this->state_entry_matches($entry, $file, $line_offset)) {
                        continue;
                    }

                    $record = $this->record_from_envelope($envelope);
                    if (! $query->matches($record)) {
                        continue;
                    }

                    yield $record;
                    $count++;

                    if (null !== $query->limit_value() && $count >= $query->limit_value()) {
                        return;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    public function compact(): void
    {
        $this->with_lock(
            function (): void {
                $this->recover_unlocked();
                $this->roll_active_segment();

                $source_segments = $this->sealed_segments();
                if (array() === $source_segments) {
                    $manifest           = $this->manifest();
                    $manifest['sealed'] = array();
                    AtomicFilesystem::write_atomic($this->manifest_path(), Jsonc::encode_object($manifest));
                    return;
                }

                $compacted_segments = $this->write_compacted_segments($source_segments);
                $manifest           = $this->manifest();
                $manifest['sealed'] = $compacted_segments;

                AtomicFilesystem::write_atomic($this->manifest_path(), Jsonc::encode_object($manifest));
            }
        );
    }

    public function recover(): void
    {
        $this->with_lock(
            function (): void {
                $this->recover_unlocked();
            }
        );
    }

    /**
     * @return array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}>
     */
    public function state_index(): array
    {
        if (! is_dir($this->state_index_root())) {
            $this->recover();
        }

        return $this->read_state_index();
    }

    private function initialize(): void
    {
        AtomicFilesystem::ensure_directory($this->collection_root());
        $this->with_lock(
            function (): void {
                AtomicFilesystem::ensure_directory($this->segments_root());
                AtomicFilesystem::cleanup_temp_files($this->collection_root());
                $this->delete_compaction_leftovers();

                if (! is_file($this->manifest_path())) {
                    $active_file = $this->segment_file_name(1);
                    @touch($this->segment_path($active_file));
                    AtomicFilesystem::write_atomic(
                        $this->manifest_path(),
                        Jsonc::encode_object(
                            array(
                                'nextSegment' => 2,
                                'sealed'      => array(),
                                'active'      => array(
                                    'file'    => $active_file,
                                    'max'     => null,
                                    'min'     => null,
                                    'records' => 0,
                                ),
                            )
                        )
                    );
                }

                $manifest = $this->manifest();
                $active   = isset($manifest['active']) && is_array($manifest['active']) ? $manifest['active'] : array();
                $file     = isset($active['file']) && is_string($active['file']) ? $active['file'] : $this->segment_file_name(1);
                if (! is_file($this->segment_path($file))) {
                    @touch($this->segment_path($file));
                }

                $this->recover_unlocked();
            }
        );
    }

    private function recover_unlocked(): void
    {
        foreach ($this->all_segments() as $segment) {
            if (isset($segment['file']) && is_string($segment['file'])) {
                $this->recover_segment($this->segment_path($segment['file']));
            }
        }

        $this->replace_state_index($this->build_state_index());
    }

    /**
     * @return array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}>
     */
    private function read_state_index(): array
    {
        $state = array();

        if (! is_dir($this->state_index_root())) {
            return $state;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->state_index_root(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || 'jsonc' !== $file->getExtension()) {
                continue;
            }

            $id    = basename($file->getPathname(), '.jsonc');
            $entry = $this->read_state_entry_file($file->getPathname());
            if (null !== $entry) {
                $state[ $id ] = $entry;
            }
        }

        ksort($state);

        return $state;
    }

    /**
     * @param array{id: string, op: string, data?: array<string, mixed>} $envelope
     */
    private function append_envelope(array $envelope): void
    {
        $manifest = $this->manifest();
        $active   = isset($manifest['active']) && is_array($manifest['active']) ? $manifest['active'] : array();
        $file     = isset($active['file']) && is_string($active['file']) ? $active['file'] : $this->segment_file_name(1);
        $path     = $this->segment_path($file);
        $handle   = @fopen($path, 'c+b');

        if (false === $handle) {
            throw new StorageException('Could not open active segment.');
        }

        try {
            fseek($handle, 0, SEEK_END);
            $offset = ftell($handle);
            $offset = false === $offset ? 0 : $offset;
            AtomicFilesystem::write_all($handle, $this->encode_line($envelope), $path);
            fflush($handle);
        } finally {
            fclose($handle);
        }

        $id = $envelope['id'];
        $active_max = isset($active['max']) && is_string($active['max']) ? $active['max'] : null;
        $active_min = isset($active['min']) && is_string($active['min']) ? $active['min'] : null;
        $active_records = isset($active['records']) && is_int($active['records']) ? $active['records'] : 0;
        $manifest['active'] = array(
            'file'    => $file,
            'max'     => null === $active_max || strcmp($id, $active_max) > 0 ? $id : $active_max,
            'min'     => null === $active_min || strcmp($id, $active_min) < 0 ? $id : $active_min,
            'records' => $active_records + 1,
        );

        AtomicFilesystem::write_atomic($this->manifest_path(), Jsonc::encode_object($manifest));
        $this->write_state_entry(
            $id,
            array(
                'deleted' => 'delete' === $envelope['op'],
                'file'    => $file,
                'offset'  => $offset,
                'aliases' => array(),
            )
        );

        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) >= $this->max_segment_bytes) {
            $this->roll_active_segment();
        }
    }

    private function roll_active_segment(): void
    {
        $manifest = $this->manifest();
        $active   = isset($manifest['active']) && is_array($manifest['active']) ? $manifest['active'] : array();
        $active_records = isset($active['records']) && is_int($active['records']) ? $active['records'] : 0;
        if (0 === $active_records) {
            return;
        }

        $file = isset($active['file']) && is_string($active['file']) ? $active['file'] : $this->segment_file_name(1);
        $path = $this->segment_path($file);
        if (! is_file($path)) {
            throw new StorageException('Could not roll missing active segment.');
        }

        $index = $this->index_file_name_for_segment($file);
        $this->write_sparse_index_to($this->segment_path($index), $this->segment_offsets($file));

        $sealed     = isset($manifest['sealed']) && is_array($manifest['sealed']) ? $manifest['sealed'] : array();
        $sealed[]   = array(
            'file'    => $file,
            'index'   => $index,
            'max'     => $active['max'] ?? null,
            'min'     => $active['min'] ?? null,
            'records' => $active_records,
        );
        $next_segment = isset($manifest['nextSegment']) && is_int($manifest['nextSegment'])
            ? $manifest['nextSegment']
            : 1;
        $next       = max($next_segment, $this->next_segment_number_after($file));
        $active_new = $this->next_available_segment_file($next);

        $manifest['sealed']      = $sealed;
        $manifest['nextSegment'] = $this->next_segment_number_after($active_new);
        $manifest['active']      = array(
            'file'    => $active_new,
            'max'     => null,
            'min'     => null,
            'records' => 0,
        );

        @touch($this->segment_path($active_new));
        AtomicFilesystem::write_atomic($this->manifest_path(), Jsonc::encode_object($manifest));
    }

    /**
     * @param list<array<string, mixed>> $source_segments
     * @return list<array<string, mixed>>
     */
    private function write_compacted_segments(array $source_segments): array
    {
        $token           = bin2hex(random_bytes(4));
        $output_segments = array();
        $output_number   = 1;
        $output_file     = '';
        $output_handle   = null;
        $output_path     = '';
        $output_offsets  = array();
        $output_records  = 0;
        $output_min      = null;
        $output_max      = null;

        try {
            foreach ($source_segments as $segment) {
                $input_file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
                if ('' === $input_file) {
                    continue;
                }

                $input_handle = @fopen($this->segment_path($input_file), 'rb');
                if (false === $input_handle) {
                    throw new StorageException('Could not open segment: ' . $input_file);
                }

                try {
                    while (true) {
                        $input_offset = ftell($input_handle);
                        $line         = fgets($input_handle);
                        if (false === $input_offset || false === $line) {
                            break;
                        }

                        $envelope = $this->decode_line($line);
                        $id    = $envelope['id'];
                        $entry = $this->state_entry($id);

                        if ('delete' === $envelope['op']) {
                            if (null !== $entry && $entry['deleted'] && $this->state_entry_matches($entry, $input_file, $input_offset)) {
                                @unlink($this->state_entry_path($id));
                            }

                            continue;
                        }

                        if ('put' !== $envelope['op']) {
                            continue;
                        }

                        if (null === $entry || $entry['deleted'] || ! $this->state_entry_matches($entry, $input_file, $input_offset)) {
                            continue;
                        }

                        $record = $this->record_from_envelope($envelope);
                        if (null === $output_handle) {
                            $opened        = $this->open_compaction_segment($token, $output_number);
                            $output_file   = $opened['file'];
                            $output_path   = $opened['path'];
                            $output_handle = $opened['handle'];
                            $output_number = $opened['nextNumber'];
                        }

                        $output_offset = ftell($output_handle);
                        $output_offset = false === $output_offset ? 0 : $output_offset;

                        AtomicFilesystem::write_all(
                            $output_handle,
                            $this->encode_line(
                                array(
                                    'op'   => 'put',
                                    'id'   => $record->id(),
                                    'data' => $record->data(),
                                )
                            ),
                            $output_path
                        );

                        if (0 === $output_records % $this->sparse_index_interval) {
                            $output_offsets[] = array( $record->id(), $output_offset );
                        }

                        $output_records++;
                        $output_min = null === $output_min || strcmp($record->id(), $output_min) < 0 ? $record->id() : $output_min;
                        $output_max = null === $output_max || strcmp($record->id(), $output_max) > 0 ? $record->id() : $output_max;

                        $this->write_state_entry(
                            $record->id(),
                            array(
                                'deleted' => false,
                                'file'    => $output_file,
                                'offset'  => $output_offset,
                                'aliases' => $this->state_locations($entry),
                            )
                        );

                        clearstatcache(true, $output_path);
                        if (is_file($output_path) && filesize($output_path) >= $this->max_segment_bytes) {
                            $output_segments[] = $this->finish_compaction_segment(
                                $output_handle,
                                $output_file,
                                $output_path,
                                $output_offsets,
                                $output_records,
                                $output_min,
                                $output_max
                            );
                            $output_handle  = null;
                            $output_file    = '';
                            $output_path    = '';
                            $output_offsets = array();
                            $output_records = 0;
                            $output_min     = null;
                            $output_max     = null;
                        }
                    }
                } finally {
                    fclose($input_handle);
                }
            }
        } finally {
            if (null !== $output_handle) {
                $output_segments[] = $this->finish_compaction_segment(
                    $output_handle,
                    $output_file,
                    $output_path,
                    $output_offsets,
                    $output_records,
                    $output_min,
                    $output_max
                );
            }
        }

        return $output_segments;
    }

    /**
     * @return array{file: string, path: string, handle: resource, nextNumber: int}
     */
    private function open_compaction_segment(string $token, int $number): array
    {
        do {
            $file = sprintf('compact-%s-%06d.ndjson', $token, $number);
            $number++;
            $path = $this->segment_path($file);
        } while (is_file($path));

        $handle = @fopen($path, 'c+b');
        if (false === $handle) {
            throw new StorageException('Could not open compaction segment.');
        }

        return array(
            'file'       => $file,
            'path'       => $path,
            'handle'     => $handle,
            'nextNumber' => $number,
        );
    }

    /**
     * @param resource $handle
     * @param list<array{0: string, 1: int}> $offsets
     * @return array<string, mixed>
     */
    private function finish_compaction_segment(mixed $handle, string $file, string $path, array $offsets, int $records, ?string $min, ?string $max): array
    {
        fclose($handle);

        if (0 === $records) {
            @unlink($path);

            return array(
                'file'      => $file,
                'index'     => $this->index_file_name_for_segment($file),
                'max'       => $max,
                'min'       => $min,
                'records'   => 0,
                'compacted' => true,
            );
        }

        $index_file = $this->index_file_name_for_segment($file);
        $this->write_sparse_entries_to($this->segment_path($index_file), $offsets);

        return array(
            'file'      => $file,
            'index'     => $index_file,
            'max'       => $max,
            'min'       => $min,
            'records'   => $records,
            'compacted' => true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return AtomicFilesystem::read_jsonc_object($this->manifest_path());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all_segments(): array
    {
        $manifest = $this->manifest();
        $segments = $this->sealed_segments_from_manifest($manifest);

        if (isset($manifest['active']) && is_array($manifest['active'])) {
            $segments[] = $this->normalize_segment_manifest_entry($manifest['active']);
        }

        return $segments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sealed_segments(): array
    {
        return $this->sealed_segments_from_manifest($this->manifest());
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function sealed_segments_from_manifest(array $manifest): array
    {
        $segments = array();
        $sealed = isset($manifest['sealed']) && is_array($manifest['sealed']) ? $manifest['sealed'] : array();

        foreach ($sealed as $segment) {
            if (is_array($segment)) {
                $segments[] = $this->normalize_segment_manifest_entry($segment);
            }
        }

        return $segments;
    }

    /**
     * @param array<mixed> $segment
     * @return array<string, mixed>
     */
    private function normalize_segment_manifest_entry(array $segment): array
    {
        $normalized = array();
        foreach ($segment as $key => $value) {
            if (is_string($key)) {
                $normalized[ $key ] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query_segments(RecordQuery $query): array
    {
        $after = $query->after_id();
        $lower = $query->lower_id();
        $upper = $query->upper_id();

        return array_values(
            array_filter(
                $this->all_segments(),
                static function (array $segment) use ($after, $lower, $upper): bool {
                    if (isset($segment['records']) && is_int($segment['records']) && 0 === $segment['records']) {
                        return false;
                    }

                    $min = isset($segment['min']) && is_string($segment['min']) ? $segment['min'] : null;
                    $max = isset($segment['max']) && is_string($segment['max']) ? $segment['max'] : null;

                    if (null === $min || null === $max) {
                        return true;
                    }

                    if (null !== $after && strcmp($max, $after) <= 0) {
                        return false;
                    }

                    if (null !== $lower && strcmp($max, $lower) < 0) {
                        return false;
                    }

                    return ! ( null !== $upper && strcmp($min, $upper) > 0 );
                }
            )
        );
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function seek_offset_for(array $segment, ?string $after_id): int
    {
        if (null === $after_id || ! isset($segment['index']) || ! is_string($segment['index'])) {
            return 0;
        }

        $path = $this->segment_path($segment['index']);
        if (! is_file($path)) {
            return 0;
        }

        $index  = AtomicFilesystem::read_jsonc_object($path);
        $offset = 0;
        $entries = isset($index['entries']) && is_array($index['entries']) ? $index['entries'] : array();
        foreach ($entries as $entry) {
            if (
                is_array($entry) &&
                isset($entry['id'], $entry['offset']) &&
                is_string($entry['id']) &&
                is_int($entry['offset']) &&
                strcmp($entry['id'], $after_id) <= 0
            ) {
                $offset = $entry['offset'];
            }
        }

        return $offset;
    }

    /**
     * @return array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}>
     */
    private function build_state_index(): array
    {
        $state = array();

        foreach ($this->all_segments() as $segment) {
            $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
            if ('' === $file) {
                continue;
            }

            $handle = @fopen($this->segment_path($file), 'rb');
            if (false === $handle) {
                continue;
            }

            try {
                while (true) {
                    $offset = ftell($handle);
                    $line   = fgets($handle);
                    if (false === $offset || false === $line) {
                        break;
                    }

                    $envelope      = $this->decode_line($line);
                    $id            = $envelope['id'];
                    $state[ $id ] = array(
                        'deleted' => 'delete' === $envelope['op'],
                        'file'    => $file,
                        'offset'  => $offset,
                        'aliases' => array(),
                    );
                }
            } finally {
                fclose($handle);
            }
        }

        ksort($state);

        return $state;
    }

    /**
     * @return array{id: string, op: string, data?: mixed}
     */
    private function read_envelope_at(string $file, int $offset): array
    {
        $handle = @fopen($this->segment_path($file), 'rb');
        if (false === $handle) {
            throw new StorageException('Could not open segment: ' . $file);
        }

        try {
            fseek($handle, $offset);
            $line = fgets($handle);
            if (false === $line) {
                throw new StorageException('Could not read segment record.');
            }

            return $this->decode_line($line);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return null|array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}
     */
    private function state_entry(string $id): ?array
    {
        $path = $this->state_entry_path($id);
        if (! is_file($path)) {
            if (! is_dir($this->state_index_root())) {
                $this->recover();
            }

            if (! is_file($path)) {
                return null;
            }
        }

        return $this->read_state_entry_file($path);
    }

    /**
     * @return null|array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}
     */
    private function read_state_entry_file(string $path): ?array
    {
        $entry = AtomicFilesystem::read_jsonc_object($path);
        if (! isset($entry['file'], $entry['offset']) || ! is_string($entry['file']) || ! is_int($entry['offset'])) {
            return null;
        }

        $aliases = array();
        $entry_aliases = isset($entry['aliases']) && is_array($entry['aliases']) ? $entry['aliases'] : array();
        foreach ($entry_aliases as $alias) {
            if (
                is_array($alias) &&
                isset($alias['file'], $alias['offset']) &&
                is_string($alias['file']) &&
                is_int($alias['offset'])
            ) {
                $aliases[] = array(
                    'file'   => $alias['file'],
                    'offset' => $alias['offset'],
                );
            }
        }

        return array(
            'deleted' => true === ( $entry['deleted'] ?? false ),
            'file'    => $entry['file'],
            'offset'  => $entry['offset'],
            'aliases' => $aliases,
        );
    }

    /**
     * @param array{deleted: bool, file: string, offset: int, aliases?: list<array{file: string, offset: int}>} $entry
     */
    private function write_state_entry(string $id, array $entry): void
    {
        $encoded = array(
            'deleted' => $entry['deleted'],
            'file'    => $entry['file'],
            'offset'  => $entry['offset'],
        );

        $aliases = $this->dedupe_locations($entry['aliases'] ?? array(), $entry['file'], $entry['offset']);
        if (array() !== $aliases) {
            $encoded['aliases'] = $aliases;
        }

        AtomicFilesystem::write_atomic($this->state_entry_path($id), Jsonc::encode_object($encoded));
    }

    /**
     * @param array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}> $state
     */
    private function replace_state_index(array $state): void
    {
        AtomicFilesystem::ensure_directory($this->state_index_root());
        $seen = array();

        foreach ($state as $id => $entry) {
            $this->write_state_entry($id, $entry);
            $seen[ $id ] = true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->state_index_root(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            // @codeCoverageIgnoreStart
            if (! $file instanceof \SplFileInfo) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            if ($file->isDir()) {
                @rmdir($file->getPathname());
                continue;
            }

            if ('jsonc' !== $file->getExtension()) {
                continue;
            }

            $id = basename($file->getPathname(), '.jsonc');
            if (! isset($seen[ $id ])) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @param array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>} $entry
     * @return list<array{file: string, offset: int}>
     */
    private function state_locations(array $entry): array
    {
        return $this->dedupe_locations(
            array_merge(
                array(
                    array(
                        'file'   => $entry['file'],
                        'offset' => $entry['offset'],
                    ),
                ),
                $entry['aliases']
            )
        );
    }

    /**
     * @param array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>} $entry
     */
    private function state_entry_matches(array $entry, string $file, int $offset): bool
    {
        foreach ($this->state_locations($entry) as $location) {
            if ($file === $location['file'] && $offset === $location['offset']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{file: string, offset: int}> $locations
     * @return list<array{file: string, offset: int}>
     */
    private function dedupe_locations(array $locations, ?string $exclude_file = null, ?int $exclude_offset = null): array
    {
        $seen   = array();
        $result = array();

        foreach ($locations as $location) {
            $file   = $location['file'];
            $offset = $location['offset'];
            if ($exclude_file === $file && $exclude_offset === $offset) {
                continue;
            }

            $key = $file . ':' . $offset;
            if (isset($seen[ $key ])) {
                continue;
            }

            $seen[ $key ] = true;
            $result[]     = array(
                'file'   => $file,
                'offset' => $offset,
            );
        }

        return $result;
    }

    /**
     * @param array{id: string, op: string, data?: mixed} $envelope
     */
    private function record_from_envelope(array $envelope): StorageRecord
    {
        $id   = $envelope['id'];
        $data = array();

        if (isset($envelope['data']) && is_array($envelope['data'])) {
            foreach ($envelope['data'] as $key => $value) {
                if (is_string($key)) {
                    $data[ $key ] = $value;
                }
            }
        }

        UuidV7::assert_valid($id);

        return new StorageRecord($id, $data);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function encode_line(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json) . "\t" . hash('crc32b', $json) . "\t" . $json . "\n";
    }

    /**
     * @return array{id: string, op: string, data?: mixed}
     */
    private function decode_line(string $line): array
    {
        $parts = explode("\t", rtrim($line, "\r\n"), 3);
        if (3 !== count($parts)) {
            throw new StorageException('Malformed segmented log line.');
        }

        $json = $parts[2];
        if ((int) $parts[0] !== strlen($json) || ! hash_equals($parts[1], hash('crc32b', $json))) {
            throw new StorageException('Corrupt segmented log line.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (
            ! is_array($decoded) ||
            ! isset($decoded['id'], $decoded['op']) ||
            ! is_string($decoded['id']) ||
            ! is_string($decoded['op'])
        ) {
            throw new StorageException('Segmented log line does not contain a record envelope.');
        }

        $envelope = array(
            'id' => $decoded['id'],
            'op' => $decoded['op'],
        );

        if (array_key_exists('data', $decoded)) {
            $envelope['data'] = $decoded['data'];
        }

        return $envelope;
    }

    private function recover_segment(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'c+b');
        // @codeCoverageIgnoreStart
        if (false === $handle) {
            throw new StorageException('Could not open segment for recovery.');
        }
        // @codeCoverageIgnoreEnd

        $last_good_offset = 0;
        try {
            while (true) {
                $offset = ftell($handle);
                $line   = fgets($handle);
                if (false === $offset || false === $line) {
                    break;
                }

                try {
                    $this->decode_line($line);
                    $last_good_offset = (int) ftell($handle);
                } catch (\Throwable) {
                    break;
                }
            }

            ftruncate($handle, max(0, $last_good_offset));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function segment_offsets(string $file): array
    {
        $offsets = array();
        $handle  = @fopen($this->segment_path($file), 'rb');
        if (false === $handle) {
            return $offsets;
        }

        try {
            while (true) {
                $offset = ftell($handle);
                $line   = fgets($handle);
                if (false === $offset || false === $line) {
                    break;
                }

                $envelope  = $this->decode_line($line);
                $offsets[] = array( $envelope['id'], $offset );
            }
        } finally {
            fclose($handle);
        }

        return $offsets;
    }

    /**
     * @param list<array{0: string, 1: int}> $offsets
     */
    private function write_sparse_index_to(string $path, array $offsets): void
    {
        $entries = array();
        foreach ($offsets as $index => $entry) {
            if (0 === $index % $this->sparse_index_interval) {
                $entries[] = $entry;
            }
        }

        $this->write_sparse_entries_to($path, $entries);
    }

    /**
     * @param list<array{0: string, 1: int}> $entries
     */
    private function write_sparse_entries_to(string $path, array $entries): void
    {
        $encoded = array();
        foreach ($entries as $entry) {
            $encoded[] = array(
                'id'     => $entry[0],
                'offset' => $entry[1],
            );
        }

        AtomicFilesystem::write_atomic($path, Jsonc::encode_object(array( 'entries' => $encoded )));
    }

    private function with_lock(callable $callback): mixed
    {
        AtomicFilesystem::ensure_directory($this->collection_root());
        $handle = @fopen($this->collection_root() . '/collection.lock', 'c');
        if (false === $handle) {
            throw new StorageException('Could not open collection lock.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                // @codeCoverageIgnoreStart
                throw new StorageException('Could not acquire collection lock.');
                // @codeCoverageIgnoreEnd
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function collection_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->collection;
    }

    private function segments_root(): string
    {
        return $this->collection_root() . '/segments';
    }

    private function segment_path(string $file): string
    {
        return $this->segments_root() . '/' . $file;
    }

    private function manifest_path(): string
    {
        return $this->collection_root() . '/manifest.jsonc';
    }

    private function state_index_root(): string
    {
        return $this->collection_root() . '/index';
    }

    private function state_entry_path(string $id): string
    {
        return $this->state_entry_path_for_root($this->state_index_root(), $id);
    }

    private function state_entry_path_for_root(string $root, string $id): string
    {
        UuidV7::assert_valid($id);

        return rtrim($root, '/\\') . '/' . substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id . '.jsonc';
    }

    private function segment_file_name(int $number): string
    {
        return sprintf('seg-%06d.ndjson', max(1, $number));
    }

    private function index_file_name_for_segment(string $file): string
    {
        return preg_replace('/\.ndjson$/', '.idx.jsonc', $file) ?? ( $file . '.idx.jsonc' );
    }

    private function next_segment_number_after(string $file): int
    {
        if (1 === preg_match('/^seg-(\d+)\.ndjson$/', $file, $matches)) {
            return (int) $matches[1] + 1;
        }

        return 2;
    }

    private function next_available_segment_file(int $start): string
    {
        $number = max(1, $start);
        do {
            $file = $this->segment_file_name($number);
            $number++;
        } while (is_file($this->segment_path($file)));

        return $file;
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

    private function delete_compaction_leftovers(): void
    {
        foreach (glob($this->collection_root() . '/segments.compact-*') ?: array() as $directory) {
            if (is_dir($directory)) {
                $this->delete_directory($directory);
            }
        }

        foreach (glob($this->collection_root() . '/index.rebuild-*') ?: array() as $directory) {
            if (is_dir($directory)) {
                $this->delete_directory($directory);
            }
        }

        foreach (glob($this->collection_root() . '/index.backup-*') ?: array() as $directory) {
            if (is_dir($directory)) {
                $this->delete_directory($directory);
            }
        }
    }
}
