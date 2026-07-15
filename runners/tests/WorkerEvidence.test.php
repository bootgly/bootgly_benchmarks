<?php

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;

require_once dirname(__DIR__, 2) . '/HTTP_Server_CLI/bootables/WorkerEvidence.php';


$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};
$previous = getenv('BENCHMARK_WARMUP_TOKEN');
$file = tempnam(sys_get_temp_dir(), 'bootgly-worker-evidence-');

if (!is_string($file)) {
   throw new RuntimeException('Could not allocate the worker-evidence proof file.');
}

try {
   $token = 'worker-evidence-proof-token';
   putenv("BENCHMARK_WARMUP_TOKEN={$token}");
   WorkerEvidence::$enabled = true;

   $Check(WorkerEvidence::identify(null) === null, 'An absent marker token was accepted.');
   $Check(WorkerEvidence::identify('wrong-token') === null, 'An incorrect marker token was accepted.');
   $Check(
      WorkerEvidence::identify('wrong-token', $token) === null && WorkerEvidence::$enabled,
      'A seal without the exact warmup token disarmed the worker.',
   );

   $identity = WorkerEvidence::identify($token);
   $Check(is_string($identity), 'The current worker did not receive an identity.');
   $Check(WorkerEvidence::$enabled, 'A missing seal disarmed the worker.');
   $Check(
      WorkerEvidence::identify($token, 'wrong-seal') === $identity && WorkerEvidence::$enabled,
      'An incorrect seal disarmed the worker or changed its acknowledgement.',
   );
   $Check(
      $identity === WorkerEvidence::identify($token),
      'The identity was not stable inside one worker process.',
   );
   $Check(
      str_starts_with($identity, $token . ':' . getmypid() . '-'),
      'The identity did not bind the token to the current process.',
   );

   $PID = pcntl_fork();
   if ($PID < 0) {
      throw new RuntimeException('Could not fork the worker-evidence proof child.');
   }
   if ($PID === 0) {
      $childIdentity = WorkerEvidence::identify($token);
      $written = is_string($childIdentity)
         ? file_put_contents($file, $childIdentity, LOCK_EX)
         : false;
      exit($written === false ? 70 : 0);
   }

   $waited = pcntl_waitpid($PID, $status);
   $Check(
      $waited === $PID && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
      'The worker-evidence proof child failed.',
   );
   $childIdentity = file_get_contents($file);
   $Check(is_string($childIdentity) && $childIdentity !== '', 'The child identity was not published.');
   $Check($childIdentity !== $identity, 'A forked worker inherited the parent identity.');
   $Check(
      str_starts_with($childIdentity, $token . ':' . $PID . '-'),
      'The forked identity did not bind to the child process.',
   );

   $sealedIdentity = WorkerEvidence::identify($token, $token);
   $Check($sealedIdentity === $identity, 'The exact seal did not return the normal acknowledgement.');
   $Check(WorkerEvidence::$enabled === false, 'The exact paired seal did not disarm the worker.');
   $Check(
      WorkerEvidence::identify($token) === null,
      'A normal warmup request was acknowledged after the worker was sealed.',
   );
}
finally {
   WorkerEvidence::$enabled = true;
   $previous === false
      ? putenv('BENCHMARK_WARMUP_TOKEN')
      : putenv("BENCHMARK_WARMUP_TOKEN={$previous}");
   @unlink($file);
}

fwrite(STDOUT, "WorkerEvidence tests: OK\n");
