<?php

declare(strict_types=1);

use Storh\Cache;
use Storh\CacheValidation;
use Storh\DocPerFileStore;
use Storh\Queue;
use Storh\RecordQuery;
use Storh\SegmentedLogStore;
use Storh\UuidV7;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', array( 'dataset::', 'engine::', 'output::', 'cache-validation::' ));
$dataset = max(1, (int) ( $options['dataset'] ?? 1000 ));
$engine  = (string) ( $options['engine'] ?? 'all' );
$output  = (string) ( $options['output'] ?? dirname(__DIR__) . '/build/bench-current.json' );
$cache_validation = CacheValidation::assert_valid((string) ( $options['cache-validation'] ?? CacheValidation::HASH ));
$root    = sys_get_temp_dir() . '/storh-bench-' . getmypid() . '-' . bin2hex(random_bytes(4));

mkdir($root, 0777, true);
UuidV7::reset_for_tests();

try {
    $results = array(
        'dataset' => $dataset,
        'engine'  => $engine,
        'php'     => PHP_VERSION,
        'time'    => gmdate('c'),
        'cacheValidation' => $cache_validation,
        'results' => array(),
    );

    if ('all' === $engine || 'doc' === $engine) {
        $results['results']['doc'] = bench_doc($root, $dataset);
    }

    if ('all' === $engine || 'log' === $engine) {
        $results['results']['log'] = bench_log($root, $dataset);
    }

    if ('all' === $engine || 'queue' === $engine) {
        $results['results']['queue'] = bench_queue($root, $dataset);
    }

    if ('all' === $engine || 'recovery' === $engine) {
        $results['results']['recovery'] = bench_recovery($root, $dataset);
    }

    if ('all' === $engine || 'cache' === $engine) {
        $results['results']['cache'] = bench_cache($root, $dataset, $cache_validation);
    }

    if ('all' === $engine || 'uuid' === $engine) {
        $results['results']['uuid'] = bench_uuid($dataset);
    }

    if (! is_dir(dirname($output))) {
        mkdir(dirname($output), 0777, true);
    }
    file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    print_report($results, $output);
} finally {
    remove_path($root);
}

/**
 * @return array<string, float|int>
 */
function bench_doc(string $root, int $dataset): array
{
    $store = new DocPerFileStore($root, 'docs');
    $ids   = array();

    $put = timed(function () use ($store, $dataset, &$ids): void {
        for ($i = 0; $i < $dataset; $i++) {
            $ids[] = $store->put(row($i))->id();
        }
    });

    $get = timed(function () use ($store, $ids): void {
        foreach (array_slice($ids, 0, min(1000, count($ids))) as $id) {
            $store->get($id);
        }
    });

    $stream = timed(static function () use ($store): void {
        iterator_to_array($store->stream());
    });

    $delete = timed(function () use ($store, $ids): void {
        foreach (array_slice($ids, 0, min(100, count($ids))) as $id) {
            $store->delete($id);
        }
    });

    $index_build = timed(static function () use ($store): void {
        $store->indexes()->field('kind')->field('publishedAt')->range()->sync();
    });

    $indexed = timed(static function () use ($store): void {
        $store->query()->where('kind')->eq('page')->limit(100)->get();
    });

    $indexed_range = timed(static function () use ($store): void {
        $store->query()->where('publishedAt')->between(1_700_000_000_010, 1_700_000_000_200)->get();
    });

    $indexed_count = timed(static function () use ($store): void {
        $store->query()->where('kind')->eq('page')->count();
    });

    $indexed_range_count = timed(static function () use ($store): void {
        $store->query()->where('publishedAt')->between(1_700_000_000_010, 1_700_000_000_200)->count();
    });

    $full = timed(static function () use ($store): void {
        iterator_to_array($store->stream(RecordQuery::all()->where_equal('kind', 'page')->limit(100)));
    });

    unset($store, $ids);
    gc_collect_cycles();

    $bulk_store = new DocPerFileStore($root, 'docs-bulk');
    $bulk_put = timed(static function () use ($bulk_store, $dataset): void {
        $bulk_store->putStream(rows($dataset));
    });

    return compact(
        'put',
        'get',
        'stream',
        'delete',
        'index_build',
        'indexed',
        'indexed_range',
        'indexed_count',
        'indexed_range_count',
        'full',
        'bulk_put'
    );
}

