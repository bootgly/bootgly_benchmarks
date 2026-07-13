<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — WS_Server_CLI
 * --------------------------------------------------------------------------
 * WebSocket server benchmark — boots WS_Server_CLI and drives it with the
 * native WS_Client_CLI over persistent connections. Three load sets, each a
 * distinct server mode + metric: echo / broadcast / connect.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Runner;


// @ Select runner
$runnerType = strtolower(getenv('BENCHMARK_RUNNER') ?: 'ws_raw');

$runnerFile = match ($runnerType) {
   'ws_raw' => 'WS_Raw',
   default  => ucfirst($runnerType),
};
$runnerPath = __DIR__ . "/../runners/{$runnerFile}.php";

/** @var Runner|false $Runner */
$Runner = is_file($runnerPath) ? include $runnerPath : false;
if ($Runner instanceof Runner === false) {
   return false;
}

// ? Load set — explicit required; this case ships three sets (each set fixes a
//   distinct server mode + metric). Skipped under --help (BENCHMARK_HELP).
$loadSet = strtolower((string) getenv('BENCHMARK_LOAD_SET'));
$validSets = ['echo', 'broadcast', 'connect'];
if (getenv('BENCHMARK_HELP') !== '1' && !in_array($loadSet, $validSets, true)) {
   fwrite(STDERR,
      "WS_Server_CLI benchmark: unknown load set '{$loadSet}'.\n"
      . "Pass --loads=<set>:<indexes> with set = echo | broadcast | connect "
      . "(e.g. --loads=echo:* or --loads=broadcast:1).\n"
   );
   exit(1);
}

// @ Surface the load set in the .marks Config header so chart tooling can name
//   outputs by `load-set` without per-tool CLI flags.
$Runner->meta['load-set'] = $loadSet;

// @ Configure
$Runner->port = 8085;
$Runner->connections = 514;
$Runner->duration = 10;

// @ Load PHP loads for the active set
$loadDir = match ($loadSet) {
   'broadcast' => '/loads/broadcast',
   'connect'   => '/loads/connect',
   default     => '/loads/echo',
};
$Runner->load(__DIR__ . $loadDir);

// @ Auto-register opponents — each folder self-registers via its own autoboot.php
foreach (glob(__DIR__ . '/opponents/*/autoboot.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
