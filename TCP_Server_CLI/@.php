<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — TCP_Server_CLI
 * --------------------------------------------------------------------------
 * Raw TCP server benchmark — measures framework overhead without HTTP parsing.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


// @ Select runner
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'tcp_raw');

$runnerFile = match ($runnerType) {
   'tcp_raw' => 'TCP_Raw',
   default   => ucfirst($runnerType),
};
$Runner = include __DIR__ . "/../runners/{$runnerFile}.php";

// @ Configure
$Runner->port = 8083;
$Runner->connections = 514;
$Runner->duration = 10;

// @ Load PHP scenarios
$Runner->load(__DIR__ . '/scenarios/php');

$httpArtifactDir = __DIR__ . '/../HTTP_Server_CLI/artifacts';

// @ Add competitors
$Runner->add(new Competitor(
   name: 'Bootgly',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/competitors/bootgly.php',
));

return $Runner;