/**
 * @return array<string, float>
 */
function bench_log(string $root, int $dataset): array
{
    $store = new SegmentedLogStore($root, 'log', 16384);
    $ids   = array();

    $append = timed(function () use ($store, $dataset, &$ids): void {
        for ($i = 0; $i < $dataset; $i++) {
            $ids[] = $store->put(row($i))->id();
        }
    });

    $cursor = timed(static function () use ($store, $ids): void {
        iterator_to_array($store->stream(RecordQuery::all()->after($ids[(int) floor(count($ids) / 2)])->limit(100)));
    });

    $range = timed(static function () use ($store): void {
        iterator_to_array($store->stream(RecordQuery::all()->time_range_ms(1_700_000_000_010, 1_700_000_000_200)));
    });

    $compact = timed(static function () use ($store): void {
        $store->compact();
    });

    unset($store, $ids);
    gc_collect_cycles();

    $bulk_store = new SegmentedLogStore($root, 'log-bulk', 16384);
    $bulk_start_ms = 1_700_000_000_000;
    $bulk_cursor_id = UuidV7::max_for_timestamp_ms($bulk_start_ms + (int) floor($dataset / 2));

    $bulk_append = timed(static function () use ($bulk_store, $dataset, $bulk_start_ms): void {
        $bulk_store->appendStream(rows_with_ids($dataset, $bulk_start_ms));
    });

    $bulk_cursor = timed(static function () use ($bulk_store, $bulk_cursor_id): void {
        iterator_to_array($bulk_store->stream(RecordQuery::all()->after($bulk_cursor_id)->limit(100)));
    });

    $bulk_range = timed(static function () use ($bulk_store): void {
        iterator_to_array($bulk_store->stream(RecordQuery::all()->time_range_ms(1_700_000_000_010, 1_700_000_000_200)));
    });

    $bulk_compact = timed(static function () use ($bulk_store): void {
        $bulk_store->compact();
    });

    return compact('append', 'cursor', 'range', 'compact', 'bulk_append', 'bulk_cursor', 'bulk_range', 'bulk_compact');
}

/**
 * @return array<string, float>
 */
function bench_queue(string $root, int $dataset): array
{
    $queue = new Queue($root, 'queue');
    $claimed = array();

    $enqueue = timed(static function () use ($queue, $dataset): void {
        for ($i = 0; $i < $dataset; $i++) {
            $queue->enqueue(row($i));
        }
    });

    $claim = timed(static function () use ($queue, $dataset, &$claimed): void {
        for ($i = 0; $i < $dataset; $i++) {
            $record = $queue->claim();
            if (null !== $record) {
                $claimed[] = $record->id();
            }
        }
    });

    $complete = timed(static function () use ($queue, $claimed): void {
        foreach ($claimed as $id) {
            $queue->complete($id);
        }
    });

    $requeue = timed(static function () use ($queue): void {
        $queue->requeue_timed_out(0);
    });

    unset($queue, $claimed);
    gc_collect_cycles();

    $bulk_queue = new Queue($root, 'queue-bulk');
    $bulk_claimed = array();

    $bulk_enqueue = timed(static function () use ($bulk_queue, $dataset): void {
        $bulk_queue->enqueueMany(rows($dataset));
    });

    $bulk_claim = timed(static function () use ($bulk_queue, $dataset, &$bulk_claimed): void {
        foreach ($bulk_queue->claimMany($dataset) as $record) {
            $bulk_claimed[] = $record->id();
        }
    });

    $bulk_complete = timed(static function () use ($bulk_queue, $bulk_claimed): void {
        $bulk_queue->completeMany($bulk_claimed);
    });

    return compact('enqueue', 'claim', 'complete', 'requeue', 'bulk_enqueue', 'bulk_claim', 'bulk_complete');
}

