<?php

declare(strict_types=1);

use Storh\CacheValidation;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', array( 'datasets::', 'engines::', 'output-dir::', 'cache-validations::', 'memory-limit::' ));

$datasets = parse_int_list((string) ( $options['datasets'] ?? '1000,10000,50000,100000' ));
$engines  = parse_string_list((string) ( $options['engines'] ?? 'doc,log,queue,recovery,cache,filter' ));
$cache_validations = parse_string_list((string) ( $options['cache-validations'] ?? 'hash,stat,trust' ));
$output_dir = (string) ( $options['output-dir'] ?? dirname(__DIR__) . '/build/bench-ranges' );
$memory_limit = isset($options['memory-limit']) ? (string) $options['memory-limit'] : null;

if (in_array('all', $engines, true)) {
    $engines = array( 'doc', 'log', 'queue', 'recovery', 'cache', 'filter' );
}

foreach ($cache_validations as $mode) {
    CacheValidation::assert_valid($mode);
}

if (! is_dir($output_dir)) {
    mkdir($output_dir, 0777, true);
}

foreach ($datasets as $dataset) {
    foreach ($engines as $engine) {
        if ('cache' === $engine) {
            foreach ($cache_validations as $mode) {
                run_bench($dataset, $engine, $output_dir, $mode, $memory_limit);
            }
            continue;
        }

        run_bench($dataset, $engine, $output_dir, CacheValidation::STAT, $memory_limit);
    }
}

/**
 * @return list<int>
 */
function parse_int_list(string $value): array
{
    $items = array();
    foreach (explode(',', $value) as $item) {
        $number = max(1, (int) trim($item));
        if (! in_array($number, $items, true)) {
            $items[] = $number;
        }
    }

    sort($items);

    return $items;
}

/**
 * @return list<string>
 */
function parse_string_list(string $value): array
{
    $items = array();
    foreach (explode(',', $value) as $item) {
        $item = trim($item);
        if ('' !== $item && ! in_array($item, $items, true)) {
            $items[] = $item;
        }
    }

    return $items;
}

function run_bench(int $dataset, string $engine, string $output_dir, string $cache_validation, ?string $memory_limit): void
{
    $suffix = 'cache' === $engine ? '-' . $cache_validation : '';
    $output = rtrim($output_dir, '/\\') . '/bench-' . $dataset . '-' . $engine . $suffix . '.json';
    $command = array(PHP_BINARY);
    if (null !== $memory_limit && '' !== $memory_limit) {
        $command[] = '-d';
        $command[] = 'memory_limit=' . $memory_limit;
    }

    $command[] = __DIR__ . '/bench.php';
    $command[] = '--dataset=' . $dataset;
    $command[] = '--engine=' . $engine;
    $command[] = '--output=' . $output;
    $command[] = '--cache-validation=' . $cache_validation;

    echo '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL;
    passthru(implode(' ', array_map('escapeshellarg', $command)), $code);

    if (0 !== $code) {
        throw new RuntimeException('Benchmark failed for ' . $engine . ' dataset ' . $dataset);
    }
}
