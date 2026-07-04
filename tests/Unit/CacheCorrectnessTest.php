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
use Storh\StorageException;
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

    public function test_trust_cache_direct_query_helpers_cover_ordered_and_unordered_branches(): void
    {
        $ids = $this->fixed_ids(10);
        $ordered = new DocPerFileStore(
            $this->root,
            'trust-direct-ordered',
            cache_validation: CacheValidation::TRUST
        );
        $ordered->putMany(
            array(
                array( 'id' => $ids[0], 'data' => array( 'value' => 'alpha' ) ),
                array( 'id' => $ids[1], 'data' => array( 'value' => 'bravo', 'marker' => null ) ),
                array( 'id' => $ids[2], 'data' => array( 'value' => 'charlie', 'marker' => 'set' ) ),
            )
        );
        $ordered_reader = new DocPerFileStore(
            $this->root,
            'trust-direct-ordered',
            cache_validation: CacheValidation::TRUST
        );
        $this->assertSame('alpha', $ordered_reader->get($ids[0])?->data()['value'] ?? null);

        $empty_ordered = new DocPerFileStore(
            $this->root,
            'trust-direct-empty',
            cache_validation: CacheValidation::TRUST
        );
        $this->assertNull($empty_ordered->cached_first_record());
        $this->assertSame(array(), $empty_ordered->cached_records(10));

        $this->assertSame(array( $ids[1] ), $this->record_ids($ordered->query_records($ordered->query()->where('id')->eq($ids[1])->limit(1))));
        $this->assertSame(array(), $this->record_ids($ordered->query_records($ordered->query()->where('id')->eq($ids[9]))));
        $this->assertSame(array( $ids[1] ), $this->record_ids($ordered->query_records($ordered->query()->where('marker')->eq(null)->limit(1))));
        $this->assertSame(array(), $this->record_ids($ordered->query_records($ordered->query()->where('missing')->eq(null))));
        $this->assertSame(array( $ids[1] ), $this->record_ids($ordered->query_records($ordered->query()->where('value')->eq('bravo')->limit(1))));
        $this->assertSame(array( $ids[1] ), $this->record_ids($ordered->query_records($ordered->query()->where('value')->prefix('b')->limit(1))));

        $this->assertSame($ids[1], $ordered->first_record($ordered->query()->where('id')->eq($ids[1]))?->id());
        $this->assertNull($ordered->first_record($ordered->query()->where('id')->eq($ids[9])));
        $this->assertSame($ids[1], $ordered->first_record($ordered->query()->where('marker')->eq(null))?->id());
        $this->assertNull($ordered->first_record($ordered->query()->where('missing')->eq(null)));
        $this->assertSame($ids[1], $ordered->first_record($ordered->query()->where('value')->eq('bravo'))?->id());
        $this->assertNull($ordered->first_record($ordered->query()->where('value')->eq('missing')));
        $this->assertSame($ids[1], $ordered->first_record($ordered->query()->where('value')->prefix('b'))?->id());
        $this->assertNull($ordered->first_record($ordered->query()->where('value')->prefix('z')));

        $this->assertSame(1, $ordered->count_records($ordered->query()->where('id')->eq($ids[1])->limit(1)));
        $this->assertSame(0, $ordered->count_records($ordered->query()->where('id')->eq($ids[9])));
        $this->assertSame(1, $ordered->count_records($ordered->query()->where('marker')->eq(null)->limit(1)));
        $this->assertSame(1, $ordered->count_records($ordered->query()->where('value')->eq('bravo')));
        $this->assertSame(1, $ordered->count_records($ordered->query()->where('value')->eq('bravo')->limit(1)));
        $this->assertSame(1, $ordered->count_records($ordered->query()->where('value')->prefix('b')->limit(1)));
        $this->assertSame(array( $ids[1] ), $this->record_ids(iterator_to_array($ordered->stream(RecordQuery::all()->where_equal('value', 'bravo')->limit(1)), false)));
        $this->assertSame(array(), $this->record_ids(iterator_to_array($ordered->stream(RecordQuery::all()->where_equal('value', 'missing')), false)));
        $this->assertSame($this->record_ids($ordered->cached_records()), $this->record_ids($ordered->cached_records(10)));

        $unordered = new DocPerFileStore(
            $this->root,
            'trust-direct-unordered',
            cache_validation: CacheValidation::TRUST
        );
        $unordered->put(array( 'value' => 'charlie', 'marker' => 'set' ), $ids[6]);
        $unordered->put(array( 'value' => 'alpha' ), $ids[4]);
        $unordered->put(array( 'value' => 'bravo', 'marker' => null ), $ids[5]);

        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->query_records($unordered->query()->where('id')->eq($ids[5])->limit(1))));
        $this->assertSame(array(), $this->record_ids($unordered->query_records($unordered->query()->where('id')->eq($ids[9]))));
        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->query_records($unordered->query()->where('marker')->eq(null)->limit(1))));
        $this->assertSame(array(), $this->record_ids($unordered->query_records($unordered->query()->where('missing')->eq(null))));
        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->query_records($unordered->query()->where('value')->eq('bravo'))));
        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->query_records($unordered->query()->where('value')->prefix('b')->limit(1))));

        $this->assertSame($ids[5], $unordered->first_record($unordered->query()->where('id')->eq($ids[5]))?->id());
        $this->assertNull($unordered->first_record($unordered->query()->where('id')->eq($ids[9])));
        $this->assertSame($ids[5], $unordered->first_record($unordered->query()->where('marker')->eq(null))?->id());
        $this->assertNull($unordered->first_record($unordered->query()->where('missing')->eq(null)));
        $this->assertSame($ids[5], $unordered->first_record($unordered->query()->where('value')->eq('bravo'))?->id());
        $this->assertNull($unordered->first_record($unordered->query()->where('value')->eq('missing')));
        $this->assertSame($ids[5], $unordered->first_record($unordered->query()->where('value')->prefix('b'))?->id());
        $this->assertNull($unordered->first_record($unordered->query()->where('value')->prefix('z')));

        $data_cache = $this->private_property($unordered, 'record_data_cache');
        $this->assertIsArray($data_cache);
        unset($data_cache[ $ids[4] ]);
        $this->set_private_property($unordered, 'record_data_cache', $data_cache);
        $this->set_private_property($unordered, 'record_sorted_ids_cache', null);

        $this->assertSame(array(), $this->record_ids($unordered->query_records($unordered->query()->where('id')->eq($ids[4]))));
        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->query_records($unordered->query()->where('marker')->eq(null)->limit(1))));
        $this->assertSame($ids[5], $unordered->first_record($unordered->query()->where('marker')->eq(null))?->id());
        $this->assertSame(0, $unordered->count_records($unordered->query()->where('value')->eq('missing')->limit(1)));
        $this->assertSame(array( $ids[5], $ids[6] ), $this->record_ids($unordered->cached_records()));
        $this->assertSame(array( $ids[5] ), $this->record_ids($unordered->cached_records(1)));
        $this->assertSame(array( $ids[5], $ids[6] ), $this->record_ids($unordered->cached_records(10)));
        $this->assertSame(array( $ids[5], $ids[6] ), $this->record_ids(iterator_to_array($unordered->stream(), false)));
        $this->assertSame(array( $ids[5] ), $this->record_ids(iterator_to_array($unordered->stream(RecordQuery::all()->where_equal('value', 'bravo')), false)));

        $export = $this->root . '/trust-direct-unordered.jsonl';
        $this->assertSame(2, $unordered->exportJsonl($export));
        $this->assertCount(2, file($export, FILE_IGNORE_NEW_LINES));
    }

    public function test_doc_store_defensive_file_cache_and_import_helpers(): void
    {
        $ids = $this->fixed_ids(12);
        $plain = new DocPerFileStore($this->root, 'doc-helper-branches');
        $path = $plain->path_for_id($ids[0]);

        $this->assertNull($this->invoke_private($plain, 'cached_record', array( $ids[0], $path )));
        $this->invoke_private($plain, 'cache_record', array( new StorageRecord($ids[0], array( 'value' => 'cached' )), $path ));
        $this->invoke_private($plain, 'cache_missing', array( $ids[0], $path ));
        $this->assertSame(array(), $this->invoke_private($plain, 'cached_data', array( 123 )));
        $this->assertFalse($this->invoke_private($plain, 'record_data_cache_matches_filesystem'));
        $this->invoke_private($plain, 'remember_trusted_read_record', array( $ids[0], array( 'value' => 'ignored' ) ));
        $this->assertSame(array(), $this->invoke_private($plain, 'cached_record_ids'));

        $parse_bytes = new \ReflectionMethod(DocPerFileStore::class, 'parse_bytes');
        $this->assertNull($parse_bytes->invoke(null, ''));
        $this->assertNull($parse_bytes->invoke(null, '0'));
        $this->assertSame(1024 * 1024 * 1024, $parse_bytes->invoke(null, '1g'));
        $this->assertSame(1024 * 1024, $parse_bytes->invoke(null, '1m'));
        $this->assertSame(1024, $parse_bytes->invoke(null, '1k'));
        $this->assertSame(512, $parse_bytes->invoke(null, '512'));

        $default_cache_bytes = new \ReflectionMethod(DocPerFileStore::class, 'default_validated_record_cache_max_bytes');
        $memory_limit = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '-1');
            $this->assertNull($default_cache_bytes->invoke(null));
        } finally {
            if (false !== $memory_limit) {
                ini_set('memory_limit', $memory_limit);
            }
        }

        $hash_store = new DocPerFileStore(
            $this->root,
            'doc-helper-hash',
            cache_validation: CacheValidation::HASH
        );
        $hash_path = $hash_store->path_for_id($ids[1]);
        $this->invoke_private($hash_store, 'remember_validated_record_from_path', array( $ids[1], array( 'value' => 'missing' ), $hash_path ));
        AtomicFilesystem::ensure_directory(dirname($hash_path));
        AtomicFilesystem::write_atomic(
            $hash_path,
            Jsonc::encode_compact_object(array( 'id' => $ids[1], 'data' => array( 'value' => 'hash' ) ))
        );
        $this->set_private_property($hash_store, 'last_record_content_path', null);
        $this->set_private_property($hash_store, 'last_record_content_hash', null);
        $this->invoke_private($hash_store, 'remember_validated_record_from_path', array( $ids[1], array( 'value' => 'hash' ), $hash_path ));

        $stat_match = new DocPerFileStore($this->root, 'doc-cache-match-branches');
        $stat_match->put(array( 'value' => 'cached' ), $ids[2]);
        $stat_path = $stat_match->path_for_id($ids[2]);
        $this->set_private_property($stat_match, 'record_path_cache', array( $ids[2] => $stat_path ));
        $this->set_private_property($stat_match, 'record_data_cache', array());
        $this->assertFalse($this->invoke_private($stat_match, 'record_data_cache_matches_filesystem'));
        $this->set_private_property($stat_match, 'record_data_cache', array( $ids[2] => array( 'value' => 'cached' ) ));
        $this->set_private_property($stat_match, 'validated_record_cache', array());
        $this->assertFalse($this->invoke_private($stat_match, 'record_data_cache_matches_filesystem'));
        $this->set_private_property(
            $stat_match,
            'validated_record_cache',
            array(
                $ids[2] => array(
                    'mtime' => (int) filemtime($stat_path),
                    'size'  => (int) filesize($stat_path),
                    'hash'  => '',
                    'data'  => array( 'value' => 'cached' ),
                ),
            )
        );
        unlink($stat_path);
        $this->assertFalse($this->invoke_private($stat_match, 'record_data_cache_matches_filesystem'));
        AtomicFilesystem::write_atomic(
            $stat_path,
            Jsonc::encode_compact_object(array( 'id' => $ids[2], 'data' => array( 'value' => 'cached' ) ))
        );
        $this->set_private_property(
            $stat_match,
            'validated_record_cache',
            array(
                $ids[2] => array(
                    'mtime' => -1,
                    'size'  => (int) filesize($stat_path),
                    'hash'  => '',
                    'data'  => array( 'value' => 'cached' ),
                ),
            )
        );
        $this->assertFalse($this->invoke_private($stat_match, 'record_data_cache_matches_filesystem'));

        $cached_stat = new DocPerFileStore(
            $this->root,
            'doc-cached-record-validated',
            cache: Cache::memory(10),
            cache_validation: CacheValidation::STAT
        );
        $cached_stat->put(array( 'value' => 'cached' ), $ids[3]);
        $cached_stat_path = $cached_stat->path_for_id($ids[3]);
        $this->assertSame('cached', $this->invoke_private($cached_stat, 'cached_record', array( $ids[3], $cached_stat_path ))?->data()['value'] ?? null);

        $hash_cache_record = new DocPerFileStore(
            $this->root,
            'doc-cache-record-hash',
            cache: Cache::memory(10),
            cache_validation: CacheValidation::HASH
        );
        $hash_cache_path = $hash_cache_record->path_for_id($ids[4]);
        AtomicFilesystem::ensure_directory(dirname($hash_cache_path));
        AtomicFilesystem::write_atomic(
            $hash_cache_path,
            Jsonc::encode_compact_object(array( 'id' => $ids[4], 'data' => array( 'value' => 'hash-cache' ) ))
        );
        $this->set_private_property($hash_cache_record, 'last_record_content_path', null);
        $this->set_private_property($hash_cache_record, 'last_record_content_hash', null);
        $this->invoke_private($hash_cache_record, 'cache_record', array( new StorageRecord($ids[4], array( 'value' => 'hash-cache' )), $hash_cache_path ));
        $this->invoke_private($hash_cache_record, 'cache_record', array( new StorageRecord($ids[5], array( 'value' => 'missing-cache' )), $this->root . '/missing-cache-record.jsonc' ));

        $missing_read = $this->root . '/missing-record-object.jsonc';
        try {
            $this->invoke_private($plain, 'read_record_object', array( $missing_read ));
            $this->fail('Expected missing record object read failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('read storage file', $exception->getMessage());
        }

        $list_json = $this->root . '/list-record-object.jsonc';
        file_put_contents($list_json, '[1]');
        try {
            $this->invoke_private($plain, 'read_record_object', array( $list_json ));
            $this->fail('Expected list record object failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('object', $exception->getMessage());
        }

        $numeric_key_json = $this->root . '/numeric-key-record-object.jsonc';
        file_put_contents($numeric_key_json, '{"1":"one"}');
        try {
            $this->invoke_private($plain, 'read_record_object', array( $numeric_key_json ));
            $this->fail('Expected numeric-key record object failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('object', $exception->getMessage());
        }

        $mismatch = new DocPerFileStore($this->root, 'doc-id-mismatch');
        $mismatch_path = $mismatch->path_for_id($ids[2]);
        AtomicFilesystem::ensure_directory(dirname($mismatch_path));
        AtomicFilesystem::write_atomic(
            $mismatch_path,
            Jsonc::encode_compact_object(array( 'id' => $ids[3], 'data' => array() ))
        );
        try {
            $mismatch->get($ids[2]);
            $this->fail('Expected mismatched record id failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }

        $lock_fail = new DocPerFileStore($this->root, 'doc-lock-open-fail');
        $lock_fail->with_write_lock(static fn(): null => null);
        $lock_handle = $this->private_property($lock_fail, 'write_lock_handle');
        if (is_resource($lock_handle)) {
            fclose($lock_handle);
        }
        $this->set_private_property($lock_fail, 'write_lock_handle', null);
        $lock_path = $lock_fail->collection_root() . '/.storh/write.lock';
        unlink($lock_path);
        mkdir($lock_path);
        try {
            $lock_fail->with_write_lock(static fn(): null => null);
            $this->fail('Expected DocStore lock open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('collection lock', $exception->getMessage());
        }

        $blocked = new DocPerFileStore($this->root, 'doc-temp-open-fail');
        $blocked_dir = $this->root . '/doc-temp-open-fail/data/blocked';
        AtomicFilesystem::ensure_directory($blocked_dir);
        chmod($blocked_dir, 0555);
        try {
            $this->invoke_private($blocked, 'write_record_file_contents', array( $blocked_dir, $blocked_dir . '/doc.jsonc', $ids[4], "{}\n" ));
            $this->fail('Expected DocStore temp open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('temporary storage file', $exception->getMessage());
        } finally {
            chmod($blocked_dir, 0777);
        }

        $rename = new DocPerFileStore($this->root, 'doc-rename-fail');
        $rename_dir = $this->root . '/doc-rename-fail/data/blocked';
        AtomicFilesystem::ensure_directory($rename_dir);
        $rename_target = $rename_dir . '/target.jsonc';
        mkdir($rename_target);
        try {
            $this->invoke_private($rename, 'write_record_file_contents', array( $rename_dir, $rename_target, $ids[5], "{}\n" ));
            $this->fail('Expected DocStore rename failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('atomically replace', $exception->getMessage());
        }

        $repair = new DocPerFileStore(
            $this->root,
            'doc-quarantine-collision',
            cache: Cache::memory(10),
            cache_validation: CacheValidation::TRUST
        );
        $repair->put(array( 'value' => 'before' ), $ids[6]);
        $repair_path = $repair->path_for_id($ids[6]);
        $quarantine_root = $repair->collection_root() . '/.storh/corrupt';
        AtomicFilesystem::ensure_directory($quarantine_root);
        file_put_contents($quarantine_root . '/' . basename($repair_path), 'existing');
        file_put_contents($repair_path, '{ broken');
        $this->set_private_property($repair, 'record_path_cache', array( $ids[6] => $repair_path ));
        $this->set_private_property($repair, 'record_data_cache', array( $ids[6] => array( 'value' => 'before' ) ));
        $this->invoke_private($repair, 'quarantine_record_file', array( $ids[6], $repair_path ));
        $this->assertFileDoesNotExist($repair_path);
        $this->assertCount(2, glob($quarantine_root . '/' . $ids[6] . '*.jsonc') ?: array());

        $export = new DocPerFileStore($this->root, 'doc-export-fallback-large');
        $export->put(array( 'value' => 'big', 'payload' => str_repeat('x', 1_100_000) ), $ids[7]);
        $export->path_for_id($ids[7]);
        $this->assertNull($export->first_record($export->query()->where('value')->prefix('z')));
        $this->assertSame(1, $export->count_records($export->query()->where('value')->eq('big')->limit(1)));
        $export_path = $this->root . '/doc-export-fallback-large.jsonl';
        $this->assertSame(1, $export->exportJsonl($export_path));

        $empty_unordered = new DocPerFileStore(
            $this->root,
            'doc-empty-unordered-cache',
            cache_validation: CacheValidation::TRUST
        );
        $this->set_private_property($empty_unordered, 'record_path_cache', array( $ids[8] => '/missing-a.jsonc', $ids[9] => '/missing-b.jsonc' ));
        $this->set_private_property($empty_unordered, 'record_data_cache', array());
        $this->set_private_property($empty_unordered, 'record_cache_ordered', false);
        $this->assertNull($empty_unordered->cached_first_record());
        $this->assertSame(array(), $empty_unordered->cached_records(10));

        $remember = new DocPerFileStore($this->root, 'doc-remember-written');
        $this->set_private_property($remember, 'record_data_cache', array());
        $this->invoke_private($remember, 'remember_written_record', array( $ids[10], '/tmp/missing.jsonc', array( 'value' => 'no-cache' ), false ));
        $this->assertNull($this->private_property($remember, 'record_data_cache'));

        $import = new DocPerFileStore($this->root, 'doc-import-space');
        $import->indexes()->field('value')->sync();
        $import_path = $this->root . '/doc-import-space.jsonl';
        file_put_contents(
            $import_path,
            "   \n" . Jsonc::encode_compact_object(array( 'id' => $ids[11], 'data' => array( 'value' => 'imported' ) ))
        );
        $this->assertSame(1, $import->importJsonl($import_path));
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
}
