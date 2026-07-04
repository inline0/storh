<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use Storh\AtomicFilesystem;
use Storh\DocPerFileStore;
use Storh\Jsonc;
use Storh\LogQueue;
use Storh\RecordQuery;
use Storh\SegmentedLogStore;
use Storh\StorageException;
use Storh\StorageRoot;
use Storh\UuidV7;
use Storh\Tests\Support\TestFilesystem;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-storage-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_uuid_v7_is_valid_sortable_monotonic_and_time_addressable(): void
    {
        $first  = UuidV7::generate(1_700_000_000_000);
        $second = UuidV7::generate(1_700_000_000_000);
        $third  = UuidV7::generate(1_700_000_000_001);

        $this->assertTrue(UuidV7::is_valid($first));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
        $this->assertSame('7', $first[14]);
        $this->assertContains($first[19], array( '8', '9', 'a', 'b' ));
        $this->assertSame(array( $first, $second, $third ), $this->sorted(array( $third, $second, $first )));
        $this->assertSame(1_700_000_000_000, UuidV7::timestamp_ms($first));
        $this->assertLessThanOrEqual($first, UuidV7::min_for_timestamp_ms(1_700_000_000_000));
        $this->assertGreaterThanOrEqual($second, UuidV7::max_for_timestamp_ms(1_700_000_000_000));

        $this->expectException(StorageException::class);
        UuidV7::generate(-1);
    }

    public function test_uuid_v7_rejects_invalid_values(): void
    {
        $this->assertFalse(UuidV7::is_valid('not-a-uuid'));

        $this->expectException(StorageException::class);
        UuidV7::timestamp_ms('018bcfe5-6800-6000-8000-000000000000');
    }

    public function test_uuid_v7_entropy_carries_across_full_random_tail(): void
    {
        $reflection = new \ReflectionClass(UuidV7::class);
        $reflection->setStaticPropertyValue('last_timestamp_ms', 1_700_000_000_000);
        $reflection->setStaticPropertyValue('last_entropy', str_repeat("\xff", 10));

        $uuid = UuidV7::generate(1_700_000_000_000);

        $this->assertTrue(UuidV7::is_valid($uuid));
        $this->assertSame(1_700_000_000_000, UuidV7::timestamp_ms($uuid));
    }

    public function test_jsonc_accepts_comments_trailing_commas_and_rejects_bad_documents(): void
    {
        $decoded = Jsonc::decode_object(
            <<<'JSONC'
			{
				// line comment
				"url": "https://example.test//not-comment",
				"block": "/* not comment */",
				"items": [
					"one",
					"two",
				],
				/* block comment */
				"nested": {
					"enabled": true,
				},
			}
JSONC
        );

        $this->assertSame('https://example.test//not-comment', $decoded['url']);
        $this->assertSame('/* not comment */', $decoded['block']);
        $this->assertSame(array( 'one', 'two' ), $decoded['items']);
        $this->assertSame("{\n    \"alpha\": 1\n}\n", Jsonc::encode_object(array( 'alpha' => 1 )));
        $this->assertSame("{\"alpha\":1}\n", Jsonc::encode_compact_object(array( 'alpha' => 1 )));

        $this->expectException(StorageException::class);
        Jsonc::decode_object('[]');
    }

    public function test_jsonc_surfaces_malformed_input(): void
    {
        $this->expectException(StorageException::class);
        Jsonc::decode_object('{ bad json');
    }

    public function test_jsonc_covers_empty_objects_numeric_keys_and_escaped_strings(): void
    {
        $this->assertSame("{}\n", Jsonc::encode_object(array()));
        $this->assertSame("{}\n", Jsonc::encode_compact_object(array()));
        $this->assertStringContainsString('1.0', Jsonc::encode_object(array( 'float' => 1.0 )));
        $this->assertStringContainsString('1.0', Jsonc::encode_compact_object(array( 'float' => 1.0 )));
        $this->assertSame(array( 'quote' => 'a"b', 'path' => 'c\\d' ), Jsonc::decode_object('{"quote":"a\"b","path":"c\\\\d",}'));

        try {
            Jsonc::decode_object('{"0":"zero"}');
            $this->fail('Expected numeric-list JSON object to fail.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('object', $exception->getMessage());
        }

        $this->expectException(StorageException::class);
        Jsonc::decode_object('{"1":"one"}');
    }

    public function test_atomic_filesystem_writes_reads_and_cleans_temp_files(): void
    {
        $path = $this->root . '/atomic/doc.jsonc';
        AtomicFilesystem::write_atomic($path, Jsonc::encode_object(array( 'ok' => true )));
        file_put_contents(dirname($path) . '/.leftover.tmp', 'partial');

        $this->assertSame(array( 'ok' => true ), AtomicFilesystem::read_jsonc_object($path));
        AtomicFilesystem::cleanup_temp_files(dirname($path));
        $this->assertFileDoesNotExist(dirname($path) . '/.leftover.tmp');
        AtomicFilesystem::cleanup_temp_files($this->root . '/missing');
    }

    public function test_atomic_filesystem_reports_write_failures(): void
    {
        file_put_contents($this->root . '/not-a-dir', 'x');

        $this->expectException(StorageException::class);
        AtomicFilesystem::write_atomic($this->root . '/not-a-dir/doc.jsonc', '{}');
    }

    public function test_atomic_filesystem_reports_low_level_io_failures(): void
    {
        $blocked = $this->root . '/blocked';
        mkdir($blocked, 0555, true);
        chmod($blocked, 0555);

        try {
            AtomicFilesystem::write_atomic($blocked . '/doc.jsonc', '{}');
            $this->fail('Expected temporary file open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('temporary storage file', $exception->getMessage());
        } finally {
            chmod($blocked, 0777);
        }

        $target_directory = $this->root . '/atomic-target-directory';
        mkdir($target_directory, 0777, true);

        try {
            AtomicFilesystem::write_atomic($target_directory, '{}');
            $this->fail('Expected atomic rename into a directory to fail.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('atomically replace', $exception->getMessage());
        }

        try {
            AtomicFilesystem::read_jsonc_object($this->root . '/missing.jsonc');
            $this->fail('Expected missing read to fail.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('read storage file', $exception->getMessage());
        }

        $bad_json = $this->root . '/bad-json.jsonc';
        file_put_contents($bad_json, '{"broken": }');
        try {
            AtomicFilesystem::read_jsonc_object($bad_json);
            $this->fail('Expected invalid JSON read to fail.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Invalid JSONC', $exception->getMessage());
        }

        file_put_contents($this->root . '/readonly.txt', 'readonly');
        $handle = fopen($this->root . '/readonly.txt', 'rb');
        $this->assertIsResource($handle);
        try {
            AtomicFilesystem::write_all($handle, 'x', $this->root . '/readonly.txt');
            $this->fail('Expected read-only handle write to fail.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('write storage file', $exception->getMessage());
        } finally {
            fclose($handle);
        }
    }

    public function test_storage_root_uses_injected_base_path(): void
    {
        $root = StorageRoot::at($this->root);

        $this->assertSame(rtrim(str_replace('\\', '/', $this->root), '/'), $root->root());
        $this->assertSame(
            rtrim(str_replace('\\', '/', $this->root), '/') . '/runtime-storage',
            $root->path('.!')
        );
    }

    public function test_storage_root_normalizes_paths_and_sanitizes_namespaces(): void
    {
        $this->assertSame(
            $this->root . '/configured/my-store',
            StorageRoot::resolve($this->root . '/configured/', 'my store!')
        );
    }

    public function test_storage_root_rejects_empty_base_paths(): void
    {
        $this->expectException(StorageException::class);
        new StorageRoot('   ');
    }

    public function test_public_engine_aliases_are_autoloadable(): void
    {
        $ids = $this->fixed_ids();

        $docs = new \Storh\DocStore($this->root, 'alias-docs', $this->id_generator($ids));
        $log = new \Storh\SegmentedLog($this->root, 'alias-log', 4096, 2, $this->id_generator(array_slice($ids, 1)));
        $queue = new \Storh\Queue($this->root, 'alias-queue', $this->id_generator(array_slice($ids, 2)));

        $this->assertInstanceOf(DocPerFileStore::class, $docs);
        $this->assertInstanceOf(SegmentedLogStore::class, $log);
        $this->assertInstanceOf(LogQueue::class, $queue);
    }

    public function test_doc_per_file_store_crud_streaming_query_sharding_and_corrupt_doc_recovery(): void
    {
        $ids   = $this->fixed_ids();
        $store = new DocPerFileStore(
            $this->root,
            'docs',
            $this->id_generator($ids)
        );

        $store->put(array( 'kind' => 'page', 'title' => 'Home' ));
        $store->put(array( 'kind' => 'page', 'title' => 'About' ));
        $store->put(array( 'kind' => 'post', 'title' => 'News' ));

        $this->assertFileExists($store->path_for_id($ids[0]));
        $this->assertStringContainsString($this->doc_shard_fragment($ids[0]), str_replace('\\', '/', $store->path_for_id($ids[0])));
        $this->assertSame('Home', $store->get($ids[0])?->data()['title'] ?? null);
        $this->assertNull($store->get($ids[4]));

        $records = iterator_to_array(
            $store->stream(
                RecordQuery::all()
                    ->after($ids[0])
                    ->time_range_ms(1_700_000_000_001, 1_700_000_000_003)
                    ->where_equal('kind', 'page')
                    ->limit(1)
            )
        );

        $this->assertCount(1, $records);
        $this->assertSame($ids[1], $records[0]->id());

        AtomicFilesystem::ensure_directory(dirname($store->path_for_id($ids[3])));
        file_put_contents($store->path_for_id($ids[3]), '{ broken');
        $errors  = array();
        $scanned = iterator_to_array(
            $store->stream(
                RecordQuery::all()->continue_on_error(
                    static function (string $id, \Throwable $throwable) use (&$errors): void {
                        $errors[ $id ] = $throwable::class;
                    }
                )
            )
        );

        $this->assertCount(3, $scanned);
        $this->assertArrayHasKey($ids[3], $errors);

        $store->delete($ids[1]);
        $store->delete($ids[4]);
        $this->assertNull($store->get($ids[1]));
    }

    public function test_doc_per_file_store_keeps_cached_streams_sorted_after_out_of_order_ids(): void
    {
        $ids   = $this->fixed_ids(3);
        $store = new DocPerFileStore($this->root, 'docs-out-of-order');

        $store->put(array( 'rank' => 2 ), $ids[2]);
        $store->put(array( 'rank' => 0 ), $ids[0]);
        $store->put(array( 'rank' => 1 ), $ids[1]);

        $this->assertSame(
            $ids,
            array_map(static fn($record): string => $record->id(), iterator_to_array($store->stream()))
        );
        $this->assertSame(
            $ids,
            array_map(static fn($record): string => $record->id(), $store->query()->where('rank')->gte(0)->get())
        );
    }

    public function test_doc_per_file_store_throws_on_corrupt_doc_without_error_handler(): void
    {
        $ids   = $this->fixed_ids();
        $store = new DocPerFileStore($this->root, 'docs-bad', $this->id_generator($ids));
        AtomicFilesystem::ensure_directory(dirname($store->path_for_id($ids[0])));
        file_put_contents($store->path_for_id($ids[0]), '{ broken');

        $this->expectException(StorageException::class);
        iterator_to_array($store->stream());
    }

    public function test_doc_per_file_store_streams_empty_collections(): void
    {
        $store = new DocPerFileStore($this->root, 'docs-empty');

        $this->assertSame(array(), iterator_to_array($store->stream()));
    }

    public function test_doc_per_file_store_reports_delete_failures(): void
    {
        $ids   = $this->fixed_ids();
        $store = new DocPerFileStore($this->root, 'docs-delete-fail', $this->id_generator($ids));
        $store->put(array( 'value' => 'locked' ));
        $directory = dirname($store->path_for_id($ids[0]));
        chmod($directory, 0555);

        try {
            $store->delete($ids[0]);
            $this->fail('Expected delete failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('delete storage record', $exception->getMessage());
        } finally {
            chmod($directory, 0777);
        }
    }

    public function test_segmented_log_store_rolls_indexes_paginates_ranges_deletes_and_compacts(): void
    {
        $ids   = $this->fixed_ids();
        $store = new SegmentedLogStore(
            $this->root,
            'log',
            512,
            1,
            $this->id_generator($ids)
        );

        foreach (range(0, 5) as $index) {
            $store->put(
                array(
                    'kind'  => 0 === $index % 2 ? 'page' : 'post',
                    'value' => $index,
                    'blob'  => str_repeat('x', 160),
                )
            );
        }

        $this->assertSame(2, $store->get($ids[2])?->data()['value'] ?? null);

        $opened = array();
        $page   = iterator_to_array(
            $store->stream(
                RecordQuery::all()
                    ->after($ids[1])
                    ->time_range_ms(1_700_000_000_002, 1_700_000_000_004)
                    ->where_equal('kind', 'page')
                    ->limit(2)
                    ->on_segment_open(
                        static function (string $segment) use (&$opened): void {
                            $opened[] = $segment;
                        }
                    )
            )
        );

        $this->assertSame(array( $ids[2], $ids[4] ), array_map(static fn($record): string => $record->id(), $page));
        $this->assertNotContains('active.ndjson', $opened);

        $store->delete($ids[2]);
        $this->assertNull($store->get($ids[2]));

        $before_compact = count(iterator_to_array($store->stream()));
        $store->compact();
        $after_compact = iterator_to_array($store->stream());

        $this->assertSame(5, $before_compact);
        $this->assertArrayNotHasKey($ids[2], $store->state_index());
        $this->assertCount(5, $after_compact);
        $this->assertSame($ids[0], $after_compact[0]->id());
    }

    public function test_segmented_log_compaction_keeps_paused_readers_valid_without_segment_directory_swaps(): void
    {
        $ids   = $this->fixed_ids(80);
        $store = new SegmentedLogStore($this->root, 'compact-reader', 640, 1, $this->id_generator($ids));

        foreach (range(0, 39) as $index) {
            $store->put(
                array(
                    'index' => $index,
                    'blob'  => str_repeat('x', 140),
                )
            );
        }

        $old_segments = glob($this->root . '/compact-reader/segments/seg-*.ndjson') ?: array();
        sort($old_segments);
        $this->assertGreaterThan(1, count($old_segments));

        $seen      = array();
        $compacted = false;
        foreach ($store->stream() as $record) {
            $seen[] = $record->id();

            if (! $compacted) {
                $store->compact();
                $compacted = true;
            }
        }

        $this->assertSame(array_slice($ids, 0, 40), $seen);
        foreach ($old_segments as $segment) {
            $this->assertFileExists($segment);
        }

        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/compact-reader/manifest.jsonc');
        $sealed   = $manifest['sealed'] ?? array();
        $this->assertIsArray($sealed);
        $this->assertNotSame(array(), $sealed);

        foreach ($sealed as $segment) {
            $this->assertIsArray($segment);
            $this->assertTrue($segment['compacted'] ?? false);
            $this->assertStringStartsWith('compact-', (string) ( $segment['file'] ?? '' ));
        }

        $after_compact = iterator_to_array($store->stream());
        $this->assertSame(array_slice($ids, 0, 40), array_map(static fn($record): string => $record->id(), $after_compact));
    }

    public function test_segmented_log_store_recovers_torn_trailing_lines_and_leftover_compaction(): void
    {
        $ids   = $this->fixed_ids();
        $store = new SegmentedLogStore($this->root, 'recover', 4096, 2, $this->id_generator($ids));
        $store->put(array( 'value' => 'kept' ));

        file_put_contents($this->active_segment_path('recover'), "99\tbad\t{\"op\":\"put\"\n", FILE_APPEND);
        mkdir($this->root . '/recover/segments.compact-leftover', 0777, true);

        $reopened = new SegmentedLogStore($this->root, 'recover', 4096, 2, $this->id_generator(array_slice($ids, 1)));

        $this->assertSame('kept', $reopened->get($ids[0])?->data()['value'] ?? null);
        $this->assertCount(1, iterator_to_array($reopened->stream()));
    }

    public function test_segmented_log_store_rejects_invalid_configuration_and_query_limits(): void
    {
        try {
            new SegmentedLogStore($this->root, 'bad', 128);
            $this->fail('Expected segment size failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Segment size', $exception->getMessage());
        }

        $this->expectException(StorageException::class);
        new SegmentedLogStore($this->root, 'bad-index', 512, 0);
    }

    public function test_record_query_rejects_bad_limits_and_fields(): void
    {
        try {
            RecordQuery::all()->limit(0);
            $this->fail('Expected limit failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }

        try {
            RecordQuery::all()->where_equal('', 'value');
            $this->fail('Expected empty field failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('field', $exception->getMessage());
        }

        $this->expectException(StorageException::class);
        RecordQuery::all()->where_equal('bad', array());
    }

    public function test_record_query_matches_lower_time_boundaries(): void
    {
        $record = new \Storh\StorageRecord(
            UuidV7::generate(1_700_000_000_001),
            array()
        );

        $this->assertFalse(RecordQuery::all()->time_range_ms(1_700_000_000_002, null)->matches($record));
    }

    public function test_log_queue_claim_complete_requeue_counts_and_verify(): void
    {
        $ids   = $this->fixed_ids();
        $queue = new LogQueue($this->root, 'log-queue', $this->id_generator($ids));

        $queue->enqueue(array( 'task' => 'one' ));
        $queue->enqueue(array( 'task' => 'two' ));
        $this->assertSame(array( 'pending' => 2, 'processing' => 0, 'done' => 0 ), $queue->counts());

        $claimed = $queue->claim();
        $this->assertSame($ids[0], $claimed?->id());
        $this->assertSame('one', $claimed?->data()['task'] ?? null);
        $this->assertSame(array( 'pending' => 1, 'processing' => 1, 'done' => 0 ), $queue->counts());

        $this->assertSame(1, $queue->requeue_timed_out(0));
        $this->assertSame(array( 'pending' => 2, 'processing' => 0, 'done' => 0 ), $queue->counts());

        $claimed_again = $queue->claim();
        $this->assertSame($ids[1], $claimed_again?->id());
        $queue->complete($ids[1]);
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 1 ), $queue->counts());
        $this->assertTrue($queue->verify()['ok']);
        $this->assertTrue($queue->health()['ok']);
        $this->assertGreaterThan(0, $queue->stats()['bytes']);
        $this->assertSame(1, $queue->purgeDone());
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 0 ), $queue->counts());

        $delete = $queue->claim();
        $this->assertSame($ids[0], $delete?->id());
        $queue->complete($ids[0], false);
        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => 0 ), $queue->counts());

        file_put_contents($this->root . '/log-queue/queue.log', "broken\n", FILE_APPEND);
        $this->assertFalse($queue->verify()['ok']);
    }

    public function test_log_queue_repairs_torn_line_before_single_append(): void
    {
        $ids   = $this->fixed_ids();
        $queue = new LogQueue($this->root, 'torn-log-queue', $this->id_generator(array( $ids[0] )));
        $path  = $this->root . '/torn-log-queue/queue.log';

        $queue->enqueue(array( 'task' => 'one' ));
        file_put_contents($path, "broken\n", FILE_APPEND);
        unset($queue);

        $repaired = new LogQueue($this->root, 'torn-log-queue', $this->id_generator(array( $ids[1] )));
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 0 ), $repaired->counts());
        $replayed = $repaired->claim();
        $this->assertSame('one', $replayed?->data()['task'] ?? null);
        $this->assertSame(1, $repaired->requeue_timed_out(0));

        $repaired->enqueue(array( 'task' => 'two' ));

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString("broken\n", $contents);
        $this->assertStringNotContainsString("\0", $contents);
        $this->assertTrue($repaired->verify()['ok']);
        $this->assertSame(array( 'pending' => 2, 'processing' => 0, 'done' => 0 ), $repaired->counts());
    }

    public function test_log_queue_bulk_enqueue_claim_and_complete(): void
    {
        $ids   = $this->fixed_ids();
        $queue = new LogQueue($this->root, 'bulk-log-queue', $this->id_generator(array_slice($ids, 1)));

        $queued = $queue->enqueueMany(
            array(
                array( 'id' => $ids[0], 'payload' => array( 'task' => 'one' ) ),
                array( 'task' => 'two' ),
                array( 'task' => 'three' ),
            )
        );

        $this->assertSame(array_slice($ids, 0, 3), $queued);
        $this->assertSame(array( 'pending' => 3, 'processing' => 0, 'done' => 0 ), $queue->counts());

        $claimed = $queue->claimMany(2);
        $this->assertSame(array_slice($ids, 0, 2), array_map(static fn($record): string => $record->id(), $claimed));
        $this->assertSame('one', $claimed[0]->data()['task'] ?? null);
        $this->assertSame(array( 'pending' => 1, 'processing' => 2, 'done' => 0 ), $queue->counts());

        $this->assertSame(1, $queue->completeMany(array( $ids[0], $ids[4] )));
        $this->assertSame(array( 'pending' => 1, 'processing' => 1, 'done' => 1 ), $queue->counts());
        $this->assertSame(1, $queue->completeMany(array( $ids[1] ), false));
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 1 ), $queue->counts());

        $remaining = $queue->claimMany(10);
        $this->assertSame(array( $ids[2] ), array_map(static fn($record): string => $record->id(), $remaining));
        $this->assertSame(array(), $queue->claimMany(10));

        try {
            $queue->claimMany(0);
            $this->fail('Expected bulk claim limit failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }
    }

    public function test_segmented_log_accepts_concurrent_appends_without_lost_or_interleaved_records(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_wait')) {
            $this->markTestSkipped('pcntl is required for forked appends.');
        }

        new SegmentedLogStore($this->root, 'fork-log', 16384);
        $children = array();

        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                $child_store = new SegmentedLogStore($this->root, 'fork-log', 16384);
                for ($index = 0; $index < 20; $index++) {
                    $child_store->put(
                        array(
                            'worker' => $worker,
                            'index'  => $index,
                        )
                    );
                }
                exit(0);
            }
            $this->assertIsInt($pid);
            $children[] = $pid;
        }

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $store   = new SegmentedLogStore($this->root, 'fork-log', 16384);
        $records = iterator_to_array($store->stream());

        $this->assertCount(80, $records);
    }

    public function test_segmented_log_matches_reference_model_for_random_operations(): void
    {
        $ids       = $this->fixed_ids(40);
        $store     = new SegmentedLogStore($this->root, 'model', 1024, 3, $this->id_generator($ids));
        $model     = array();
        $created   = array();
        $id_cursor = 0;

        foreach (range(0, 29) as $step) {
            if (0 === $step % 5 && array() !== $created) {
                $id = $created[ array_key_first($created) ];
                $store->delete($id);
                unset($model[ $id ], $created[ array_key_first($created) ]);
                continue;
            }

            $record = $store->put(
                array(
                    'step' => $step,
                    'even' => 0 === $step % 2,
                )
            );
            $model[ $record->id() ]   = $record->data();
            $created[ $id_cursor++ ] = $record->id();
        }

        ksort($model);
        $actual = array();
        $memory_before_stream = memory_get_usage(true);
        foreach ($store->stream() as $record) {
            $actual[ $record->id() ] = $record->data();
        }
        $memory_after_stream = memory_get_usage(true);

        $this->assertSame($model, $actual);
        $this->assertLessThan(2 * 1024 * 1024, $memory_after_stream - $memory_before_stream);
    }

    public function test_segmented_log_uses_derived_state_index_and_reads_active_offsets(): void
    {
        $ids   = $this->fixed_ids();
        $store = new SegmentedLogStore($this->root, 'active-index', 4096, 2, $this->id_generator($ids));

        $store->put(array( 'value' => 'first' ));
        $store->put(array( 'value' => 'second' ));
        $store->put(array( 'value' => 'third' ));

        $this->assertSame('third', $store->get($ids[2])?->data()['value'] ?? null);
        $this->assertArrayHasKey($ids[2], $store->state_index());
    }

    public function test_segmented_log_recovers_derived_state(): void
    {
        $ids   = $this->fixed_ids();
        $store = new SegmentedLogStore($this->root, 'state-recover', 4096, 2, $this->id_generator($ids));
        $store->put(array( 'value' => 'first' ));
        $store->put(array( 'value' => 'second' ));

        $this->assertSame('second', $store->get($ids[1])?->data()['value'] ?? null);
        $this->assertNull($store->get($ids[4]));
        $this->assertSame('first', $store->get($ids[0])?->data()['value'] ?? null);

        $store->recover();

        $this->assertSame('second', $store->get($ids[1])?->data()['value'] ?? null);
    }

    public function test_segmented_log_reports_corrupt_lines_and_missing_segments(): void
    {
        $ids   = $this->fixed_ids(20);
        $store = new SegmentedLogStore($this->root, 'corrupt-log', 4096, 2, $this->id_generator($ids));
        $store->put(array( 'value' => 'kept' ));
            file_put_contents($this->active_segment_path('corrupt-log'), "broken\n", FILE_APPEND);

        try {
            iterator_to_array($store->stream());
            $this->fail('Expected malformed line failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Malformed', $exception->getMessage());
        }

        $continued_errors = array();
        $continued        = iterator_to_array(
            $store->stream(
                RecordQuery::all()->continue_on_error(
                    static function (string $id, \Throwable $throwable) use (&$continued_errors): void {
                        $continued_errors[ $id ] = $throwable::class;
                    }
                )
            )
        );
        $this->assertCount(1, $continued);
        $this->assertCount(1, $continued_errors);

        $envelope_store = new SegmentedLogStore($this->root, 'bad-envelope', 4096, 2, $this->id_generator(array_slice($ids, 1)));
        $envelope_store->put(array( 'value' => 'kept' ));
        file_put_contents(
            $this->active_segment_path('bad-envelope'),
            $this->encoded_log_line(array( 'id' => $ids[2] )),
            FILE_APPEND
        );

        try {
            iterator_to_array($envelope_store->stream());
            $this->fail('Expected malformed envelope failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('record envelope', $exception->getMessage());
        }

        $missing_store = new SegmentedLogStore($this->root, 'missing-segment', 512, 1, $this->id_generator(array_slice($ids, 3)));
        foreach (range(0, 5) as $index) {
            $missing_store->put(array( 'value' => $index, 'blob' => str_repeat('x', 160) ));
        }

        $sealed = glob($this->root . '/missing-segment/segments/seg-*.ndjson') ?: array();
        $this->assertNotSame(array(), $sealed);
        unlink($sealed[0]);

        try {
            iterator_to_array($missing_store->stream());
            $this->fail('Expected missing segment failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('open segment', $exception->getMessage());
        }

        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/missing-segment/manifest.jsonc');
        $manifest['sealed'][] = array(
            'file'    => 'missing-during-recovery.ndjson',
            'index'   => 'missing-during-recovery.idx.jsonc',
            'max'     => $ids[0],
            'min'     => $ids[0],
            'records' => 1,
        );
        AtomicFilesystem::write_atomic($this->root . '/missing-segment/manifest.jsonc', Jsonc::encode_object($manifest));
        $missing_store->recover();
        $this->assertIsArray($missing_store->state_index());
    }

    public function test_segmented_log_reports_active_segment_open_failures(): void
    {
        $ids   = $this->fixed_ids();
        $store = new SegmentedLogStore($this->root, 'bad-active', 4096, 2, $this->id_generator($ids));
            $active = $this->active_segment_path('bad-active');
            unlink($active);
            mkdir($active);

        $this->expectException(StorageException::class);
        $store->put(array( 'value' => 'nope' ));
    }

    public function test_segmented_log_covers_seek_recovery_and_cleanup_branches(): void
    {
        $ids   = $this->fixed_ids(40);
        $store = new SegmentedLogStore($this->root, 'branch-log', 512, 1, $this->id_generator($ids));

        foreach (range(0, 8) as $index) {
            $store->put(array( 'value' => $index, 'blob' => str_repeat('x', 120) ));
        }

        $page = iterator_to_array($store->stream(RecordQuery::all()->after($ids[1])->limit(2)));
        $this->assertSame(array( $ids[2], $ids[3] ), array_map(static fn($record): string => $record->id(), $page));

        $index_files = glob($this->root . '/branch-log/segments/seg-*.idx.jsonc') ?: array();
        sort($index_files);
        $this->assertNotSame(array(), $index_files);
        $sparse_index = AtomicFilesystem::read_jsonc_object($index_files[0]);
        $entries = isset($sparse_index['entries']) && is_array($sparse_index['entries']) ? $sparse_index['entries'] : array();
        $this->assertNotSame(array(), $entries);
        $this->assertSame($ids[0], $entries[0]['id'] ?? null);
        $this->assertSame(0, $entries[0]['offset'] ?? null);
        unlink($index_files[0]);
        $this->assertNotSame(array(), iterator_to_array($store->stream(RecordQuery::all()->after($ids[0])->limit(1))));

        $this->assertNotSame(array(), $store->state_index());
        $this->assertSame(0, $this->invoke_private($store, 'seek_offset_for', array( array( 'file' => 'active.ndjson' ), $ids[0] )));

        $empty_store = new SegmentedLogStore($this->root, 'empty-roll', 4096);
        $this->assertNull($this->invoke_private($empty_store, 'roll_active_segment'));

        mkdir($this->root . '/leftover-clean/segments.compact-leftover', 0777, true);
        new SegmentedLogStore($this->root, 'leftover-clean', 4096);
        $this->assertDirectoryDoesNotExist($this->root . '/leftover-clean/segments.compact-leftover');
    }

    public function test_segmented_log_covers_compaction_manifest_and_state_edge_branches(): void
    {
        $ids = $this->fixed_ids(40);

        $empty = new SegmentedLogStore($this->root, 'empty-compact', 4096);
        $empty->compact();
        $this->assertSame(array(), iterator_to_array($empty->stream()));

        new SegmentedLogStore($this->root, 'missing-active-init', 4096);
        $missing_active = $this->active_segment_path('missing-active-init');
        unlink($missing_active);
        new SegmentedLogStore($this->root, 'missing-active-init', 4096);
        $this->assertFileExists($missing_active);

        $missing_roll = new SegmentedLogStore($this->root, 'missing-roll', 4096, 2, $this->id_generator($ids));
        $missing_roll->put(array( 'value' => 'missing active' ));
        unlink($this->active_segment_path('missing-roll'));
        try {
            $this->invoke_private($missing_roll, 'roll_active_segment');
            $this->fail('Expected missing active roll failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('missing active', $exception->getMessage());
        }

        $invalid_next = new SegmentedLogStore($this->root, 'invalid-next', 4096, 2, $this->id_generator($ids));
        $invalid_next->put(array( 'value' => 'invalid next segment' ));
        $invalid_manifest = AtomicFilesystem::read_jsonc_object($this->root . '/invalid-next/manifest.jsonc');
        $invalid_manifest['nextSegment'] = 'bad';
        AtomicFilesystem::write_atomic(
            $this->root . '/invalid-next/manifest.jsonc',
            Jsonc::encode_object($invalid_manifest)
        );
        $this->assertNull($this->invoke_private($invalid_next, 'roll_active_segment'));

        $manifest_store = new SegmentedLogStore($this->root, 'manifest-branches', 4096);
        $manifest       = AtomicFilesystem::read_jsonc_object($this->root . '/manifest-branches/manifest.jsonc');
        $manifest['sealed'] = array(
            array( 'records' => 1 ),
            array(
                'file'    => 'manual.ndjson',
                'records' => 1,
            ),
        );
        touch($this->root . '/manifest-branches/segments/manual.ndjson');
        AtomicFilesystem::write_atomic($this->root . '/manifest-branches/manifest.jsonc', Jsonc::encode_object($manifest));

        $opened = array();
        $this->assertSame(
            array(),
            iterator_to_array(
                $manifest_store->stream(
                    RecordQuery::all()->on_segment_open(
                        static function (string $segment) use (&$opened): void {
                            $opened[] = $segment;
                        }
                    )
                )
            )
        );
        $this->assertContains('manual.ndjson', $opened);
        $this->assertSame(array(), $this->invoke_private($manifest_store, 'build_state_index'));

        $manifest_store->recover();
        $this->assertSame(array(), $this->invoke_private($manifest_store, 'write_compacted_segments', array( array( array( 'records' => 1 ) ) )));
        file_put_contents(
            $this->root . '/manifest-branches/segments/noop.ndjson',
            $this->encoded_log_line(
                array(
                    'id' => $ids[0],
                    'op' => 'noop',
                )
            )
        );
        $this->assertSame(
            array(),
            $this->invoke_private($manifest_store, 'write_compacted_segments', array( array( array( 'file' => 'noop.ndjson' ) ) ))
        );

        $compact_missing = new SegmentedLogStore($this->root, 'compact-missing', 512, 1, $this->id_generator(array_slice($ids, 1)));
        foreach (range(0, 5) as $index) {
            $compact_missing->put(array( 'index' => $index, 'blob' => str_repeat('x', 160) ));
        }
        $missing_manifest = AtomicFilesystem::read_jsonc_object($this->root . '/compact-missing/manifest.jsonc');
        $this->assertIsArray($missing_manifest['sealed'] ?? null);
        $missing_segment = $missing_manifest['sealed'][0]['file'] ?? '';
        $this->assertIsString($missing_segment);
        unlink($this->root . '/compact-missing/segments/' . $missing_segment);
        try {
            $compact_missing->compact();
            $this->fail('Expected missing sealed segment failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('open segment', $exception->getMessage());
        }

        $open_failure = new SegmentedLogStore($this->root, 'compact-open-fail', 4096);
        mkdir($this->root . '/compact-open-fail/segments/compact-fixed-000001.ndjson');
        try {
            $this->invoke_private($open_failure, 'open_compaction_segment', array( 'fixed', 1 ));
            $this->fail('Expected compaction segment open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('compaction segment', $exception->getMessage());
        }

        $finish_zero = new SegmentedLogStore($this->root, 'finish-zero', 4096);
        $zero_path   = $this->root . '/finish-zero/segments/zero.ndjson';
        $zero_handle = fopen($zero_path, 'c+b');
        $this->assertIsResource($zero_handle);
        $zero_segment = $this->invoke_private(
            $finish_zero,
            'finish_compaction_segment',
            array( $zero_handle, 'zero.ndjson', $zero_path, array(), 0, null, null )
        );
        $this->assertSame(0, $zero_segment['records'] ?? null);
        $this->assertFileDoesNotExist($zero_path);

        $entry = array(
            'deleted' => false,
            'file'    => 'one.ndjson',
            'offset'  => 10,
            'aliases' => array(),
        );
        $this->assertFalse($this->invoke_private($finish_zero, 'state_entry_matches', array( $entry, 'two.ndjson', 20 )));
        $this->assertSame(
            array(
                array(
                    'file'   => 'two.ndjson',
                    'offset' => 20,
                ),
            ),
            $this->invoke_private(
                $finish_zero,
                'dedupe_locations',
                array(
                    array(
                        array(
                            'file'   => 'one.ndjson',
                            'offset' => 10,
                        ),
                        array(
                            'file'   => 'two.ndjson',
                            'offset' => 20,
                        ),
                        array(
                            'file'   => 'two.ndjson',
                            'offset' => 20,
                        ),
                    ),
                    'one.ndjson',
                    10,
                )
            )
        );
        $this->assertSame(2, $this->invoke_private($finish_zero, 'next_segment_number_after', array( 'active.ndjson' )));
        $this->assertNull($this->invoke_private($finish_zero, 'delete_directory', array( $this->root . '/missing-directory' )));

        mkdir($this->root . '/leftover-clean-files/segments.compact-leftover/nested', 0777, true);
        file_put_contents($this->root . '/leftover-clean-files/segments.compact-leftover/nested/file.txt', 'leftover');
        new SegmentedLogStore($this->root, 'leftover-clean-files', 4096);
        $this->assertDirectoryDoesNotExist($this->root . '/leftover-clean-files/segments.compact-leftover');
    }

    public function test_segmented_log_reports_lock_open_failures(): void
    {
        new SegmentedLogStore($this->root, 'bad-lock', 4096);
        unlink($this->root . '/bad-lock/collection.lock');
        mkdir($this->root . '/bad-lock/collection.lock');

        $this->expectException(StorageException::class);
        new SegmentedLogStore($this->root, 'bad-lock', 4096);
    }

    public function test_segmented_log_cursor_time_range_and_limit_reads_are_bounded_at_scale(): void
    {
        $ids   = $this->fixed_ids(420);
        $store = new SegmentedLogStore($this->root, 'scale-log', 2048, 2, $this->id_generator($ids));

        foreach (range(0, 419) as $index) {
            $store->put(
                array(
                    'index' => $index,
                    'blob'  => str_repeat('x', 120),
                )
            );
        }

        $total_segments = count(glob($this->root . '/scale-log/segments/*.ndjson') ?: array());
        $this->assertGreaterThan(10, $total_segments);

        $opened_cursor       = array();
        $memory_before_cursor = memory_get_usage(true);
        $page                = iterator_to_array(
            $store->stream(
                RecordQuery::all()
                    ->after($ids[299])
                    ->limit(12)
                    ->on_segment_open(
                        static function (string $segment) use (&$opened_cursor): void {
                            $opened_cursor[] = $segment;
                        }
                    )
            )
        );
        $memory_after_cursor = memory_get_usage(true);

        $this->assertCount(12, $page);
        $this->assertSame($ids[300], $page[0]->id());
        $this->assertLessThan($total_segments, count(array_unique($opened_cursor)));
        $this->assertLessThanOrEqual(2 * 1024 * 1024, $memory_after_cursor - $memory_before_cursor);

        $opened_range = array();
        $range        = iterator_to_array(
            $store->stream(
                RecordQuery::all()
                    ->time_range_ms(1_700_000_000_200, 1_700_000_000_205)
                    ->on_segment_open(
                        static function (string $segment) use (&$opened_range): void {
                            $opened_range[] = $segment;
                        }
                    )
            )
        );

        $this->assertSame(array_slice($ids, 200, 6), array_map(static fn($record): string => $record->id(), $range));
        $this->assertLessThan($total_segments, count(array_unique($opened_range)));

        $memory_before_compact = memory_get_usage(true);
        $store->compact();
        $memory_after_compact = memory_get_usage(true);
        $this->assertLessThan(4 * 1024 * 1024, $memory_after_compact - $memory_before_compact);

        $opened_after_compact = array();
        $page_after_compact   = iterator_to_array(
            $store->stream(
                RecordQuery::all()
                    ->after($ids[299])
                    ->limit(12)
                    ->on_segment_open(
                        static function (string $segment) use (&$opened_after_compact): void {
                            $opened_after_compact[] = $segment;
                        }
                    )
            )
        );

        $this->assertSame(array_slice($ids, 300, 12), array_map(static fn($record): string => $record->id(), $page_after_compact));
        $this->assertLessThan(count(glob($this->root . '/scale-log/segments/*.ndjson') ?: array()), count(array_unique($opened_after_compact)));
    }

    public function test_apcu_absent_path_has_no_runtime_dependency(): void
    {
        $store = new DocPerFileStore($this->root, 'portable');
        $id    = $store->put(array( 'portable' => true ))->id();

        $this->assertSame(true, $store->get($id)?->data()['portable'] ?? false);
        $this->assertFileExists(dirname(__DIR__, 2) . '/src/DocPerFileStore.php');
    }

    /**
     * @param list<string> $values
     * @return callable(): string
     */
    private function id_generator(array $values): callable
    {
        $index = 0;

        return static function () use ($values, &$index): string {
            return $values[ $index++ ] ?? UuidV7::generate(1_700_000_999_999 + $index);
        };
    }

    /**
     * @return list<string>
     */
    private function fixed_ids(int $count = 16): array
    {
        return array_map(
            static fn(int $index): string => UuidV7::generate(1_700_000_000_000 + $index),
            range(0, $count - 1)
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function doc_shard_fragment(string $id): string
    {
        return '/data/' . substr($id, 24, 2) . '/';
    }

    private function active_segment_path(string $collection): string
    {
        return $this->root . '/' . $collection . '/segments/' . $this->active_segment_file($collection);
    }

    private function active_segment_file(string $collection): string
    {
        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/' . $collection . '/manifest.jsonc');
        $active   = $manifest['active'] ?? null;
        if (! is_array($active) || ! isset($active['file']) || ! is_string($active['file'])) {
            throw new \RuntimeException('Missing active segment in test manifest.');
        }

        return $active['file'];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function encoded_log_line(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strlen($json) . "\t" . hash('xxh32', $json) . "\t" . $json . "\n";
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
