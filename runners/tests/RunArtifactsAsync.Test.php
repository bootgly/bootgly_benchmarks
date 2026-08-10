<?php

use Bootgly\Benchmarks\Runners\RunArtifacts;

require_once dirname(__DIR__) . '/RunArtifacts.php';

$root = sys_get_temp_dir() . '/bootgly-run-artifacts-async-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0o755)) {
   throw new RuntimeException("Could not create proof directory: {$root}");
}

$previous = getenv('BENCHMARK_RUN_DIR');
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};

try {
   putenv("BENCHMARK_RUN_DIR={$root}");

   // @ Normal completion: the exact tracked child must finish before its large,
   //   separated streams and final status become visible.
   $Artifacts = RunArtifacts::create('async-proof');
   $payload = <<<'PHP'
$stdout = str_repeat('O', 1024 * 1024);
$stderr = str_repeat('E', 1024 * 1024);
fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr);
usleep(250_000);
exit(23);
PHP;
   $Process = $Artifacts->start([PHP_BINARY, '-r', $payload]);

   $Check(!file_exists($Artifacts->resolve('process')), 'The output pair was published before the child was joined.');
   $Check(is_dir($Artifacts->resolve('process.capture')), 'The live output pair has no staging directory.');
   $running = json_decode((string) file_get_contents($Artifacts->resolve('status.json')), true, flags: JSON_THROW_ON_ERROR);
   $Check(($running['state'] ?? null) === 'running', 'The initial process manifest is not running.');

   $started = microtime(true);
   $result = $Process->wait(2.0, 0.2);
   $elapsed = microtime(true) - $started;

   $Check($elapsed >= 0.15, 'wait() returned before the delayed child exited.');
   $Check($result['exit'] === 23, 'The delayed child exit status was not preserved.');
   $Check($result['state'] === 'exited' && !$result['timed_out'], 'The delayed child was misclassified.');
   $Check(filesize($result['stdout']) === 1024 * 1024, 'The large stdout capture is incomplete.');
   $Check(filesize($result['stderr']) === 1024 * 1024, 'The large stderr capture is incomplete.');
   $Check(hash_file('sha256', $result['stdout']) === hash('sha256', str_repeat('O', 1024 * 1024)), 'stdout was mixed or corrupted.');
   $Check(hash_file('sha256', $result['stderr']) === hash('sha256', str_repeat('E', 1024 * 1024)), 'stderr was mixed or corrupted.');
   $Check(!file_exists($Artifacts->resolve('process.capture')), 'A normal completion leaked its staging directory.');

   $status = json_decode((string) file_get_contents($Artifacts->resolve('status.json')), true, flags: JSON_THROW_ON_ERROR);
   $Check(($status['state'] ?? null) === 'exited', 'The final process manifest is not exited.');
   $Check(($status['exit'] ?? null) === 23, 'The final process manifest lost the exit status.');
   $Check(is_int($status['pid'] ?? null) && $status['pid'] > 0, 'The final process manifest has no PID.');

   // @ A readiness poll may observe an early wrapper exit before wait() reaps
   //   it. The observed exit code must survive that non-reaping check.
   $CheckArtifacts = RunArtifacts::create('async-check-proof');
   $CheckProcess = $CheckArtifacts->start([PHP_BINARY, '-r', 'exit(37);']);
   usleep(100_000);
   $Check(!$CheckProcess->check(), 'check() reported an already-exited child as running.');
   $Check($CheckProcess->wait()['exit'] === 37, 'check() consumed the early child exit status.');

   // @ Normal completion is not terminal while a descendant in the isolated
   //   group can still mutate a captured channel.
   $NaturalArtifacts = RunArtifacts::create('async-natural-descendant-proof');
   $naturalPayload = <<<'PHP'
$PID = pcntl_fork();
if ($PID === 0) {
   usleep(250_000);
   fwrite(STDOUT, "natural-late");
   exit(0);
}
exit(0);
PHP;
   $NaturalProcess = $NaturalArtifacts->start([PHP_BINARY, '-r', $naturalPayload]);
   $started = microtime(true);
   $naturalResult = $NaturalProcess->wait(2.0, 0.2);
   $elapsed = microtime(true) - $started;
   $naturalBytes = filesize($naturalResult['stdout']);
   usleep(300_000);
   clearstatcache(true, $naturalResult['stdout']);

   $Check($elapsed >= 0.20, 'wait() published output before the natural descendant exited.');
   $Check(file_get_contents($naturalResult['stdout']) === 'natural-late', 'Natural descendant output was lost.');
   $Check(filesize($naturalResult['stdout']) === $naturalBytes, 'Natural descendant output grew after publication.');

   // @ Timeout completion: ignore TERM so wait() must escalate to KILL, reap
   //   the same PID, retain both partial streams, and publish timeout metadata.
   $TimeoutArtifacts = RunArtifacts::create('async-timeout-proof');
   $timeoutPayload = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
