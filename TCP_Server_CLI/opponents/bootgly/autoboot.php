<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — TCP_Server_CLI — Bootgly Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Bootgly',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/bootgly.php',
));