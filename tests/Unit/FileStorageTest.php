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

    public function test_uuid_v7_entropy_increment_helper_covers_partial_carries(): void
    {
        $reflection = new \ReflectionMethod(UuidV7::class, 'increment_entropy');

        $this->assertSame(str_repeat("\0", 9) . "\x01", $reflection->invoke(null, str_repeat("\0", 10)));
        $this->assertSame(str_repeat("\0", 8) . "\x01\0", $reflection->invoke(null, str_repeat("\0", 9) . "\xff"));
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
        $leftover = dirname($path) . '/.leftover.tmp';
        file_put_contents($leftover, 'partial');
        touch($leftover, time() - 120);

        $this->assertSame(array( 'ok' => true ), AtomicFilesystem::read_jsonc_object($path));
        AtomicFilesystem::append($this->root . '/atomic/events.log', "first\n");
        AtomicFilesystem::append($this->root . '/atomic/events.log', "second\n");
        $this->assertSame("first\nsecond\n", file_get_contents($this->root . '/atomic/events.log'));
        $empty = fopen($this->root . '/atomic/empty.txt', 'wb');
        $this->assertIsResource($empty);
        AtomicFilesystem::write_all($empty, '', $this->root . '/atomic/empty.txt');
        fclose($empty);
        $fresh = dirname($path) . '/.fresh.tmp';
        file_put_contents($fresh, 'partial');
        $alive = dirname($path) . '/.' . getmypid() . '.abcdef.1.tmp';
        file_put_contents($alive, 'partial');
        AtomicFilesystem::cleanup_temp_files(dirname($path));
        $this->assertFileDoesNotExist($leftover);
        $this->assertFileExists($fresh);
        $this->assertFileExists($alive);
        unlink($fresh);
        unlink($alive);
        AtomicFilesystem::cleanup_temp_files($this->root . '/missing');
        AtomicFilesystem::sync_directory($this->root . '/missing-directory');
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
            AtomicFilesystem::append($target_directory, 'x');
            $this->fail('Expected append open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('appending', $exception->getMessage());
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
        $ids   = $this->fixed_ids(4);
        $store = new DocPerFileStore($this->root, 'docs-out-of-order');

        $store->put(array( 'rank' => 2 ), $ids[2]);
        $store->put(array( 'rank' => 0 ), $ids[0]);
        $store->put(array( 'rank' => 3 ), $ids[3]);

        $this->assertSame(
            array( $ids[0], $ids[2], $ids[3] ),
            array_map(static fn($record): string => $record->id(), iterator_to_array($store->stream()))
        );

        $store->put(array( 'rank' => 1 ), $ids[1]);

        $this->assertSame(
            $ids,
            array_map(static fn($record): string => $record->id(), iterator_to_array($store->stream()))
        );
        $this->assertSame(
            $ids,
            array_map(static fn($record): string => $record->id(), $store->query()->where('rank')->gte(0)->get())
        );

        $store->delete($ids[2]);

        $this->assertSame(
            array( $ids[0], $ids[1], $ids[3] ),
            array_map(static fn($record): string => $record->id(), iterator_to_array($store->stream()))
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

    public function test_record_query_reports_id_and_data_filters_separately(): void
    {
        $ids = $this->fixed_ids(4);

        $id_only = RecordQuery::all()
            ->after($ids[0])
            ->time_range_ms(UuidV7::timestamp_ms($ids[1]), UuidV7::timestamp_ms($ids[2]));

        $this->assertTrue($id_only->filters_records());
        $this->assertFalse($id_only->filters_data());
        $this->assertFalse($id_only->matches_id($ids[0]));
        $this->assertTrue($id_only->matches_id($ids[1]));
        $this->assertTrue($id_only->matches_id($ids[2]));
        $this->assertFalse($id_only->matches_id($ids[3]));

        $data_filter = $id_only->where_equal('kind', 'page');

        $this->assertTrue($data_filter->filters_data());
        $this->assertFalse($data_filter->matches_data($ids[1], array( 'kind' => 'post' )));
        $this->assertTrue($data_filter->matches_data($ids[1], array( 'kind' => 'page' )));
        $this->assertFalse($data_filter->matches_data($ids[3], array( 'kind' => 'page' )));
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

    public function test_log_queue_syncs_external_appends_and_file_truncation(): void
    {
        $ids   = $this->fixed_ids(2);
        $queue = new LogQueue($this->root, 'external-log-queue');
        $path  = $this->root . '/external-log-queue/queue.log';

        $generated = $queue->enqueue(array( 'task' => 'generated' ));
        $this->assertTrue(UuidV7::is_valid($generated));
        file_put_contents(
            $path,
            $this->encoded_log_line(array(
                'op'      => 'enqueue',
                'id'      => $ids[0],
                'payload' => array( 'task' => 'external' ),
                'ts'      => time(),
            )),
            FILE_APPEND
        );

        $this->assertSame(array( 'pending' => 2, 'processing' => 0, 'done' => 0 ), $queue->counts());
        $first = $queue->claim();
        $this->assertSame($generated, $first?->id());
        $this->assertSame('generated', $first?->data()['task'] ?? null);
        $second = $queue->claim();
        $this->assertSame($ids[0], $second?->id());
        $this->assertSame('external', $second?->data()['task'] ?? null);

        $handle = fopen($path, 'c+b');
        $this->assertIsResource($handle);
        ftruncate($handle, 0);
        fclose($handle);

        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => 0 ), $queue->counts());
    }

    public function test_log_queue_replay_normalizes_payloads_and_pending_complete_events(): void
    {
        $ids  = $this->fixed_ids(3);
        $root = $this->root . '/queue-replay-payloads';
        mkdir($root, 0777, true);
        file_put_contents(
            $root . '/queue.log',
            $this->encoded_log_line(array( 'op' => 'enqueue', 'id' => $ids[0], 'payload' => array( 'task' => 'done' ), 'ts' => 1 ))
            . $this->encoded_log_line(array( 'op' => 'complete', 'id' => $ids[0], 'done' => true, 'ts' => 2 ))
            . $this->encoded_log_line(array( 'op' => 'complete', 'id' => $ids[0], 'done' => false, 'ts' => 3 ))
            . $this->encoded_log_line(array( 'op' => 'enqueue', 'id' => $ids[1], 'payload' => 'not-an-object', 'ts' => 4 ))
            . $this->encoded_log_line(array( 'op' => 'enqueue', 'id' => $ids[2], 'payload' => array( 'drop', 'name' => 'kept' ), 'ts' => 5 ))
        );

        $queue = new LogQueue($this->root, 'queue-replay-payloads');

        $this->assertSame(array( 'pending' => 2, 'processing' => 0, 'done' => 0 ), $queue->counts());
        $first = $queue->claim();
        $this->assertSame($ids[1], $first?->id());
        $this->assertSame(array(), $first?->data());
        $second = $queue->claim();
        $this->assertSame($ids[2], $second?->id());
        $this->assertSame(array( 'name' => 'kept' ), $second?->data());
        $this->assertNull($queue->claim());
        $this->assertTrue($queue->verify()['ok']);
    }

    public function test_log_queue_verify_and_reopen_handle_invalid_framed_tail(): void
    {
        $ids   = $this->fixed_ids(1);
        $queue = new LogQueue($this->root, 'invalid-framed-log-queue');
        $path  = $this->root . '/invalid-framed-log-queue/queue.log';
        $queue->enqueue(array( 'task' => 'valid' ), $ids[0]);
        file_put_contents(
            $path,
            $this->encoded_log_line(array( 'op' => 'unsupported', 'id' => $ids[0], 'ts' => time() )),
            FILE_APPEND
        );

        $verify = $queue->verify();
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('Unsupported log queue event', implode("\n", $verify['errors']));

        unset($queue);
        $repaired = new LogQueue($this->root, 'invalid-framed-log-queue');
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 0 ), $repaired->counts());
        $this->assertTrue($repaired->verify()['ok']);
    }

    public function test_log_queue_invalid_event_edges_and_compaction_helpers(): void
    {
        $ids   = $this->fixed_ids(4);
        $queue = new LogQueue($this->root, 'queue-edge-coverage');
        $path  = $this->root . '/queue-edge-coverage/queue.log';
        $queue->enqueue(array( 'task' => 'pending' ), $ids[0]);
        $queue->complete($ids[0]);
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 0 ), $queue->counts());
        $this->assertSame(0, $queue->completeMany(array()));
        $claimed = $queue->claim();
        $this->assertSame($ids[0], $claimed?->id());
        $this->assertSame(0, $queue->requeue_timed_out(3600));
        file_put_contents(
            $path,
            $this->encoded_log_line(array( 'op' => 'claim', 'id' => 'not-a-uuid', 'ts' => time() )),
            FILE_APPEND
        );
        $verify = $queue->verify();
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('Invalid log queue event', implode("\n", $verify['errors']));

        $decode_line = new \ReflectionMethod(LogQueue::class, 'decode_line');
        foreach (
            array(
                "2\tdeadbeef\t{}\n" => 'Corrupt log queue line',
                $this->framed_json('[1]') => 'event must be an object',
                $this->framed_json('{"0":"zero","name":"value"}') => 'event must be an object',
            ) as $line => $message
        ) {
            try {
                $decode_line->invoke($queue, $line);
                $this->fail('Expected invalid framed queue line to fail.');
            } catch (StorageException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $compact = new LogQueue($this->root, 'queue-map-compaction');
        $this->invoke_private($compact, 'apply_claim_event', array( $ids[1], time() ));
        $this->set_private_property($compact, 'pending_order', array( $ids[0], $ids[1] ));
        $this->set_private_property($compact, 'pending_offset', 1);
        $this->invoke_private($compact, 'compact_pending_order');
        $this->assertSame(array( $ids[0], $ids[1] ), $this->private_property($compact, 'pending_order'));

        $this->set_private_property($compact, 'pending_order', array_merge(array_fill(0, 4096, $ids[0]), array( $ids[1] )));
        $this->set_private_property($compact, 'pending_offset', 4096);
        $this->invoke_private($compact, 'compact_pending_order');
        $this->assertSame(array( $ids[1] ), $this->private_property($compact, 'pending_order'));
        $this->assertSame(0, $this->private_property($compact, 'pending_offset'));

        $this->set_private_property($compact, 'pending', array( $ids[1] => true ));
        $this->set_private_property($compact, 'pending_deletes', 4096);
        $this->invoke_private($compact, 'compact_pending_map');
        $this->assertSame(array( $ids[1] => true ), $this->private_property($compact, 'pending'));
        $this->assertSame(0, $this->private_property($compact, 'pending_deletes'));

        $this->set_private_property($compact, 'processing', array( $ids[2] => time() ));
        $this->set_private_property($compact, 'processing_deletes', 4096);
        $this->invoke_private($compact, 'compact_processing_map');
        $this->assertArrayHasKey($ids[2], $this->private_property($compact, 'processing'));
        $this->assertSame(0, $this->private_property($compact, 'processing_deletes'));

        $this->set_private_property($compact, 'done', array( $ids[3] => time() ));
        $this->set_private_property($compact, 'done_deletes', 4096);
        $this->invoke_private($compact, 'compact_done_map');
        $this->assertArrayHasKey($ids[3], $this->private_property($compact, 'done'));
        $this->assertSame(0, $this->private_property($compact, 'done_deletes'));
    }

    public function test_log_queue_compacts_stale_pending_order_during_claims(): void
    {
        $ids = $this->fixed_ids(2);

        $claim = new LogQueue($this->root, 'queue-stale-claim');
        $this->set_private_property($claim, 'pending_order', array_merge(array_fill(0, 4096, $ids[0]), array( $ids[1] )));
        $this->set_private_property($claim, 'pending', array( $ids[1] => true ));
        $this->set_private_property($claim, 'payloads', array( $ids[1] => array( 'task' => 'last' ) ));
        $claimed = $claim->claim();
        $this->assertSame($ids[1], $claimed?->id());
        $this->assertSame('last', $claimed?->data()['task'] ?? null);
        $this->assertSame(array(), $this->private_property($claim, 'pending_order'));
        $this->assertSame(0, $this->private_property($claim, 'pending_offset'));

        $empty_claim = new LogQueue($this->root, 'queue-stale-empty-claim');
        $this->set_private_property($empty_claim, 'pending_order', array_fill(0, 4096, $ids[0]));
        $this->assertNull($empty_claim->claim());
        $this->assertSame(array(), $this->private_property($empty_claim, 'pending_order'));

        $empty_claim_many = new LogQueue($this->root, 'queue-stale-empty-claim-many');
        $this->set_private_property($empty_claim_many, 'pending_order', array_fill(0, 4096, $ids[0]));
        $this->assertSame(array(), $empty_claim_many->claimMany(1));
        $this->assertSame(array(), $this->private_property($empty_claim_many, 'pending_order'));
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

    public function test_log_queue_large_bulk_operations_flush_event_buffers(): void
    {
        $count = 13000;
        $ids   = $this->fixed_ids($count);
        $queue = new LogQueue($this->root, 'large-bulk-log-queue');

        $jobs = static function () use ($ids): \Generator {
            foreach ($ids as $index => $id) {
                yield array( 'id' => $id, 'payload' => array( 'index' => $index ) );
            }
        };

        $queued = $queue->enqueueMany($jobs());
        $this->assertCount($count, $queued);
        $this->assertSame($ids[0], $queued[0]);
        $this->assertSame($ids[$count - 1], $queued[$count - 1]);

        $claimed = $queue->claimMany($count);
        $this->assertCount($count, $claimed);
        $this->assertSame($ids[0], $claimed[0]->id());
        $this->assertSame($ids[$count - 1], $claimed[$count - 1]->id());

        $this->assertSame($count, $queue->completeMany($ids));
        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => $count ), $queue->counts());
        $this->assertSame($count, $queue->purgeDone());
        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => 0 ), $queue->counts());

        $handle = fopen('php://memory', 'wb');
        $this->assertIsResource($handle);
        $lines = '';
        $pending_ids = array();
        $pending_payloads = array();
        $flush_enqueue = new \ReflectionMethod(LogQueue::class, 'flush_enqueue_event_buffer');
        $this->assertFalse($flush_enqueue->invokeArgs($queue, array(
            $handle,
            &$lines,
            'php://memory',
            &$pending_ids,
            &$pending_payloads,
            time(),
        )));

        $pending = array();
        $flush_complete = new \ReflectionMethod(LogQueue::class, 'flush_complete_event_buffer');
        $this->assertFalse($flush_complete->invokeArgs($queue, array(
            $handle,
            &$lines,
            'php://memory',
            &$pending,
        )));
        fclose($handle);
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

    public function test_segmented_log_state_aliases_count_fast_paths_and_line_helpers(): void
    {
        $ids   = $this->fixed_ids(32);
        $store = new SegmentedLogStore($this->root, 'alias-count', 512, 1, $this->id_generator($ids));

        foreach (range(0, 7) as $index) {
            $store->put(
                array(
                    'metric'   => 0 === $index ? 1.5 : $index,
                    'nullable' => 1 === $index ? null : 'value-' . $index,
                    'tags'     => array( 'tag-' . $index ),
                    'blob'     => str_repeat('x', 120),
                )
            );
        }

        $this->assertSame(
            1,
            $this->invoke_private(
                $store,
                'count_equal_live_records',
                array( new \Storh\QueryCondition('id', 'eq', $ids[0]), null, 3 )
            )
        );
        $this->assertSame(
            0,
            $this->invoke_private(
                $store,
                'count_equal_live_records',
                array( new \Storh\QueryCondition('id', 'eq', array( 'not-a-string' )), null, null )
            )
        );
        $this->assertNull(
            $this->invoke_private(
                $store,
                'count_equal_live_records',
                array( new \Storh\QueryCondition('tags', 'eq', array( 'tag-0' )), null, null )
            )
        );

        $this->assertSame(1, $store->query()->where('metric')->eq(1.5)->count());
        $this->assertSame(1, $store->query()->where('nullable')->eq(null)->count());
        $this->assertSame(2, $store->query()->cursor($ids[1])->limit(2)->count());
        $this->assertSame(2, $store->query()->where('metric')->gt(2)->cursor($ids[1])->limit(2)->count());
        $this->assertSame(1, $store->query()->where('tags')->eq(array( 'tag-0' ))->count());
        $this->assertNull($this->invoke_private($store, 'line_match_marker', array( new \Storh\QueryCondition('id', 'eq', $ids[0]) )));
        $this->assertNull($this->invoke_private($store, 'line_match_marker', array( new \Storh\QueryCondition('metric', 'gt', 1) )));

        $store->compact();
        $state = $store->state_index();
        $alias_id = null;
        $alias_entry = null;
        foreach ($state as $id => $entry) {
            if (array() !== $entry['aliases']) {
                $alias_id    = $id;
                $alias_entry = $entry;
                break;
            }
        }

        $this->assertIsString($alias_id);
        $this->assertIsArray($alias_entry);
        $this->assertTrue(
            $this->invoke_private(
                $store,
                'state_entry_matches',
                array( $alias_entry, $alias_entry['aliases'][0]['file'], $alias_entry['aliases'][0]['offset'] )
            )
        );

        unlink($this->root . '/alias-count/segments/' . $alias_entry['file']);
        $this->assertSame($alias_id, $store->get($alias_id)?->id());

        $wrong = new SegmentedLogStore($this->root, 'wrong-state', 4096, 2, $this->id_generator(array_slice($ids, 16)));
        $wrong->put(array( 'name' => 'first' ));
        $wrong->put(array( 'name' => 'second' ));
        $wrong_state = $wrong->state_index();
        $this->set_private_property($wrong, 'state', array( $ids[16] => $wrong_state[ $ids[17] ] ));
        try {
            $wrong->get($ids[16]);
            $this->fail('Expected wrong state entry failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('wrong record', $exception->getMessage());
        }

        $bad_offset = $wrong_state[ $ids[16] ];
        $bad_offset['offset'] = 999_999;
        $this->set_private_property($wrong, 'state', array( $ids[16] => $bad_offset ));
        try {
            $wrong->get($ids[16]);
            $this->fail('Expected unreadable state entry failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('read segment record', $exception->getMessage());
        }

        $this->set_private_property($wrong, 'state', null);
        $this->assertArrayHasKey($ids[16], $wrong->state_index());
        $this->set_private_property($wrong, 'state', null);
        $this->assertSame('first', $wrong->get($ids[16])?->data()['name'] ?? null);

        $helpers = new SegmentedLogStore($this->root, 'line-helpers', 4096);
        $canonical = $this->encoded_log_line(
            array(
                'op'   => 'put',
                'id'   => $ids[0],
                'data' => array( 'keep' => 1 ),
            )
        );
        $canonical_json = explode("\t", rtrim($canonical, "\r\n"), 3)[2];

        $this->assertSame(
            $canonical,
            $this->invoke_private(
                $helpers,
                'compaction_line',
                array(
                    $canonical,
                    array(
                        'op'   => 'put',
                        'id'   => $ids[0],
                        'data' => array( 'keep' => 1 ),
                    ),
                )
            )
        );

        $rewritten = $this->invoke_private(
            $helpers,
            'compaction_line',
            array(
                rtrim($canonical, "\n"),
                array(
                    'op' => 'delete',
                    'id' => $ids[0],
                ),
            )
        );
        $this->assertSame(array( 'id' => $ids[0], 'op' => 'put', 'data' => array() ), $this->invoke_private($helpers, 'decode_line', array( $rewritten )));
        $this->assertSame(array(), $this->invoke_private($helpers, 'data_from_envelope', array( array( 'id' => $ids[0], 'op' => 'put' ) )));
        $this->assertSame(
            array( 'keep' => 'yes' ),
            $this->invoke_private(
                $helpers,
                'data_from_envelope',
                array(
                    array(
                        'id'   => $ids[0],
                        'op'   => 'put',
                        'data' => array(
                            0      => 'drop',
                            'keep' => 'yes',
                        ),
                    ),
                )
            )
        );

        $noncanonical = $this->encoded_log_line(
            array(
                'id'   => $ids[0],
                'op'   => 'put',
                'data' => array( 'keep' => 1 ),
            )
        );
        $this->assertSame(array( 'id' => $ids[0], 'op' => 'put' ), $this->invoke_private($helpers, 'state_index_entry_from_line', array( $noncanonical )));
        $this->assertSame($canonical_json, $this->invoke_private($helpers, 'validated_line_json', array( str_replace("\n", "\r\n", $canonical) )));

        try {
            $this->invoke_private($helpers, 'validated_line_json', array( 'broken' ));
            $this->fail('Expected malformed validation failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Malformed', $exception->getMessage());
        }

        $parts = explode("\t", $canonical, 3);
        try {
            $this->invoke_private($helpers, 'decode_line', array( $parts[0] . "\tbad\t" . $parts[2] ));
            $this->fail('Expected corrupt line failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Corrupt', $exception->getMessage());
        }

        try {
            $this->invoke_private($helpers, 'decode_json_envelope', array( '{}' ));
            $this->fail('Expected invalid envelope failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('record envelope', $exception->getMessage());
        }
    }

    public function test_segmented_log_count_scans_skip_empty_manifest_entries_and_report_missing_files(): void
    {
        $empty_manifest = new SegmentedLogStore($this->root, 'count-empty-file', 4096);
        $manifest = AtomicFilesystem::read_jsonc_object($this->root . '/count-empty-file/manifest.jsonc');
        $manifest['sealed'][] = array( 'records' => 1 );
        AtomicFilesystem::write_atomic($this->root . '/count-empty-file/manifest.jsonc', Jsonc::encode_object($manifest));

        $this->assertSame(0, $empty_manifest->query()->where('value')->gt(0)->count());

        $ids = $this->fixed_ids(16);
        $missing = new SegmentedLogStore($this->root, 'count-missing-file', 512, 1, $this->id_generator($ids));
        foreach (range(0, 5) as $index) {
            $missing->put(array( 'value' => $index, 'blob' => str_repeat('x', 160) ));
        }

        $missing_manifest = AtomicFilesystem::read_jsonc_object($this->root . '/count-missing-file/manifest.jsonc');
        $this->assertIsArray($missing_manifest['sealed'] ?? null);
        $missing_segment = $missing_manifest['sealed'][0]['file'] ?? '';
        $this->assertIsString($missing_segment);
        unlink($this->root . '/count-missing-file/segments/' . $missing_segment);

        try {
            $missing->query()->where('value')->gt(-1)->count();
            $this->fail('Expected count scan missing segment failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('open segment', $exception->getMessage());
        }
    }

    public function test_segmented_log_bulk_append_roll_and_state_defensive_branches(): void
    {
        $ids = $this->fixed_ids(48);

        $bad_many = new SegmentedLogStore($this->root, 'append-many-open-fail', 4096, 2, $this->id_generator($ids));
        $bad_many_active = $this->active_segment_path('append-many-open-fail');
        unlink($bad_many_active);
        mkdir($bad_many_active);
        try {
            $bad_many->appendMany(array( array( 'value' => 'blocked' ) ));
            $this->fail('Expected appendMany active segment open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('active segment', $exception->getMessage());
        }

        $bad_stream = new SegmentedLogStore($this->root, 'append-stream-open-fail', 4096, 2, $this->id_generator(array_slice($ids, 4)));
        $bad_stream_active = $this->active_segment_path('append-stream-open-fail');
        unlink($bad_stream_active);
        mkdir($bad_stream_active);
        try {
            $bad_stream->appendStream(array( array( 'value' => 'blocked' ) ));
            $this->fail('Expected appendStream active segment open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('active segment', $exception->getMessage());
        }

        $roll_many = new SegmentedLogStore($this->root, 'append-many-roll-open-fail', 256, 1, $this->id_generator(array_slice($ids, 8)));
        mkdir($this->root . '/append-many-roll-open-fail/segments/seg-000002.ndjson');
        try {
            $roll_many->appendMany(
                array(
                    array( 'value' => 1, 'blob' => str_repeat('x', 220) ),
                    array( 'value' => 2, 'blob' => str_repeat('x', 220) ),
                )
            );
            $this->fail('Expected appendMany roll reopen failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('active segment', $exception->getMessage());
        }

        $roll_stream = new SegmentedLogStore($this->root, 'append-stream-roll-open-fail', 256, 1, $this->id_generator(array_slice($ids, 12)));
        mkdir($this->root . '/append-stream-roll-open-fail/segments/seg-000002.ndjson');
        try {
            $roll_stream->appendStream(
                array(
                    array( 'value' => 1, 'blob' => str_repeat('x', 220) ),
                    array( 'value' => 2, 'blob' => str_repeat('x', 220) ),
                )
            );
            $this->fail('Expected appendStream roll reopen failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('active segment', $exception->getMessage());
        }

        $missing_offsets = new SegmentedLogStore($this->root, 'missing-sparse-offsets', 4096, 2, $this->id_generator(array_slice($ids, 16)));
        $missing_offsets->put(array( 'value' => 'has stats' ));
        $this->set_private_property($missing_offsets, 'segment_sparse_offsets', array());
        try {
            $this->invoke_private($missing_offsets, 'roll_active_segment');
            $this->fail('Expected sparse offset roll failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Sparse offsets', $exception->getMessage());
        }

        $cursor_count = new SegmentedLogStore($this->root, 'count-deleted-cursor', 4096, 2, $this->id_generator(array_slice($ids, 20)));
        $cursor_count->put(array( 'value' => 'first' ));
        $cursor_count->put(array( 'value' => 'deleted' ));
        $cursor_count->put(array( 'value' => 'kept' ));
        $cursor_count->delete($ids[21]);
        $this->assertSame(1, $cursor_count->query()->cursor($ids[20])->count());

        $seek_count = new SegmentedLogStore($this->root, 'count-seek-offset', 900, 1, $this->id_generator(array_slice($ids, 24)));
        foreach (range(0, 8) as $index) {
            $seek_count->put(array( 'value' => $index, 'blob' => str_repeat('x', 150) ));
        }
        $this->assertSame(3, $seek_count->query()->where('value')->gte(0)->cursor($ids[25])->limit(3)->count());
        $this->assertSame(
            0,
            $this->invoke_private(
                $seek_count,
                'seek_offset_for',
                array(
                    array(
                        'file'    => 'seg-000001.ndjson',
                        'index'   => 'missing.idx.jsonc',
                        'min'     => $ids[24],
                        'max'     => $ids[30],
                        'ordered' => true,
                    ),
                    $ids[25],
                )
            )
        );

        $this->assertFalse(
            $this->invoke_private(
                $seek_count,
                'state_indexes_equivalent',
                array(
                    array(
                        $ids[0] => array(
                            'deleted' => false,
                            'file'    => 'one.ndjson',
                            'offset'  => 1,
                            'aliases' => array(),
                        ),
                    ),
                    array(
                        $ids[0] => array(
                            'deleted' => false,
                            'file'    => 'one.ndjson',
                            'offset'  => 2,
                            'aliases' => array(),
                        ),
                    ),
                )
            )
        );

        $state_writer = new SegmentedLogStore($this->root, 'state-writer', 4096);
        $this->set_private_property(
            $state_writer,
            'state',
            array(
                $ids[0] => array(
                    'deleted' => true,
                    'file'    => 'old.ndjson',
                    'offset'  => 1,
                    'aliases' => array(),
                ),
            )
        );
        $this->set_private_property($state_writer, 'deleted_record_count', 1);
        $this->set_private_property($state_writer, 'live_record_count', 0);
        $this->invoke_private(
            $state_writer,
            'write_compacted_state_entry',
            array(
                $ids[0],
                'compact.ndjson',
                7,
                array(
                    'deleted' => false,
                    'file'    => 'old.ndjson',
                    'offset'  => 1,
                    'aliases' => array(
                        array(
                            'file'   => 'older.ndjson',
                            'offset' => 0,
                        ),
                    ),
                ),
            )
        );
        $this->invoke_private(
            $state_writer,
            'write_compacted_state_entry',
            array(
                $ids[1],
                'compact.ndjson',
                17,
                array(
                    'deleted' => false,
                    'file'    => 'new.ndjson',
                    'offset'  => 3,
                    'aliases' => array(),
                ),
            )
        );
        $this->assertSame(2, $this->private_property($state_writer, 'live_record_count'));
        $this->assertSame(0, $this->private_property($state_writer, 'deleted_record_count'));

        $delete_state = new SegmentedLogStore($this->root, 'delete-state-entry', 4096);
        $this->set_private_property(
            $delete_state,
            'state',
            array(
                $ids[2] => array(
                    'deleted' => false,
                    'file'    => 'old.ndjson',
                    'offset'  => 1,
                    'aliases' => array(),
                ),
            )
        );
        $this->set_private_property($delete_state, 'live_record_count', 1);
        $this->invoke_private($delete_state, 'delete_state_entry', array( $ids[2] ));
        $this->assertSame(0, $this->private_property($delete_state, 'live_record_count'));
    }

    public function test_segmented_log_manifest_cleanup_cache_and_flush_defensive_branches(): void
    {
        $ids = $this->fixed_ids(16);

        $manifest_stats = new SegmentedLogStore($this->root, 'manifest-stat-defaults', 4096);
        AtomicFilesystem::write_atomic(
            $this->root . '/manifest-stat-defaults/manifest.jsonc',
            Jsonc::encode_object(
                array(
                    'sealed' => array(
                        array( 'file' => 'missing.ndjson' ),
                    ),
                    'active' => array( 'file' => 123 ),
                )
            )
        );
        $this->set_private_property($manifest_stats, 'manifest_state', null);
        $this->invoke_private($manifest_stats, 'repair_manifest_stats_from_segments');
        $repaired_manifest = AtomicFilesystem::read_jsonc_object($this->root . '/manifest-stat-defaults/manifest.jsonc');
        $this->assertSame(0, $repaired_manifest['sealed'][0]['records'] ?? null);

        $buffer_store = new SegmentedLogStore($this->root, 'buffer-flush-empty', 4096);
        $buffer_path = $this->root . '/buffer-flush-empty/segments/buffer.ndjson';
        $buffer_handle = fopen($buffer_path, 'c+b');
        $this->assertIsResource($buffer_handle);
        $buffer = '';
        $pending = array();
        $this->invoke_private($buffer_store, 'flush_compaction_buffer', array( $buffer_handle, &$buffer, $buffer_path, &$pending ));
        fclose($buffer_handle);
        $this->assertSame('', file_get_contents($buffer_path));

        $dirty_store = new SegmentedLogStore($this->root, 'dirty-active-flush', 4096);
        $dirty_path = $this->root . '/dirty-active-flush/segments/dirty.ndjson';
        $dirty_handle = fopen($dirty_path, 'c+b');
        $this->assertIsResource($dirty_handle);
        fwrite($dirty_handle, "dirty\n");
        $this->set_private_property($dirty_store, 'active_handle', $dirty_handle);
        $this->set_private_property($dirty_store, 'active_handle_path', $dirty_path);
        $this->set_private_property($dirty_store, 'active_handle_dirty', true);
        $this->invoke_private($dirty_store, 'flush_active_handle');
        $this->assertFalse($this->private_property($dirty_store, 'active_handle_dirty'));
        $this->set_private_property($dirty_store, 'active_handle', null);
        fclose($dirty_handle);

        $record_stats = new SegmentedLogStore($this->root, 'remember-record-stats', 4096);
        $this->invoke_private($record_stats, 'remember_segment_record', array( 'manual.ndjson', $ids[0], 0 ));
        $stats = $this->private_property($record_stats, 'segment_stats');
        $this->assertSame(1, $stats['manual.ndjson']['records'] ?? null);

        mkdir($this->root . '/cleanup-leftovers/index.rebuild-old/nested', 0777, true);
        mkdir($this->root . '/cleanup-leftovers/index.backup-old/nested', 0777, true);
        new SegmentedLogStore($this->root, 'cleanup-leftovers', 4096);
        $this->assertDirectoryDoesNotExist($this->root . '/cleanup-leftovers/index.rebuild-old');
        $this->assertDirectoryDoesNotExist($this->root . '/cleanup-leftovers/index.backup-old');

        $invalid_manifest = new SegmentedLogStore($this->root, 'invalid-cleanup-manifest', 4096);
        file_put_contents($this->root . '/invalid-cleanup-manifest/manifest.jsonc', '{broken');
        touch($this->root . '/invalid-cleanup-manifest/segments/compact-orphan.ndjson');
        $this->invoke_private($invalid_manifest, 'delete_compaction_leftovers');
        $this->assertFileExists($this->root . '/invalid-cleanup-manifest/segments/compact-orphan.ndjson');

        $referenced = new SegmentedLogStore($this->root, 'referenced-artifacts', 4096);
        AtomicFilesystem::write_atomic(
            $this->root . '/referenced-artifacts/manifest.jsonc',
            Jsonc::encode_object(
                array(
                    'sealed' => array(
                        'not-a-segment',
                        array(
                            'file'  => 'seg-000001.ndjson',
                            'index' => 'custom.idx.jsonc',
                        ),
                    ),
                    'active' => array( 'file' => 'active.ndjson' ),
                )
            )
        );
        $artifacts = $this->invoke_private($referenced, 'referenced_segment_artifacts');
        $this->assertArrayHasKey('custom.idx.jsonc', $artifacts);
        $this->assertArrayHasKey('active.idx.jsonc', $artifacts);

        $trust_cache = new \Storh\MemoryCache();
        $trust = new SegmentedLogStore($this->root, 'trust-jsonc-cache', 4096, 2, null, $trust_cache, null, null, \Storh\CacheValidation::TRUST);
        $trust_path = $this->root . '/trust-jsonc-cache/segments/trust.jsonc';
        AtomicFilesystem::write_atomic($trust_path, Jsonc::encode_object(array( 'fresh' => true )));
        $trust_cache->set('manual-trust', array( 'data' => array( 'cached' => true ) ));
        $this->assertSame(array( 'cached' => true ), $this->invoke_private($trust, 'read_cached_jsonc_object', array( $trust_path, 'manual-trust' )));

        $hash_cache = new \Storh\MemoryCache();
        $hash = new SegmentedLogStore($this->root, 'hash-jsonc-cache', 4096, 2, null, $hash_cache, null, null, \Storh\CacheValidation::HASH);
        $hash_path = $this->root . '/hash-jsonc-cache/segments/hash.jsonc';
        AtomicFilesystem::write_atomic($hash_path, Jsonc::encode_object(array( 'fresh' => true )));
        $this->assertSame(array( 'fresh' => true ), $this->invoke_private($hash, 'read_cached_jsonc_object', array( $hash_path, 'manual-hash' )));
        $this->assertSame(array( 'fresh' => true ), $this->invoke_private($hash, 'read_cached_jsonc_object', array( $hash_path, 'manual-hash' )));
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

    private function framed_json(string $json): string
    {
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

    private function set_private_property(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    private function private_property(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
