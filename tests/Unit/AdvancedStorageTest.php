<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\AtomicFilesystem;
use Storh\Cache;
use Storh\CacheValidation;
use Storh\DocPerFileStore;
use Storh\Jsonc;
use Storh\LogQueue;
use Storh\MemoryCache;
use Storh\RecordQuery;
use Storh\Schema;
use Storh\SegmentedLogStore;
use Storh\StorageRecord;
use Storh\StorageException;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class AdvancedStorageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-advanced-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);
        TestFilesystem::remove_path($this->root . '-bench.json');

        parent::tearDown();
    }

    public function test_doc_store_query_builder_indexes_schema_cache_bulk_and_maintenance(): void
    {
        $ids = $this->fixed_ids(12);
        $schema = Schema::collection('posts')
            ->string('slug')->unique()
            ->string('status')->index()
            ->int('publishedAt')->range()
            ->bool('featured')->required(array( 'slug', 'status' ));

        $store = new DocPerFileStore(
            $this->root,
            'posts',
            $this->id_generator($ids),
            Cache::memory(100),
            $schema
        );

        $records = $store->putMany(
            array(
                array( 'slug' => 'home', 'status' => 'published', 'publishedAt' => 10, 'featured' => true ),
                array( 'slug' => 'about', 'status' => 'draft', 'publishedAt' => 20, 'featured' => false ),
                array( 'slug' => 'news', 'status' => 'published', 'publishedAt' => 30, 'featured' => false ),
                array( 'slug' => 'notes', 'status' => 'archived', 'publishedAt' => 40, 'featured' => true ),
            )
        );

        $this->assertSame($ids[0], $records[0]->id());
        $this->assertSame('index_scan', $store->query()->where('status')->eq('published')->explain()['plan']);
        $this->assertSame('home', $store->query()->where('slug')->eq('home')->first()?->data()['slug'] ?? null);
        $this->assertSame('home', $store->query()->where('id')->eq($ids[0])->first()?->data()['slug'] ?? null);
        $this->assertSame(1, $store->query()->where('id')->eq($ids[0])->count());
        $this->assertSame('home', $store->query()->where('id')->eq($ids[0])->where('status')->eq('published')->first()?->data()['slug'] ?? null);
        $this->assertNull($store->query()->where('id')->eq('not-a-uuid')->first());
        $this->assertSame(0, $store->query()->where('id')->eq('not-a-uuid')->count());
        $this->assertSame(2, $store->query()->where('status')->in(array( 'draft', 'archived' ))->count());
        $this->assertSame(1, $store->query()->where('status')->eq('published')->where('featured')->eq(false)->count());
        $this->assertSame(2, $store->query()->where('publishedAt')->gte(10)->limit(2)->count());
        $statusIndexes = glob(
            $store->collection_root() . '/.storh/indexes/entries/eq/' . bin2hex('status') . '/*.jsonc'
        ) ?: array();
        $this->assertCount(3, $statusIndexes);

        $range = $store->query()
            ->where('publishedAt')->between(10, 35)
            ->where('status')->neq('draft')
            ->orderBy('publishedAt', 'desc')
            ->limit(2)
            ->get();
        $this->assertSame(array( 'news', 'home' ), array_map(static fn($record): string => $record->data()['slug'], $range));
        $range_page = $store->query()
            ->where('publishedAt')->gte(10)
            ->orderBy('publishedAt')
            ->limit(2)
            ->get();
        $this->assertSame(array( 'home', 'about' ), array_map(static fn($record): string => $record->data()['slug'], $range_page));
        $range_desc_page = $store->query()
            ->where('publishedAt')->gte(10)
            ->orderBy('publishedAt', 'desc')
            ->limit(2)
            ->get();
        $this->assertSame(array( 'notes', 'news' ), array_map(static fn($record): string => $record->data()['slug'], $range_desc_page));

        $or = $store->query()
            ->where('slug')->prefix('ho')
            ->orWhere(static fn($query) => $query->where('featured')->eq(true))
            ->orderBy('id')
            ->get();
        $this->assertSame(array( 'home', 'notes' ), array_map(static fn($record): string => $record->data()['slug'], $or));

        $filtered = $store->query()
            ->where('missing')->missing()
            ->where('slug')->notIn(array( 'about' ))
            ->where('publishedAt')->gte(10)
            ->where('publishedAt')->lte(40)
            ->cursor($ids[0])
            ->page(2)
            ->get();
        $this->assertSame(array( 'news', 'notes' ), array_map(static fn($record): string => $record->data()['slug'], $filtered));

        $this->assertSame('published', $store->get($ids[0])?->data()['status'] ?? null);
        AtomicFilesystem::write_atomic(
            $store->path_for_id($ids[0]),
            Jsonc::encode_object(
                array(
                    'id'   => $ids[0],
                    'data' => array(
                        'slug'        => 'home',
                        'status'      => 'changed',
                        'publishedAt' => 10,
                        'featured'    => true,
                    ),
                )
            )
        );
        $this->assertSame('changed', $store->get($ids[0])?->data()['status'] ?? null);

        try {
            $store->put(array( 'slug' => 'home', 'status' => 'published', 'publishedAt' => 50, 'featured' => false ));
            $this->fail('Expected unique index violation.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Unique index', $exception->getMessage());
        }

        try {
            $store->put(array( 'slug' => 'bad', 'publishedAt' => 60, 'featured' => true ));
            $this->fail('Expected schema validation failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('required', $exception->getMessage());
        }

        $export = $this->root . '/export/posts.jsonl';
        $this->assertSame(4, $store->exportJsonl($export));

        $imported = new DocPerFileStore($this->root, 'imported', cache: Cache::memory());
        $this->assertSame(4, $imported->importJsonl($export));
        $this->assertSame(4, $imported->stats()['records']);
        $streamed = new DocPerFileStore($this->root, 'streamed-docs', $this->id_generator(array_slice($ids, 6)));
        $this->assertSame(
            2,
            $streamed->putStream(
                array(
                    array( 'slug' => 'stream-a', 'kind' => 'page' ),
                    array( 'slug' => 'stream-b', 'kind' => 'page' ),
                )
            )
        );
        $this->assertSame(2, $streamed->stats()['records']);
        $this->assertSame(
            array( 'stream-a', 'stream-b' ),
            array_map(static fn(StorageRecord $record): string => $record->data()['slug'], iterator_to_array($streamed->stream()))
        );
        $this->assertTrue($store->health()['ok']);
        $this->assertTrue($store->verify()['ok']);
        $this->assertSame(3, $store->reindex()['fields']);
        $this->assertTrue($store->repair()['ok']);
        $this->assertTrue($store->compact()['ok']);
    }

    public function test_doc_store_index_builder_and_health_edge_cases(): void
    {
        $ids = $this->fixed_ids(6);
        $store = new DocPerFileStore($this->root, 'indexed', $this->id_generator($ids), Cache::memory(100));
        $store->putMany(
            array(
                array( 'title' => 'Alpha', 'active' => true, 'score' => 1, 'metric' => 1, 'nullable' => null ),
                array( 'title' => 'Beta', 'active' => false, 'score' => 2, 'metric' => 1.0, 'nullable' => null ),
                array( 'title' => 'Gamma', 'active' => true, 'score' => 3, 'metric' => 2, 'nullable' => 'x' ),
            )
        );

        $manager = $store->indexes();
        $manager
            ->field('title')->range()
            ->field('active')->range()
            ->field('nullable')->unique()
            ->field('metric')
            ->field('score')->sync();

        $this->assertFileExists($store->collection_root() . '/.storh/indexes/entries/range/' . bin2hex('title') . '.idx.jsonc');
        $this->assertSame('index_scan', $store->query()->where('score')->in(array( 1, 3 ))->explain()['plan']);
        $this->assertSame(2, $store->query()->where('active')->eq(true)->count());
        $this->assertSame(1, $store->query()->where('active')->eq(true)->orderBy('score')->limit(1)->count());
        $this->assertSame(1, $store->query()->where('score')->in(array( 1, 3 ))->limit(1)->count());
        $this->assertSame(1, $store->query()->where('metric')->eq(1)->count());
        $this->assertSame(1, $store->query()->where('metric')->eq(1.0)->count());
        $this->assertSame(1, $store->query()->where('metric')->eq(1)->where('score')->eq(1)->count());
        $this->assertSame(0, $store->query()->where('metric')->eq(1)->where('score')->eq(2)->count());
        $this->assertSame(1, $store->query()->where('score')->eq(1)->where('score')->eq(1)->count());
        $this->assertSame(array( $ids[0] ), $store->indexes()->candidate_ids($store->query()->where('metric')->eq(1)->where('score')->eq(1)));
        $this->assertSame(array(), $store->indexes()->candidate_ids($store->query()->where('score')->eq(2)->where('metric')->eq(1)));
        $this->assertSame(2, $store->query()->where('active')->eq(true)->where('score')->in(array( 1, 3 ))->count());
        $this->assertSame(0, $store->query()->where('active')->eq(false)->where('score')->eq(1)->count());
        $this->assertSame(2, $store->indexes()->candidate_count($store->query()->where('active')->eq(true)->where('score')->in(array( 1, 3 ))));
        $this->assertSame(0, $store->indexes()->candidate_count($store->query()->where('active')->eq(false)->where('score')->eq(1)));
        $this->assertSame(array(), $store->indexes()->candidate_ids($store->query()->where('active')->eq(false)->where('score')->eq(1)));
        $this->assertSame(2, $store->query()->where('title')->prefix('A')->orWhere(static fn($query) => $query->where('title')->eq('Beta'))->count());
        $this->assertSame(0, $store->query()->where('score')->gt(99)->count());
        $this->assertNull($store->indexes()->candidate_ids($store->query()));
        $this->assertSame(array(), $store->indexes()->candidate_ids($store->query()->where('score')->eq(array( 'bad' ))));
        $negative = new DocPerFileStore($this->root, 'negative-range', $this->id_generator($this->fixed_ids(4)));
        $negative->putMany(
            array(
                array( 'score' => -2 ),
                array( 'score' => -1 ),
                array( 'score' => 0 ),
                array( 'score' => 1 ),
            )
        );
        $negative->indexes()->field('score')->range()->sync();
        $negative_asc = $negative->query()->where('score')->gte(-2)->orderBy('score')->limit(3)->get();
        $this->assertSame(array( -2, -1, 0 ), array_map(static fn($record): int => $record->data()['score'], $negative_asc));
        $negative_desc = $negative->query()->where('score')->gte(-2)->orderBy('score', 'desc')->limit(3)->get();
        $this->assertSame(array( 1, 0, -1 ), array_map(static fn($record): int => $record->data()['score'], $negative_desc));
        $reopened = new DocPerFileStore($this->root, 'indexed');
        $this->assertSame(1, $reopened->query()->where('metric')->eq(1.0)->count());
        $this->assertSame(5, $reopened->reindex()['fields']);
        $this->assertSame(1, $reopened->query()->where('metric')->eq(1.0)->count());
        $store->indexes()->field('builder-a')->field('builder-b')->sync(false);

        try {
            $manager->field('');
            $this->fail('Expected empty index field failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('field', $exception->getMessage());
        }

        $store->put(array( 'title' => 'Beta Prime', 'active' => false, 'score' => 20, 'nullable' => 'updated' ), $ids[1]);
        $this->assertSame('Beta Prime', $store->get($ids[1])?->data()['title'] ?? null);
        $this->assertSame(1, $store->query()->where('title')->eq('Beta Prime')->count());
        $this->assertSame(0, $store->query()->where('title')->eq('Beta')->count());
        $this->assertFileExists($store->collection_root() . '/.storh/indexes/entries/range/' . bin2hex('title') . '.delta.jsonl');

        AtomicFilesystem::ensure_directory(dirname($store->path_for_id($ids[4])));
        file_put_contents($store->path_for_id($ids[4]), '{ broken');
        $this->assertFalse($store->health()['ok']);
        $this->assertSame(1, $store->stats()['corrupt']);

        try {
            $store->importJsonl($this->root . '/missing.jsonl');
            $this->fail('Expected missing import file failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('import', $exception->getMessage());
        }

        $badJsonl = $this->root . '/bad.jsonl';
        file_put_contents($badJsonl, "\"not-object\"\n");
        try {
            $store->importJsonl($badJsonl);
            $this->fail('Expected invalid JSONL row failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('JSONL', $exception->getMessage());
        }

        $jsonl = $this->root . '/blank.jsonl';
        file_put_contents($jsonl, "\n" . json_encode(array( 'ok' => true ), JSON_THROW_ON_ERROR) . "\n");
        $this->assertSame(1, $store->importJsonl($jsonl));

        $numericKeyJsonl = $this->root . '/numeric-key.jsonl';
        file_put_contents($numericKeyJsonl, "{\"0\":\"drop\",\"ok\":true}\n");
        $numericKeyStore = new DocPerFileStore($this->root, 'numeric-key-jsonl');
        $this->assertSame(1, $numericKeyStore->importJsonl($numericKeyJsonl));
        $numericKeyRecords = iterator_to_array($numericKeyStore->stream());
        $this->assertCount(1, $numericKeyRecords);
        $this->assertSame(array( 'ok' => true ), $numericKeyRecords[0]->data());

        $extraEnvelopeJsonl = $this->root . '/extra-envelope.jsonl';
        file_put_contents(
            $extraEnvelopeJsonl,
            json_encode(array( 'id' => $ids[5], 'data' => array( 'ok' => true ), 'extra' => 'drop' ), JSON_THROW_ON_ERROR) . "\n"
        );
        $extraEnvelopeStore = new DocPerFileStore($this->root, 'extra-envelope-jsonl');
        $this->assertSame(1, $extraEnvelopeStore->importJsonl($extraEnvelopeJsonl));
        $extraEnvelopeRecords = iterator_to_array($extraEnvelopeStore->stream());
        $this->assertCount(1, $extraEnvelopeRecords);
        $this->assertSame(array( 'ok' => true ), $extraEnvelopeRecords[0]->data());

        $exportDirectory = $this->root . '/export-directory';
        mkdir($exportDirectory);
        try {
            $store->exportJsonl($exportDirectory);
            $this->fail('Expected export open failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('export', $exception->getMessage());
        }

        $missingId = $ids[5];
        $this->assertNull($store->get($missingId));
        $this->assertNull($store->get($missingId));

        $store->indexes()->define_field('arrayUnique', unique: true)->sync(false);
        $store->indexes()->validate_unique(null, array( 'arrayUnique' => array( 'not' => 'indexable' ) ));
        $store->indexes()->remove_record($ids[0], array( 'arrayUnique' => array( 'not' => 'indexable' ) ));
    }

    public function test_doc_store_compound_equality_indexes_update_and_remove(): void
    {
        $ids = $this->fixed_ids(4);
        $store = new DocPerFileStore($this->root, 'compound-index', $this->id_generator($ids));
        $store->putMany(
            array(
                array( 'kind' => 'page', 'bucket' => 1, 'slug' => 'home' ),
                array( 'kind' => 'page', 'bucket' => 2, 'slug' => 'about' ),
                array( 'kind' => 'post', 'bucket' => 1, 'slug' => 'news' ),
            )
        );
        $store->indexes()->field('kind')->field('bucket')->sync();

        $hit = $store->query()->where('kind')->eq('page')->where('bucket')->eq(1);
        $this->assertSame(1, $hit->count());
        $this->assertSame(array( $ids[0] ), $store->indexes()->candidate_ids($hit));
        $this->assertSame(array( 'home' ), array_map(static fn($record): string => $record->data()['slug'], $hit->get()));

        $miss = $store->query()->where('kind')->eq('post')->where('bucket')->eq(2);
        $this->assertSame(0, $miss->count());
        $this->assertSame(array(), $store->indexes()->candidate_ids($miss));

        $store->put(array( 'kind' => 'page', 'bucket' => 2, 'slug' => 'home' ), $ids[0]);
        $this->assertSame(0, $hit->count());
        $this->assertSame(2, $store->query()->where('kind')->eq('page')->where('bucket')->eq(2)->count());

        $store->delete($ids[0]);
        $this->assertSame(1, $store->query()->where('kind')->eq('page')->where('bucket')->eq(2)->count());
    }

    public function test_index_manifest_and_range_defensive_paths(): void
    {
        $ids = $this->fixed_ids(4);
        $store = new DocPerFileStore($this->root, 'defensive-index', $this->id_generator($ids));
        $store->indexes()->define_field('direct')->sync(false);
        $store->indexes()->field('rangeField')->range()->sync();
        $store->indexes()->field('missingRange')->range()->sync(false);
        $this->assertSame(array(), $store->query()->where('missingRange')->gt(1)->get());

        $manifest = $store->collection_root() . '/.storh/indexes/manifest.jsonc';
        \Storh\AtomicFilesystem::write_atomic(
            $manifest,
            Jsonc::encode_object(
                array(
                    'fields' => array(
                        array( 'field' => 'rangeField', 'range' => true ),
                        array( 'field' => 123 ),
                    ),
                )
            )
        );
        $this->assertArrayHasKey('rangeField', $store->indexes()->definitions());

        $rangeRoot = $store->collection_root() . '/.storh/indexes/entries/range';
        mkdir($rangeRoot, 0777, true);
        file_put_contents(
            $rangeRoot . '/' . bin2hex('rangeField') . '.jsonl',
            json_encode(array( 'key' => 'manual', 'value' => 10 ), JSON_THROW_ON_ERROR) . "\n"
        );
        $this->assertSame(array(), $store->query()->where('rangeField')->gt(1)->get());

        $reflection = new \ReflectionMethod($store->indexes(), 'range_key');
        $this->assertStringStartsWith('z-', (string) $reflection->invoke($store->indexes(), array( 'not' => 'scalar' )));
    }

    public function test_limited_equality_index_reads_across_chunks(): void
    {
        $ids = $this->fixed_ids(280);
        $store = new DocPerFileStore($this->root, 'chunked-eq-index', $this->id_generator($ids));
        foreach ($ids as $index => $_) {
            $store->put(array( 'kind' => 'page', 'position' => $index ));
        }

        $store->indexes()->field('kind')->sync();

        $records = $store->query()->where('kind')->eq('page')->limit(260)->get();

        $this->assertCount(260, $records);
        $this->assertSame($ids[0], $records[0]->id());
        $this->assertSame($ids[259], $records[259]->id());
    }

    public function test_equality_index_count_reads_long_values(): void
    {
        $ids = $this->fixed_ids(3);
        $store = new DocPerFileStore($this->root, 'long-eq-count', $this->id_generator($ids));
        $value = str_repeat('x', 12000);
        $store->put(array( 'tag' => $value, 'name' => 'first' ));
        $store->put(array( 'tag' => $value, 'name' => 'second' ));
        $store->put(array( 'tag' => 'short', 'name' => 'third' ));

        $store->indexes()->field('tag')->sync();

        $this->assertSame(2, $store->query()->where('tag')->eq($value)->count());
        $this->assertSame(1, $store->query()->where('tag')->eq($value)->limit(1)->count());
        $this->assertSame(0, $store->query()->where('tag')->eq(str_repeat('y', 12000))->count());
    }

    public function test_range_sparse_index_reads_duplicate_keys_across_checkpoints(): void
    {
        $ids = $this->fixed_ids(300);
        $store = new DocPerFileStore($this->root, 'chunked-range-index', $this->id_generator($ids));
        foreach ($ids as $index => $_) {
            $store->put(array( 'enabled' => true, 'position' => $index ));
        }

        $store->indexes()->field('enabled')->range()->sync();

        $this->assertSame(300, $store->query()->where('enabled')->eq(true)->count());
        $this->assertSame(300, $store->query()->where('enabled')->gte(true)->count());
    }

    public function test_doc_store_can_add_indexes_after_no_index_writes(): void
    {
        $ids = $this->fixed_ids(3);
        $store = new DocPerFileStore($this->root, 'late-index', $this->id_generator($ids));

        $store->put(array( 'kind' => 'page', 'slug' => 'one' ));
        $store->indexes()->field('kind')->sync();
        $store->put(array( 'kind' => 'page', 'slug' => 'two' ));

        $records = $store->query()->where('kind')->eq('page')->get();

        $this->assertSame(array( 'one', 'two' ), array_map(static fn($record): string => $record->data()['slug'], $records));
    }

    public function test_query_builder_all_operators_without_indexes(): void
    {
        $store = new DocPerFileStore($this->root, 'operators', $this->id_generator($this->fixed_ids(5)));
        $store->put(array( 'name' => 'alpha', 'score' => 10, 'active' => true ));
        $store->put(array( 'name' => 'beta', 'score' => 20, 'active' => false ));
        $store->put(array( 'name' => 'alpine', 'score' => 30 ));

        $this->assertSame(2, $store->query()->where('name')->prefix('al')->count());
        $this->assertSame(2, $store->query()->where('score')->gt(10)->count());
        $this->assertSame(1, $store->query()->where('score')->lt(20)->count());
        $this->assertSame(2, $store->query()->where('active')->exists()->count());
        $this->assertSame(1, $store->query()->where('active')->missing()->count());
        $this->assertSame(1, $store->query()->where('id')->eq($store->query()->first()?->id())->count());
        $this->assertSame(1, $store->query()->andWhere(static fn($query) => $query->where('score')->eq(10))->count());
        $this->assertSame('full_scan', $store->query()->where('name')->eq('alpha')->explain()['plan']);
        $this->assertSame('full_scan', ( new SegmentedLogStore($this->root, 'query-log') )->query()->explain()['plan']);
        $this->assertSame(1, $store->query()->limit(1)->limit_value());
        $cursor = $store->query()->first()?->id() ?? $this->fixed_ids(1)[0];
        $this->assertSame($cursor, $store->query()->cursor($cursor)->cursor_id());

        try {
            $store->query()->orderBy('name', 'sideways');
            $this->fail('Expected order direction failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('direction', $exception->getMessage());
        }

        try {
            $store->query()->limit(0);
            $this->fail('Expected query limit failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }

        try {
            $store->query()->andWhere(static fn() => null);
            $this->fail('Expected andWhere callback failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('andWhere', $exception->getMessage());
        }

        try {
            $store->query()->orWhere(static fn() => null);
            $this->fail('Expected orWhere callback failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('orWhere', $exception->getMessage());
        }

        try {
            new \Storh\QueryCondition('', 'eq', 'x');
            $this->fail('Expected empty query field failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('field', $exception->getMessage());
        }

        $condition = new \Storh\QueryCondition('name', 'between', 'a', 'z');
        $this->assertSame('z', $condition->second_value());
        $this->assertSame(0, \Storh\QueryCondition::compare(null, null));
        $this->assertLessThan(0, \Storh\QueryCondition::compare(false, true));
        $this->assertNotSame(0, \Storh\QueryCondition::compare(array( 'a' ), array( 'b' )));

        try {
            ( new \Storh\QueryCondition('name', 'unknown') )->matches(new \Storh\StorageRecord($this->fixed_ids(1)[0], array()));
            $this->fail('Expected unknown operator failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('operator', $exception->getMessage());
        }
    }

    public function test_schema_edge_cases_and_type_validation(): void
    {
        try {
            Schema::collection('');
            $this->fail('Expected empty collection failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('collection', $exception->getMessage());
        }

        $schema = Schema::collection('typed')
            ->float('rating')->required()
            ->mixed('meta')->required_fields(array( 'rating', 'meta' ));
        $schema->define('extra', 'mixed');
        Schema::collection('chain')->string('a')->int('b')->float('c')->bool('d');
        Schema::collection('chain-two')->int('a')->string('b');
        $schema->validate(array( 'rating' => 4, 'meta' => array( 'ok' => true ) ));

        try {
            $schema->validate(array( 'rating' => 'bad', 'meta' => null ));
            $this->fail('Expected schema type failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('rating', $exception->getMessage());
        }

        try {
            new DocPerFileStore($this->root, 'wrong', schema: Schema::collection('other'));
            $this->fail('Expected schema collection mismatch.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Schema collection', $exception->getMessage());
        }

        try {
            Schema::collection('bad')->string('');
            $this->fail('Expected empty schema field failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('field', $exception->getMessage());
        }
    }

    public function test_segmented_log_bulk_query_retention_partition_cache_and_maintenance(): void
    {
        $ids = $this->fixed_ids(10);
        $store = new SegmentedLogStore(
            $this->root,
            'events',
            512,
            1,
            $this->id_generator($ids),
            Cache::memory(),
            'daily',
            1_700_000_000_000
        );

        $store->appendMany(
            array(
                array( 'type' => 'old', 'value' => 1, 'blob' => str_repeat('x', 120) ),
                array( 'type' => 'new', 'value' => 2, 'blob' => str_repeat('x', 120) ),
                array( 'type' => 'new', 'value' => 3, 'blob' => str_repeat('x', 120) ),
            )
        );

        $this->assertDirectoryExists($this->root . '/events/partitions/2023-11-14');
        $this->assertSame(2, $store->query()->where('type')->eq('new')->count());
        $limited = $store->query()->where('type')->eq('new')->limit(1)->get();
        $this->assertCount(1, $limited);
        $this->assertSame(2, $limited[0]->data()['value']);
        $this->assertSame(2, $store->query()->where('id')->eq($ids[1])->first()?->data()['value'] ?? null);
        $this->assertSame(1, $store->query()->where('id')->eq($ids[1])->where('type')->eq('new')->count());
        $cursor_page = $store->query()->where('type')->eq('new')->cursor($ids[1])->limit(1)->get();
        $this->assertCount(1, $cursor_page);
        $this->assertSame($ids[2], $cursor_page[0]->id());
        $this->assertSame(3, $cursor_page[0]->data()['value']);
        $this->assertGreaterThanOrEqual(1, $store->stats()['segments']);
        $this->assertTrue($store->health()['ok']);
        $this->assertTrue($store->verify()['ok']);
        $this->assertTrue($store->repair()['ok']);
        $store->delete($ids[2]);
        $this->assertSame(2, $store->query()->count());
        $this->assertSame(1, $store->query()->where('type')->eq('new')->count());
        $this->assertSame(1, $store->query()->where('type')->eq('new')->cursor($ids[0])->count());
        $store->put(array( 'type' => 'old', 'value' => 22, 'blob' => str_repeat('x', 120) ), $ids[1]);
        $this->assertSame(0, $store->query()->where('type')->eq('new')->count());
        $this->assertSame(2, $store->query()->where('type')->eq('old')->count());
        $this->assertSame(2, $store->stats()['records']);
        $this->assertGreaterThanOrEqual(1, $store->stats()['deleted']);

        $stream = new SegmentedLogStore($this->root, 'stream-events', 512, 1, $this->id_generator(array_slice($ids, 3)));
        $this->assertSame(
            3,
            $stream->appendStream(
                array(
                    array( 'type' => 'streamed', 'value' => 4, 'blob' => str_repeat('x', 120) ),
                    array( 'type' => 'streamed', 'value' => 5, 'blob' => str_repeat('x', 120) ),
                    array( 'type' => 'streamed', 'value' => 6, 'blob' => str_repeat('x', 120) ),
                )
            )
        );
        $this->assertSame(3, $stream->query()->where('type')->eq('streamed')->count());
        $reopened_stream = new SegmentedLogStore($this->root, 'stream-events', 512, 1);
        $this->assertSame(3, $reopened_stream->query()->where('type')->eq('streamed')->count());
        $this->assertSame(5, $reopened_stream->get($ids[4])?->data()['value'] ?? null);

        $deleted = $store->retain()->olderThanMs(UuidV7::timestamp_ms($ids[0]))->compact();
        $this->assertSame(1, $deleted);
        $this->assertNull($store->get($ids[0]));

        $monthly = new SegmentedLogStore($this->root, 'monthly', 4096, 2, partition: 'monthly', partition_timestamp_ms: 1_700_000_000_000);
        $monthly->put(array( 'ok' => true ));
        $this->assertDirectoryExists($this->root . '/monthly/partitions/2023-11');
        $this->assertGreaterThanOrEqual(0, $monthly->retain()->olderThanDays(1)->compact());

        try {
            new SegmentedLogStore($this->root, 'bad-partition', partition: 'yearly');
            $this->fail('Expected partition failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('partition', $exception->getMessage());
        }

        try {
            $monthly->retain()->olderThanDays(0);
            $this->fail('Expected retention day failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Retention', $exception->getMessage());
        }

        try {
            $monthly->retain()->compact();
            $this->fail('Expected retention cutoff failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('cutoff', $exception->getMessage());
        }

        $corrupt = new SegmentedLogStore($this->root, 'verify-corrupt', 4096);
        $corrupt->put(array( 'ok' => true ));
        $manifest = Jsonc::decode_object((string) file_get_contents($this->root . '/verify-corrupt/manifest.jsonc'));
        $active = $manifest['active']['file'] ?? '';
        $this->assertIsString($active);
        file_put_contents($this->root . '/verify-corrupt/segments/' . $active, "broken\n", FILE_APPEND);
        $this->assertFalse($corrupt->verify()['ok']);
    }

    public function test_queue_purge_stats_verify_and_repair(): void
    {
        $ids = $this->fixed_ids(4);
        $queue = new LogQueue($this->root, 'queue', $this->id_generator($ids));
        $queue->enqueue(array( 'task' => 'a' ));
        $queue->enqueue(array( 'task' => 'b' ));

        $first = $queue->claim();
        $this->assertNotNull($first);
        $queue->complete($first->id());
        $this->assertSame(0, $queue->purgeDone(1000));
        $this->assertSame(1, $queue->purgeDone());

        $second = $queue->claim();
        $this->assertNotNull($second);

        $this->assertSame(1, $queue->repair(0)['requeued']);
        $this->assertTrue($queue->verify()['ok']);
        $this->assertTrue($queue->health()['ok']);
        $this->assertSame(array( 'pending' => 1, 'processing' => 0, 'done' => 0 ), $queue->counts());
        $this->assertGreaterThan(0, $queue->stats()['bytes']);
        $recent = $queue->claim();
        $this->assertNotNull($recent);
        $queue->complete($recent->id());
        $this->assertSame(0, $queue->purgeDone(1000));

        file_put_contents($this->root . '/queue/queue.log', "broken\n", FILE_APPEND);
        $this->assertFalse($queue->verify()['ok']);
    }

    public function test_cache_factories_and_eviction(): void
    {
        $memory = new MemoryCache(1);
        $memory->set('a', 1);
        $memory->set('b', 2);

        $this->assertNull($memory->get('a'));
        $this->assertSame(2, $memory->get('b'));
        $memory->clear_prefix('b');
        $this->assertNull($memory->get('b'));
        $memory->set('expires', true, 1);
        sleep(2);
        $this->assertNull($memory->get('expires'));

        $ordered = new MemoryCache(2);
        $ordered->set('a', 1);
        $ordered->set('b', 2);
        $this->assertSame(1, $ordered->get('a'));
        $ordered->set('c', 3);
        $this->assertNull($ordered->get('b'));

        $bounded = new MemoryCache(10, 1);
        $bounded->set('large', str_repeat('x', 1024));
        $this->assertNull($bounded->get('large'));

        try {
            new MemoryCache(0);
            $this->fail('Expected memory cache size failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('cache', $exception->getMessage());
        }

        try {
            new MemoryCache(1, 0);
            $this->fail('Expected memory cache byte size failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('cache', $exception->getMessage());
        }

        $null = Cache::null();
        $null->set('x', true);
        $null->delete('x');
        $null->clear_prefix('x');
        $this->assertNull($null->get('x'));

        $apcu = Cache::apcu('storh-test');
        $apcu->set('x', 'y');
        $apcu->delete('x');
        $apcu->clear_prefix('x');
        $this->assertTrue(true);
    }

    public function test_smart_cache_validation_modes_keep_internal_writes_consistent(): void
    {
        $doc_ids = $this->fixed_ids(2);
        $docs = new DocPerFileStore(
            $this->root,
            'trusted-docs',
            $this->id_generator($doc_ids),
            Cache::memory(10),
            cache_validation: CacheValidation::TRUST
        );

        $docs->put(array( 'value' => 'cached' ));
        $this->assertSame('cached', $docs->get($doc_ids[0])?->data()['value'] ?? null);

        AtomicFilesystem::write_atomic(
            $docs->path_for_id($doc_ids[0]),
            Jsonc::encode_object(array( 'id' => $doc_ids[0], 'data' => array( 'value' => 'external' ) ))
        );
        $this->assertSame('cached', $docs->get($doc_ids[0])?->data()['value'] ?? null);

        $docs->put(array( 'value' => 'internal' ), $doc_ids[0]);
        $this->assertSame('internal', $docs->get($doc_ids[0])?->data()['value'] ?? null);

        $log_ids = $this->fixed_ids(3);
        $log = new SegmentedLogStore(
            $this->root,
            'trusted-log',
            4096,
            2,
            $this->id_generator($log_ids),
            Cache::memory(10),
            cache_validation: CacheValidation::TRUST
        );

        $log->appendMany(array(array( 'id' => $log_ids[0], 'data' => array( 'value' => 'first' ) )));
        $this->assertSame('first', $log->get($log_ids[0])?->data()['value'] ?? null);

        $log->appendMany(array(array( 'id' => $log_ids[0], 'data' => array( 'value' => 'second' ) )));
        $this->assertSame('second', $log->get($log_ids[0])?->data()['value'] ?? null);

        try {
            new DocPerFileStore($this->root, 'bad-cache-mode', cache_validation: 'never');
            $this->fail('Expected cache validation mode failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('cache validation', $exception->getMessage());
        }
    }

    public function test_hash_cache_detects_external_changes_after_cold_read(): void
    {
        $ids = $this->fixed_ids(1);
        $writer = new DocPerFileStore($this->root, 'hash-docs', $this->id_generator($ids));
        $writer->put(array( 'value' => 'initial' ));

        $cache = Cache::memory(10);
        $reader = new DocPerFileStore(
            $this->root,
            'hash-docs',
            cache: $cache,
            cache_validation: CacheValidation::HASH
        );
        $this->assertSame('initial', $reader->get($ids[0])?->data()['value'] ?? null);

        AtomicFilesystem::write_atomic(
            $reader->path_for_id($ids[0]),
            Jsonc::encode_object(array( 'id' => $ids[0], 'data' => array( 'value' => 'changed' ) ))
        );

        $this->assertSame('changed', $reader->get($ids[0])?->data()['value'] ?? null);
    }

    public function test_stat_cache_detects_external_changes_after_local_warm_read(): void
    {
        $ids = $this->fixed_ids(1);
        $writer = new DocPerFileStore($this->root, 'stat-docs', $this->id_generator($ids));
        $writer->put(array( 'value' => 'initial' ));

        $cache = Cache::memory(10);
        $reader = new DocPerFileStore(
            $this->root,
            'stat-docs',
            cache: $cache,
            cache_validation: CacheValidation::STAT
        );
        $this->assertSame('initial', $reader->get($ids[0])?->data()['value'] ?? null);

        $path_reader = new DocPerFileStore($this->root, 'stat-docs');
        AtomicFilesystem::write_atomic(
            $path_reader->path_for_id($ids[0]),
            Jsonc::encode_object(array( 'id' => $ids[0], 'data' => array( 'value' => 'changed-value' ) ))
        );

        $this->assertSame('changed-value', $reader->get($ids[0])?->data()['value'] ?? null);
    }

    public function test_cli_and_bench_scripts_run(): void
    {
        $store = new DocPerFileStore($this->root, 'cli', $this->id_generator($this->fixed_ids(2)));
        $store->put(array( 'kind' => 'page' ));

        $output = array();
        $code = 0;
        exec(PHP_BINARY . ' bin/storh stats ' . escapeshellarg($this->root) . ' cli doc', $output, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"records": 1', implode("\n", $output));

        $queue = new LogQueue($this->root, 'cli-queue', $this->id_generator($this->fixed_ids(2)));
        $queue->enqueue(array( 'task' => 'run' ));
        $output = array();
        exec(PHP_BINARY . ' bin/storh stats ' . escapeshellarg($this->root) . ' cli-queue queue', $output, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"pending": 1', implode("\n", $output));

        $benchOutput = $this->root . '-bench.json';
        $output = array();
        exec(PHP_BINARY . ' bench/bench.php --dataset=5 --engine=doc --output=' . escapeshellarg($benchOutput), $output, $code);
        $this->assertSame(0, $code);
        $this->assertFileExists($benchOutput);

        $output = array();
        exec(PHP_BINARY . ' bench/compare.php ' . escapeshellarg($benchOutput) . ' ' . escapeshellarg($benchOutput), $output, $code);
        $this->assertSame(0, $code);
        $this->assertNotSame(array(), $output);
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
}
