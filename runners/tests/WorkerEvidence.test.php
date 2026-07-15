<?php

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once dirname(__DIR__, 2) . '/HTTP_Server_CLI/bootables/WorkerEvidence.php';


return new Specification(
   description: 'It should prove the generic worker evidence lifecycle and lease protocol',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$Leases = static function (string $directory): array {
   $files = glob($directory . '/workers/worker-*.lease') ?: [];
   sort($files, SORT_STRING);

   return $files;
};
$Read = static function (string $file): array {
   $contents = file_get_contents($file);
   if (!is_string($contents)) {
      throw new RuntimeException('Could not read a worker-evidence lease.');
   }
   $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
   if (!is_array($metadata)) {
      throw new RuntimeException('Worker-evidence lease was not an object.');
   }

   return [$contents, $metadata];
};
$Locked = static function (string $file): bool {
   $Handle = fopen($file, 'r+b');
   if ($Handle === false) {
      throw new RuntimeException('Could not open a worker-evidence lease.');
   }
   try {
      $wouldBlock = 0;
      $acquired = flock($Handle, LOCK_EX | LOCK_NB, $wouldBlock);
      if ($acquired) {
         flock($Handle, LOCK_UN);
      }

      return !$acquired && $wouldBlock === 1;
   }
   finally {
      fclose($Handle);
   }
};
$Await = static function (string $file): string {
   $deadline = microtime(true) + 5.0;
   do {
      $contents = @file_get_contents($file);
      if (is_string($contents) && $contents !== '') {
         return $contents;
      }
      usleep(1_000);
   } while (microtime(true) < $deadline);

   throw new RuntimeException('Timed out waiting for worker-evidence child proof.');
};

$previousToken = getenv('BENCHMARK_WARMUP_TOKEN');
$previousDirectory = getenv('BENCHMARK_SERVER_DIR');
$root = sys_get_temp_dir() . '/bootgly-worker-evidence-' . bin2hex(random_bytes(12));
$children = [];
$Reflection = new ReflectionClass(WorkerEvidence::class);
$EnabledProperty = $Reflection->getProperty('enabled');
$PIDProperty = $Reflection->getProperty('PID');
$IdentityProperty = $Reflection->getProperty('identity');
$LeaseProperty = $Reflection->getProperty('lease');
$previousEnabled = $EnabledProperty->getValue();
$previousPID = $PIDProperty->getValue();
$previousIdentity = $IdentityProperty->getValue();
$previousLease = $LeaseProperty->getValue();

if (!mkdir($root, 0o700)) {
   throw new RuntimeException('Could not create the worker-evidence proof directory.');
}

try {
   $token = 'worker-evidence-proof-token';
   $nonce = str_repeat('a', 64);
   putenv('BENCHMARK_WARMUP_TOKEN');
   putenv("BENCHMARK_SERVER_DIR={$root}");
   $EnabledProperty->setValue(null, true);
   $PIDProperty->setValue(null, null);
   $IdentityProperty->setValue(null, null);
   $LeaseProperty->setValue(null, null);
   WorkerEvidence::boot();
   $disabledResponse = WorkerEvidence::identify('ignored', str_repeat('0', 64));
   $Check(
      WorkerEvidence::$enabled === false
         && $disabledResponse === null
         && $PIDProperty->getValue() === null
         && $IdentityProperty->getValue() === null
         && $LeaseProperty->getValue() === null
         && !is_dir($root . '/workers'),
      'A manual boot without a warmup token enabled or registered evidence.',
   );

   putenv("BENCHMARK_WARMUP_TOKEN={$token}");
   WorkerEvidence::$enabled = true;

   $unregisteredIdentity = WorkerEvidence::identify($token, $nonce);
   $Check(
      $unregisteredIdentity === null
         && $PIDProperty->getValue() === null
         && $IdentityProperty->getValue() === null
         && $LeaseProperty->getValue() === null
         && !is_dir($root . '/workers'),
      'Request traffic registered worker evidence before the lifecycle boot hook.',
   );

   // # The serving-worker lifecycle establishes the process lease before any
   //   ordinary request can arrive. Repeated boot calls are idempotent.
   WorkerEvidence::boot();
   WorkerEvidence::boot();
   $leases = $Leases($root);
   $Check(count($leases) === 1, 'Worker boot did not create exactly one pre-request worker lease.');
   $Check(WorkerEvidence::identify(null, null) === null, 'An absent marker token was accepted.');
   [$leaseJSON, $lease] = $Read($leases[0]);
   $Check(
      ($lease['schema'] ?? null) === 'bootgly.worker-lease'
         && ($lease['version'] ?? null) === 1
         && ($lease['pid'] ?? null) === getmypid()
         && preg_match('/\Asha256:[0-9a-f]{64}\z/D', $lease['fingerprint'] ?? '') === 1,
      'The parent worker lease metadata is invalid.',
   );
   $Check(
      (fileperms($leases[0]) & 0o777) === 0o600,
      'The worker lease permissions are not owner-only.',
   );
   $Check($Locked($leases[0]), 'The parent worker did not retain its lifetime lease lock.');

   $Check(WorkerEvidence::identify('wrong-token', $nonce) === null, 'An incorrect marker token was accepted.');
   $Check(
      WorkerEvidence::identify('wrong-token', $nonce, $token) === null && WorkerEvidence::$enabled,
      'A seal without the exact warmup token disarmed the worker.',
   );
   $Check(
      WorkerEvidence::identify($token, null, $token) === null && WorkerEvidence::$enabled,
      'A missing nonce was accepted or allowed sealing.',
   );
   foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64)] as $invalidNonce) {
      $Check(
         WorkerEvidence::identify($token, $invalidNonce, $token) === null && WorkerEvidence::$enabled,
         'A nonce outside the exact lowercase hexadecimal contract was accepted.',
      );
   }

   $identity = WorkerEvidence::identify($token, $nonce);
   $Check(is_string($identity), 'The current worker did not receive an identity.');
   $Check(WorkerEvidence::$enabled, 'A missing seal disarmed the worker.');
   $Check(
      WorkerEvidence::identify($token, $nonce, 'wrong-seal') === $identity && WorkerEvidence::$enabled,
      'An incorrect seal disarmed the worker or changed its acknowledgement.',
   );
   $Check(
      $identity === WorkerEvidence::identify($token, $nonce),
      'The identity was not stable inside one worker process.',
   );
   $Check(
      str_starts_with($identity, $token . ':' . $nonce . ':' . getmypid() . '-'),
      'The acknowledgement did not bind the token and nonce to the current process.',
   );

   $rawIdentity = substr($identity, strlen($token) + strlen($nonce) + 2);
   $SHA = hash('sha256', "worker\0{$rawIdentity}");
   $Check(
      basename($leases[0]) === "worker-{$SHA}.lease"
         && ($lease['fingerprint'] ?? null) === 'sha256:' . $SHA,
      'The lease fingerprint did not bind to the response identity.',
   );
   $Check(
      !str_contains($leaseJSON, $token)
         && !str_contains($leaseJSON, $nonce)
         && !str_contains($leaseJSON, $rawIdentity),
      'Worker lease metadata leaked the token, nonce, or raw identity.',
   );

   // # A fork must close its inherited duplicate descriptor, allocate a new
   //   identity, and hold a distinct lock while it remains alive.
   $childProof = $root . '/child.identity';
   $childRelease = $root . '/child.release';
   $PID = pcntl_fork();
   if ($PID < 0) {
      throw new RuntimeException('Could not fork the worker-evidence proof child.');
   }
   if ($PID === 0) {
      $unbootedIdentity = WorkerEvidence::identify($token, $nonce);
      $leasesBeforeBoot = array_map('basename', $Leases($root));
      WorkerEvidence::boot();
      $childIdentity = WorkerEvidence::identify($token, $nonce);
      $written = is_string($childIdentity)
         ? file_put_contents(
            $childProof,
            json_encode([
               'unbooted_identity' => $unbootedIdentity,
               'leases_before_boot' => $leasesBeforeBoot,
               'identity' => $childIdentity,
            ], JSON_THROW_ON_ERROR) . "\n",
            LOCK_EX,
         )
         : false;
      $deadline = microtime(true) + 5.0;
      while ($written !== false && !is_file($childRelease) && microtime(true) < $deadline) {
         usleep(1_000);
      }
      if ($written === false || !is_file($childRelease)) {
         exit(70);
      }
      exit(0);
   }
   $children[$PID] = true;
   $childProofData = json_decode($Await($childProof), true, flags: JSON_THROW_ON_ERROR);
   $Check(is_array($childProofData), 'The forked worker proof was not a JSON object.');
   $childIdentity = is_string($childProofData['identity'] ?? null)
      ? $childProofData['identity']
      : '';
   $Check(
      array_key_exists('unbooted_identity', $childProofData)
         && $childProofData['unbooted_identity'] === null
         && ($childProofData['leases_before_boot'] ?? null) === [basename($leases[0])],
      'A fork without its lifecycle boot inherited valid evidence or created a lease.',
   );
   $Check($childIdentity !== '' && $childIdentity !== $identity, 'A forked worker inherited the parent identity.');
   $Check(
      str_starts_with($childIdentity, $token . ':' . $nonce . ':' . $PID . '-'),
      'The forked acknowledgement did not bind to the nonce and child process.',
   );
   $childRawIdentity = substr($childIdentity, strlen($token) + strlen($nonce) + 2);
   $childFile = $root . '/workers/worker-' . hash('sha256', "worker\0{$childRawIdentity}") . '.lease';
   $Check(is_file($childFile) && $Locked($childFile), 'The live child did not retain a distinct lease lock.');
   file_put_contents($childRelease, "release\n", LOCK_EX);
   $waited = pcntl_waitpid($PID, $status);
   unset($children[$PID]);
   $Check(
      $waited === $PID && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
      'The worker-evidence proof child failed.',
   );
   $Check(!$Locked($childFile), 'A terminated worker retained its lease lock.');

   // # Exercise the framework adapters without loading either framework. Each
   //   adapter runs in a child process with minimal contract/event stubs, so the
   //   test process keeps no foreign classes and each hook must create its lease
   //   before the first marked request.
   foreach (['hyperf', 'laravel'] as $framework) {
      $hookRoot = $root . '/hook-' . $framework;
      $hookProof = $hookRoot . '/proof.json';
      $hookRelease = $hookRoot . '/release';
      if (!mkdir($hookRoot, 0o700)) {
         throw new RuntimeException("Could not create the {$framework} hook proof directory.");
      }

      $hookPID = pcntl_fork();
      if ($hookPID < 0) {
         throw new RuntimeException("Could not fork the {$framework} hook proof child.");
      }
      if ($hookPID === 0) {
         try {
            putenv("BENCHMARK_SERVER_DIR={$hookRoot}");
            WorkerEvidence::$enabled = true;
            $beforeIdentity = WorkerEvidence::identify($token, $nonce);
            $beforeLeases = $Leases($hookRoot);
            $listens = null;
            $wrongEventLeases = null;
            $taskWorkerLeases = null;

            if ($framework === 'hyperf') {
               if (!interface_exists(\Hyperf\Event\Contract\ListenerInterface::class)) {
                  eval(<<<'PHP'
namespace Hyperf\Event\Contract;
interface ListenerInterface
{
   public function listen(): array;
   public function process(object $Event): void;
}
PHP);
               }
               if (!class_exists(\Hyperf\Framework\Event\AfterWorkerStart::class)) {
                  eval(<<<'PHP'
namespace Hyperf\Framework\Event;
final class AfterWorkerStart
{
   public function __construct(public object $server, public int $workerId) {}
}
PHP);
               }

               require_once dirname(__DIR__, 2)
                  . '/HTTP_Server_CLI/bootables/hyperf/app/WorkerEvidenceListener.php';
               $Listener = new \App\WorkerEvidenceListener;
               $listens = $Listener->listen();
               $Listener->process(new stdClass);
               $wrongEventLeases = $Leases($hookRoot);
               if (class_exists(\Swoole\Server::class)) {
                  $ServerClass = new ReflectionClass(\Swoole\Server::class);
                  $TaskServer = $ServerClass->newInstanceWithoutConstructor();
                  $ServingServer = $ServerClass->newInstanceWithoutConstructor();
               }
               else {
                  $TaskServer = new stdClass;
                  $ServingServer = new stdClass;
               }
               $TaskServer->taskworker = true;
               $ServingServer->taskworker = false;
               $Listener->process(new \Hyperf\Framework\Event\AfterWorkerStart($TaskServer, 1));
               $taskWorkerLeases = $Leases($hookRoot);
               $Listener->process(new \Hyperf\Framework\Event\AfterWorkerStart($ServingServer, 0));
            }
            else {
               if (!class_exists(\Laravel\Octane\Events\WorkerStarting::class)) {
                  eval('namespace Laravel\Octane\Events; final class WorkerStarting {}');
               }

               require_once dirname(__DIR__, 2)
                  . '/HTTP_Server_CLI/bootables/laravel/app/Listeners/WorkerEvidenceListener.php';
               $Listener = new \App\Listeners\WorkerEvidenceListener;
               $EventClass = new ReflectionClass(\Laravel\Octane\Events\WorkerStarting::class);
               $Event = $EventClass->newInstanceWithoutConstructor();
               $Listener->handle($Event);
            }

            $registeredLeases = $Leases($hookRoot);
            $hookIdentity = WorkerEvidence::identify($token, $nonce);
            $written = file_put_contents(
               $hookProof,
               json_encode([
                  'before_identity' => $beforeIdentity,
                  'before_leases' => $beforeLeases,
                  'listens' => $listens,
                  'wrong_event_leases' => $wrongEventLeases,
                  'task_worker_leases' => $taskWorkerLeases,
                  'registered_leases' => array_map('basename', $registeredLeases),
                  'identity' => $hookIdentity,
               ], JSON_THROW_ON_ERROR) . "\n",
               LOCK_EX,
            );
            $deadline = microtime(true) + 5.0;
            while ($written !== false && !is_file($hookRelease) && microtime(true) < $deadline) {
               usleep(1_000);
            }
            exit($written !== false && is_file($hookRelease) ? 0 : 72);
         }
         catch (Throwable $Throwable) {
            @file_put_contents(
               $hookProof,
               json_encode(['error' => $Throwable->getMessage()], JSON_THROW_ON_ERROR) . "\n",
               LOCK_EX,
            );
            exit(73);
         }
      }

      $children[$hookPID] = true;
      $hookData = json_decode($Await($hookProof), true, flags: JSON_THROW_ON_ERROR);
      $Check(
         is_array($hookData) && !isset($hookData['error']),
         "The {$framework} lifecycle hook failed: " . (string) ($hookData['error'] ?? 'invalid proof'),
      );
      $hookLeases = $Leases($hookRoot);
      $hookIdentity = is_string($hookData['identity'] ?? null)
         ? $hookData['identity']
         : '';
      $expectedListens = $framework === 'hyperf'
         ? [\Hyperf\Framework\Event\AfterWorkerStart::class]
         : null;
      $Check(
         array_key_exists('before_identity', $hookData)
            && $hookData['before_identity'] === null
            && ($hookData['before_leases'] ?? null) === []
            && ($hookData['listens'] ?? null) === $expectedListens
            && ($framework === 'laravel'
               ? ($hookData['wrong_event_leases'] ?? null) === null
               : ($hookData['wrong_event_leases'] ?? null) === [])
            && ($framework === 'laravel'
               ? ($hookData['task_worker_leases'] ?? null) === null
               : ($hookData['task_worker_leases'] ?? null) === [])
            && count($hookLeases) === 1
            && ($hookData['registered_leases'] ?? null) === [basename($hookLeases[0])]
            && $Locked($hookLeases[0])
            && str_starts_with(
               $hookIdentity,
               $token . ':' . $nonce . ':' . $hookPID . '-',
            ),
         "The {$framework} hook did not register exactly one worker before request evidence.",
      );
      file_put_contents($hookRelease, "release\n", LOCK_EX);
      $waited = pcntl_waitpid($hookPID, $status);
      unset($children[$hookPID]);
      $Check(
         $waited === $hookPID && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
         "The {$framework} lifecycle hook proof child failed.",
      );
      $Check(!$Locked($hookLeases[0]), "The {$framework} hook child retained its lease lock.");
   }

   $sealedIdentity = WorkerEvidence::identify($token, $nonce, $token);
   $Check($sealedIdentity === $identity, 'The exact seal did not return the normal acknowledgement.');
   $Check(WorkerEvidence::$enabled === false, 'The exact paired seal did not disarm the worker.');
   $sealedLease = $LeaseProperty->getValue();
   $registeredIdentity = $IdentityProperty->getValue();
   $leaseFilesBeforeBoot = $Leases($root);
   WorkerEvidence::boot();
   $Check(
      WorkerEvidence::$enabled === false
         && $LeaseProperty->getValue() === $sealedLease
         && $IdentityProperty->getValue() === $registeredIdentity
         && $Leases($root) === $leaseFilesBeforeBoot
         && $Locked($leases[0]),
      'A same-PID lifecycle boot rearmed the sealed worker or replaced its identity/lease.',
   );
   $Check(
      WorkerEvidence::identify($token, $nonce) === null,
      'A normal warmup request was acknowledged after the worker was sealed.',
   );
   $Check($Locked($leases[0]), 'Sealing released the parent worker lifetime lease.');

   // # Model a replacement forked from a sealed serving process. Its lifecycle
   //   boot must reactivate evidence, close the inherited lease duplicate, and
   //   register a new lock before its first ordinary request.
   $replacementProof = $root . '/replacement.ready';
   $replacementRelease = $root . '/replacement.release';
   $replacementPID = pcntl_fork();
   if ($replacementPID < 0) {
      throw new RuntimeException('Could not fork the replacement proof child.');
   }
   if ($replacementPID === 0) {
      WorkerEvidence::boot();
      $unmarked = WorkerEvidence::$enabled
         ? WorkerEvidence::identify(null, null)
         : null;
      $written = file_put_contents(
         $replacementProof,
         ($unmarked === null ? 'ready' : 'marked') . "\n",
         LOCK_EX,
      );
      $deadline = microtime(true) + 5.0;
      while ($written !== false && !is_file($replacementRelease) && microtime(true) < $deadline) {
         usleep(1_000);
      }
      if ($written === false || !is_file($replacementRelease)) {
         exit(71);
      }
      exit(0);
   }
   $children[$replacementPID] = true;
   $Check($Await($replacementProof) === "ready\n", 'Ordinary replacement traffic emitted a marker response.');
   $replacementFile = null;
   foreach ($Leases($root) as $file) {
      [, $metadata] = $Read($file);
      if (($metadata['pid'] ?? null) === $replacementPID) {
         $replacementFile = $file;
         break;
      }
   }
   $Check(
      is_string($replacementFile) && $Locked($replacementFile),
      'An unmarked replacement request did not create a live distinct lease.',
   );
   file_put_contents($replacementRelease, "release\n", LOCK_EX);
   $waited = pcntl_waitpid($replacementPID, $status);
   unset($children[$replacementPID]);
   $Check(
      $waited === $replacementPID && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
      'The replacement proof child failed.',
   );
   $Check(!$Locked($replacementFile), 'A terminated replacement retained its lease lock.');
}
finally {
   foreach (array_keys($children) as $PID) {
      @posix_kill($PID, SIGKILL);
      pcntl_waitpid($PID, $status);
   }
   $currentLease = $LeaseProperty->getValue();
   if (is_resource($currentLease) && $currentLease !== $previousLease) {
      fclose($currentLease);
   }
   $LeaseProperty->setValue(null, $previousLease);
   $IdentityProperty->setValue(null, $previousIdentity);
   $PIDProperty->setValue(null, $previousPID);
   $EnabledProperty->setValue(null, $previousEnabled);
   $previousToken === false
      ? putenv('BENCHMARK_WARMUP_TOKEN')
      : putenv("BENCHMARK_WARMUP_TOKEN={$previousToken}");
   $previousDirectory === false
      ? putenv('BENCHMARK_SERVER_DIR')
      : putenv("BENCHMARK_SERVER_DIR={$previousDirectory}");

   if (is_dir($root)) {
      $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
      $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($Iterator as $Child) {
         $Child->isDir() ? @rmdir($Child->getPathname()) : @unlink($Child->getPathname());
      }
      @rmdir($root);
   }
}

return true;
   },
);
