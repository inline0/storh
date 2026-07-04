<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$baseline = $root . '/bench/baselines/ci-1k-all.json';
$current = $root . '/build/bench-ci-1k-all.json';
$threshold = getenv('STORH_BENCH_CI_THRESHOLD');
$threshold = false === $threshold || '' === $threshold ? '3000' : $threshold;
$metrics = array(
    'doc.put',
    'doc.index_build',
    'doc.bulk_put',
    'doc.jsonl_import',
    'log.append',
    'log.compact',
    'log.bulk_append',
    'queue.enqueue',
    'queue.claim',
    'queue.complete',
    'queue.bulk_enqueue',
    'recovery.recover',
    'cache.cold',
    'cache.warm',
    'uuid.monotonic',
);

if (! is_dir(dirname($current)) && ! mkdir(dirname($current), 0777, true) && ! is_dir(dirname($current))) {
    fwrite(STDERR, 'Could not create benchmark output directory.' . PHP_EOL);
    exit(1);
}

run_command(array(
    PHP_BINARY,
    $root . '/bench/bench.php',
    '--dataset=1000',
    '--engine=all',
    '--output=' . $current,
));

$gate = array(
    PHP_BINARY,
    $root . '/bench/gate.php',
    $baseline,
    $current,
    '--threshold=' . $threshold,
);
foreach ($metrics as $metric) {
    $gate[] = '--metric=' . $metric;
}

run_command($gate);

/**
 * @param list<string> $command
 */
function run_command(array $command): void
{
    $escaped = array_map('escapeshellarg', $command);
    passthru(implode(' ', $escaped), $status);
    if (0 !== $status) {
        exit($status);
    }
}
