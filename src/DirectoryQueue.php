<?php

declare(strict_types=1);

namespace Storh;

final class DirectoryQueue
{
    /** @var callable(): string */
    private mixed $id_generator;

    private bool $trusted_generated_ids;

    /** @var null|list<string> */
    private ?array $pending_claim_paths = null;

    private int $pending_claim_offset = 0;

    private int $temp_counter = 0;

    /** @var array<string, array{payload: array<string, mixed>, size: int}> */
    private array $claim_payload_cache = array();

    /** @var list<string> */
    private array $claim_payload_cache_order = array();

    private int $claim_payload_cache_offset = 0;

    /** @var array<string, true> */
    private array $known_directories = array();

    public function __construct(
        private readonly string $root,
        private readonly string $name,
        ?callable $id_generator = null,
        private readonly int $claim_cache_limit = 100000
    ) {
        if ($this->claim_cache_limit < 0) {
            throw new StorageException('Queue claim cache limit must be at least 0.');
        }

        $this->trusted_generated_ids = null === $id_generator;
        $this->id_generator          = $id_generator ?? static fn(): string => UuidV7::generate();

        foreach (array( 'pending', 'processing', 'done' ) as $lane) {
            AtomicFilesystem::ensure_directory($this->lane_path($lane));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload, ?string $id = null): string
    {
        $generated = null === $id;
        $id ??= ( $this->id_generator )();
        $this->assert_job_id($id, $generated);

        $bytes = $this->write_queue_record($this->queue_record_path('pending', $id, true), $id, $payload);
        $this->remember_claim_payload($id, $payload, $bytes);

        if (null !== $this->pending_claim_paths) {
            $this->reset_pending_claim_paths();
        }

        return $id;
    }

    public function claim(): ?StorageRecord
    {
        while (true) {
            $path = $this->next_pending_claim_path();
            if (null === $path) {
                return null;
            }

            $id     = basename($path, '.jsonc');
            $target = $this->queue_record_path('processing', $id, true);

            if (@rename($path, $target)) {
                return $this->record_from_file($target, $id);
            }
        }
    }

    public function complete(string $id, bool $keep_done = true): void
    {
        UuidV7::assert_valid($id);
        $source = $this->queue_record_path('processing', $id);

        if (! $keep_done) {
            @unlink($source);
            return;
        }

        $target = $this->queue_record_path('done', $id, true);
        if (! @rename($source, $target)) {
            if (! file_exists($source)) {
                return;
            }

            throw new StorageException('Could not complete queue job: ' . $id);
        }
    }

    public function requeue_timed_out(int $timeout_seconds): int
    {
        $count = 0;
        $now   = time();

        foreach ($this->lane_files('processing') as $path) {
            $id = basename($path, '.jsonc');
            if ($now - filemtime($path) < $timeout_seconds) {
                continue;
            }

            if (@rename($path, $this->queue_record_path('pending', $id, true))) {
                $count++;
            }
        }

        if ($count > 0) {
            $this->reset_pending_claim_paths();
        }

        return $count;
    }

    public function purgeDone(int $olderThanSeconds = 0): int
    {
        $count = 0;
        $now   = time();

        foreach ($this->lane_files('done') as $path) {
            if ($olderThanSeconds > 0 && $now - filemtime($path) < $olderThanSeconds) {
                continue;
            }

            if (@unlink($path)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{pending: int, processing: int, done: int}
     */
    public function counts(): array
    {
        return array(
            'pending'    => count($this->lane_files('pending')),
            'processing' => count($this->lane_files('processing')),
            'done'       => count($this->lane_files('done')),
        );
    }

    /**
     * @return array{pending: int, processing: int, done: int, bytes: int}
     */
    public function stats(): array
    {
        $counts = $this->counts();
        $bytes  = 0;
        foreach (array( 'pending', 'processing', 'done' ) as $lane) {
            foreach ($this->lane_files($lane) as $path) {
                $bytes += is_file($path) ? (int) filesize($path) : 0;
            }
        }

        return array(
            'pending'    => $counts['pending'],
            'processing' => $counts['processing'],
            'done'       => $counts['done'],
            'bytes'      => $bytes,
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
        foreach (array( 'pending', 'processing', 'done' ) as $lane) {
            foreach ($this->lane_files($lane) as $path) {
                try {
                    $this->record_from_file($path, basename($path, '.jsonc'), false);
                } catch (\Throwable $throwable) {
                    $errors[] = $lane . '/' . basename($path) . ': ' . $throwable->getMessage();
                }
            }
        }

        return array(
            'ok'     => array() === $errors,
            'errors' => $errors,
            'stats'  => $this->stats(),
        );
    }

    /**
     * @return array{ok: bool, requeued: int}
     */
    public function repair(int $processingTimeoutSeconds = 3600): array
    {
        return array(
            'ok'       => true,
            'requeued' => $this->requeue_timed_out($processingTimeoutSeconds),
        );
    }

    private function queue_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->name;
    }

    private function assert_job_id(string $id, bool $generated): void
    {
        if ($generated && $this->trusted_generated_ids) {
            return;
        }

        UuidV7::assert_valid($id);
    }

    private function lane_path(string $lane): string
    {
        return $this->queue_root() . '/' . $lane;
    }

    private function queue_record_path(string $lane, string $id, bool $ensure_directory = false): string
    {
        $directory = $this->lane_path($lane) . '/' . $this->queue_shard($id);
        if ($ensure_directory) {
            $this->ensure_queue_directory($directory);
        }

        return $directory . '/' . $id . '.jsonc';
    }

    private function queue_shard(string $id): string
    {
        return substr($id, 9, 2);
    }

    /**
     * @return list<string>
     */
    private function lane_files(string $lane, bool $files_only = true): array
    {
        $root  = $this->lane_path($lane);
        $files = array();
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: array() as $directory) {
            foreach (glob($directory . '/*.jsonc') ?: array() as $file) {
                $files[] = $file;
            }
        }

        if ($files_only) {
            $files = array_values(array_filter($files, 'is_file'));
        }

        $by_id = array();
        foreach ($files as $file) {
            $by_id[ basename($file, '.jsonc') ] = $file;
        }
        ksort($by_id);

        return array_values($by_id);
    }

    private function next_pending_claim_path(): ?string
    {
        while (true) {
            if (
                null === $this->pending_claim_paths ||
                $this->pending_claim_offset >= count($this->pending_claim_paths)
            ) {
                $this->pending_claim_paths = $this->lane_files('pending', false);
                $this->pending_claim_offset = 0;
            }

            if (array() === $this->pending_claim_paths) {
                return null;
            }

            $path = $this->pending_claim_paths[ $this->pending_claim_offset ];
            $this->pending_claim_offset++;

            return $path;
        }
    }

    private function reset_pending_claim_paths(): void
    {
        $this->pending_claim_paths  = null;
        $this->pending_claim_offset = 0;
    }

    private function record_from_file(string $path, string $expected_id, bool $use_cache = true): StorageRecord
    {
        if ($use_cache) {
            $cached = $this->cached_record_from_file($path, $expected_id);
            if ($cached instanceof StorageRecord) {
                return $cached;
            }
        }

        $data = $this->read_queue_record($path);
        $id   = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
        UuidV7::assert_valid($id);
        if ($id !== $expected_id) {
            throw new StorageException('Queue job id does not match its path.');
        }

        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
        /** @var array<string, mixed> $payload */
        return new StorageRecord($id, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write_queue_record(string $path, string $id, array $payload): int
    {
        $json = json_encode(
            array(
                'id'      => $id,
                'payload' => $payload,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $contents = $json . "\n";

        $directory = dirname($path);
        $temp = $directory . '/.' . basename($path) . '.' . getmypid() . '.' . ++$this->temp_counter . '.tmp';
        $handle = @fopen($temp, 'wb');
        if (false === $handle) {
            throw new StorageException('Could not open temporary queue file for writing: ' . $temp);
        }

        try {
            AtomicFilesystem::write_all($handle, $contents, $temp);
        } finally {
            fclose($handle);
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new StorageException('Could not atomically write queue file: ' . $path);
        }

        return strlen($contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function read_queue_record(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new StorageException('Could not read storage file: ' . $path);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ( array() !== $data && array_is_list($data) )) {
                throw new StorageException('JSONC document must decode to an object.');
            }

            $object = array();
            foreach ($data as $key => $value) {
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

    /**
     * @param array<string, mixed> $payload
     */
    private function remember_claim_payload(string $id, array $payload, int $bytes): void
    {
        if (0 === $this->claim_cache_limit) {
            return;
        }

        if (! isset($this->claim_payload_cache[ $id ])) {
            $this->claim_payload_cache_order[] = $id;
        }

        $this->claim_payload_cache[ $id ] = array(
            'payload' => $payload,
            'size'    => $bytes,
        );

        $this->evict_claim_payloads();
    }

    private function cached_record_from_file(string $path, string $id): ?StorageRecord
    {
        $cached = $this->claim_payload_cache[ $id ] ?? null;
        if (null === $cached) {
            return null;
        }

        unset($this->claim_payload_cache[ $id ]);

        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size !== $cached['size']) {
            return null;
        }

        return new StorageRecord($id, $cached['payload']);
    }

    private function evict_claim_payloads(): void
    {
        while (count($this->claim_payload_cache) > $this->claim_cache_limit) {
            $id = $this->claim_payload_cache_order[ $this->claim_payload_cache_offset ] ?? null;
            $this->claim_payload_cache_offset++;

            if (null !== $id) {
                unset($this->claim_payload_cache[ $id ]);
            }
        }

        if ($this->claim_payload_cache_offset > 1000 && $this->claim_payload_cache_offset * 2 > count($this->claim_payload_cache_order)) {
            $this->claim_payload_cache_order  = array_slice($this->claim_payload_cache_order, $this->claim_payload_cache_offset);
            $this->claim_payload_cache_offset = 0;
        }
    }

    private function ensure_queue_directory(string $directory): void
    {
        if (isset($this->known_directories[ $directory ])) {
            return;
        }

        AtomicFilesystem::ensure_directory($directory);
        $this->known_directories[ $directory ] = true;
    }
}
