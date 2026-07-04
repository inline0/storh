<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\AtomicFilesystem;
use Storh\DocPerFileStore;
use Storh\LogQueue;
use Storh\SegmentedLogStore;
use Storh\StorageRecord;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class DurabilityConcurrencyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-durability-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_doc_repair_removes_abandoned_nested_atomic_temp_files_without_touching_records(): void
    {
        $id = UuidV7::generate(1_700_400_000_000);
        $store = new DocPerFileStore($this->root, 'docs');
        $store->put(array( 'title' => 'Committed' ), $id);

        $record_path = $store->path_for_id($id);
        $temp_path = dirname($record_path) . '/.99999999.abcdef01.1.tmp';
        file_put_contents($temp_path, '{"id":"broken"');
        $this->assertFileExists($temp_path);

        $repair = $store->repair();

        $this->assertTrue($repair['ok']);
        $this->assertFileDoesNotExist($temp_path);
        $this->assertSame('Committed', $store->get($id)?->data()['title'] ?? null);
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_doc_repair_quarantines_corrupt_records_and_rebuilds_indexes(): void
    {
        $ids = $this->fixed_ids(2, 1_700_450_000_000);
        $store = new DocPerFileStore($this->root, 'corrupt-docs');
        $store->indexes()->field('kind')->field('bucket')->field('score')->range()->sync();
        $store->put(array( 'kind' => 'page', 'bucket' => 1, 'score' => 10 ), $ids[0]);
        $store->put(array( 'kind' => 'page', 'bucket' => 2, 'score' => 20 ), $ids[1]);

        file_put_contents($store->path_for_id($ids[1]), '{"id":');

        $this->assertFalse($store->verify()['ok']);
        $repair = $store->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame(1, $repair['quarantined']);
        $this->assertCount(1, glob($this->root . '/corrupt-docs/.storh/corrupt/*.jsonc') ?: array());
        $this->assertNull($store->get($ids[1]));
        $this->assertSame(1, $store->query()->where('kind')->eq('page')->count());
        $this->assertSame(1, $store->query()->where('score')->gte(0)->count());
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_doc_verify_detects_index_drift_and_repair_rebuilds_indexes(): void
    {
        $ids = $this->fixed_ids(3, 1_700_470_000_000);
        $store = new DocPerFileStore($this->root, 'drift-docs');
        $store->indexes()->field('kind')->field('bucket')->field('score')->range()->sync();
        $first = array( 'kind' => 'page', 'bucket' => 1, 'score' => 10 );
        $second = array( 'kind' => 'page', 'bucket' => 2, 'score' => 20 );
        $store->put($first, $ids[0]);
        $store->put($second, $ids[1]);

        $store->indexes()->remove_record($ids[0], $first);
        $store->indexes()->update_record($ids[2], array( 'kind' => 'page', 'bucket' => 9, 'score' => 90 ), null);

        $verify = $store->verify();
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('Index mismatch', implode("\n", $verify['errors']));
        $this->assertSame(
            array( $ids[1], $ids[2] ),
            $store->indexes()->candidate_ids($store->query()->where('kind')->eq('page'))
        );

        $repair = $store->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame(0, $repair['quarantined']);
        $this->assertSame(2, $store->query()->where('kind')->eq('page')->count());
        $this->assertSame(2, $store->query()->where('score')->gte(0)->count());
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_log_queue_reopen_truncates_torn_tail_without_losing_committed_events(): void
    {
        $ids = $this->fixed_ids(3, 1_700_500_000_000);
        $queue = new LogQueue($this->root, 'queue');
        $queue->enqueue(array( 'job' => 'first' ), $ids[0]);
        $queue->enqueue(array( 'job' => 'second' ), $ids[1]);
        $claimed = $queue->claim();
        $this->assertSame($ids[0], $claimed?->id());

        file_put_contents($this->root . '/queue/queue.log', '{"op":"enqueue"', FILE_APPEND);

        $reopened = new LogQueue($this->root, 'queue');

        $this->assertSame(array( 'pending' => 1, 'processing' => 1, 'done' => 0 ), $reopened->counts());
        $this->assertTrue($reopened->verify()['ok']);
        $next = $reopened->claim();
        $this->assertSame($ids[1], $next?->id());
        $this->assertSame(array( 'job' => 'second' ), $next?->data());
    }

    public function test_log_queue_verify_detects_state_drift_and_repair_replays_log(): void
    {
        $ids = $this->fixed_ids(2, 1_700_510_000_000);
        $queue = new LogQueue($this->root, 'queue-drift');
        $queue->enqueue(array( 'job' => 'first' ), $ids[0]);
        $queue->enqueue(array( 'job' => 'second' ), $ids[1]);
        $claimed = $queue->claim();
        $this->assertSame($ids[0], $claimed?->id());

        $this->set_private_property($queue, 'pending', array());

        $verify = $queue->verify();
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('state drift', implode("\n", $verify['errors']));

        $repair = $queue->repair(3600);

        $this->assertTrue($repair['ok']);
        $this->assertSame(array( 'pending' => 1, 'processing' => 1, 'done' => 0 ), $queue->counts());
        $next = $queue->claim();
        $this->assertSame($ids[1], $next?->id());
        $this->assertTrue($queue->verify()['ok']);
    }

    public function test_log_queue_replay_preserves_requeued_claim_order_after_reopen(): void
    {
        $ids = $this->fixed_ids(2, 1_700_515_000_000);
        $queue = new LogQueue($this->root, 'queue-requeue-order');
        $queue->enqueue(array( 'job' => 'first' ), $ids[0]);
        $queue->enqueue(array( 'job' => 'second' ), $ids[1]);
        $first = $queue->claim();
        $this->assertSame($ids[0], $first?->id());
        $this->assertSame(1, $queue->requeue_timed_out(0));

        $reopened = new LogQueue($this->root, 'queue-requeue-order');
        $second = $reopened->claim();
        $requeued = $reopened->claim();

        $this->assertSame($ids[1], $second?->id());
        $this->assertSame($ids[0], $requeued?->id());
        $this->assertTrue($reopened->verify()['ok']);
    }

    public function test_segmented_log_verify_detects_state_drift_and_repair_replays_segments(): void
    {
        $ids = $this->fixed_ids(2, 1_700_520_000_000);
        $store = new SegmentedLogStore($this->root, 'log-drift', 4096, 2);
        $store->put(array( 'value' => 'first' ), $ids[0]);

        $this->assertSame(1, $store->query()->count());
        $active_path = $this->active_segment_path('log-drift');
        file_put_contents(
            $active_path,
            $this->encoded_log_line(array( 'op' => 'put', 'id' => $ids[1], 'data' => array( 'value' => 'external' ) )),
            FILE_APPEND
        );

        $verify = $store->verify();
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('state index drift', implode("\n", $verify['errors']));
        $this->assertNull($store->get($ids[1]));

        $repair = $store->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame('external', $store->get($ids[1])?->data()['value'] ?? null);
        $this->assertSame(2, $store->query()->count());
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_doc_store_accepts_concurrent_distinct_writes_without_lost_records(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for forked writes.');
        }

        $workers = 4;
        $per_worker = 18;
        $ids = $this->fixed_ids($workers * $per_worker, 1_700_600_000_000);
        $children = array();

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                try {
                    $store = new DocPerFileStore($this->root, 'concurrent-docs');
                    for ($index = 0; $index < $per_worker; $index++) {
                        $offset = $worker * $per_worker + $index;
                        $store->put(
                            array(
                                'worker' => $worker,
                                'index'  => $index,
                            ),
                            $ids[ $offset ]
                        );
                    }
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $this->assertIsInt($pid);
            $children[] = $pid;
        }

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $store = new DocPerFileStore($this->root, 'concurrent-docs');
        $records = iterator_to_array($store->stream(), false);

        $this->assertCount($workers * $per_worker, $records);
        $this->assertSame($workers * $per_worker, $store->stats()['records']);
        $this->assertSame($ids, $this->record_ids($records));
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_indexed_doc_store_serializes_concurrent_writes_without_index_drift(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for forked writes.');
        }

        $workers = 4;
        $per_worker = 16;
        $ids = $this->fixed_ids($workers * $per_worker, 1_700_700_000_000);
        $store = new DocPerFileStore($this->root, 'indexed-concurrent-docs');
        $store->indexes()->field('kind')->field('bucket')->sync();
        unset($store);

        $children = array();
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                try {
                    $child_store = new DocPerFileStore($this->root, 'indexed-concurrent-docs');
                    for ($index = 0; $index < $per_worker; $index++) {
                        $offset = $worker * $per_worker + $index;
                        $child_store->put(
                            array(
                                'kind'   => 'page',
                                'bucket' => $index % 4,
                                'worker' => $worker,
                                'index'  => $index,
                            ),
                            $ids[ $offset ]
                        );
                    }
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $this->assertIsInt($pid);
            $children[] = $pid;
        }

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $reopened = new DocPerFileStore($this->root, 'indexed-concurrent-docs');
        $query = $reopened->query()->where('kind')->eq('page');
        $this->assertSame($workers * $per_worker, $reopened->stats()['records']);
        $this->assertSame($workers * $per_worker, $query->count());
        $this->assertSame($ids, $this->record_ids($query->get()));

        $candidate_ids = $reopened->indexes()->candidate_ids($query);
        $this->assertIsArray($candidate_ids);
        $this->assertSame($ids, $candidate_ids);

        $bucket_query = $reopened->query()->where('kind')->eq('page')->where('bucket')->eq(3);
        $this->assertSame($workers * 4, $bucket_query->count());
        $this->assertSame($workers * 4, count($reopened->indexes()->candidate_ids($bucket_query) ?? array()));
        $this->assertTrue($reopened->verify()['ok']);
    }

    /**
     * @return list<string>
     */
    private function fixed_ids(int $count, int $start_ms): array
    {
        UuidV7::reset_for_tests();

        $ids = array();
        for ($index = 0; $index < $count; $index++) {
            $ids[] = UuidV7::generate($start_ms + $index);
        }

        return $ids;
    }

    /**
     * @param list<StorageRecord> $records
     * @return list<string>
     */
    private function record_ids(array $records): array
    {
        return array_map(static fn(StorageRecord $record): string => $record->id(), $records);
    }

    private function active_segment_path(string $collection): string
    {
        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/' . $collection . '/manifest.jsonc');
        $active = $manifest['active'] ?? null;
        if (! is_array($active) || ! isset($active['file']) || ! is_string($active['file'])) {
            throw new \RuntimeException('Missing active segment in test manifest.');
        }

        return $this->root . '/' . $collection . '/segments/' . $active['file'];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function encoded_log_line(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json) . "\t" . hash('xxh32', $json) . "\t" . $json . "\n";
    }

    private function set_private_property(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
