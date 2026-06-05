<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Server_CLI
 * --------------------------------------------------------------------------
 * Raw UDP server benchmark — measures framework overhead without HTTP or
 * TCP framing. Each datagram is echoed back verbatim.
 * --------------------------------------------------------------------------
 */

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

// @ Load PHP loads
$Runner->load(__DIR__ . '/loads');

// @ Auto-register competitors — each folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
