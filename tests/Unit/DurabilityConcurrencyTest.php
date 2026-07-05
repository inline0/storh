<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\AtomicFilesystem;
use Storh\DocPerFileStore;
use Storh\Jsonc;
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

    public function test_reopen_sweeps_temp_files_only_when_a_writer_crashed(): void
    {
        $id = UuidV7::generate(1_700_390_000_000);
        $store = new DocPerFileStore($this->root, 'marker-docs');
        $store->put(array( 'title' => 'Committed' ), $id);

        $collection_root = $this->root . '/marker-docs';
        $writers = glob($collection_root . '/.storh/writers/*') ?: array();
        $this->assertCount(1, $writers, 'A writing instance must register exactly one marker.');
        $this->assertStringStartsWith((string) getmypid(), basename($writers[0]));

        unset($store);
        $this->assertSame(array(), glob($collection_root . '/.storh/writers/*') ?: array(), 'A clean destruct must unregister the marker.');

        $temp = dirname(( new DocPerFileStore($this->root, 'marker-docs') )->path_for_id($id)) . '/.99999999.abcdef01.1.tmp';
        file_put_contents($temp, 'torn');
        touch($temp, time() - 120);

        $reopened = new DocPerFileStore($this->root, 'marker-docs');
        $this->assertFileExists($temp, 'A reopen after clean shutdowns must skip the sweep.');

        $live_second = new DocPerFileStore($this->root, 'marker-docs');
        $live_second->put(array( 'title' => 'Second writer' ));
        $third = new DocPerFileStore($this->root, 'marker-docs');
        $this->assertFileExists($temp, 'Live writer markers must not trigger a sweep.');
        unset($live_second, $third, $reopened);

        AtomicFilesystem::register_writer(
            AtomicFilesystem::writer_marker_path($collection_root, '99999999.deadbeef')
        );
        $garbage_marker = AtomicFilesystem::writer_marker_path($collection_root, 'not-a-pid');
        AtomicFilesystem::register_writer($garbage_marker);

        new DocPerFileStore($this->root, 'marker-docs');
        $this->assertFileDoesNotExist($temp, 'A dead writer marker must trigger the sweep.');
        $this->assertFileDoesNotExist($garbage_marker, 'Unparseable markers count as dead.');

        TestFilesystem::remove_path($collection_root . '/.storh/writers');
        $compat_temp = $collection_root . '/data/.99999999.abcdef01.2.tmp';
        file_put_contents($compat_temp, 'pre-marker leftover');
        touch($compat_temp, time() - 120);

        new DocPerFileStore($this->root, 'marker-docs');
        $this->assertFileDoesNotExist($compat_temp, 'A collection without a writers directory must sweep once.');
        $this->assertDirectoryExists($collection_root . '/.storh/writers');
    }

    public function test_log_reopen_sweeps_temp_files_only_when_a_writer_crashed(): void
    {
        $log = new SegmentedLogStore($this->root, 'marker-log', 4096);
        $log->put(array( 'type' => 'event' ));

        $collection_root = $this->root . '/marker-log';
        $this->assertCount(1, glob($collection_root . '/.storh/writers/*') ?: array());
        unset($log);
        $this->assertSame(array(), glob($collection_root . '/.storh/writers/*') ?: array());

        $temp = $collection_root . '/segments/.99999999.abcdef01.1.tmp';
        file_put_contents($temp, 'torn');
        touch($temp, time() - 120);

        $reopened = new SegmentedLogStore($this->root, 'marker-log', 4096);
        $this->assertFileExists($temp, 'A clean log reopen must skip the sweep.');
        unset($reopened);

        AtomicFilesystem::register_writer(
            AtomicFilesystem::writer_marker_path($collection_root, '99999999.deadbeef')
        );
        new SegmentedLogStore($this->root, 'marker-log', 4096);
        $this->assertFileDoesNotExist($temp, 'A dead log writer marker must trigger the sweep.');
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

    public function test_doc_repair_recovers_when_crash_leaves_committed_record_ahead_of_indexes(): void
    {
        $id = UuidV7::generate(1_700_480_000_000);
        $store = new DocPerFileStore($this->root, 'crash-ahead-docs');
        $store->indexes()->field('kind')->field('bucket')->field('score')->range()->sync();
        $old = array( 'kind' => 'old', 'bucket' => 1, 'score' => 10 );
        $new = array( 'kind' => 'new', 'bucket' => 2, 'score' => 90 );
        $store->put($old, $id);

        $this->write_doc_record_file($store, $id, $new);
        $reopened = new DocPerFileStore($this->root, 'crash-ahead-docs');

        $this->assertSame($new, $reopened->get($id)?->data());
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('old')));
        $this->assertSame(array(), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('new')));
        $this->assertFalse($reopened->verify()['ok']);

        $repair = $reopened->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame(0, $repair['quarantined']);
        $this->assertSame(array(), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('old')));
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('new')));
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('score')->gte(80)));
        $this->assertSame(1, $reopened->query()->where('kind')->eq('new')->where('bucket')->eq(2)->count());
        $this->assertTrue($reopened->verify()['ok']);
    }

    public function test_doc_repair_recovers_when_crash_leaves_removed_old_indexes_without_new_indexes(): void
    {
        $id = UuidV7::generate(1_700_490_000_000);
        $store = new DocPerFileStore($this->root, 'crash-gap-docs');
        $store->indexes()->field('kind')->field('bucket')->field('score')->range()->sync();
        $old = array( 'kind' => 'old', 'bucket' => 1, 'score' => 10 );
        $new = array( 'kind' => 'new', 'bucket' => 3, 'score' => 70 );
        $store->put($old, $id);

        $this->write_doc_record_file($store, $id, $new);
        $store->indexes()->remove_record($id, $old);
        $reopened = new DocPerFileStore($this->root, 'crash-gap-docs');

        $this->assertSame($new, $reopened->get($id)?->data());
        $this->assertSame(array(), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('old')));
        $this->assertSame(array(), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('new')));
        $this->assertFalse($reopened->verify()['ok']);

        $repair = $reopened->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame(0, $repair['quarantined']);
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('new')));
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('score')->between(60, 80)));
        $this->assertSame(1, $reopened->query()->where('kind')->eq('new')->where('bucket')->eq(3)->count());
        $this->assertTrue($reopened->verify()['ok']);
    }

    public function test_doc_reopen_and_repair_ignore_unrenamed_atomic_temp_outputs(): void
    {
        $id = UuidV7::generate(1_700_495_000_000);
        $store = new DocPerFileStore($this->root, 'unrenamed-temp-docs');
        $store->indexes()->field('kind')->field('bucket')->sync();
        $committed = array( 'kind' => 'committed', 'bucket' => 1 );
        $uncommitted = array( 'kind' => 'uncommitted', 'bucket' => 9 );
        $store->put($committed, $id);

        $record_temp = dirname($store->path_for_id($id)) . '/.' . basename($store->path_for_id($id)) . '.deadbeef.tmp';
        file_put_contents(
            $record_temp,
            Jsonc::encode_compact_object(array( 'id' => $id, 'data' => $uncommitted ))
        );
        touch($record_temp, time() - 120);

        $index_files = glob($store->collection_root() . '/.storh/indexes/entries/eq/' . bin2hex('kind') . '/*.jsonc') ?: array();
        $this->assertNotSame(array(), $index_files);
        $index_temp = dirname($index_files[0]) . '/.' . basename($index_files[0]) . '.deadbeef.tmp';
        file_put_contents(
            $index_temp,
            Jsonc::encode_compact_object(
                array(
                    'field' => 'kind',
                    'key'   => 's:uncommitted',
                    'count' => 1,
                    'value' => 'uncommitted',
                    'ids'   => array( $id ),
                )
            )
        );
        touch($index_temp, time() - 120);
        unset($store);

        $dead_marker = AtomicFilesystem::writer_marker_path(
            $this->root . '/unrenamed-temp-docs',
            '99999999.deadbeef'
        );
        AtomicFilesystem::register_writer($dead_marker);

        $reopened = new DocPerFileStore($this->root, 'unrenamed-temp-docs');

        $this->assertFileDoesNotExist($dead_marker);

        $this->assertFileDoesNotExist($record_temp);
        $this->assertFileDoesNotExist($index_temp);
        $this->assertSame($committed, $reopened->get($id)?->data());
        $this->assertSame(1, $reopened->query()->where('kind')->eq('committed')->count());
        $this->assertSame(0, $reopened->query()->where('kind')->eq('uncommitted')->count());
        $this->assertSame(array( $id ), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('committed')));
        $this->assertSame(array(), $reopened->indexes()->candidate_ids($reopened->query()->where('kind')->eq('uncommitted')));

        $repair = $reopened->repair();

        $this->assertTrue($repair['ok']);
        $this->assertSame(0, $repair['quarantined']);
        $this->assertSame($committed, $reopened->get($id)?->data());
        $this->assertSame(1, $reopened->query()->where('kind')->eq('committed')->count());
        $this->assertSame(0, $reopened->query()->where('kind')->eq('uncommitted')->count());
        $this->assertTrue($reopened->verify()['ok']);
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

    public function test_log_queue_concurrent_workers_claim_each_job_once(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for forked queue workers.');
        }

        $workers = 5;
        $jobs = 60;
        $ids = $this->fixed_ids($jobs, 1_700_518_000_000);
        $queue = new LogQueue($this->root, 'queue-workers');
        $queue->enqueueMany(
            array_map(
                static fn(string $id, int $index): array => array(
                    'id'      => $id,
                    'payload' => array( 'index' => $index ),
                ),
                $ids,
                array_keys($ids)
            )
        );
        unset($queue);

        $claim_root = $this->root . '/queue-worker-claims';
        mkdir($claim_root, 0777, true);
        $children = array();
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                try {
                    $worker_queue = new LogQueue($this->root, 'queue-workers');
                    $claim_path = $claim_root . '/worker-' . $worker . '.txt';
                    while (true) {
                        $record = $worker_queue->claim();
                        if (null === $record) {
                            break;
                        }

                        file_put_contents($claim_path, $record->id() . "\n", FILE_APPEND | LOCK_EX);
                        usleep(( ( $worker + (int) ( $record->data()['index'] ?? 0 ) ) % 5 ) * 1000);
                        $worker_queue->complete($record->id());
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

        $claimed = array();
        foreach (glob($claim_root . '/worker-*.txt') ?: array() as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($lines);
            foreach ($lines as $line) {
                $claimed[] = $line;
            }
        }

        sort($claimed);
        $expected = $ids;
        sort($expected);
        $this->assertSame($expected, $claimed);
        $this->assertSame($jobs, count(array_unique($claimed)));

        $reopened = new LogQueue($this->root, 'queue-workers');
        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => $jobs ), $reopened->counts());
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

    public function test_segmented_log_reopen_discards_compaction_output_left_before_manifest_swap(): void
    {
        $ids = $this->fixed_ids(12, 1_700_530_000_000);
        $store = new SegmentedLogStore($this->root, 'log-compact-crash', 512, 1);
        for ($index = 0; $index < 8; $index++) {
            $store->put(
                array(
                    'index' => $index,
                    'blob'  => str_repeat('x', 160),
                ),
                $ids[ $index ]
            );
        }

        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/log-compact-crash/manifest.jsonc');
        $source_segments = $manifest['sealed'] ?? array();
        $this->assertIsArray($source_segments);
        $this->assertNotSame(array(), $source_segments);

        $compacted_segments = $this->invoke_private($store, 'write_compacted_segments', array( $source_segments ));
        $this->assertIsArray($compacted_segments);
        $this->assertNotSame(array(), $compacted_segments);
        $this->assertNotSame(array(), glob($this->root . '/log-compact-crash/segments/compact-*.ndjson') ?: array());
        $this->assertNotSame(array(), glob($this->root . '/log-compact-crash/segments/compact-*.idx.jsonc') ?: array());
        unset($store);

        $reopened = new SegmentedLogStore($this->root, 'log-compact-crash', 512, 1);

        $this->assertSame(array(), glob($this->root . '/log-compact-crash/segments/compact-*.ndjson') ?: array());
        $this->assertSame(array(), glob($this->root . '/log-compact-crash/segments/compact-*.idx.jsonc') ?: array());
        $this->assertSame(array_slice($ids, 0, 8), $this->record_ids(iterator_to_array($reopened->stream(), false)));
        $this->assertTrue($reopened->verify()['ok']);
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

    public function test_indexed_doc_store_serializes_concurrent_updates_to_same_records_without_stale_index_entries(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for forked writes.');
        }

        $workers = 5;
        $records = 20;
        $ids = $this->fixed_ids($records, 1_700_800_000_000);
        $store = new DocPerFileStore($this->root, 'indexed-concurrent-updates');
        $store->indexes()->field('kind')->field('bucket')->field('revision')->range()->sync();
        foreach ($ids as $slot => $id) {
            $store->put(
                array(
                    'kind'     => 'initial',
                    'bucket'   => $slot % 5,
                    'revision' => -1,
                    'slot'     => $slot,
                    'writer'   => -1,
                    'marker'   => 'initial-' . $slot,
                ),
                $id
            );
        }
        unset($store);

        $children = array();
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                try {
                    $child_store = new DocPerFileStore($this->root, 'indexed-concurrent-updates');
                    for ($slot = 0; $slot < $records; $slot++) {
                        usleep(( ( $worker + $slot ) % 4 ) * 1000);
                        $child_store->put(
                            array(
                                'kind'     => 'updated',
                                'bucket'   => ( $slot + $worker ) % 5,
                                'revision' => $worker,
                                'slot'     => $slot,
                                'writer'   => $worker,
                                'marker'   => 'worker-' . $worker . '-slot-' . $slot,
                            ),
                            $ids[ $slot ]
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

        $reopened = new DocPerFileStore($this->root, 'indexed-concurrent-updates');
        $records_by_id = array();
        foreach ($reopened->stream() as $record) {
            $records_by_id[ $record->id() ] = $record->data();
        }

        $this->assertSame($records, count($records_by_id));
        $this->assertSame($records, $reopened->stats()['records']);
        $this->assertSame(0, $reopened->query()->where('kind')->eq('initial')->count());
        $this->assertSame($records, $reopened->query()->where('kind')->eq('updated')->count());

        $expected_by_revision = array_fill(0, $workers, array());
        $expected_by_bucket = array_fill(0, 5, array());
        foreach ($ids as $slot => $id) {
            $data = $records_by_id[ $id ] ?? null;
            $this->assertIsArray($data);
            $this->assertSame('updated', $data['kind'] ?? null);
            $this->assertSame($slot, $data['slot'] ?? null);
            $this->assertSame($data['revision'] ?? null, $data['writer'] ?? null);
            $this->assertSame('worker-' . $data['writer'] . '-slot-' . $slot, $data['marker'] ?? null);

            $revision = $data['revision'] ?? null;
            $bucket = $data['bucket'] ?? null;
            $this->assertIsInt($revision);
            $this->assertGreaterThanOrEqual(0, $revision);
            $this->assertLessThan($workers, $revision);
            $this->assertIsInt($bucket);
            $this->assertGreaterThanOrEqual(0, $bucket);
            $this->assertLessThan(5, $bucket);
            $expected_by_revision[ $revision ][] = $id;
            $expected_by_bucket[ $bucket ][] = $id;
        }

        for ($revision = 0; $revision < $workers; $revision++) {
            sort($expected_by_revision[ $revision ]);
            $query = $reopened->query()->where('revision')->eq($revision);
            $this->assertSame($expected_by_revision[ $revision ], $this->record_ids($query->get()));
            $this->assertSame(count($expected_by_revision[ $revision ]), $query->count());
            $this->assertSame($expected_by_revision[ $revision ], $reopened->indexes()->candidate_ids($query));
        }

        for ($bucket = 0; $bucket < 5; $bucket++) {
            sort($expected_by_bucket[ $bucket ]);
            $query = $reopened->query()->where('bucket')->eq($bucket);
            $this->assertSame($expected_by_bucket[ $bucket ], $this->record_ids($query->get()));
            $this->assertSame(count($expected_by_bucket[ $bucket ]), $query->count());
            $this->assertSame($expected_by_bucket[ $bucket ], $reopened->indexes()->candidate_ids($query));
        }

        $this->assertTrue($reopened->verify()['ok']);
    }

    public function test_doc_store_readers_do_not_observe_torn_records_during_concurrent_writes(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for forked writes.');
        }

        $records = 24;
        $rounds = 36;
        $ids = $this->fixed_ids($records, 1_700_900_000_000);
        $collection = 'reader-writer-docs';
        $store = new DocPerFileStore($this->root, $collection);
        $store->indexes()->field('bucket')->field('version')->range()->sync();
        foreach ($ids as $slot => $id) {
            $store->put(
                array(
                    'slot'    => $slot,
                    'version' => 0,
                    'bucket'  => $slot % 4,
                    'marker'  => 'round-0-slot-' . $slot,
                ),
                $id
            );
        }
        unset($store);

        $pid = pcntl_fork();
        if (0 === $pid) {
            try {
                usleep(5000);
                $child_store = new DocPerFileStore($this->root, $collection);
                for ($round = 1; $round <= $rounds; $round++) {
                    foreach ($ids as $slot => $id) {
                        $child_store->put(
                            array(
                                'slot'    => $slot,
                                'version' => $round,
                                'bucket'  => ( $slot + $round ) % 4,
                                'marker'  => 'round-' . $round . '-slot-' . $slot,
                            ),
                            $id
                        );

                        if (0 === $slot % 6) {
                            usleep(500);
                        }
                    }
                }
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        $this->assertIsInt($pid);
        $id_to_slot = array_flip($ids);
        $read_passes = 0;
        $status = 0;
        while (true) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($pid === $waited) {
                break;
            }
            $this->assertSame(0, $waited);

            $reader = new DocPerFileStore($this->root, $collection);
            $seen = 0;
            foreach ($reader->stream() as $record) {
                $expected_slot = $id_to_slot[ $record->id() ] ?? null;
                $this->assertIsInt($expected_slot);

                $data = $record->data();
                $slot = $data['slot'] ?? null;
                $version = $data['version'] ?? null;
                $bucket = $data['bucket'] ?? null;
                $this->assertSame($expected_slot, $slot);
                $this->assertIsInt($version);
                $this->assertGreaterThanOrEqual(0, $version);
                $this->assertLessThanOrEqual($rounds, $version);
                $this->assertSame(( $slot + $version ) % 4, $bucket);
                $this->assertSame('round-' . $version . '-slot-' . $slot, $data['marker'] ?? null);
                $seen++;
            }

            $this->assertSame($records, $seen);
            $read_passes++;
            usleep(1000);
        }

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertGreaterThan(0, $read_passes);

        $reopened = new DocPerFileStore($this->root, $collection);
        $this->assertSame($records, $reopened->stats()['records']);
        $this->assertSame($records, $reopened->query()->where('version')->eq($rounds)->count());
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
     * @param array<string, mixed> $data
     */
    private function write_doc_record_file(DocPerFileStore $store, string $id, array $data): void
    {
        $json = json_encode(
            array( 'id' => $id, 'data' => $data ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        AtomicFilesystem::write_atomic($store->path_for_id($id), $json . "\n");
        clearstatcache(true, $store->path_for_id($id));
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

    /**
     * @param list<mixed> $arguments
     */
    private function invoke_private(object $object, string $method, array $arguments = array()): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invokeArgs($object, $arguments);
    }
}
