<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\AtomicFilesystem;
use Storh\Cache;
use Storh\CacheValidation;
use Storh\DocPerFileStore;
use Storh\Jsonc;
use Storh\RecordQuery;
use Storh\StorageRecord;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class CacheCorrectnessTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-cache-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_hash_validation_detects_same_stat_external_doc_change_after_local_write(): void
    {
        $ids = $this->fixed_ids(1);
        $store = new DocPerFileStore(
            $this->root,
            'hash-local-write',
            $this->id_generator($ids),
            cache_validation: CacheValidation::HASH
        );

        $store->put(array( 'value' => 'alpha' ));
        $path = $this->path_for_id('hash-local-write', $ids[0]);
        $mtime = (int) filemtime($path);
        $size = (int) filesize($path);

        $this->write_raw_record($path, $ids[0], array( 'value' => 'bravo' ));
        $this->force_stat($path, $mtime, $size);

        $this->assertSame('bravo', $store->get($ids[0])?->data()['value'] ?? null);
    }

    public function test_stat_validation_detects_external_doc_change_after_local_write_when_metadata_changes(): void
    {
        $ids = $this->fixed_ids(1);
        $store = new DocPerFileStore(
            $this->root,
            'stat-local-write',
            $this->id_generator($ids),
            cache_validation: CacheValidation::STAT
        );

        $store->put(array( 'value' => 'alpha' ));
        $path = $this->path_for_id('stat-local-write', $ids[0]);
        $size = (int) filesize($path);

        $this->write_raw_record($path, $ids[0], array( 'value' => 'changed-value' ));
        clearstatcache(true, $path);

        $this->assertNotSame($size, (int) filesize($path));
        $this->assertSame('changed-value', $store->get($ids[0])?->data()['value'] ?? null);
    }

    public function test_stat_query_count_sees_external_insert_instead_of_stale_fast_count(): void
    {
        $ids = $this->fixed_ids(2);
        $store = new DocPerFileStore(
            $this->root,
            'stat-external-insert',
            $this->id_generator(array( $ids[0] )),
            cache_validation: CacheValidation::STAT
        );

        $store->put(array( 'value' => 'first' ));
        $this->write_raw_record($this->path_for_id('stat-external-insert', $ids[1]), $ids[1], array( 'value' => 'second' ));

        $this->assertCount(2, $store->record_paths());
        $this->assertSame(2, $store->query()->count());
        $this->assertSame(
            $ids,
            array_map(static fn(StorageRecord $record): string => $record->id(), $store->query()->orderBy('id')->get())
        );
    }

    public function test_hash_query_fast_paths_do_not_return_stale_data_after_same_stat_external_change(): void
    {
        $ids = $this->fixed_ids(2);
        $store = new DocPerFileStore(
            $this->root,
            'hash-query',
            $this->id_generator($ids),
            cache_validation: CacheValidation::HASH
        );

        $store->putMany(array(
            array( 'value' => 'alpha' ),
            array( 'value' => 'other' ),
        ));

        $path = $this->path_for_id('hash-query', $ids[0]);
        $mtime = (int) filemtime($path);
        $size = (int) filesize($path);
        $this->write_raw_record($path, $ids[0], array( 'value' => 'bravo' ));
        $this->force_stat($path, $mtime, $size);

        $records = $store->query()->where('value')->eq('bravo')->get();

        $this->assertSame(array( $ids[0] ), array_map(static fn(StorageRecord $record): string => $record->id(), $records));
    }

    public function test_validating_complete_cache_fast_paths_recheck_external_deletes(): void
    {
        foreach (array( CacheValidation::STAT, CacheValidation::HASH ) as $mode) {
            $ids = $this->fixed_ids(2);
            $store = new DocPerFileStore(
                $this->root,
                'external-delete-' . $mode,
                $this->id_generator($ids),
                Cache::memory(10),
                cache_validation: $mode
            );

            $store->putMany(array(
                array( 'value' => 'alpha' ),
                array( 'value' => 'bravo' ),
            ));
            $this->assertSame($ids, $this->record_ids($store->query()->get()));
            $this->assertSame(2, $store->query()->count());
            $this->assertSame($ids[0], $store->query()->first()?->id());

            $path = $this->path_for_id('external-delete-' . $mode, $ids[0]);
            $this->assertTrue(@unlink($path));
            clearstatcache(true, $path);

            $this->assertNull($store->get($ids[0]));
            $this->assertSame(1, $store->query()->count());
            $this->assertSame($ids[1], $store->query()->first()?->id());
            $this->assertSame(array( $ids[1] ), $this->record_ids($store->query()->get()));
            $this->assertSame(array( $ids[1] ), $this->record_ids(iterator_to_array($store->stream(), false)));
        }
    }

    public function test_hash_complete_cache_fast_paths_recheck_same_stat_external_rewrite(): void
    {
        $ids = $this->fixed_ids(2);
        $store = new DocPerFileStore(
            $this->root,
            'hash-complete-cache',
            $this->id_generator($ids),
            Cache::memory(10),
            cache_validation: CacheValidation::HASH
        );

        $store->putMany(array(
            array( 'value' => 'alpha' ),
            array( 'value' => 'other' ),
        ));
        $this->assertSame(array( 'alpha', 'other' ), $this->record_values($store->query()->get()));
        $this->assertSame('alpha', $store->query()->first()?->data()['value'] ?? null);
        $this->assertSame(2, $store->query()->count());

        $path = $this->path_for_id('hash-complete-cache', $ids[0]);
        $mtime = (int) filemtime($path);
        $size = (int) filesize($path);
        $this->write_raw_record($path, $ids[0], array( 'value' => 'bravo' ));
        $this->force_stat($path, $mtime, $size);

        $this->assertSame('bravo', $store->get($ids[0])?->data()['value'] ?? null);
        $this->assertSame('bravo', $store->query()->first()?->data()['value'] ?? null);
        $this->assertSame(2, $store->query()->count());
        $this->assertSame(array( 'bravo', 'other' ), $this->record_values($store->query()->get()));
        $this->assertSame(array( 'bravo', 'other' ), $this->record_values(iterator_to_array($store->stream(), false)));
        $this->assertSame(0, $store->query()->where('value')->eq('alpha')->count());
        $this->assertSame(1, $store->query()->where('value')->eq('bravo')->count());
    }

    public function test_trust_complete_cache_fast_paths_follow_same_instance_mutations(): void
    {
        $ids = $this->fixed_ids(3);
        $store = new DocPerFileStore(
            $this->root,
            'trust-local-fast-paths',
            $this->id_generator($ids),
            Cache::memory(10),
            cache_validation: CacheValidation::TRUST
        );

        $store->putMany(array(
            array( 'value' => 'alpha' ),
            array( 'value' => 'bravo' ),
            array( 'value' => 'charlie' ),
        ));
        $this->assertSame(array( 'alpha', 'bravo', 'charlie' ), $this->record_values($store->query()->get()));
        $this->assertSame(3, $store->query()->count());
        $this->assertSame($ids[0], $store->query()->first()?->id());

        $store->put(array( 'value' => 'zulu' ), $ids[0]);
        $this->assertSame('zulu', $store->get($ids[0])?->data()['value'] ?? null);
        $this->assertSame('zulu', $store->query()->first()?->data()['value'] ?? null);
        $this->assertSame(array( 'zulu', 'bravo', 'charlie' ), $this->record_values($store->query()->get()));
        $this->assertSame(0, $store->query()->where('value')->eq('alpha')->count());
        $this->assertSame(1, $store->query()->where('value')->eq('zulu')->count());

        $store->delete($ids[0]);
        $this->assertNull($store->get($ids[0]));
        $this->assertSame(2, $store->query()->count());
        $this->assertSame($ids[1], $store->query()->first()?->id());
        $this->assertSame(array( $ids[1], $ids[2] ), $this->record_ids($store->query()->get()));
        $this->assertSame(array( $ids[1], $ids[2] ), $this->record_ids(iterator_to_array($store->stream(), false)));
    }

    public function test_trust_unordered_write_cache_fast_paths_remain_sorted_and_filter_correctly(): void
    {
        $ids = $this->fixed_ids(4);
        $store = new DocPerFileStore(
            $this->root,
            'trust-unordered-fast-paths',
            cache_validation: CacheValidation::TRUST
        );

        $store->put(array( 'value' => 'charlie', 'marker' => 'value' ), $ids[2]);
        $store->put(array( 'value' => 'alpha', 'marker' => null ), $ids[0]);
        $store->put(array( 'value' => 'bravo' ), $ids[1]);
        $store->put(array( 'value' => 'delta', 'marker' => 'value' ), $ids[3]);

        $this->assertSame($ids, array_map(static fn(string $path): string => basename($path, '.jsonc'), $store->record_paths()));
        $this->assertSame($ids[0], $store->query()->first()?->id());
        $this->assertSame(array_slice($ids, 0, 2), $this->record_ids($store->query()->limit(2)->get()));
        $this->assertSame(array( $ids[0] ), $this->record_ids(iterator_to_array($store->stream(RecordQuery::all()->limit(1)), false)));
        $this->assertSame(array( $ids[1] ), $this->record_ids(iterator_to_array($store->stream(RecordQuery::all()->where_equal('value', 'bravo')->limit(1)), false)));

        $this->assertSame(array( $ids[1] ), $this->record_ids($store->query()->where('value')->eq('bravo')->limit(1)->get()));
        $this->assertSame(array( $ids[0] ), $this->record_ids($store->query()->where('marker')->eq(null)->limit(1)->get()));
        $this->assertSame($ids[1], $store->query()->where('value')->eq('bravo')->first()?->id());
        $this->assertSame($ids[0], $store->query()->where('marker')->eq(null)->first()?->id());
        $this->assertSame(1, $store->query()->where('value')->eq('bravo')->count());
        $this->assertSame(1, $store->query()->where('marker')->eq(null)->count());
    }

    public function test_reindex_reads_filesystem_records_and_clears_stat_caches_after_same_stat_change(): void
    {
        $ids = $this->fixed_ids(1);
        $store = new DocPerFileStore(
            $this->root,
            'stat-reindex',
            $this->id_generator($ids),
            Cache::memory(10),
            cache_validation: CacheValidation::STAT
        );
        $store->indexes()->field('value')->sync();

        $store->put(array( 'value' => 'alpha' ));
        $this->assertSame('alpha', $store->get($ids[0])?->data()['value'] ?? null);

        $path = $this->path_for_id('stat-reindex', $ids[0]);
        $mtime = (int) filemtime($path);
        $size = (int) filesize($path);
        $this->write_raw_record($path, $ids[0], array( 'value' => 'bravo' ));
        $this->force_stat($path, $mtime, $size);

        $this->assertFalse($store->verify()['ok']);
        $store->reindex();

        $this->assertTrue($store->verify()['ok']);
        $this->assertSame('bravo', $store->get($ids[0])?->data()['value'] ?? null);
        $this->assertSame(0, $store->query()->where('value')->eq('alpha')->count());
        $this->assertSame(1, $store->query()->where('value')->eq('bravo')->count());
    }

    public function test_trust_validation_uses_shared_cache_updates_before_private_fast_path(): void
    {
        $ids = $this->fixed_ids(1);
        $cache = Cache::memory(10);
        $writer = new DocPerFileStore(
            $this->root,
            'trust-shared',
            $this->id_generator($ids),
            $cache,
            cache_validation: CacheValidation::TRUST
        );
        $reader = new DocPerFileStore(
            $this->root,
            'trust-shared',
            cache: $cache,
            cache_validation: CacheValidation::TRUST
        );

        $writer->put(array( 'value' => 'alpha' ));
        $this->assertSame('alpha', $reader->get($ids[0])?->data()['value'] ?? null);

        $writer->put(array( 'value' => 'bravo' ), $ids[0]);
        $this->assertSame('bravo', $reader->get($ids[0])?->data()['value'] ?? null);

        $this->write_raw_record($this->path_for_id('trust-shared', $ids[0]), $ids[0], array( 'value' => 'external' ));
        $this->assertSame('bravo', $reader->get($ids[0])?->data()['value'] ?? null);

        $reader->put(array( 'value' => 'local' ), $ids[0]);
        $this->assertSame('local', $reader->get($ids[0])?->data()['value'] ?? null);
    }

    public function test_trust_ordered_and_unordered_export_fast_paths_flush_buffers(): void
    {
        $ids = $this->fixed_ids(70);
        $payload = str_repeat('x', 18000);
        $ordered = new DocPerFileStore(
            $this->root,
            'trust-ordered-export',
            cache_validation: CacheValidation::TRUST
        );

        $records = array();
        foreach ($ids as $index => $id) {
            $records[] = array( 'id' => $id, 'data' => array( 'index' => $index, 'payload' => $payload ) );
        }

        $ordered->putMany($records);
        $this->assertSame(70, $ordered->cached_record_count());
        $this->assertSame(2, $ordered->cached_record_count(2));
        $this->assertSame($ids[0], $ordered->cached_first_record()?->id());
        $this->assertSame($ids, $this->record_ids($ordered->cached_records()));
        $this->assertSame(array_slice($ids, 0, 2), $this->record_ids($ordered->cached_records(2)));
        $this->assertSame(array_slice($ids, 0, 2), $this->record_ids(iterator_to_array($ordered->stream(RecordQuery::all()->limit(2)), false)));
        $this->assertSame(array( $ids[10] ), $this->record_ids($ordered->query()->where('id')->eq($ids[10])->get()));
        $this->assertSame($ids[10], $ordered->query()->where('id')->eq($ids[10])->first()?->id());
        $this->assertSame(1, $ordered->query()->where('id')->eq($ids[10])->count());
        $this->assertSame(1, $ordered->query()->where('index')->eq(10)->limit(1)->count());
        $this->assertSame($ids[10], $ordered->query()->where('index')->eq(10)->first()?->id());
        $this->assertSame(array( $ids[10] ), $this->record_ids($ordered->query()->where('index')->eq(10)->limit(1)->get()));

        $ordered_export = $this->root . '/ordered-export.jsonl';
        $this->assertSame(70, $ordered->exportJsonl($ordered_export));
        $ordered_lines = file($ordered_export, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($ordered_lines);
        $this->assertCount(70, $ordered_lines);
        $this->assertStringContainsString('"id":"' . $ids[0] . '"', $ordered_lines[0]);
        $this->assertStringContainsString('"id":"' . $ids[69] . '"', $ordered_lines[69]);

        $unordered = new DocPerFileStore(
            $this->root,
            'trust-unordered-export',
            cache_validation: CacheValidation::TRUST
        );
        foreach (array_reverse($records) as $record) {
            $unordered->put($record['data'], $record['id']);
        }

        $unordered_export = $this->root . '/unordered-export.jsonl';
        $this->assertSame(70, $unordered->exportJsonl($unordered_export));
        $unordered_lines = file($unordered_export, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($unordered_lines);
        $this->assertCount(70, $unordered_lines);
        $this->assertStringContainsString('"id":"' . $ids[0] . '"', $unordered_lines[0]);
        $this->assertStringContainsString('"id":"' . $ids[69] . '"', $unordered_lines[69]);
    }

    public function test_indexed_put_stream_updates_existing_indexes_and_shared_cache(): void
    {
        $ids = $this->fixed_ids(3);
        $store = new DocPerFileStore(
            $this->root,
            'indexed-put-stream',
            cache: Cache::memory(10),
            cache_validation: CacheValidation::HASH
        );
        $store->indexes()->field('kind')->field('rank')->range()->sync();
        $store->put(array( 'kind' => 'old', 'rank' => 1, 'value' => 'before' ), $ids[0]);

        $this->assertSame(2, $store->putStream(array(
            array( 'id' => $ids[0], 'data' => array( 'kind' => 'new', 'rank' => 10, 'value' => 'updated' ) ),
            array( 'id' => $ids[1], 'data' => array( 'kind' => 'new', 'rank' => 20, 'value' => 'created' ) ),
        )));

        $this->assertSame(0, $store->query()->where('kind')->eq('old')->count());
        $this->assertSame(2, $store->query()->where('kind')->eq('new')->count());
        $this->assertSame(array( $ids[0], $ids[1] ), $store->indexes()->candidate_ids($store->query()->where('rank')->gte(10)));
        $this->assertSame('updated', $store->get($ids[0])?->data()['value'] ?? null);
        $this->assertTrue($store->verify()['ok']);
    }

    public function test_shared_cache_normalizes_malformed_cached_payloads(): void
    {
        $id = $this->fixed_ids(1)[0];
        $cache = Cache::memory(10);
        $store = new DocPerFileStore(
            $this->root,
            'cached-payload-normalization',
            cache: $cache,
            cache_validation: CacheValidation::TRUST
        );
        $scope = $this->private_property($store, 'cache_scope');

        $cache->set('doc:cached-payload-normalization:' . $id, array( true, $scope, 0, -1, '', '{"broken"' ));
        $this->assertSame(array(), $store->get($id)?->data());

        $cache->set('doc:cached-payload-normalization:' . $id, array( true, $scope, 0, -1, '', 'not-an-array' ));
        $this->assertSame(array(), $store->get($id)?->data());

        $cache->set('doc:cached-payload-normalization:' . $id, array( true, $scope, 0, -1, '', array( 'drop', 'name' => 'kept' ) ));
        $this->assertSame(array( 'name' => 'kept' ), $store->get($id)?->data());
    }

    public function test_empty_paths_nested_locks_and_shared_cache_scope_fallbacks(): void
    {
        $id = $this->fixed_ids(1)[0];
        $empty = new DocPerFileStore($this->root, 'empty-paths');

        $this->assertSame(array(), $empty->record_paths());
        $this->assertSame('nested', $empty->with_write_lock(
            fn(): string => $empty->with_write_lock(fn(): string => 'nested')
        ));

        $cache = Cache::memory(10);
        $cached = new DocPerFileStore(
            $this->root,
            'scope-fallbacks',
            cache: $cache,
            cache_validation: CacheValidation::TRUST
        );

        $cache->set('doc:scope-fallbacks:' . $id, array( true, 'wrong-scope', 0, -1, '', array( 'value' => 'stale' ) ));
        $this->assertNull($cached->get($id));

        $cache->set('doc:scope-fallbacks:' . $id, array( true ));
        $this->assertNull($cached->get($id));
    }

    /**
     * @param list<string> $values
     * @return callable(): string
     */
    private function id_generator(array $values): callable
    {
        $index = 0;

        return static function () use ($values, &$index): string {
            return $values[ $index++ ] ?? UuidV7::generate(1_700_001_000_000 + $index);
        };
    }

    /**
     * @return list<string>
     */
    private function fixed_ids(int $count): array
    {
        return array_map(
            static fn(int $index): string => UuidV7::generate(1_700_000_000_000 + $index),
            range(0, $count - 1)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write_raw_record(string $path, string $id, array $data): void
    {
        AtomicFilesystem::write_atomic(
            $path,
            Jsonc::encode_compact_object(array( 'id' => $id, 'data' => $data ))
        );
    }

    private function path_for_id(string $collection, string $id): string
    {
        return ( new DocPerFileStore($this->root, $collection) )->path_for_id($id);
    }

    /**
     * @param list<StorageRecord> $records
     * @return list<string>
     */
    private function record_ids(array $records): array
    {
        return array_map(static fn(StorageRecord $record): string => $record->id(), $records);
    }

    /**
     * @param list<StorageRecord> $records
     * @return list<string>
     */
    private function record_values(array $records): array
    {
        return array_map(static fn(StorageRecord $record): string => (string) ( $record->data()['value'] ?? '' ), $records);
    }

    private function force_stat(string $path, int $mtime, int $size): void
    {
        $this->assertTrue(@touch($path, $mtime));
        clearstatcache(true, $path);
        $this->assertSame($mtime, (int) filemtime($path));
        $this->assertSame($size, (int) filesize($path));
    }

    private function private_property(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
