<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Server_CLI
 * --------------------------------------------------------------------------
 * Raw UDP server benchmark — measures framework overhead without HTTP or
 * TCP framing. Each datagram is echoed back verbatim.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


// @ Select runner
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'udp_raw');

$runnerFile = match ($runnerType) {
   'udp_raw' => 'UDP_Raw',
   default   => ucfirst($runnerType),
};
$Runner = include __DIR__ . "/../runners/{$runnerFile}.php";

// @ Configure
$Runner->port = 8084;
$Runner->connections = 514;
$Runner->duration = 10;

// @ Load PHP scenarios
$Runner->load(__DIR__ . '/scenarios/php');

// @ Add competitors
$Runner->add(new Competitor(
   name: 'Bootgly',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/competitors/bootgly.php',
));

return $Runner;
