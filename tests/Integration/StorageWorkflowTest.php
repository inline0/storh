<?php

declare(strict_types=1);

namespace Storh\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Storh\DocPerFileStore;
use Storh\LogQueue;
use Storh\RecordQuery;
use Storh\Schema;
use Storh\SegmentedLogStore;
use Storh\StorageException;
use Storh\StorageRoot;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class StorageWorkflowTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-integration-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_document_pipeline_survives_reopen_with_schema_indexes_and_queries(): void
    {
        $root = StorageRoot::resolve($this->root, 'app');
        $schema = Schema::collection('pages')
            ->string('slug')->unique()
            ->string('kind')->index()
            ->int('publishedAt')->range()
            ->required(array( 'slug', 'kind' ));

        $writer = new DocPerFileStore($root, 'pages', schema: $schema);
        $ids = array();
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $writer->put(
                array(
                    'slug'        => 'page-' . $i,
                    'kind'        => 0 === $i % 2 ? 'page' : 'post',
                    'publishedAt' => 1_700_000_000_000 + $i,
                )
            )->id();
        }
        unset($writer);

        $reader = new DocPerFileStore($root, 'pages', schema: $schema);

        $record = $reader->get($ids[3]);
        $this->assertNotNull($record);
        $this->assertSame('page-3', $record->data()['slug']);

        $this->assertSame(10, $reader->query()->where('kind')->eq('page')->count());
        $this->assertSame(
            array( 'page-8' ),
            array_column(
                array_map(
                    static fn($found) => $found->data(),
                    $reader->query()->where('slug')->eq('page-8')->get()
                ),
                'slug'
            )
        );

        $window = $reader
            ->query()
            ->where('publishedAt')->between(1_700_000_000_005, 1_700_000_000_009)
            ->orderBy('publishedAt', 'desc')
            ->get();
        $this->assertSame(
            array( 1_700_000_000_009, 1_700_000_000_008, 1_700_000_000_007, 1_700_000_000_006, 1_700_000_000_005 ),
            array_column(array_map(static fn($found) => $found->data(), $window), 'publishedAt')
        );

        try {
            $reader->put(
                array(
                    'slug'        => 'page-4',
                    'kind'        => 'page',
                    'publishedAt' => 1_700_000_000_099,
                )
            );
            $this->fail('Expected a unique index violation across store instances.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Unique index violation', $exception->getMessage());
        }

        $reindexed = $reader->reindex();
        $this->assertSame(20, $reindexed['entries']);

        $health = $reader->verify();
        $this->assertTrue($health['ok'], implode(' | ', $health['errors']));
        $this->assertSame(20, $health['stats']['records']);
    }

    public function test_segmented_log_compaction_and_time_range_reads_survive_reopen(): void
    {
        $writer = new SegmentedLogStore($this->root, 'events', 2048);
        $ids = array();
        $records = array();
        for ($i = 0; $i < 30; $i++) {
            $id = UuidV7::generate(1_700_000_000_000 + $i);
            $ids[] = $id;
            $records[] = array(
                'id'   => $id,
                'data' => array( 'sequence' => $i, 'type' => 'page.saved' ),
            );
        }
        $writer->appendMany($records);

        for ($i = 0; $i < 10; $i++) {
            $writer->delete($ids[ $i ]);
        }
        $writer->compact();
        unset($writer);

        $reader = new SegmentedLogStore($this->root, 'events', 2048);

        $live = iterator_to_array($reader->stream(), false);
        $this->assertCount(20, $live);
        $this->assertSame($ids[10], $live[0]->id());

        $window = iterator_to_array(
            $reader->stream(RecordQuery::all()->time_range_ms(1_700_000_000_005, 1_700_000_000_014)),
            false
        );
        $this->assertSame(
            array( 10, 11, 12, 13, 14 ),
            array_column(array_map(static fn($record) => $record->data(), $window), 'sequence')
        );

        $this->assertSame(20, $reader->query()->count());

        $health = $reader->verify();
        $this->assertTrue($health['ok'], implode(' | ', $health['errors']));
        $this->assertSame(20, $health['stats']['records']);
    }

    public function test_queue_hands_jobs_between_producer_and_worker_instances(): void
    {
        $producer = new LogQueue($this->root, 'jobs');
        $ids = $producer->enqueueMany(
            array_map(
                static fn(int $i): array => array( 'task' => 'render', 'page' => $i ),
                range(0, 9)
            )
        );
        $this->assertCount(10, $ids);

        $worker = new LogQueue($this->root, 'jobs');
        $claimed = $worker->claimMany(6);
        $this->assertSame(array_slice($ids, 0, 6), array_map(static fn($job) => $job->id(), $claimed));
        $this->assertSame('render', $claimed[0]->data()['task']);

        $completed = $worker->completeMany(array_map(static fn($job) => $job->id(), array_slice($claimed, 0, 4)));
        $this->assertSame(4, $completed);

        $counts = $producer->counts();
        $this->assertSame(array( 'pending' => 4, 'processing' => 2, 'done' => 4 ), $counts);

        $janitor = new LogQueue($this->root, 'jobs');
        $this->assertSame(2, $janitor->requeue_timed_out(0));
        $this->assertSame(6, $producer->counts()['pending']);

        foreach (array( $producer, $worker, $janitor ) as $queue) {
            $health = $queue->verify();
            $this->assertTrue($health['ok'], implode(' | ', $health['errors']));
        }
    }

    public function test_docs_export_import_and_downstream_log_and_queue_flow(): void
    {
        $root = StorageRoot::resolve($this->root, 'app');
        $source = new DocPerFileStore($root, 'drafts');
        for ($i = 0; $i < 8; $i++) {
            $source->put(array( 'slug' => 'draft-' . $i, 'bucket' => $i % 2 ));
        }

        $jsonl = $this->root . '/drafts.jsonl';
        $this->assertSame(8, $source->exportJsonl($jsonl));

        $published = new DocPerFileStore($root, 'published');
        $this->assertSame(8, $published->importJsonl($jsonl));

        $log = new SegmentedLogStore($root, 'activity');
        $queue = new LogQueue($root, 'render');
        foreach ($published->stream() as $record) {
            $log->put(array( 'type' => 'doc.published', 'docId' => $record->id() ));
            $queue->enqueue(array( 'task' => 'render', 'docId' => $record->id() ));
        }

        $this->assertSame(8, $log->query()->count());

        $rendered = 0;
        while (null !== ( $job = $queue->claim() )) {
            $docId = $job->data()['docId'];
            $this->assertIsString($docId);
            $this->assertNotNull($published->get($docId));
            $queue->complete($job->id());
            $rendered++;
        }

        $this->assertSame(8, $rendered);
        $this->assertSame(array( 'pending' => 0, 'processing' => 0, 'done' => 8 ), $queue->counts());
    }

    public function test_cli_reports_stats_and_verifies_stores(): void
    {
        if (! function_exists('exec')) {
            $this->markTestSkipped('exec() is unavailable.');
        }

        $docs = new DocPerFileStore($this->root, 'pages');
        for ($i = 0; $i < 3; $i++) {
            $docs->put(array( 'slug' => 'page-' . $i ));
        }

        $log = new SegmentedLogStore($this->root, 'events');
        $log->put(array( 'type' => 'page.saved' ));
        unset($docs, $log);

        $stats = $this->run_cli(array( 'stats', $this->root, 'pages', 'doc' ));
        $this->assertSame(3, $stats['records']);
        $this->assertSame(0, $stats['corrupt']);

        $verified = $this->run_cli(array( 'verify', $this->root, 'events', 'log' ));
        $this->assertTrue($verified['ok']);
        $this->assertSame(1, $verified['stats']['records']);
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function run_cli(array $arguments): array
    {
        $command = array_merge(
            array( PHP_BINARY, dirname(__DIR__, 2) . '/bin/storh' ),
            $arguments
        );
        $escaped = implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1';

        exec($escaped, $output, $status);
        $json = implode("\n", $output);
        $this->assertSame(0, $status, $json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, $json);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
