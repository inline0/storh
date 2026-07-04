<?php

declare(strict_types=1);

/** @var resource|null $storh_bench_child_process */
$storh_bench_child_process = null;

register_shutdown_function('storh_bench_terminate_child_process');

/**
 * @param list<string> $command
 */
function storh_bench_command_line(array $command): string
{
    return implode(' ', array_map('escapeshellarg', $command));
}

/**
 * @param list<string> $command
 * @return array{code: int, output: list<string>}
 */
function storh_bench_run_capture(array $command): array
{
    storh_bench_install_signal_handlers();

    $pipes = array();
    $process = proc_open(
        $command,
        array(
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        ),
        $pipes
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start benchmark process: ' . storh_bench_command_line($command));
    }

    storh_bench_set_child_process($process);
    $closed = false;

    try {
        $output = storh_bench_read_process_output($process, $pipes);
        $code = proc_close($process);
        $closed = true;

        return array(
            'code'   => $code,
            'output' => preg_split('/\r?\n/', trim($output), -1, PREG_SPLIT_NO_EMPTY) ?: array(),
        );
    } finally {
        if (! $closed) {
            storh_bench_terminate_child_process();
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @proc_close($process);
        }

        storh_bench_set_child_process(null);
    }
}

/**
 * @param list<string> $command
 */
function storh_bench_run_passthrough(array $command): int
{
    storh_bench_install_signal_handlers();

    $process = proc_open(
        $command,
        array(
            0 => array( 'file', 'php://stdin', 'r' ),
            1 => array( 'file', 'php://stdout', 'w' ),
            2 => array( 'file', 'php://stderr', 'w' ),
        ),
        $pipes
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start benchmark process: ' . storh_bench_command_line($command));
    }

    storh_bench_set_child_process($process);
    $closed = false;

    try {
        $code = proc_close($process);
        $closed = true;

        return $code;
    } finally {
        if (! $closed) {
            storh_bench_terminate_child_process();
            @proc_close($process);
        }

        storh_bench_set_child_process(null);
    }
}

function storh_bench_install_signal_handlers(): void
{
    static $installed = false;

    if ($installed || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
        return;
    }

    if (! defined('SIGINT') || ! defined('SIGTERM')) {
        return;
    }

    $installed = true;
    pcntl_async_signals(true);
    foreach (array( SIGINT, SIGTERM ) as $signal) {
        pcntl_signal(
            $signal,
            static function (int $received): void {
                storh_bench_terminate_child_process();
                exit(128 + $received);
            }
        );
    }
}

/**
 * @param resource|null $process
 */
function storh_bench_set_child_process(mixed $process): void
{
    global $storh_bench_child_process;

    $storh_bench_child_process = $process;
}

function storh_bench_terminate_child_process(): void
{
    global $storh_bench_child_process;

    if (! is_resource($storh_bench_child_process)) {
        return;
    }

    $status = @proc_get_status($storh_bench_child_process);
    if (! is_array($status) || empty($status['running'])) {
        return;
    }

    @proc_terminate($storh_bench_child_process);
    usleep(100000);

    $status = @proc_get_status($storh_bench_child_process);
    if (is_array($status) && ! empty($status['running'])) {
        @proc_terminate($storh_bench_child_process, 9);
    }
}

/**
 * @param resource $process
 * @param array<int, resource> $pipes
 */
function storh_bench_read_process_output(mixed $process, array $pipes): string
{
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
    while (true) {
        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if (false !== $chunk && '' !== $chunk) {
                $output .= $chunk;
            }
        }

        $status = proc_get_status($process);
        if (! is_array($status) || empty($status['running'])) {
            break;
        }

        usleep(10000);
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if (false !== $chunk && '' !== $chunk) {
            $output .= $chunk;
        }
        fclose($pipe);
    }

    return $output;
}
