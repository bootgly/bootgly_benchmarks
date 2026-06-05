<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — TCP_Server_CLI
 * --------------------------------------------------------------------------
 * Raw TCP server benchmark — measures framework overhead without HTTP parsing.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Runner;


// @ Select runner
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'tcp_raw');

$runnerFile = match ($runnerType) {
   'tcp_raw', 'tcp_client' => 'TCP_Raw',
   default                 => ucfirst($runnerType),
};
$runnerPath = __DIR__ . "/../runners/{$runnerFile}.php";

/** @var Runner|false $Runner */
$Runner = is_file($runnerPath) ? include $runnerPath : false;
if ($Runner instanceof Runner === false) {
   return false;
}

// @ Configure
$Runner->port = 8083;
$Runner->connections = 514;
$Runner->duration = 10;

// @ Load PHP loads
$Runner->load(__DIR__ . '/loads');

// @ Auto-register competitors — each folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
