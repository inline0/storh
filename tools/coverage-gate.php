<?php

declare(strict_types=1);

$options = parse_arguments($argv);
if (null === $options) {
    fwrite(
        STDERR,
        "Usage: php tools/coverage-gate.php <clover.xml> [--min-lines=85] [--min-methods=70] [--min-classes=50]\n"
    );
    exit(1);
}

[$path, $minimums] = $options;
$xml = @simplexml_load_file($path);
if (false === $xml) {
    fwrite(STDERR, 'Could not read Clover coverage XML: ' . $path . PHP_EOL);
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if (null === $metrics) {
    fwrite(STDERR, 'Clover coverage XML does not contain project metrics.' . PHP_EOL);
    exit(1);
}

$checks = array(
    'methods' => coverage_percent($metrics, 'coveredmethods', 'methods'),
    'lines'   => coverage_percent($metrics, 'coveredstatements', 'statements'),
);
if (isset($minimums['classes'])) {
    $checks['classes'] = coverage_percent($metrics, 'coveredclasses', 'classes');
}

$failed = false;
foreach ($minimums as $name => $minimum) {
    $actual = $checks[ $name ] ?? null;
    if (null === $actual) {
        printf("%-8s coverage unavailable FAIL\n", $name);
        $failed = true;
        continue;
    }

    $regressed = $actual < $minimum;
    printf("%-8s coverage %6.2f%% minimum %6.2f%%%s\n", $name, $actual, $minimum, $regressed ? ' FAIL' : '');
    if ($regressed) {
        $failed = true;
    }
}

if ($failed) {
    fwrite(STDERR, 'Coverage gate failed.' . PHP_EOL);
    exit(1);
}

echo 'Coverage gate passed.' . PHP_EOL;

/**
 * @param list<string> $argv
 * @return array{string, array<string, float>}|null
 */
function parse_arguments(array $argv): ?array
{
    array_shift($argv);

    $path = null;
    $minimums = array(
        'methods' => 70.0,
        'lines'   => 85.0,
    );

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--min-classes=')) {
            $minimums['classes'] = (float) substr($argument, strlen('--min-classes='));
            continue;
        }

        if (str_starts_with($argument, '--min-methods=')) {
            $minimums['methods'] = (float) substr($argument, strlen('--min-methods='));
            continue;
        }

        if (str_starts_with($argument, '--min-lines=')) {
            $minimums['lines'] = (float) substr($argument, strlen('--min-lines='));
            continue;
        }

        if (null !== $path) {
            return null;
        }

        $path = $argument;
    }

    foreach ($minimums as $minimum) {
        if ($minimum < 0.0 || $minimum > 100.0) {
            return null;
        }
    }

    return null === $path ? null : array($path, $minimums);
}

function coverage_percent(\SimpleXMLElement $metrics, string $covered_attribute, string $total_attribute): ?float
{
    $attributes = $metrics->attributes();
    $covered = isset($attributes[ $covered_attribute ]) ? (int) $attributes[ $covered_attribute ] : null;
    $total = isset($attributes[ $total_attribute ]) ? (int) $attributes[ $total_attribute ] : null;

    if (null === $covered || null === $total || 0 === $total) {
        return null;
    }

    return ( $covered / $total ) * 100;
}