fwrite(STDOUT, "before-timeout\n");
fwrite(STDERR, "timeout-stderr\n");
usleep(5_000_000);
PHP;
   $TimeoutProcess = $TimeoutArtifacts->start([PHP_BINARY, '-r', $timeoutPayload]);
   $started = microtime(true);
   $timeoutResult = $TimeoutProcess->wait(0.1, 0.1);
   $elapsed = microtime(true) - $started;

   $Check($elapsed < 2.0, 'Timeout escalation did not remain bounded.');
   $Check($timeoutResult['exit'] === 124, 'A timed-out child did not use the timeout exit status.');
   $Check($timeoutResult['timed_out'], 'A timed-out child was not marked timed out.');
   $Check($timeoutResult['state'] === 'killed', 'The TERM-ignoring child was not escalated to KILL.');
   $Check(file_get_contents($timeoutResult['stdout']) === "before-timeout\n", 'Timed-out stdout was lost.');
   $Check(file_get_contents($timeoutResult['stderr']) === "timeout-stderr\n", 'Timed-out stderr was lost.');
   $Check(!file_exists($TimeoutArtifacts->resolve('process.capture')), 'Timeout completion leaked its staging directory.');

   $timeoutStatus = json_decode((string) file_get_contents($TimeoutArtifacts->resolve('status.json')), true, flags: JSON_THROW_ON_ERROR);
   $Check(($timeoutStatus['state'] ?? null) === 'killed', 'The timeout manifest does not record KILL.');
   $Check(($timeoutStatus['exit'] ?? null) === 124, 'The timeout manifest lost the timeout exit status.');
   $Check(($timeoutStatus['timed_out'] ?? null) === true, 'The timeout manifest lost the timeout flag.');
   $Check(($timeoutStatus['termination'] ?? null) === 'kill', 'The timeout manifest lost the escalation mode.');

   // @ A TERM-ignoring descendant must be included in KILL escalation. The
   //   final capture must remain byte-for-byte stable after wait() returns.
   $TreeArtifacts = RunArtifacts::create('async-timeout-descendant-proof');
   $treePayload = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
