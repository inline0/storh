<?php

declare(strict_types=1);

namespace Storh;

final class SegmentedLogStore implements FileStoreInterface
{
    /** @var callable(): string */
    private mixed $id_generator;

    private bool $trusted_generated_ids;

    private readonly string $collection_path;

    private CacheInterface $cache;

    /** @var null|array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}> */
    private ?array $state = null;

    /** @var array<string, array{min: string|null, max: string|null, records: int}> */
    private array $segment_stats = array();

    /** @var array<string, list<array{0: string, 1: int}>> */
    private array $segment_sparse_offsets = array();

    /** @var resource|null */
    private mixed $lock_handle = null;

    /** @var resource|null */
    private mixed $active_handle = null;

    private bool $active_handle_dirty = false;

    private ?string $active_handle_file = null;

    private ?string $active_handle_path = null;

    /** @var array<string, mixed>|null */
    private ?array $manifest_state = null;

    private ?int $manifest_mtime = null;

    private ?int $manifest_size = null;

    public function __construct(
        private readonly string $root,
        private readonly string $collection,
        private readonly int $max_segment_bytes = 1048576,
        private readonly int $sparse_index_interval = 64,
        ?callable $id_generator = null,
        ?CacheInterface $cache = null,
        ?string $partition = null,
        ?int $partition_timestamp_ms = null,
        private readonly string $cache_validation = CacheValidation::HASH
    ) {
        CacheValidation::assert_valid($this->cache_validation);

        if ($this->max_segment_bytes < 256) {
            throw new StorageException('Segment size must be at least 256 bytes.');
        }

        if ($this->sparse_index_interval < 1) {
            throw new StorageException('Sparse index interval must be at least 1.');
        }

        $this->trusted_generated_ids = null === $id_generator;
        $this->id_generator          = $id_generator ?? static fn(): string => UuidV7::generate();
        $this->cache                 = $cache ?? Cache::null();
        $this->collection_path       = $this->partitioned_collection($collection, $partition, $partition_timestamp_ms);
        $this->initialize();
    }

