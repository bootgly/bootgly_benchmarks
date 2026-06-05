<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole Opponents
 * --------------------------------------------------------------------------
 *
 * Swoole ships several variations: Base / Process / Coroutine reactor modes
 * (router loads) and TechEmpower (PROCESS + PDO pool + /plaintext & /json).
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$swooleVersion = function (): string {
   $v = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
   return 'v' . $v;
};

$Runner->add(new Opponent(
   name: 'Swoole (Base)',
   version: $swooleVersion,
   script: __DIR__ . '/swoole-base.php',
));
$Runner->add(new Opponent(
   name: 'Swoole (Process)',
   version: $swooleVersion,
   script: __DIR__ . '/swoole-process.php',
));
$Runner->add(new Opponent(
   name: 'Swoole (Coroutine)',
   version: $swooleVersion,
   script: __DIR__ . '/swoole-coroutine.php',
));
$Runner->add(new Opponent(
   name: 'Swoole TechEmpower',
   version: function () {
      $swoole = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      $pdoPgsql = exec("php -r \"echo extension_loaded('pdo_pgsql') ? 'pdo_pgsql' : 'missing-pdo_pgsql';\" 2>/dev/null") ?: 'missing-pdo_pgsql';
      return 'v' . $swoole . ' ' . $pdoPgsql;
   },
   script: __DIR__ . '/swoole-techempower.php',
));
