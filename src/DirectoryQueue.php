<?php

declare(strict_types=1);

namespace Storh;

final class DirectoryQueue
{
    /** @var callable(): string */
    private mixed $id_generator;

    public function __construct(
        private readonly string $root,
        private readonly string $name,
        ?callable $id_generator = null
    ) {
        $this->id_generator = $id_generator ?? static fn(): string => UuidV7::generate();

        foreach (array( 'pending', 'processing', 'done' ) as $lane) {
            AtomicFilesystem::ensure_directory($this->lane_path($lane));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload, ?string $id = null): string
    {
        $id ??= ( $this->id_generator )();
        UuidV7::assert_valid($id);

        AtomicFilesystem::write_atomic(
            $this->lane_path('pending') . '/' . $id . '.jsonc',
            Jsonc::encode_object(
                array(
                    'id'      => $id,
                    'payload' => $payload,
                )
            )
        );

        return $id;
    }

    public function claim(): ?StorageRecord
    {
        foreach ($this->lane_files('pending') as $path) {
            $id     = basename($path, '.jsonc');
            $target = $this->lane_path('processing') . '/' . $id . '.jsonc';

            if (@rename($path, $target)) {
                return $this->record_from_file($target, $id);
            }
        }

        return null;
    }

    public function complete(string $id, bool $keep_done = true): void
    {
        UuidV7::assert_valid($id);
        $source = $this->lane_path('processing') . '/' . $id . '.jsonc';

        if (! is_file($source)) {
            return;
        }

        if (! $keep_done) {
            @unlink($source);
            return;
        }

        if (! @rename($source, $this->lane_path('done') . '/' . $id . '.jsonc')) {
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

            if (@rename($path, $this->lane_path('pending') . '/' . $id . '.jsonc')) {
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

    private function queue_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->name;
    }

    private function lane_path(string $lane): string
    {
        return $this->queue_root() . '/' . $lane;
    }

    /**
     * @return list<string>
     */
    private function lane_files(string $lane): array
    {
        $files = glob($this->lane_path($lane) . '/*.jsonc') ?: array();
        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    private function record_from_file(string $path, string $fallback_id): StorageRecord
    {
        $data = AtomicFilesystem::read_jsonc_object($path);
        $id   = isset($data['id']) && is_string($data['id']) ? $data['id'] : $fallback_id;
        UuidV7::assert_valid($id);

        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
        /** @var array<string, mixed> $payload */
        return new StorageRecord($id, $payload);
    }
}
