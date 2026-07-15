<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\Benchmarks\HTTP_Server_CLI\DatabaseParity;


require_once __DIR__ . '/DatabaseParity.php';

// @ Select runner based on environment variable
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'tcp_client');

$runnerFile = match ($runnerType) {
   'tcp_client'  => 'TCP_Client',
   'http_client' => 'HTTP_Client',
   default       => ucfirst($runnerType),
};
$Runner = include __DIR__ . "/../runners/{$runnerFile}.php";
// @ Load set — provided by the framework from `--loads=<set>:<indexes>` (BENCHMARK_LOAD_SET).
$loadSet = strtolower((string) getenv('BENCHMARK_LOAD_SET'));
$poolMax = DatabaseParity::normalize($loadSet);

// ? Explicit set required — this case ships two sets, no silent default.
//   Skipped under --help (BENCHMARK_HELP), which only needs the Runner options.
if (getenv('BENCHMARK_HELP') !== '1' && $loadSet !== 'techempower' && $loadSet !== 'benchmark') {
   fwrite(STDERR,
      "HTTP_Server_CLI benchmark: unknown load set '{$loadSet}'.\n"
      . "Pass --loads=<set>:<indexes> with set = techempower | benchmark "
      . "(e.g. --loads=techempower:* or --loads=benchmark:1,2).\n"
   );
   exit(1);
}

// @ Surface the load set in the .marks Config header so chart tooling
//   can name outputs by `load-set` without per-tool CLI flags.
$Runner->meta['load-set'] = $loadSet;

// # Both load sets exercise PostgreSQL — `techempower` via /db /query
//   /fortunes /updates, `benchmark` via the Bootgly /database/* probes.
if ($loadSet === 'techempower' || $loadSet === 'benchmark') {
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

      // ? Machine mode (--format=json) keeps the whole output free of diagnostics
      if ($seeded !== 0 && getenv('BENCHMARK_FORMAT') !== 'json') {
         fwrite(STDERR, "Warning: could not seed TechEmpower database tables. Benchmark preflight may fail.\n");
      }
   }
}

// @ Configure runner
$Runner->port = 8082;
$Runner->connections = 514;
$Runner->duration = 10;
// ? Cold heavy-DB workers (/query, /updates open the whole pool) can need >3s on
//   their first request at high server-workers; widen the preflight window so a
//   slow cold start is not misread as a failed (N/A) cell. Only the TCP_Client
//   runner exposes this knob.
if (property_exists($Runner, 'preflightTimeout')) {
   $Runner->preflightTimeout = 8;
}

// @ Load PHP loads for the active set
$loadDir = match ($loadSet) {
   'techempower' => '/loads/techempower',
   default       => '/loads/benchmark',
};
$Runner->load(__DIR__ . $loadDir);

// @ Auto-register opponents — each folder self-registers via its own autoboot.php
foreach (glob(__DIR__ . '/opponents/*/autoboot.php') as $opponentFile) {
   require $opponentFile;
}

// ! Resolve and prove the effective DB ceiling only after TestCommand knows
//   the concrete opponent/load selection, but before it publishes the resolved
//   manifest config, renders the banner, or starts a measured process.
$Runner->Validator = static function (Runner $Runner, Configs $Configs) use ($poolMax): void {
   $effective = DatabaseParity::validate($Configs, $Runner->opponents, $poolMax);
   if ($effective === null) {
      return;
   }

   $Runner->meta['db-pool-max'] = $effective;
   if (DatabaseParity::check($Configs)) {
      $Runner->meta['db-pool-comparability'] = DatabaseParity::CONTRACT;
   }
};

return $Runner;
