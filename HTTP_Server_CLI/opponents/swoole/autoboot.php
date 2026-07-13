<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole Opponent
 * --------------------------------------------------------------------------
 *
 * Swoole runs in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:swoole), always in SWOOLE_BASE mode. The
 * bootable is derived from the active load set: techempower -> the 7 TFB
 * routes with a per-worker PDO pool; any other set -> the generic route set.
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Swoole',
   version: function () {
      $swoole = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      $pdoPgsql = exec("php -r \"echo extension_loaded('pdo_pgsql') ? 'pdo_pgsql' : 'missing-pdo_pgsql';\" 2>/dev/null") ?: 'missing-pdo_pgsql';
      return "v{$swoole} {$pdoPgsql}";
   },
   script: __DIR__ . '/swoole.php',
));