    public function __destruct()
    {
        $this->close_active_handle();
        if (is_resource($this->lock_handle)) {
            fclose($this->lock_handle);
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

    /**
     * @param iterable<array<string, mixed>> $records
     * @return list<StorageRecord>
     */
    public function appendMany(iterable $records): array
    {
        $stored = array();

        foreach ($records as $record) {
            $id   = isset($record['id']) && is_string($record['id']) ? $record['id'] : null;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
            $generated = null === $id;
            $id ??= ( $this->id_generator )();
            $this->assert_record_id($id, $generated);

            /** @var array<string, mixed> $data */
            $stored[] = new StorageRecord($id, $data);
        }

        if (array() !== $stored) {
            $this->with_lock(
                function () use ($stored): void {
                    $this->append_envelopes($this->record_envelopes($stored));
                }
            );
        }

        return $stored;
    }

    /**
     * @param iterable<array<string, mixed>> $records
     */
    public function appendStream(iterable $records): int
    {
        $count = 0;

        $this->with_lock(
            function () use ($records, &$count): void {
                $this->append_envelopes($this->stream_envelopes($records, $count));
            }
        );

        return $count;
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
        $this->flush_active_handle();

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
                    $this->write_manifest($manifest);
                    return;
                }

                $compacted_segments = $this->write_compacted_segments($source_segments);
                $manifest           = $this->manifest();
                $manifest['sealed'] = $compacted_segments;

                $this->write_manifest($manifest);
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
        if (null === $this->state) {
            $this->recover();
        }

        return $this->state ?? array();
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function count_records(QueryBuilder $query): int
    {
        $this->flush_active_handle();

        $count = 0;
        $limit = $query->limit_value();
        $cursor = $query->cursor_id();
        $state = $this->state_index();
        $segment_query = RecordQuery::all();
        if (null !== $cursor) {
            $segment_query = $segment_query->after($cursor);
        }

        foreach ($this->query_segments($segment_query) as $segment) {
            $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
            if ('' === $file) {
                continue;
            }

            $handle = @fopen($this->segment_path($file), 'rb');
            if (false === $handle) {
                throw new StorageException('Could not open segment: ' . $file);
            }

            try {
                $offset = $this->seek_offset_for($segment, $cursor);
                if ($offset > 0) {
                    fseek($handle, $offset);
                }

                while (true) {
                    $line_offset = ftell($handle);
                    $line        = fgets($handle);
                    if (false === $line || false === $line_offset) {
                        break;
                    }

                    $envelope = $this->decode_line($line);
                    $id       = $envelope['id'];
                    $entry    = $state[ $id ] ?? null;
                    if (null === $entry || $entry['deleted'] || ! $this->state_entry_matches($entry, $file, $line_offset)) {
                        continue;
                    }

                    UuidV7::assert_valid($id);
                    if (! $query->matches_data($id, $this->data_from_envelope($envelope))) {
                        continue;
                    }

                    $count++;
                    if (null !== $limit && $count >= $limit) {
                        return $count;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $count;
    }

    public function retain(): SegmentedLogRetention
    {
        return new SegmentedLogRetention($this);
    }

    /**
     * @return array{segments: int, records: int, deleted: int, bytes: int}
     */
    public function stats(): array
    {
        $this->flush_active_handle();

        $segments = $this->all_segments();
        $bytes    = 0;
        foreach ($segments as $segment) {
            $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
            $path = '' === $file ? '' : $this->segment_path($file);
            $bytes += is_file($path) ? (int) filesize($path) : 0;
        }

        $state   = $this->state_index();
        $deleted = 0;
        foreach ($state as $entry) {
            if ($entry['deleted']) {
                $deleted++;
            }
        }

        return array(
            'segments' => count($segments),
            'records'  => count(iterator_to_array($this->stream())),
            'deleted'  => $deleted,
            'bytes'    => $bytes,
        );
    }

    /**
     * @return array{ok: bool, errors: list<string>, stats: array<string, int>}
     */
    public function health(): array
    {
        return $this->verify();
    }

    /**
     * @return array{ok: bool, errors: list<string>, stats: array<string, int>}
     */
    public function verify(): array
    {
        $errors = array();
        try {
            iterator_to_array($this->stream());
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        try {
            $stats = $this->stats();
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
            $stats = array(
                'segments' => count($this->all_segments()),
                'records'  => 0,
                'deleted'  => 0,
                'bytes'    => 0,
            );
        }

        return array(
            'ok'     => array() === $errors,
            'errors' => $errors,
            'stats'  => $stats,
        );
    }

    /**
     * @return array{ok: bool, stats: array<string, int>}
     */
    public function repair(): array
    {
        $this->recover();

        return array(
            'ok'    => true,
            'stats' => $this->stats(),
        );
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
                    $this->write_manifest(
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
        $this->replace_state_index($this->build_state_index(true));
        $this->repair_manifest_stats_from_segments();
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
        $handle   = $this->active_segment_handle($file, $path);

        fseek($handle, 0, SEEK_END);
        $offset = ftell($handle);
        $offset = false === $offset ? 0 : $offset;
        $line = $this->encode_line($envelope);
        AtomicFilesystem::write_all($handle, $line, $path);
        $this->active_handle_dirty = true;
        $end_offset = $offset + strlen($line);

        $id = $envelope['id'];
        $this->remember_segment_record($file, $id, $offset);

        $this->write_state_entry(
            $id,
            array(
                'deleted' => 'delete' === $envelope['op'],
                'file'    => $file,
                'offset'  => $offset,
                'aliases' => array(),
            )
        );

        if ($end_offset >= $this->max_segment_bytes) {
            $this->roll_active_segment();
        }
    }

    /**
     * @param iterable<array{id: string, op: string, data?: array<string, mixed>}> $envelopes
     */
    private function append_envelopes(iterable $envelopes): void
    {
        $this->close_active_handle();

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
            $position = ftell($handle);
            $position = false === $position ? 0 : $position;
            $buffer = '';
            $pending = array();

            foreach ($envelopes as $envelope) {
                $line = $this->encode_line($envelope);
                $pending[] = array(
                    'envelope' => $envelope,
                    'file'     => $file,
                    'offset'   => $position,
                );
                $buffer .= $line;
                $position += strlen($line);

                if (strlen($buffer) < 1_048_576 && $position < $this->max_segment_bytes) {
                    continue;
                }

                $this->flush_envelope_buffer($handle, $buffer, $path, $pending);

                if ($position < $this->max_segment_bytes) {
                    continue;
                }

                fflush($handle);
                fclose($handle);
                $handle = null;

                $this->roll_active_segment();

                $manifest = $this->manifest();
                $active   = isset($manifest['active']) && is_array($manifest['active']) ? $manifest['active'] : array();
                $file     = isset($active['file']) && is_string($active['file']) ? $active['file'] : $this->segment_file_name(1);
                $path     = $this->segment_path($file);
                $handle   = @fopen($path, 'c+b');

                if (false === $handle) {
                    throw new StorageException('Could not open active segment.');
                }

                fseek($handle, 0, SEEK_END);
                $position = ftell($handle);
                $position = false === $position ? 0 : $position;
            }

            $this->flush_envelope_buffer($handle, $buffer, $path, $pending);
        } finally {
            if (is_resource($handle)) {
                fflush($handle);
                fclose($handle);
            }
        }
    }

    /**
     * @param list<StorageRecord> $records
     * @return \Generator<int, array{id: string, op: string, data: array<string, mixed>}>
     */
    private function record_envelopes(array $records): \Generator
    {
        foreach ($records as $record) {
            yield array(
                'op'   => 'put',
                'id'   => $record->id(),
                'data' => $record->data(),
            );
        }
    }

    /**
     * @param iterable<array<string, mixed>> $records
     * @return \Generator<int, array{id: string, op: string, data: array<string, mixed>}>
     */
    private function stream_envelopes(iterable $records, int &$count): \Generator
    {
        foreach ($records as $record) {
            $id   = isset($record['id']) && is_string($record['id']) ? $record['id'] : null;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
            $generated = null === $id;
            $id ??= ( $this->id_generator )();
            $this->assert_record_id($id, $generated);

            /** @var array<string, mixed> $data */
            $count++;
            yield array(
                'op'   => 'put',
                'id'   => $id,
                'data' => $data,
            );
        }
    }

    /**
     * @param resource $handle
     * @param list<array{envelope: array{id: string, op: string, data?: array<string, mixed>}, file: string, offset: int}> $pending
     */
    private function flush_envelope_buffer(mixed $handle, string &$buffer, string $path, array &$pending): void
    {
        if ('' === $buffer) {
            return;
        }

        AtomicFilesystem::write_all($handle, $buffer, $path);

        foreach ($pending as $entry) {
            $envelope = $entry['envelope'];
            $id = $envelope['id'];
            $this->remember_segment_record($entry['file'], $id, $entry['offset']);
            $this->write_state_entry(
                $id,
                array(
                    'deleted' => 'delete' === $envelope['op'],
                    'file'    => $entry['file'],
                    'offset'  => $entry['offset'],
                    'aliases' => array(),
                )
            );
        }

        $buffer = '';
        $pending = array();
    }

    private function roll_active_segment(): void
    {
        $manifest = $this->manifest();
        $active   = isset($manifest['active']) && is_array($manifest['active']) ? $manifest['active'] : array();
        $file = isset($active['file']) && is_string($active['file']) ? $active['file'] : $this->segment_file_name(1);
        $stats = $this->segment_stats[ $file ] ?? null;
        $active_records = is_array($stats) ? $stats['records'] : ( isset($active['records']) && is_int($active['records']) ? $active['records'] : 0 );
        if (0 === $active_records) {
            return;
        }

        $path = $this->segment_path($file);
        if (! is_file($path)) {
            throw new StorageException('Could not roll missing active segment.');
        }

        $this->flush_active_handle();
        $this->close_active_handle();

        $index = $this->index_file_name_for_segment($file);
        $offsets = $this->segment_sparse_offsets[ $file ] ?? null;
        if (null === $offsets) {
            throw new StorageException('Sparse offsets missing for active segment.');
        }

        $this->write_sparse_entries_to($this->segment_path($index), $offsets);
        unset($this->segment_sparse_offsets[ $file ]);

        $sealed     = isset($manifest['sealed']) && is_array($manifest['sealed']) ? $manifest['sealed'] : array();
        $sealed[]   = array(
            'file'    => $file,
            'index'   => $index,
            'max'     => is_array($stats) ? $stats['max'] : ( $active['max'] ?? null ),
            'min'     => is_array($stats) ? $stats['min'] : ( $active['min'] ?? null ),
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
        $this->segment_stats[ $active_new ] = array(
            'min'     => null,
            'max'     => null,
            'records' => 0,
        );
        $this->segment_sparse_offsets[ $active_new ] = array();
        $this->write_manifest($manifest);
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
                                $this->delete_state_entry($id);
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

                        $output_line = $this->encode_line(
                            array(
                                'op'   => 'put',
                                'id'   => $record->id(),
                                'data' => $record->data(),
                            )
                        );
                        AtomicFilesystem::write_all($output_handle, $output_line, $output_path);
                        $output_position = $output_offset + strlen($output_line);

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

                        if ($output_position >= $this->max_segment_bytes) {
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
            $this->segment_stats[ $file ] = array(
                'min'     => $min,
                'max'     => $max,
                'records' => 0,
            );

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
        $this->segment_stats[ $file ] = array(
            'min'     => $min,
            'max'     => $max,
            'records' => $records,
        );

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
        $path = $this->manifest_path();
        clearstatcache(true, $path);
        $exists = is_file($path);
        $mtime = $exists ? (int) filemtime($path) : 0;
        $size  = $exists ? (int) filesize($path) : -1;

        if (
            null !== $this->manifest_state &&
            $this->manifest_mtime === $mtime &&
            $this->manifest_size === $size
        ) {
            return $this->manifest_state;
        }

        $this->manifest_state = AtomicFilesystem::read_jsonc_object($path);
        $this->manifest_mtime = $mtime;
        $this->manifest_size  = $size;

        return $this->manifest_state;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function write_manifest(array $manifest): void
    {
        $path = $this->manifest_path();
        AtomicFilesystem::write_atomic($path, Jsonc::encode_object($manifest));
        clearstatcache(true, $path);
        $exists = is_file($path);
        $this->manifest_state = $manifest;
        $this->manifest_mtime = $exists ? (int) filemtime($path) : 0;
        $this->manifest_size  = $exists ? (int) filesize($path) : -1;
        $this->cache->delete($this->manifest_cache_key());
    }

    private function repair_manifest_stats_from_segments(): void
    {
        $manifest = $this->manifest();

        if (isset($manifest['sealed']) && is_array($manifest['sealed'])) {
            $sealed = array();
            foreach ($manifest['sealed'] as $index => $segment) {
                if (! is_array($segment) || ! isset($segment['file']) || ! is_string($segment['file'])) {
                    $sealed[ $index ] = $segment;
                    continue;
                }

                $stats = $this->segment_stats_for($segment['file']);
                $segment['min']     = $stats['min'];
                $segment['max']     = $stats['max'];
                $segment['records'] = $stats['records'];
                $sealed[ $index ] = $segment;
            }
            $manifest['sealed'] = $sealed;
        }

        if (isset($manifest['active']) && is_array($manifest['active'])) {
            $active_file = isset($manifest['active']['file']) && is_string($manifest['active']['file'])
                ? $manifest['active']['file']
                : '';
            if ('' !== $active_file) {
                $stats = $this->segment_stats_for($active_file);
                $manifest['active']['min']     = $stats['min'];
                $manifest['active']['max']     = $stats['max'];
                $manifest['active']['records'] = $stats['records'];
            }
        }

        $this->write_manifest($manifest);
    }

    /**
     * @return array{min: string|null, max: string|null, records: int}
     */
    private function segment_stats_for(string $file): array
    {
        return $this->segment_stats[ $file ] ?? array(
            'min'     => null,
            'max'     => null,
            'records' => 0,
        );
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

        $segment_stats = $this->segment_stats;

        return array_values(
            array_filter(
                $this->all_segments(),
                static function (array $segment) use ($after, $lower, $upper, $segment_stats): bool {
                    $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
                    $stats = '' !== $file && isset($segment_stats[ $file ]) ? $segment_stats[ $file ] : null;
                    $min = is_array($stats) ? $stats['min'] : ( isset($segment['min']) && is_string($segment['min']) ? $segment['min'] : null );
                    $max = is_array($stats) ? $stats['max'] : ( isset($segment['max']) && is_string($segment['max']) ? $segment['max'] : null );

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

        $index  = $this->read_cached_jsonc_object($path, $this->sparse_cache_key($path));
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
    private function build_state_index(bool $truncate_torn = false): array
    {
        $this->flush_active_handle();

        $state = array();
        $stats = array();
        $sparse_offsets = array();

        foreach ($this->all_segments() as $segment) {
            $file = isset($segment['file']) && is_string($segment['file']) ? $segment['file'] : '';
            if ('' === $file) {
                continue;
            }

            $path = $this->segment_path($file);
            if (! is_file($path)) {
                continue;
            }

            $handle = @fopen($path, $truncate_torn ? 'c+b' : 'rb');
            if (false === $handle) {
                continue;
            }

            try {
                $min     = null;
                $max     = null;
                $records = 0;
                $offsets = array();
                $last_good_offset = 0;
                while (true) {
                    $offset = ftell($handle);
                    $line   = fgets($handle);
                    if (false === $offset || false === $line) {
                        break;
                    }

                    try {
                        $envelope = $this->decode_line($line);
                    } catch (\Throwable $throwable) {
                        if ($truncate_torn) {
                            ftruncate($handle, max(0, $last_good_offset));
                            break;
                        }

                        throw $throwable;
                    }

                    $line_end = ftell($handle);
                    $last_good_offset = false === $line_end ? $last_good_offset : $line_end;
                    $id            = $envelope['id'];
                    $min           = null === $min || strcmp($id, $min) < 0 ? $id : $min;
                    $max           = null === $max || strcmp($id, $max) > 0 ? $id : $max;
                    if (0 === $records % $this->sparse_index_interval) {
                        $offsets[] = array( $id, $offset );
                    }
                    $records++;
                    $state[ $id ] = array(
                        'deleted' => 'delete' === $envelope['op'],
                        'file'    => $file,
                        'offset'  => $offset,
                        'aliases' => array(),
                    );
                }

                $stats[ $file ] = array(
                    'min'     => $min,
                    'max'     => $max,
                    'records' => $records,
                );
                $sparse_offsets[ $file ] = $offsets;
            } finally {
                fclose($handle);
            }
        }

        ksort($state);
        $this->segment_stats          = $stats;
        $this->segment_sparse_offsets = $sparse_offsets;

        return $state;
    }

    /**
     * @return array{id: string, op: string, data?: mixed}
     */
    private function read_envelope_at(string $file, int $offset): array
    {
        $this->flush_active_handle();

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
        if (null === $this->state) {
            $this->recover();
        }

        return $this->state[ $id ] ?? null;
    }

    /**
     * @param array{deleted: bool, file: string, offset: int, aliases?: list<array{file: string, offset: int}>} $entry
     */
    private function write_state_entry(string $id, array $entry, bool $atomic = true): void
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

        $this->state ??= array();
        $this->state[ $id ] = array(
            'deleted' => $encoded['deleted'],
            'file'    => $encoded['file'],
            'offset'  => $encoded['offset'],
            'aliases' => isset($encoded['aliases']) && is_array($encoded['aliases']) ? $encoded['aliases'] : array(),
        );
        $this->cache->delete($this->state_cache_key($id));
    }

    /**
     * @param array<string, array{deleted: bool, file: string, offset: int, aliases: list<array{file: string, offset: int}>}> $state
     */
    private function replace_state_index(array $state): void
    {
        $this->cache->clear_prefix($this->state_cache_prefix());
        $this->state = $state;
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
        if ($file === $entry['file'] && $offset === $entry['offset']) {
            return true;
        }

        foreach ($entry['aliases'] as $location) {
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
        UuidV7::assert_valid($id);

        return new StorageRecord($id, $this->data_from_envelope($envelope));
    }

    /**
     * @param array{id: string, op: string, data?: mixed} $envelope
     * @return array<string, mixed>
     */
    private function data_from_envelope(array $envelope): array
    {
        $data = array();

        if (isset($envelope['data']) && is_array($envelope['data'])) {
            foreach ($envelope['data'] as $key => $value) {
                if (is_string($key)) {
                    $data[ $key ] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function encode_line(array $envelope): string
    {
        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

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
        $this->cache->delete($this->sparse_cache_key($path));
    }

    private function with_lock(callable $callback): mixed
    {
        AtomicFilesystem::ensure_directory($this->collection_root());
        if (! is_resource($this->lock_handle)) {
            $handle = @fopen($this->collection_root() . '/collection.lock', 'c');
            if (false !== $handle) {
                $this->lock_handle = $handle;
            }
        }

        if (! is_resource($this->lock_handle)) {
            throw new StorageException('Could not open collection lock.');
        }

        try {
            if (! flock($this->lock_handle, LOCK_EX)) {
                // @codeCoverageIgnoreStart
                throw new StorageException('Could not acquire collection lock.');
                // @codeCoverageIgnoreEnd
            }

            return $callback();
        } finally {
            flock($this->lock_handle, LOCK_UN);
        }
    }

    private function collection_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->collection_path;
    }

    private function assert_record_id(string $id, bool $generated): void
    {
        if ($generated && $this->trusted_generated_ids) {
            return;
        }

        UuidV7::assert_valid($id);
    }

    private function segments_root(): string
    {
        return $this->collection_root() . '/segments';
    }

    private function segment_path(string $file): string
    {
        return $this->segments_root() . '/' . $file;
    }

    /**
     * @return resource
     */
    private function active_segment_handle(string $file, string $path): mixed
    {
        if (
            is_resource($this->active_handle) &&
            $this->active_handle_file === $file &&
            $this->active_handle_path === $path
        ) {
            return $this->active_handle;
        }

        $this->close_active_handle();
        $handle = @fopen($path, 'c+b');
        if (false === $handle) {
            throw new StorageException('Could not open active segment.');
        }

        $this->active_handle      = $handle;
        $this->active_handle_file = $file;
        $this->active_handle_path = $path;

        return $handle;
    }

    private function close_active_handle(): void
    {
        $handle = $this->active_handle;
        if (is_resource($handle)) {
            $this->flush_active_handle();
            fclose($handle);
        }

        $this->active_handle      = null;
        $this->active_handle_dirty = false;
        $this->active_handle_file = null;
        $this->active_handle_path = null;
    }

    private function flush_active_handle(): void
    {
        if (! is_resource($this->active_handle) || ! $this->active_handle_dirty) {
            return;
        }

        fflush($this->active_handle);
        $this->active_handle_dirty = false;
    }

    private function remember_segment_record(string $file, string $id, int $offset): void
    {
        $stats = $this->segment_stats[ $file ] ?? array(
            'min'     => null,
            'max'     => null,
            'records' => 0,
        );

        $stats['min'] = null === $stats['min'] || strcmp($id, $stats['min']) < 0 ? $id : $stats['min'];
        $stats['max'] = null === $stats['max'] || strcmp($id, $stats['max']) > 0 ? $id : $stats['max'];
        if (0 === $stats['records'] % $this->sparse_index_interval) {
            $this->segment_sparse_offsets[ $file ][] = array( $id, $offset );
        }
        $stats['records']++;

        $this->segment_stats[ $file ] = $stats;
    }

    private function manifest_path(): string
    {
        return $this->collection_root() . '/manifest.jsonc';
    }

    private function delete_state_entry(string $id): void
    {
        if (null !== $this->state) {
            unset($this->state[ $id ]);
        }
        $this->cache->delete($this->state_cache_key($id));
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

    private function partitioned_collection(string $collection, ?string $partition, ?int $timestamp_ms): string
    {
        if (null === $partition) {
            return $collection;
        }

        $timestamp_ms ??= (int) floor(microtime(true) * 1000);
        $seconds = intdiv($timestamp_ms, 1000);

        return match ($partition) {
            'daily' => $collection . '/partitions/' . gmdate('Y-m-d', $seconds),
            'monthly' => $collection . '/partitions/' . gmdate('Y-m', $seconds),
            default => throw new StorageException('Unsupported log partition: ' . $partition),
        };
    }

    private function manifest_cache_key(): string
    {
        return 'log:manifest:' . $this->collection_path;
    }

    private function sparse_cache_key(string $path): string
    {
        return 'log:sparse:' . $this->collection_path . ':' . basename($path);
    }

    private function state_cache_prefix(): string
    {
        return 'log:state:' . $this->collection_path . ':';
    }

    private function state_cache_key(string $id): string
    {
        return $this->state_cache_prefix() . $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function read_cached_jsonc_object(string $path, string $key): array
    {
        $cached = $this->cache->get($key);
        if (CacheValidation::TRUST === $this->cache_validation && is_array($cached) && is_array($cached['data'] ?? null)) {
            /** @var array<string, mixed> $data */
            $data = $cached['data'];
            return $data;
        }

        clearstatcache(true, $path);
        $exists = is_file($path);
        $mtime  = $exists ? (int) filemtime($path) : 0;
        $size   = $exists ? (int) filesize($path) : -1;
        $hash   = $exists && CacheValidation::HASH === $this->cache_validation ? (string) sha1_file($path) : '';

        if (
            is_array($cached) &&
            isset($cached['mtime'], $cached['size'], $cached['data']) &&
            $cached['mtime'] === $mtime &&
            $cached['size'] === $size &&
            (
                CacheValidation::HASH !== $this->cache_validation ||
                ( isset($cached['hash']) && $cached['hash'] === $hash )
            ) &&
            is_array($cached['data'])
        ) {
            /** @var array<string, mixed> $data */
            $data = $cached['data'];
            return $data;
        }

        $data = AtomicFilesystem::read_jsonc_object($path);
        $this->cache->set(
            $key,
            array(
                'mtime' => $mtime,
                'size'  => $size,
                'hash'  => $hash,
                'data'  => $data,
            )
        );

        return $data;
    }
}
