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

// @ Auto-register opponents — each folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
