<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — FrankenPHP Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'FrankenPHP',
   version: function () {
      $output = exec('frankenphp --version 2>/dev/null') ?: '';
      preg_match('/v[0-9]+\.[0-9]+\.[0-9]+/', $output, $m);
      return $m[0] ?? 'unknown';
   },
   script: __DIR__ . '/frankenphp.php',
));
