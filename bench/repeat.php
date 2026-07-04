<?php

declare(strict_types=1);

require __DIR__ . '/process.php';

$options = getopt('', array( 'dataset::', 'engine::', 'output::', 'cache-validation::', 'repeat::', 'memory-limit::' ));
$dataset = max(1, (int) ( $options['dataset'] ?? 1000 ));
$engine  = (string) ( $options['engine'] ?? 'all' );
$output  = (string) ( $options['output'] ?? dirname(__DIR__) . '/build/bench-repeat-current.json' );
$cache_validation = (string) ( $options['cache-validation'] ?? 'stat' );
$repeat = max(1, (int) ( $options['repeat'] ?? 5 ));
$memory_limit = isset($options['memory-limit']) ? (string) $options['memory-limit'] : null;

$series = array();
$bench = __DIR__ . '/bench.php';

for ($index = 0; $index < $repeat; $index++) {
    $temp = sys_get_temp_dir() . '/storh-repeat-' . getmypid() . '-' . $index . '.json';
    $command = array(PHP_BINARY);
    if (null !== $memory_limit && '' !== $memory_limit) {
        $command[] = '-d';
        $command[] = 'memory_limit=' . $memory_limit;
    }

    $command[] = $bench;
    $command[] = '--dataset=' . $dataset;
    $command[] = '--engine=' . $engine;
    $command[] = '--cache-validation=' . $cache_validation;
    $command[] = '--output=' . $temp;

    $result = storh_bench_run_capture($command);
    if (0 !== $result['code']) {
        throw new RuntimeException('Benchmark run failed: ' . implode("\n", $result['output']));
    }

    $run = read_bench($temp);
    @unlink($temp);

    foreach ($run['results'] as $engine_name => $items) {
        foreach ($items as $metric => $seconds) {
            if (is_int($seconds) || is_float($seconds)) {
                $series[ $engine_name ][ $metric ][] = (float) $seconds;
            }
        }
    }
}

$results = array();
$summary = array();
foreach ($series as $engine_name => $items) {
    foreach ($items as $metric => $values) {
        $stats = stats($values);
        $results[ $engine_name ][ $metric ] = $stats['median'];
        $summary[ $engine_name ][ $metric ] = $stats;
    }
}

$report = array(
    'dataset' => $dataset,
    'engine' => $engine,
    'php' => PHP_VERSION,
    'time' => gmdate('c'),
    'cacheValidation' => $cache_validation,
    'repeat' => $repeat,
    'memoryLimit' => $memory_limit,
    'results' => $results,
    'summary' => $summary,
);

if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0777, true);
}

file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
print_report($report, $output);

/**
 * @return array<string, mixed>
 */
function read_bench(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid benchmark JSON: ' . $path);
    }

    return $decoded;
}

/**
 * @param list<float> $values
 * @return array{median: float, min: float, max: float, mean: float, runs: int}
 */
function stats(array $values): array
{
    if (array() === $values) {
        throw new RuntimeException('Cannot summarize an empty benchmark series.');
    }

    sort($values, SORT_NUMERIC);
    $runs = count($values);
    $middle = intdiv($runs, 2);
    $median = 1 === ( $runs & 1 )
        ? $values[ $middle ]
        : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;

    return array(
        'median' => $median,
        'min' => $values[0],
        'max' => $values[ $runs - 1 ],
        'mean' => array_sum($values) / $runs,
        'runs' => $runs,
    );
}

/**
 * @param array<string, mixed> $report
 */
function print_report(array $report, string $output): void
{
    echo 'storh bench repeat=' . $report['repeat']
        . ' dataset=' . $report['dataset']
        . ( null !== $report['memoryLimit'] ? ' memory_limit=' . $report['memoryLimit'] : '' )
        . ' output=' . $output
        . PHP_EOL;

    foreach ($report['summary'] as $engine_name => $items) {
        echo '[' . $engine_name . ']' . PHP_EOL;
        foreach ($items as $metric => $stats) {
            printf(
                "  %-28s median %.6fs min %.6fs max %.6fs mean %.6fs\n",
                $metric,
                $stats['median'],
                $stats['min'],
                $stats['max'],
                $stats['mean']
            );
        }
    }
}
