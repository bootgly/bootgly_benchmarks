<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — Shared driver
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Shared',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/bootgly.php',
));
