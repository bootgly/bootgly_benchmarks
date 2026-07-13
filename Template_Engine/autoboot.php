<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Template_Engine (foreach directive)
 * --------------------------------------------------------------------------
 *
 * Compares foreach directive performance with 1,000,000 items.
 */

$Runner = include __DIR__ . '/../runners/Code.php';
$Runner->iterations = 1;

// ? Load set — explicit `default` required (single-set case, no silent default).
//   Skipped under --help (BENCHMARK_HELP), which only needs the Runner options.
$loadSet = strtolower((string) getenv('BENCHMARK_LOAD_SET'));
if (getenv('BENCHMARK_HELP') !== '1' && $loadSet !== 'default') {
   fwrite(STDERR,
      "Template_Engine benchmark: single load set 'default'. "
      . "Pass --loads=default:<indexes> (e.g. --loads=default:*).\n"
   );
   exit(1);
}

// @ Auto-register opponents — each folder self-registers via its own autoboot.php
foreach (glob(__DIR__ . '/opponents/*/autoboot.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
