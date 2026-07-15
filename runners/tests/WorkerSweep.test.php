<?php

use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\Runners\WorkerSweep;
use Bootgly\Benchmarks\Runners\WorkerWarmup;


require_once dirname(__DIR__) . '/WorkerWarmup.php';
require_once dirname(__DIR__) . '/WorkerSweep.php';

return new Specification(
   description: 'It should reject an unschedulable late worker-proof sweep before round one',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$Reject = static function (callable $Callback, string $message): void {
   try {
      $Callback();
   }
   catch (InvalidArgumentException) {
      return;
   }

   throw new AssertionError($message);
};

$boundaryPaths = array_map(static fn (int $index): string => "/boundary-{$index}", range(1, 4_096));
$boundary = WorkerWarmup::plan(['paths' => $boundaryPaths], workers: 1);
$trivial = WorkerWarmup::plan(['paths' => ['/only']], workers: 1);
$Check(
   ($boundary['budget_seconds'] ?? null) === 37.5
      && ($trivial['budget_seconds'] ?? null) === 10.0
      && $boundary['budget_seconds'] > $trivial['budget_seconds'],
   'Automatic proof time did not scale with minimum worker/path request waves.',
);

foreach (['garbage', 0, -1, INF, NAN, 3_600.1] as $invalidBudget) {
   foreach (['TCP_Client.php', 'HTTP_Client.php'] as $runnerFile) {
      $Reject(
         static function () use ($runnerFile, $invalidBudget): void {
            $Runner = include dirname(__DIR__) . "/{$runnerFile}";
            $Runner->configure([
               'client-workers' => 1,
               'worker-proof-budget' => $invalidBudget,
            ]);
         },
         "{$runnerFile} accepted an invalid public worker proof budget.",
      );
   }
}

$Runner = include dirname(__DIR__) . '/TCP_Client.php';
$Runner->load(dirname(__DIR__, 2) . '/HTTP_Server_CLI/loads/benchmark');
$Opponent = new Opponent(name: 'Bootgly', script: '/dev/null');
$Runner->add($Opponent);
$Configs = Configs::parse([
   'opponents' => 'bootgly',
   'loads' => 'benchmark:7',
]);
$rounds = [
   ['server-workers' => 1],
   ['server-workers' => 5],
];

$Runner->configure(['client-workers' => 1, 'worker-proof-budget' => 'auto']);
$lateRejected = false;
try {
   WorkerSweep::validate($Runner, $Configs, $rounds);
}
catch (InvalidArgumentException $Exception) {
   $lateRejected = str_contains($Exception->getMessage(), 'exceeds the automatic limit');
}
$Check(
   $lateRejected && $Opponent->workers === null,
   'The late 5x1,000 matrix did not fail before any sweep round was applied.',
);

$Runner->configure(['client-workers' => 1, 'worker-proof-budget' => '120']);
WorkerSweep::validate($Runner, $Configs, $rounds);
$Check(
   $Opponent->workers === null,
   'The explicit budget did not admit the full plan or mutated a round early.',
);

return true;
   },
);
