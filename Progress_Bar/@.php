<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Progress_Bar
 * --------------------------------------------------------------------------
 *
 * Compares Progress Bar rendering performance (250,000 iterations).
 */

$Runner = include __DIR__ . '/../runners/Code.php';
$Runner->iterations = 1;

// @ Auto-register competitors — each folder self-registers via its own @.php
foreach (glob(__DIR__ . '/opponents/*/@.php') as $opponentFile) {
   require $opponentFile;
}

return $Runner;
