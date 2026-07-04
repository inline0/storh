<?php

declare(strict_types=1);

$parsed = parse_arguments($argv);
if (null === $parsed) {
    fwrite(
        STDERR,
        "Usage: php bench/gate.php <base.json> <current.json> [--threshold=10] [--metric=engine.name ...]\n"
    );
    exit(1);
}

[$base_path, $current_path, $threshold, $tracked_metrics] = $parsed;
$base = flatten_results(read_bench($base_path));
$current = flatten_results(read_bench($current_path));
$metrics = array() === $tracked_metrics ? array_keys($base) : $tracked_metrics;
$failed = false;

foreach ($metrics as $metric) {
    $before = $base[ $metric ] ?? null;
    $after = $current[ $metric ] ?? null;

    if (null === $before) {
        printf("%-36s missing in baseline\n", $metric);
        $failed = true;
        continue;
    }

    if (null === $after) {
        printf("%-36s missing in current\n", $metric);
        $failed = true;
        continue;
    }

    $delta = 0.0 === $before ? 0.0 : ( ( $after - $before ) / $before ) * 100;
    $regressed = $delta > $threshold;
    printf(
        "%-36s base %.6fs current %.6fs %+0.2f%%%s\n",
        $metric,
        $before,
        $after,
        $delta,
        $regressed ? ' FAIL' : ''
    );

    if ($regressed) {
        $failed = true;
    }
}

if ($failed) {
    fwrite(STDERR, sprintf("Benchmark regression gate failed at threshold %+0.2f%%.\n", $threshold));
    exit(1);
}

printf("Benchmark regression gate passed at threshold %+0.2f%%.\n", $threshold);

/**
 * @param list<string> $argv
 * @return array{string, string, float, list<string>}|null
 */
function parse_arguments(array $argv): ?array
{
    array_shift($argv);

    $paths = array();
    $metrics = array();
    $threshold = 10.0;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--threshold=')) {
            $threshold = (float) substr($argument, strlen('--threshold='));
            continue;
        }

        if (str_starts_with($argument, '--metric=')) {
            $metrics[] = substr($argument, strlen('--metric='));
            continue;
        }

        $paths[] = $argument;
    }

    if (2 !== count($paths) || $threshold < 0.0) {
        return null;
    }

    return array($paths[0], $paths[1], $threshold, $metrics);
}

/**
 * @return array<string, mixed>
 */
function read_bench(string $path): array
{
    $contents = @file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException('Could not read benchmark JSON: ' . $path);
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid benchmark JSON: ' . $path);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $bench
 * @return array<string, float>
 */
function flatten_results(array $bench): array
{
    $results = $bench['results'] ?? null;
    if (! is_array($results)) {
        throw new RuntimeException('Benchmark JSON must contain a results object.');
    }

    $flat = array();
    foreach ($results as $engine => $metrics) {
        if (! is_string($engine) || ! is_array($metrics)) {
            continue;
        }

        foreach ($metrics as $name => $seconds) {
            if (! is_string($name) || ! is_int($seconds) && ! is_float($seconds)) {
                continue;
            }

            $flat[ $engine . '.' . $name ] = (float) $seconds;
        }
    }

    ksort($flat);

    return $flat;
}
