<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — Redis driver (blocking)
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Redis',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/bootgly.php',
));
