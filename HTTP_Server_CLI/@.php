<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI
 * --------------------------------------------------------------------------
 */

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

// ? Explicit set required — this case ships two sets, no silent default.
if ($loadSet !== 'techempower' && $loadSet !== 'benchmark') {
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

      if ($seeded !== 0) {
         fwrite(STDERR, "Warning: could not seed TechEmpower database tables. Benchmark preflight may fail.\n");
      }
   }
}

// @ Configure runner
$Runner->port = 8082;
$Runner->connections = 514;
$Runner->duration = 10;

// @ Load PHP loads for the active set
$loadDir = match ($loadSet) {
   'techempower' => '/loads/techempower',
   default       => '/loads/benchmark',
};
$Runner->load(__DIR__ . $loadDir);

// @ Auto-register opponents — each folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