/**
 * @return array<string, float>
 */
function bench_recovery(string $root, int $dataset): array
{
    $store = new SegmentedLogStore($root, 'recover', 16384);
    for ($i = 0; $i < $dataset; $i++) {
        $store->put(row($i));
    }

    $manifest = Storh\AtomicFilesystem::read_jsonc_object($root . '/recover/manifest.jsonc');
    $active   = $manifest['active']['file'] ?? '';
    if (is_string($active) && '' !== $active) {
        file_put_contents($root . '/recover/segments/' . $active, "broken\n", FILE_APPEND);
    }

    $recover = timed(static function () use ($root): void {
        new SegmentedLogStore($root, 'recover', 16384);
    });

    return compact('recover');
}

/**
 * @return array<string, float>
 */
function bench_cache(string $root, int $dataset, string $cache_validation): array
{
    $cache = Cache::memory($dataset + 10);
    $writer = new DocPerFileStore($root, 'cache', cache: $cache, cache_validation: $cache_validation);
    $ids   = array();
    for ($i = 0; $i < $dataset; $i++) {
        $ids[] = $writer->put(row($i))->id();
    }

    $store = new DocPerFileStore($root, 'cache', cache: $cache, cache_validation: $cache_validation);
    $cache->clear_prefix('doc:cache:');
    $cold = timed(static function () use ($store, $ids): void {
        foreach ($ids as $id) {
            $store->get($id);
        }
    });

    $warm = timed(static function () use ($store, $ids): void {
        foreach ($ids as $id) {
            $store->get($id);
        }
    });

    return compact('cold', 'warm');
}

/**
 * @return array<string, float>
 */
function bench_uuid(int $dataset): array
{
    UuidV7::reset_for_tests();
    $monotonic = timed(static function () use ($dataset): void {
        for ($i = 0; $i < $dataset; $i++) {
            UuidV7::generate(1_700_000_000_000);
        }
    });

    UuidV7::reset_for_tests();
    $spread = timed(static function () use ($dataset): void {
        for ($i = 0; $i < $dataset; $i++) {
            UuidV7::generate(1_700_000_000_000 + $i);
        }
    });

    return compact('monotonic', 'spread');
}

/**
 * @return array<string, mixed>
 */
function row(int $i): array
{
    return array(
        'kind'        => 0 === $i % 2 ? 'page' : 'post',
        'status'      => 0 === $i % 3 ? 'draft' : 'published',
        'slug'        => 'item-' . $i,
        'publishedAt' => 1_700_000_000_000 + $i,
    );
}

/**
 * @return Generator<int, array<string, mixed>>
 */
function rows(int $dataset): Generator
{
    for ($i = 0; $i < $dataset; $i++) {
        yield row($i);
    }
}

/**
 * @return Generator<int, array{id: string, data: array<string, mixed>}>
 */
function rows_with_ids(int $dataset, int $startTimestampMs): Generator
{
    for ($i = 0; $i < $dataset; $i++) {
        yield array(
            'id'   => UuidV7::generate($startTimestampMs + $i),
            'data' => row($i),
        );
    }
}

function timed(callable $callback): float
{
    $start = hrtime(true);
    $callback();

    return ( hrtime(true) - $start ) / 1_000_000_000;
}

/**
 * @param array<string, mixed> $results
 */
function print_report(array $results, string $output): void
{
    echo 'storh bench dataset=' . $results['dataset'] . ' output=' . $output . PHP_EOL;
    foreach ($results['results'] as $engine => $items) {
        echo '[' . $engine . ']' . PHP_EOL;
        foreach ($items as $name => $seconds) {
            printf("  %-12s %.6fs\n", $name, $seconds);
        }
    }
}

function remove_path(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }

    @rmdir($path);
}
