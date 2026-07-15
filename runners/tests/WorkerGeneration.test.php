<?php

use Bootgly\Benchmarks\Runners\WorkerGeneration;
use Bootgly\Benchmarks\Runners\WorkerGenerationFailure;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once dirname(__DIR__) . '/WorkerGeneration.php';


return new Specification(
   description: 'It should prove the measured worker generation remains stable and fail closed on changes',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$RejectArgument = static function (Closure $Callback, string $message): InvalidArgumentException {
   try {
      $Callback();
   }
   catch (InvalidArgumentException $Exception) {
      return $Exception;
   }

   throw new AssertionError($message);
};
$Contains = static function (array $values, string $value): bool {
   return in_array($value, $values, true);
};
$root = sys_get_temp_dir() . '/bootgly-worker-generation-' . bin2hex(random_bytes(12));
$proc = $root . '/proc';
$procLink = $root . '/proc-link';
$directory = $root . '/workers';
$Handles = [];

if (
   !mkdir($proc . '/sys/kernel/random', 0o700, true)
   || !mkdir($directory, 0o700, true)
) {
   throw new RuntimeException('Could not create the worker-generation proof directories.');
}

$Stat = static function (
   string $proc,
   int $PID,
   string $name,
   string $state,
   int $PPID,
   string $start,
): void {
   $path = $proc . '/' . $PID;
   if (!is_dir($path) && !mkdir($path, 0o700)) {
      throw new RuntimeException('Could not create a fake proc process directory.');
   }
   // Linux /proc/<pid>/stat fields beginning at field 3. starttime is field
   // 22, therefore index 19 after the closing `) ` delimiter.
   $fields = [
      $state,
      (string) $PPID,
      '1', '1', '0', '0', '0',
      '0', '0', '0', '0',
      '0', '0', '0', '0',
      '0', '0', '1', '0',
      $start,
      '0', '0', '0', '0',
   ];
   if (file_put_contents($path . '/stat', $PID . ' (' . $name . ') ' . implode(' ', $fields) . "\n") === false) {
      throw new RuntimeException('Could not write fake proc process evidence.');
   }
};
$Lease = static function (string $directory, string $fingerprint, int $PID, bool $lock = true): array {
   $SHA = substr($fingerprint, strlen('sha256:'));
   $file = $directory . '/worker-' . $SHA . '.lease';
   $Handle = fopen($file, 'x+b');
   if ($Handle === false) {
      throw new RuntimeException('Could not create a worker-generation proof lease.');
   }
   if ($lock && !flock($Handle, LOCK_EX | LOCK_NB)) {
      fclose($Handle);
      throw new RuntimeException('Could not lock a worker-generation proof lease.');
   }
   $JSON = json_encode([
      'schema' => 'bootgly.worker-lease',
      'version' => 1,
      'fingerprint' => $fingerprint,
      'pid' => $PID,
   ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
   if (fwrite($Handle, $JSON) !== strlen($JSON) || !fflush($Handle)) {
      fclose($Handle);
      throw new RuntimeException('Could not publish a worker-generation proof lease.');
   }

   return [$file, $Handle];
};
$Rewrite = static function ($Handle, string $fingerprint, int $PID): void {
   $JSON = json_encode([
      'schema' => 'bootgly.worker-lease',
      'version' => 1,
      'fingerprint' => $fingerprint,
      'pid' => $PID,
   ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
   if (
      !ftruncate($Handle, 0)
      || !rewind($Handle)
      || fwrite($Handle, $JSON) !== strlen($JSON)
      || !fflush($Handle)
   ) {
      throw new RuntimeException('Could not rewrite a worker-generation proof lease.');
   }
};

try {
   if (!symlink($proc, $procLink)) {
      throw new RuntimeException('Could not create the worker-generation proc symlink proof.');
   }
   foreach (['', $root . '/missing-proc', $procLink] as $invalidProc) {
      $RejectArgument(
         static fn (): WorkerGeneration => new WorkerGeneration($invalidProc),
         'WorkerGeneration accepted an invalid proc root.',
      );
   }

   $bootID = '11111111-2222-3333-4444-555555555555';
   file_put_contents($proc . '/sys/kernel/random/boot_id', $bootID . "\n");

   // The first comm deliberately contains spaces and `)`; parsing must use the
   // final delimiter rather than splitting the stat record naively.
   $Stat($proc, 10, 'server supervisor', 'S', 1, '1000');
   $Stat($proc, 11, 'replacement supervisor', 'S', 1, '1100');
   $Stat($proc, 101, 'worker ) one', 'R', 10, '2001');
   $Stat($proc, 102, 'worker two', 'S', 10, '2002');
   $Stat($proc, 103, 'worker replacement', 'S', 10, '2003');

   $rawA = '101-generation-secret-token-alpha';
   $rawB = '102-generation-secret-token-beta';
   $fingerprintA = 'sha256:' . hash('sha256', "worker\0{$rawA}");
   $fingerprintB = 'sha256:' . hash('sha256', "worker\0{$rawB}");
   [$fileA, $Handles['A']] = $Lease($directory, $fingerprintA, 101);
   [$fileB, $Handles['B']] = $Lease($directory, $fingerprintB, 102);

   $Generation = new WorkerGeneration($proc);

   // # Baseline refuses missing and extra active leases.
   try {
      $Generation->capture($directory, [$fingerprintA]);
      throw new RuntimeException('An extra active lease was accepted by the baseline.');
   }
   catch (WorkerGenerationFailure $Failure) {
      $Check(
         $Contains($Failure->evidence['changes']['added'] ?? [], $fingerprintB),
         'Baseline failure did not identify the extra active worker.',
      );
   }
   $missingFingerprint = 'sha256:' . hash('sha256', 'missing-worker');
   try {
      $Generation->capture($directory, [$fingerprintA, $fingerprintB, $missingFingerprint]);
      throw new RuntimeException('A missing expected lease was accepted by the baseline.');
   }
   catch (WorkerGenerationFailure $Failure) {
      $Check(
         $Contains($Failure->evidence['changes']['missing'] ?? [], $missingFingerprint),
         'Baseline failure did not identify the missing worker.',
      );
   }
   flock($Handles['A'], LOCK_UN);
   try {
      $Generation->capture($directory, [$fingerprintA, $fingerprintB]);
      throw new RuntimeException('An unlocked expected lease was accepted by the baseline.');
   }
   catch (WorkerGenerationFailure $Failure) {
      $Check(
         $Contains($Failure->evidence['changes']['unlocked'] ?? [], $fingerprintA),
         'Baseline failure did not identify the unlocked worker.',
      );
   }
   $Check(flock($Handles['A'], LOCK_EX | LOCK_NB), 'Could not restore the baseline proof lock.');

   $baseline = $Generation->capture($directory, [$fingerprintB, $fingerprintA]);
   $Check(
      ($baseline['expected'] ?? null) === array_values(array_unique([
         min($fingerprintA, $fingerprintB),
         max($fingerprintA, $fingerprintB),
      ])),
      'Baseline worker fingerprints were not sorted deterministically.',
   );
   $Check(count($baseline['workers'] ?? []) === 2, 'Baseline did not retain both worker processes.');
   $Check(
      ($baseline['workers'][array_search(
         $fingerprintA,
         array_column($baseline['workers'], 'fingerprint'),
         true,
      )]['start_ticks'] ?? null) === '2001',
      'A process stat comm containing `)` was not parsed correctly.',
   );

   $terminal = $Generation->verify($baseline);
   $Check(($terminal['stable'] ?? null) === true, 'An unchanged worker generation was rejected.');
   $evidence = $Generation->compose($baseline, $terminal, [
      'opponent' => 'proof',
      'label' => 'stable generation',
   ]);
   $EvidenceJSON = json_encode($evidence, JSON_THROW_ON_ERROR);
   $Check(
      ($evidence['schema'] ?? null) === 'bootgly.worker-generation'
         && ($evidence['version'] ?? null) === 1
         && ($evidence['validated'] ?? null) === true
         && ($evidence['workers_expected'] ?? null) === 2,
      'Stable generation evidence has an invalid schema or validation state.',
   );
   $Check(
      !str_contains($EvidenceJSON, 'generation-secret-token')
         && !str_contains($EvidenceJSON, $rawA)
         && !str_contains($EvidenceJSON, $rawB)
         && !str_contains($EvidenceJSON, $directory),
      'Generation evidence leaked a raw identity or internal directory.',
   );

   // # Same PID with different start ticks is PID reuse/replacement.
   $Stat($proc, 101, 'worker ) one', 'R', 10, '9001');
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['replaced'], $fingerprintA),
      'PID reuse with changed start ticks was not rejected.',
   );
   $Stat($proc, 101, 'worker ) one', 'R', 10, '2001');

   // # Reparenting and parent generation changes are distinct evidence.
   $Stat($proc, 101, 'worker ) one', 'R', 11, '2001');
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['reparented'], $fingerprintA),
      'Worker reparenting was not rejected.',
   );
   $Stat($proc, 101, 'worker ) one', 'R', 10, '2001');
   $Stat($proc, 10, 'server supervisor', 'S', 1, '9999');
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['parent_changed'], $fingerprintA),
      'Parent PID reuse was not rejected.',
   );
   $Stat($proc, 10, 'server supervisor', 'S', 1, '1000');

   // # Zombie and malformed process records fail closed.
   $Stat($proc, 101, 'worker ) one', 'Z', 10, '2001');
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable']
         && $Contains($changed['changes']['replaced'], $fingerprintA)
         && ($changed['failures']['worker_not_live'] ?? 0) === 1,
      'A zombie worker was not rejected with explicit evidence.',
   );
   $Stat($proc, 101, 'worker ) one', 'R', 10, '2001');
   file_put_contents($proc . '/101/stat', "malformed\n");
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable']
         && $Contains($changed['changes']['replaced'], $fingerprintA)
         && ($changed['failures']['process_stat_malformed'] ?? 0) === 1,
      'Malformed proc evidence was not rejected.',
   );
   $Stat($proc, 101, 'worker ) one', 'R', 10, '2001');

   // # Lease metadata cannot switch to another PID while retaining the same
   //   fingerprint and lock.
   $Rewrite($Handles['A'], $fingerprintA, 103);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['replaced'], $fingerprintA),
      'A lease rebound to another PID was not rejected.',
   );
   $Rewrite($Handles['A'], $fingerprintA, 101);

   // # A process exit releases its lock; the unchanged file is not live proof.
   flock($Handles['A'], LOCK_UN);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['unlocked'], $fingerprintA),
      'An unlocked terminated-worker lease was accepted.',
   );
   $Check(flock($Handles['A'], LOCK_EX | LOCK_NB), 'Could not restore the proof worker lock.');

   // # A transient replacement remains visible by its new filename even after
   //   its lifetime lock has already been released.
   $fingerprintC = 'sha256:' . hash('sha256', 'transient-worker');
   [$fileC, $HandleC] = $Lease($directory, $fingerprintC, 103);
   flock($HandleC, LOCK_UN);
   fclose($HandleC);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['added'], $fingerprintC),
      'A transient added worker was not retained as terminal evidence.',
   );
   unlink($fileC);

   // # A currently-live extra worker is rejected too.
   $fingerprintD = 'sha256:' . hash('sha256', 'live-extra-worker');
   [$fileD, $Handles['D']] = $Lease($directory, $fingerprintD, 103);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['added'], $fingerprintD),
      'A live added worker was not rejected.',
   );
   flock($Handles['D'], LOCK_UN);
   fclose($Handles['D']);
   unset($Handles['D']);
   unlink($fileD);

   // # A vanished lease and a changed kernel boot identity both fail closed.
   flock($Handles['A'], LOCK_UN);
   fclose($Handles['A']);
   unset($Handles['A']);
   unlink($fileA);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && $Contains($changed['changes']['missing'], $fingerprintA),
      'A missing baseline lease was not rejected.',
   );
   [$fileA, $Handles['A']] = $Lease($directory, $fingerprintA, 101);

   file_put_contents($proc . '/sys/kernel/random/boot_id', "different-boot\n");
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && ($changed['failures']['boot_changed'] ?? 0) === 1,
      'A changed boot identity did not invalidate start-time evidence.',
   );
   file_put_contents($proc . '/sys/kernel/random/boot_id', $bootID . "\n");

   // # Malformed and linked terminal leases cannot be hidden as inactive data.
   $badSHA = hash('sha256', 'malformed-lease');
   $badFile = $directory . '/worker-' . $badSHA . '.lease';
   file_put_contents($badFile, "{\n");
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && ($changed['failures']['verification_failed'] ?? 0) === 1,
      'Malformed terminal lease metadata did not fail closed.',
   );
   unlink($badFile);

   $linkedSHA = hash('sha256', 'linked-lease');
   $linkedFile = $directory . '/worker-' . $linkedSHA . '.lease';
   symlink($fileB, $linkedFile);
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable'] && ($changed['failures']['verification_failed'] ?? 0) === 1,
      'A symbolic-link terminal lease did not fail closed.',
   );
   unlink($linkedFile);

   // # Unreadable/missing proc data cannot be converted into a stable result.
   unlink($proc . '/102/stat');
   $changed = $Generation->verify($baseline);
   $Check(
      !$changed['stable']
         && $Contains($changed['changes']['replaced'], $fingerprintB)
         && ($changed['failures']['process_stat_unavailable'] ?? 0) === 1,
      'Missing proc evidence did not fail closed.',
   );
   $Stat($proc, 102, 'worker two', 'S', 10, '2002');

   $invalid = $Generation->compose($baseline, $changed);
   $Check(
      ($invalid['validated'] ?? null) === false && ($invalid['stable'] ?? null) === false,
      'An unstable terminal generation composed as validated.',
   );

   return true;
}
finally {
   foreach ($Handles as $Handle) {
      if (is_resource($Handle)) {
         @flock($Handle, LOCK_UN);
         fclose($Handle);
      }
   }
   if (is_link($procLink)) {
      @unlink($procLink);
   }
   if (is_dir($root)) {
      $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
      $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($Iterator as $Child) {
         $Child->isDir() ? @rmdir($Child->getPathname()) : @unlink($Child->getPathname());
      }
      @rmdir($root);
   }
}
   },
);
