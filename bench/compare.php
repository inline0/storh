<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bench/compare.php <base.json> <current.json>\n");
    exit(1);
}

$base    = read_bench($argv[1]);
$current = read_bench($argv[2]);

foreach ($current['results'] as $engine => $items) {
    echo '[' . $engine . ']' . PHP_EOL;
    foreach ($items as $name => $seconds) {
        $before = $base['results'][ $engine ][ $name ] ?? null;
        if (! is_int($before) && ! is_float($before)) {
            printf("  %-12s %.6fs new\n", $name, $seconds);
            continue;
        }

        $delta = 0.0 === (float) $before ? 0.0 : ( ( (float) $seconds - (float) $before ) / (float) $before ) * 100;
        printf("  %-12s %.6fs %+0.2f%%\n", $name, $seconds, $delta);
    }
}

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
