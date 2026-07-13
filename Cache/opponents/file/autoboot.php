<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — File driver
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'File',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/bootgly.php',
));