$PID = pcntl_fork();
if ($PID === 0) {
   usleep(800_000);
   fwrite(STDOUT, "forbidden-late-output");
   exit(0);
}
fwrite(STDERR, "descendant={$PID}\n");
usleep(5_000_000);
PHP;
   $TreeProcess = $TreeArtifacts->start([PHP_BINARY, '-r', $treePayload]);
   $treeResult = $TreeProcess->wait(0.1, 0.1);
   $treeBytes = filesize($treeResult['stdout']);
   $treeError = (string) file_get_contents($treeResult['stderr']);
   preg_match('/descendant=(\d+)/', $treeError, $matches);
   $descendantPID = isset($matches[1]) ? (int) $matches[1] : 0;
   usleep(900_000);
   clearstatcache(true, $treeResult['stdout']);

   $Check($treeResult['exit'] === 124, 'Descendant timeout did not return the timeout exit status.');
   $Check($descendantPID > 0 && !@posix_kill($descendantPID, 0), 'A timed-out descendant survived group escalation.');
   $Check(filesize($treeResult['stdout']) === $treeBytes, 'A timed-out descendant mutated stdout after publication.');
   $Check(!str_contains((string) file_get_contents($treeResult['stdout']), 'forbidden-late-output'), 'Late descendant output reached the final capture.');

   // @ A final-directory collision must not erase the complete staging pair.
   $CollisionArtifacts = RunArtifacts::create('async-collision-safety');
   $CollisionProcess = $CollisionArtifacts->start([
      PHP_BINARY,
      '-r',
      'fwrite(STDOUT, "collision-out"); fwrite(STDERR, "collision-err");',
   ]);
   mkdir($CollisionArtifacts->resolve('process'), 0o755);

   try {
      $CollisionProcess->wait();
      throw new RuntimeException('The forced process-output collision was not reported.');
   }
   catch (RuntimeException $Exception) {
      $Check(
         str_contains($Exception->getMessage(), 'output already exists'),
         'The collision-safety check failed for an unexpected reason.'
      );
   }

   $Check(
      file_get_contents($CollisionArtifacts->resolve('process.capture/stdout.log')) === 'collision-out',
      'A publish collision erased staged stdout evidence.'
   );
   $Check(
      file_get_contents($CollisionArtifacts->resolve('process.capture/stderr.log')) === 'collision-err',
      'A publish collision erased staged stderr evidence.'
   );
   $collisionStatus = json_decode(
      (string) file_get_contents($CollisionArtifacts->resolve('status.json')),
      true,
      flags: JSON_THROW_ON_ERROR,
   );
   $Check(
      ($collisionStatus['state'] ?? null) === 'publication-failed'
         && ($collisionStatus['stdout'] ?? null) === $CollisionArtifacts->resolve('process.capture/stdout.log')
         && ($collisionStatus['stderr'] ?? null) === $CollisionArtifacts->resolve('process.capture/stderr.log'),
      'A publish collision left misleading running status or lost staged paths.'
   );

   // @ If the initial running manifest cannot publish, start() must still
   //   return the process handle so wait() can reap it and publish its streams.
   $ManifestArtifacts = RunArtifacts::create('async-manifest-proof');
   mkdir($ManifestArtifacts->resolve('status.json'), 0o755);
   $ManifestProcess = $ManifestArtifacts->start([
      PHP_BINARY,
      '-r',
      'fwrite(STDOUT, "manifest-out"); fwrite(STDERR, "manifest-err");',
   ]);

   try {
      $ManifestProcess->wait();
      throw new RuntimeException('The forced final manifest failure was not reported.');
   }
   catch (RuntimeException $Exception) {
      $Check(
         str_contains($Exception->getMessage(), 'commit benchmark artifact'),
         'The manifest proof failed for an unexpected reason.'
      );
   }

   $Check(!$ManifestProcess->check(), 'A manifest failure left the child process running.');
   $Check(
      file_get_contents($ManifestArtifacts->resolve('process/stdout.log')) === 'manifest-out',
      'A manifest failure prevented stdout publication after reaping.'
   );
   $Check(
      file_get_contents($ManifestArtifacts->resolve('process/stderr.log')) === 'manifest-err',
      'A manifest failure prevented stderr publication after reaping.'
   );
   $failureStatus = json_decode(
      (string) file_get_contents($ManifestArtifacts->resolve('status.failure.json')),
      true,
      flags: JSON_THROW_ON_ERROR
   );
   $Check(($failureStatus['state'] ?? null) === 'exited', 'The fallback manifest lost terminal state.');
   $Check(
      is_string($failureStatus['status_error'] ?? null),
      'The fallback manifest did not preserve the status publication error.'
   );

   // @ Commands are useful provenance, but raw credentials must never survive
   //   in the status artifact that the run manifest hashes and indexes.
   $SecretArtifacts = RunArtifacts::create('async-secret-redaction');
   $SecretArtifacts->run([
      PHP_BINARY,
      '-r',
      'exit(0);',
      '--',
      '--token=runner-secret-marker',
      '--dsn=redis://user:password@127.0.0.1/0',
      'session.save_path=tcp://user:password@127.0.0.1:6379',
      '--api-key=runner-api-key-marker',
      '--access-key',
      'runner-access-key-marker',
   ]);
   $secretStatus = (string) file_get_contents($SecretArtifacts->resolve('status.json'));
   $Check(!str_contains($secretStatus, 'runner-secret-marker'), 'Status persisted a token value.');
   $Check(!str_contains($secretStatus, 'user:password'), 'Status persisted URI credentials.');
   $Check(str_contains($secretStatus, '--token=<redacted>'), 'Status omitted token redaction evidence.');
   $Check(str_contains($secretStatus, '--dsn=<redacted-uri>'), 'Status omitted URI redaction evidence.');
   $Check(
      str_contains($secretStatus, 'session.save_path=<redacted-uri>'),
      'Status omitted sensitive directive redaction evidence.'
   );
   $Check(!str_contains($secretStatus, 'runner-api-key-marker'), 'Status persisted an API key value.');
   $Check(!str_contains($secretStatus, 'runner-access-key-marker'), 'Status persisted a separate access key value.');

   // @ Invalid timeout values are rejected before capture/status allocation.
   $InvalidArtifacts = RunArtifacts::create('async-invalid-timeout-proof');
   try {
      $InvalidArtifacts->run([PHP_BINARY, '-r', 'exit(0);'], -1.0);
      throw new RuntimeException('A negative timeout was accepted.');
   }
   catch (InvalidArgumentException) {
      // Expected.
   }
   $Check(!file_exists($InvalidArtifacts->resolve('process.capture')), 'Invalid timeout started a subprocess capture.');
   $Check(!file_exists($InvalidArtifacts->resolve('status.json')), 'Invalid timeout published subprocess status.');
}
finally {
   $previous === false
      ? putenv('BENCHMARK_RUN_DIR')
      : putenv("BENCHMARK_RUN_DIR={$previous}");

   $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
   $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
   foreach ($Iterator as $Child) {
      $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
   }
   rmdir($root);
}

fwrite(STDOUT, "RunArtifacts async proof: OK\n");
