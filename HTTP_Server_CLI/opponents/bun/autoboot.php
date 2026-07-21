<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Bun (Bun.serve) Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Bun',
   version: function () {
      $bun = exec('bun --version 2>/dev/null') ?: 'unknown';

      return "v{$bun} (Bun.serve)";
   },
   script: __DIR__ . '/bun.php',
));
