<?php

declare(strict_types=1);

namespace Storh;

final class LogQueue
{
    /** @var callable(): string */
    private mixed $id_generator;

    /** @var resource|null */
    private mixed $lock_handle = null;

    /** @var resource|null */
    private mixed $log_handle = null;

    private int $log_offset = 0;

    /** @var array<string, array<string, mixed>> */
    private array $payloads = array();

    /** @var array<string, true> */
    private array $pending = array();

    /** @var array<string, int> */
    private array $processing = array();

    /** @var array<string, int> */
    private array $done = array();

    /** @var list<string> */
    private array $pending_order = array();

    private int $pending_offset = 0;

    public function __construct(
        private readonly string $root,
        private readonly string $name,
        ?callable $id_generator = null
    ) {
        $this->id_generator = $id_generator ?? static fn(): string => UuidV7::generate();
        AtomicFilesystem::ensure_directory($this->queue_root());
        $this->with_lock(function (): void {
            $this->replay_log(true);
        });
    }

    public function __destruct()
    {
        if (is_resource($this->log_handle)) {
            fclose($this->log_handle);
        }

        if (is_resource($this->lock_handle)) {
            fclose($this->lock_handle);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload, ?string $id = null): string
    {
        $id ??= ( $this->id_generator )();
        UuidV7::assert_valid($id);

        $this->with_lock(function () use ($id, $payload): void {
            $this->sync_from_log();
            $this->append_event(
                array(
                    'op'      => 'enqueue',
                    'id'      => $id,
                    'payload' => $payload,
                    'ts'      => time(),
                )
            );
        });

        return $id;
    }

    public function claim(): ?StorageRecord
    {
        return $this->with_lock(function (): ?StorageRecord {
            $this->sync_from_log();

            while ($this->pending_offset < count($this->pending_order)) {
                $id = $this->pending_order[ $this->pending_offset ];
                $this->pending_offset++;
                if (! isset($this->pending[ $id ])) {
                    continue;
                }

                $this->append_event(
                    array(
                        'op' => 'claim',
                        'id' => $id,
                        'ts' => time(),
                    )
                );

                return new StorageRecord($id, $this->payloads[ $id ] ?? array());
            }

            return null;
        });
    }

    public function complete(string $id, bool $keep_done = true): void
    {
        UuidV7::assert_valid($id);

        $this->with_lock(function () use ($id, $keep_done): void {
            $this->sync_from_log();
            if (! isset($this->processing[ $id ])) {
                return;
            }

            $this->append_event(
                array(
                    'op'   => 'complete',
                    'id'   => $id,
                    'done' => $keep_done,
                    'ts'   => time(),
                )
            );
        });
    }

    public function requeue_timed_out(int $timeout_seconds): int
    {
        return $this->with_lock(function () use ($timeout_seconds): int {
            $this->sync_from_log();
            $now = time();
            $count = 0;

            foreach ($this->processing as $id => $claimed_at) {
                if ($now - $claimed_at < $timeout_seconds) {
                    continue;
                }

                $this->append_event(
                    array(
                        'op' => 'requeue',
                        'id' => $id,
                        'ts' => $now,
                    )
                );
                $count++;
            }

            return $count;
        });
    }

    public function purgeDone(int $olderThanSeconds = 0): int
    {
        return $this->with_lock(function () use ($olderThanSeconds): int {
            $this->sync_from_log();
            $now = time();
            $count = 0;

            foreach ($this->done as $id => $completed_at) {
                if ($olderThanSeconds > 0 && $now - $completed_at < $olderThanSeconds) {
                    continue;
                }

                $this->append_event(
                    array(
                        'op' => 'purge',
                        'id' => $id,
                        'ts' => $now,
                    )
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * @return array{pending: int, processing: int, done: int}
     */
    public function counts(): array
    {
        return $this->with_lock(function (): array {
            $this->sync_from_log();

            return $this->current_counts();
        });
    }

    /**
     * @return array{pending: int, processing: int, done: int, bytes: int}
     */
    public function stats(): array
    {
        return $this->with_lock(function (): array {
            $this->sync_from_log();
            $counts = $this->current_counts();

            return array(
                'pending'    => $counts['pending'],
                'processing' => $counts['processing'],
                'done'       => $counts['done'],
                'bytes'      => is_file($this->log_path()) ? (int) filesize($this->log_path()) : 0,
            );
        });
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
        return $this->with_lock(function (): array {
            $errors = array();
            $handle = @fopen($this->log_path(), 'rb');
            if (false === $handle) {
                $errors[] = 'Could not read log queue file.';
            } else {
                try {
                    $line_number = 0;
                    while (false !== ( $line = fgets($handle) )) {
                        $line_number++;
                        try {
                            $this->decode_line($line);
                        } catch (\Throwable $throwable) {
                            $errors[] = 'line ' . $line_number . ': ' . $throwable->getMessage();
                            break;
                        }
                    }
                } finally {
                    fclose($handle);
                }
            }

            $this->sync_from_log();

            return array(
                'ok'     => array() === $errors,
                'errors' => $errors,
                'stats'  => $this->stats_without_lock(),
            );
        });
    }

    /**
     * @return array{ok: bool, requeued: int}
     */
    public function repair(int $processingTimeoutSeconds = 3600): array
    {
        return $this->with_lock(function () use ($processingTimeoutSeconds): array {
            $this->replay_log(true);
            $requeued = 0;
            $now = time();

            foreach ($this->processing as $id => $claimed_at) {
                if ($now - $claimed_at < $processingTimeoutSeconds) {
                    continue;
                }

                $this->append_event(
                    array(
                        'op' => 'requeue',
                        'id' => $id,
                        'ts' => $now,
                    )
                );
                $requeued++;
            }

            return array(
                'ok'       => true,
                'requeued' => $requeued,
            );
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function with_lock(callable $callback): mixed
    {
        $handle = $this->lock_handle();
        if (! flock($handle, LOCK_EX)) {
            throw new StorageException('Could not lock log queue.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    /**
     * @return resource
     */
    private function lock_handle(): mixed
    {
        if (is_resource($this->lock_handle)) {
            return $this->lock_handle;
        }

        $handle = @fopen($this->queue_root() . '/queue.lock', 'c+b');
        if (false === $handle) {
            throw new StorageException('Could not open log queue lock.');
        }

        $this->lock_handle = $handle;

        return $handle;
    }

    /**
     * @return resource
     */
    private function log_handle(): mixed
    {
        if (is_resource($this->log_handle)) {
            return $this->log_handle;
        }

        $handle = @fopen($this->log_path(), 'c+b');
        if (false === $handle) {
            throw new StorageException('Could not open log queue file.');
        }

        $this->log_handle = $handle;

        return $handle;
    }

    private function queue_root(): string
    {
        return rtrim($this->root, '/\\') . '/' . $this->name;
    }

    private function log_path(): string
    {
        return $this->queue_root() . '/queue.log';
    }

    private function sync_from_log(): void
    {
        clearstatcache(true, $this->log_path());
        $size = is_file($this->log_path()) ? (int) filesize($this->log_path()) : 0;
        if ($size < $this->log_offset) {
            $this->replay_log(false);
            return;
        }

        $handle = $this->log_handle();
        fseek($handle, $this->log_offset);
        while (false !== ( $line = fgets($handle) )) {
            $offset = ftell($handle);
            try {
                $event = $this->decode_line($line);
            } catch (\Throwable) {
                break;
            }

            $this->apply_event($event);
            $this->log_offset = false === $offset ? $this->log_offset : $offset;
        }
    }

    private function replay_log(bool $truncate_torn): void
    {
        $this->payloads      = array();
        $this->pending       = array();
        $this->processing    = array();
        $this->done          = array();
        $this->pending_order = array();
        $this->pending_offset = 0;
        $this->log_offset    = 0;

        $handle = $this->log_handle();
        fseek($handle, 0);
        while (false !== ( $line = fgets($handle) )) {
            $line_start = $this->log_offset;
            $offset = ftell($handle);
            try {
                $event = $this->decode_line($line);
            } catch (\Throwable) {
                if ($truncate_torn) {
                    $truncate_at = max(0, $line_start);
                    ftruncate($handle, $truncate_at);
                    fflush($handle);
                }
                break;
            }

            $this->apply_event($event);
            $this->log_offset = false === $offset ? $this->log_offset : $offset;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function append_event(array $event): void
    {
        $path = $this->log_path();
        $handle = $this->log_handle();
        fseek($handle, 0, SEEK_END);
        AtomicFilesystem::write_all($handle, $this->encode_line($event), $path);
        fflush($handle);
        $offset = ftell($handle);
        $this->log_offset = false === $offset ? $this->log_offset : $offset;
        $this->apply_event($event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function encode_line(array $event): string
    {
        $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json) . "\t" . hash('crc32b', $json) . "\t" . $json . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_line(string $line): array
    {
        if (! str_ends_with($line, "\n")) {
            throw new StorageException('Torn log queue line.');
        }

        $parts = explode("\t", rtrim($line, "\r\n"), 3);
        if (3 !== count($parts) || ! ctype_digit($parts[0])) {
            throw new StorageException('Malformed log queue line.');
        }

        $length = (int) $parts[0];
        $crc = $parts[1];
        $json = $parts[2];
        if ($length !== strlen($json) || $crc !== hash('crc32b', $json)) {
            throw new StorageException('Corrupt log queue line.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ( array() !== $decoded && array_is_list($decoded) )) {
            throw new StorageException('Log queue event must be an object.');
        }

        $event = array();
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new StorageException('Log queue event must be an object.');
            }

            $event[ $key ] = $value;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function apply_event(array $event): void
    {
        $op = isset($event['op']) && is_string($event['op']) ? $event['op'] : '';
        $id = isset($event['id']) && is_string($event['id']) ? $event['id'] : '';
        if ('' === $op || ! UuidV7::is_valid($id)) {
            throw new StorageException('Invalid log queue event.');
        }

        $ts = isset($event['ts']) && is_int($event['ts']) ? $event['ts'] : time();

        if ('enqueue' === $op) {
            $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : array();
            /** @var array<string, mixed> $payload */
            $this->payloads[ $id ] = $payload;
            $this->pending[ $id ] = true;
            unset($this->processing[ $id ], $this->done[ $id ]);
            $this->pending_order[] = $id;
            return;
        }

        if ('claim' === $op) {
            if (isset($this->pending[ $id ])) {
                unset($this->pending[ $id ]);
                $this->processing[ $id ] = $ts;
            }
            return;
        }

        if ('complete' === $op) {
            unset($this->pending[ $id ], $this->processing[ $id ]);
            if (true === ( $event['done'] ?? false )) {
                $this->done[ $id ] = $ts;
            } else {
                unset($this->done[ $id ], $this->payloads[ $id ]);
            }
            return;
        }

        if ('requeue' === $op) {
            if (isset($this->processing[ $id ])) {
                unset($this->processing[ $id ]);
                $this->pending[ $id ] = true;
                $this->pending_order[] = $id;
            }
            return;
        }

        if ('purge' === $op) {
            unset($this->done[ $id ], $this->payloads[ $id ]);
            return;
        }

        throw new StorageException('Unsupported log queue event: ' . $op);
    }

    /**
     * @return array{pending: int, processing: int, done: int}
     */
    private function current_counts(): array
    {
        return array(
            'pending'    => count($this->pending),
            'processing' => count($this->processing),
            'done'       => count($this->done),
        );
    }

    /**
     * @return array{pending: int, processing: int, done: int, bytes: int}
     */
    private function stats_without_lock(): array
    {
        $counts = $this->current_counts();

        return array(
            'pending'    => $counts['pending'],
            'processing' => $counts['processing'],
            'done'       => $counts['done'],
            'bytes'      => is_file($this->log_path()) ? (int) filesize($this->log_path()) : 0,
        );
    }
}
