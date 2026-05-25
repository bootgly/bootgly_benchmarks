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
   'tcp_client'  => 'TCP_Client',
   'http_client' => 'HTTP_Client',
   'wrk'         => 'WRK',
   default       => ucfirst($runnerType),
};
$Runner = include __DIR__ . "/../runners/{$runnerFile}.php";
$scenarioSet = strtolower(getenv('BOOTGLY_HTTP_SERVER_CLI_SCENARIOS') ?: 'router');

if ($scenarioSet === 'database') {
   $psql = trim(exec('command -v psql 2>/dev/null') ?: '');
   $seedFile = __DIR__ . '/artifacts/@postgresql/techempower.sql';

   if ($psql !== '' && is_file($seedFile)) {
      $password = getenv('DB_PASS');
      $command = 'PGPASSWORD=' . escapeshellarg($password === false ? '' : $password) . ' '
         . escapeshellarg($psql)
         . ' -h ' . escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1')
         . ' -p ' . escapeshellarg(getenv('DB_PORT') ?: '5432')
         . ' -U ' . escapeshellarg(getenv('DB_USER') ?: 'postgres')
         . ' -d ' . escapeshellarg(getenv('DB_NAME') ?: 'bootgly')
         . ' -v ON_ERROR_STOP=1'
         . ' -f ' . escapeshellarg($seedFile)
         . ' >/dev/null 2>&1';
      $seedOutput = [];
      $seeded = 0;
      exec($command, $seedOutput, $seeded);

      if ($seeded !== 0) {
         fwrite(STDERR, "Warning: could not seed TechEmpower database tables. Benchmark preflight may fail.\n");
      }
   }
}

// @ Configure per runner type
if ($runnerType === 'tcp_client' || $runnerType === 'http_client') {
   $Runner->port = 8082;
   $Runner->connections = 514;
   $Runner->duration = 10;

   // @ Load PHP scenarios
   $Runner->load(__DIR__ . ($scenarioSet === 'database' ? '/scenarios/database/php' : '/scenarios/php'));
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
   name: 'Swoole Database',
   version: function () {
      $swoole = exec("php -r \"echo phpversion('swoole') ?? 'unknown';\" 2>/dev/null") ?: 'unknown';
      $pdoPgsql = exec("php -r \"echo extension_loaded('pdo_pgsql') ? 'pdo_pgsql' : 'missing-pdo_pgsql';\" 2>/dev/null") ?: 'missing-pdo_pgsql';
      return 'v' . $swoole . ' ' . $pdoPgsql;
   },
   script: __DIR__ . '/competitors/swoole-database.php',
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
