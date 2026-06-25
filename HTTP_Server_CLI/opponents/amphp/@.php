<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — AMPHP Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$bootablesDir = dirname(__DIR__, 2) . '/bootables';

$Runner->add(new Opponent(
   name: 'AMPHP',
   version: function () use ($bootablesDir) {
      $lock = @file_get_contents("{$bootablesDir}/amphp/composer.lock");
      if ($lock && preg_match('/"name":\s*"amphp\/http-server".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return 'v' . ltrim($m[1], 'v');
      }
      return 'unknown';
   },
   script: __DIR__ . '/amphp.php',
));
