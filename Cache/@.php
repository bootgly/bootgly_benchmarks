<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache (per-driver, per-operation matrix)
 * --------------------------------------------------------------------------
 *
 * Profiles every Cache driver (File / APCu / Shared / Redis) across the full
 * operation set, using the Code runner. Each driver runs the SAME workload
 * (scenarios.php) and emits a label→{time,memory} map; the report renders a
 * driver×operation matrix (fastest highlighted per operation).
 *
 * Unavailable drivers (missing extension / unreachable server) exit non-zero
 * and show N/A — the run still succeeds on minimal installs.
 */

$Runner = include __DIR__ . '/../runners/Code.php';
$Runner->iterations = 3;
$Runner->warmup = 1;

// ? Load set — explicit `default` required (single-set case, no silent default).
//   Skipped under --help (BENCHMARK_HELP), which only needs the Runner options.
$loadSet = strtolower((string) getenv('BENCHMARK_LOAD_SET'));
if (getenv('BENCHMARK_HELP') !== '1' && $loadSet !== 'default') {
   fwrite(STDERR,
      "Cache benchmark: single load set 'default'. "
      . "Pass --loads=default:<indexes> (e.g. --loads=default:*).\n"
   );
   exit(1);
}

// @ Auto-register opponents — each driver folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
