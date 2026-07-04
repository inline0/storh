<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\DocPerFileStore;
use Storh\LogQueue;
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
}
