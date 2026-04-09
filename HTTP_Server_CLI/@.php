<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


// @ Select runner based on environment variable
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'tcp_client');

$runnerFile = match ($runnerType) {
   'tcp_client' => 'TCP_Client',
   'wrk'        => 'WRK',
   default      => ucfirst($runnerType),
};
$Runner = include __DIR__ . "/../runners/{$runnerFile}.php";

// @ Configure per runner type
if ($runnerType === 'tcp_client') {
   $Runner->port = 8082;
   $Runner->connections = 514;
   $Runner->duration = 10;

   // @ Load PHP scenarios
   $Runner->load(__DIR__ . '/scenarios/php');
} else if ($runnerType === 'wrk') {
   $Runner->port = 8082;
   $Runner->threads = 10;
   $Runner->connections = 514;
   $Runner->duration = '10s';

   // @ Load Lua scenarios
   $Runner->load(__DIR__ . '/scenarios');
}

$artifactDir = __DIR__ . '/artifacts';

// @ Add competitors
$Runner->add(new Competitor(
   name: 'Bootgly',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/competitors/bootgly.php',
));
$Runner->add(new Competitor(
   name: 'FrankenPHP',
   version: function () {
      $output = exec('frankenphp --version 2>/dev/null') ?: '';
      preg_match('/v[0-9]+\.[0-9]+\.[0-9]+/', $output, $m);
      return $m[0] ?? 'unknown';
   },
   script: __DIR__ . '/competitors/frankenphp.php',
));
$Runner->add(new Competitor(
   name: 'Hyperf',
   version: function () use ($artifactDir) {
      $lock = @file_get_contents("{$artifactDir}/hyperf/composer.lock");
      if ($lock && preg_match('/"name":\s*"hyperf\/framework".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return 'v' . ltrim($m[1], 'v');
      }
      return 'unknown';
   },
   script: __DIR__ . '/competitors/hyperf.php',
));
$Runner->add(new Competitor(
   name: 'RoadRunner',
   version: function () use ($artifactDir) {
      $output = exec("cd {$artifactDir}/roadrunner && ./rr -v 2>/dev/null") ?: '';
      preg_match('/version ([0-9]+\.[0-9]+\.[0-9]+)/', $output, $m);
      return isset($m[1]) ? 'v' . $m[1] : 'unknown';
   },
   script: __DIR__ . '/competitors/roadrunner.php',
));
$Runner->add(new Competitor(
   name: 'Swoole (Base)',
   version: function () {
      $v = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      return 'v' . $v;
   },
   script: __DIR__ . '/competitors/swoole-base.php',
));
$Runner->add(new Competitor(
   name: 'Swoole (Process)',
   version: function () {
      $v = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      return 'v' . $v;
   },
   script: __DIR__ . '/competitors/swoole-process.php',
));
$Runner->add(new Competitor(
   name: 'Swoole (Coroutine)',
   version: function () {
      $v = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      return 'v' . $v;
   },
   script: __DIR__ . '/competitors/swoole-coroutine.php',
));
$Runner->add(new Competitor(
   name: 'Workerman',
   version: function () use ($artifactDir) {
      $lock = @file_get_contents("{$artifactDir}/workerman/composer.lock");
      if ($lock && preg_match('/"name":\s*"workerman\/workerman".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return 'v' . ltrim($m[1], 'v');
      }
      return 'unknown';
   },
   script: __DIR__ . '/competitors/workerman.php',
));

return $Runner;
