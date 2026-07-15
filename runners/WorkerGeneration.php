<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — measured-window worker generation evidence
 * --------------------------------------------------------------------------
 * Binds the worker fingerprints proved by WorkerWarmup to lifetime file locks
 * and Linux /proc process generations immediately before and after measurement.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Bootgly\Benchmarks\Runners;


final class WorkerGenerationFailure extends \RuntimeException
{
   /** @param array<string,mixed> $evidence */
   public function __construct (
      string $message,
      public readonly array $evidence = [],
   )
   {
      parent::__construct($message);
   }
}


/**
 * @phpstan-type Process array{
 *    state:string,
 *    start_ticks:string,
 *    ppid:int,
 *    parent_state:string,
 *    parent_start_ticks:string
 * }
 * @phpstan-type Lease array{
 *    fingerprint:string,
 *    file:string,
 *    pid:int,
 *    locked:bool,
 *    process:?Process,
 *    error:?string
 * }
 * @phpstan-type Worker array{
 *    fingerprint:string,
 *    file:string,
 *    pid:int,
 *    state:string,
 *    start_ticks:string,
 *    ppid:int,
 *    parent_state:string,
 *    parent_start_ticks:string
 * }
 */
final class WorkerGeneration
{
   public function __construct (private readonly string $proc = '/proc')
   {
      if ($this->proc === '' || !is_dir($this->proc) || is_link($this->proc)) {
         throw new \InvalidArgumentException('Worker generation proc root must be a real directory.');
      }
   }

   /**
    * Capture the exact locked lease set proved by the warmup immediately before
    * measurement.
    *
    * @param list<string> $workers SHA-256 worker fingerprints from WorkerWarmup.
    * @return array<string,mixed> Internal baseline consumed by verify().
    */
   public function capture (string $directory, array $workers): array
   {
      $expected = $this->normalize($workers);
      if ($expected === []) {
         throw new \InvalidArgumentException('Worker generation requires at least one fingerprint.');
      }

      $base = [
         'schema' => 'bootgly.worker-generation-baseline',
         'version' => 1,
         'directory' => $directory,
         'captured_ns' => hrtime(true),
         'boot_id_sha256' => null,
         'expected' => $expected,
         'observed' => [],
         'workers' => [],
         'changes' => [
            'missing' => [],
            'unlocked' => [],
            'added' => [],
         ],
         'failures' => [],
      ];

      try {
         $base['boot_id_sha256'] = $this->boot();
         $scan = $this->scan($directory);
         $base['observed'] = array_keys($scan);

         $active = [];
         foreach ($scan as $fingerprint => $lease) {
            if ($lease['locked'] !== true) {
               continue;
            }
            if (!is_array($lease['process'] ?? null)) {
               $failure = is_string($lease['error'] ?? null)
                  ? $lease['error']
                  : 'process_invalid';
               $base['failures'][$failure] = 1;
               continue;
            }
            $active[$fingerprint] = $this->export($lease);
         }

         $base['changes']['missing'] = array_values(array_diff($expected, array_keys($scan)));
         $base['changes']['unlocked'] = array_values(array_filter(
            $expected,
            static fn (string $fingerprint): bool => isset($scan[$fingerprint])
               && $scan[$fingerprint]['locked'] !== true,
         ));
         $base['changes']['added'] = array_values(array_diff(array_keys($active), $expected));
         sort($base['changes']['missing'], SORT_STRING);
         sort($base['changes']['unlocked'], SORT_STRING);
         sort($base['changes']['added'], SORT_STRING);

         foreach ($expected as $fingerprint) {
            if (isset($active[$fingerprint])) {
               $base['workers'][] = $active[$fingerprint];
            }
         }

         if (
            $base['changes']['missing'] !== []
            || $base['changes']['unlocked'] !== []
            || $base['changes']['added'] !== []
            || $base['failures'] !== []
            || count($base['workers']) !== count($expected)
         ) {
            throw new WorkerGenerationFailure(
               'Worker generation baseline does not match the sealed warmup set.',
               $this->sanitize($base),
            );
         }

         return $base;
      }
      catch (WorkerGenerationFailure $Failure) {
         throw $Failure;
      }
      catch (\Throwable $Throwable) {
         $base['failures']['capture_failed'] = 1;
         $base['error'] = $Throwable->getMessage();

         throw new WorkerGenerationFailure(
            'Worker generation baseline could not be captured.',
            $this->sanitize($base),
         );
      }
   }

   /**
    * Compare the terminal locked/process set with a successful baseline.
    * Generation changes are returned as evidence rather than thrown so callers
    * can persist the reason while withholding the measured Result.
    *
    * @param array<string,mixed> $baseline Successful output from capture().
    * @return array<string,mixed>
    */
   public function verify (array $baseline): array
   {
      if (
         ($baseline['schema'] ?? null) !== 'bootgly.worker-generation-baseline'
         || ($baseline['version'] ?? null) !== 1
         || !is_string($baseline['directory'] ?? null)
         || !is_array($baseline['expected'] ?? null)
         || !is_array($baseline['observed'] ?? null)
         || !is_array($baseline['workers'] ?? null)
         || !is_string($baseline['boot_id_sha256'] ?? null)
      ) {
         throw new \InvalidArgumentException('Worker generation baseline is invalid.');
      }

      $expected = $this->normalize($baseline['expected']);
      $before = [];
      foreach ($baseline['workers'] as $worker) {
         if (!is_array($worker) || !is_string($worker['fingerprint'] ?? null)) {
            throw new \InvalidArgumentException('Worker generation baseline worker is invalid.');
         }
         $before[$worker['fingerprint']] = $worker;
      }
      ksort($before, SORT_STRING);
      if (array_keys($before) !== $expected) {
         throw new \InvalidArgumentException('Worker generation baseline worker set is incomplete.');
      }

      $terminal = [
         'schema' => 'bootgly.worker-generation-terminal',
         'version' => 1,
         'captured_ns' => hrtime(true),
         'boot_id_sha256' => null,
         'observed' => [],
         'workers' => [],
         'changes' => [
            'missing' => [],
            'unlocked' => [],
            'replaced' => [],
            'reparented' => [],
            'parent_changed' => [],
            'added' => [],
         ],
         'failures' => [],
         'stable' => false,
      ];

      try {
         $terminal['boot_id_sha256'] = $this->boot();
         if (!hash_equals($baseline['boot_id_sha256'], $terminal['boot_id_sha256'])) {
            $terminal['failures']['boot_changed'] = 1;
         }

         $scan = $this->scan($baseline['directory']);
         $terminal['observed'] = array_keys($scan);
         $terminal['changes']['missing'] = array_values(array_diff($expected, array_keys($scan)));

         $new = array_values(array_diff($terminal['observed'], $baseline['observed']));
         foreach ($scan as $fingerprint => $lease) {
            if ($lease['locked'] === true && !isset($before[$fingerprint])) {
               $new[] = $fingerprint;
            }
         }
         $terminal['changes']['added'] = array_values(array_unique($new));

         foreach ($expected as $fingerprint) {
            $lease = $scan[$fingerprint] ?? null;
            if (!is_array($lease)) {
               continue;
            }
            if ($lease['locked'] !== true) {
               $terminal['changes']['unlocked'][] = $fingerprint;
               continue;
            }
            if (!is_array($lease['process'] ?? null)) {
               $terminal['changes']['replaced'][] = $fingerprint;
               $failure = is_string($lease['error'] ?? null)
                  ? $lease['error']
                  : 'process_invalid';
               $terminal['failures'][$failure] = ($terminal['failures'][$failure] ?? 0) + 1;
               continue;
            }

            $after = $this->export($lease);
            $terminal['workers'][] = $after;
            $worker = $before[$fingerprint];
            if (
               ($worker['pid'] ?? null) !== $after['pid']
               || ($worker['start_ticks'] ?? null) !== $after['start_ticks']
            ) {
               $terminal['changes']['replaced'][] = $fingerprint;
            }
            if (($worker['ppid'] ?? null) !== $after['ppid']) {
               $terminal['changes']['reparented'][] = $fingerprint;
            }
            if (
               ($worker['parent_start_ticks'] ?? null) !== $after['parent_start_ticks']
            ) {
               $terminal['changes']['parent_changed'][] = $fingerprint;
            }
         }

         foreach ($terminal['changes'] as &$changes) {
            $changes = array_values(array_unique($changes));
            sort($changes, SORT_STRING);
         }
         unset($changes);
         ksort($terminal['failures'], SORT_STRING);
         usort(
            $terminal['workers'],
            static fn (array $left, array $right): int => $left['fingerprint'] <=> $right['fingerprint'],
         );

         $terminal['stable'] = $terminal['failures'] === [];
         foreach ($terminal['changes'] as $changes) {
            $terminal['stable'] = $terminal['stable'] && $changes === [];
         }

         return $terminal;
      }
      catch (\Throwable $Throwable) {
         $terminal['failures']['verification_failed'] = 1;
         $terminal['error'] = $Throwable->getMessage();

         return $terminal;
      }
   }

   /**
    * Compose one persistable, token-free generation document.
    *
    * @param array<string,mixed> $baseline Successful output from capture().
    * @param array<string,mixed> $terminal Output from verify().
    * @param array<string,mixed> $context Runner-owned opponent/load context.
    * @return array<string,mixed>
    */
   public function compose (array $baseline, array $terminal, array $context = []): array
   {
      if (
         ($baseline['schema'] ?? null) !== 'bootgly.worker-generation-baseline'
         || ($terminal['schema'] ?? null) !== 'bootgly.worker-generation-terminal'
         || !is_array($baseline['expected'] ?? null)
         || !is_array($terminal['changes'] ?? null)
         || !is_array($terminal['failures'] ?? null)
      ) {
         throw new \InvalidArgumentException('Worker generation evidence inputs are invalid.');
      }

      return [
         'schema' => 'bootgly.worker-generation',
         'version' => 1,
         'validated' => ($terminal['stable'] ?? false) === true,
         'stable' => ($terminal['stable'] ?? false) === true,
         'context' => $context,
         'workers_expected' => count($baseline['expected']),
         'baseline' => $this->sanitize($baseline),
         'terminal' => $this->sanitize($terminal),
         'changes' => $terminal['changes'],
         'failures' => $terminal['failures'],
      ];
   }

   /**
    * @param array<array-key,mixed> $workers
    * @return list<string>
    */
   private function normalize (array $workers): array
   {
      $normalized = [];
      foreach ($workers as $fingerprint) {
         if (
            !is_string($fingerprint)
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $fingerprint) !== 1
         ) {
            throw new \InvalidArgumentException('Worker generation fingerprint is invalid.');
         }
         $normalized[$fingerprint] = true;
      }
      $normalized = array_keys($normalized);
      sort($normalized, SORT_STRING);

      return $normalized;
   }

   /** @return array<string,Lease> Fingerprint-keyed lease state. */
   private function scan (string $directory): array
   {
      if ($directory === '' || !is_dir($directory) || is_link($directory)) {
         throw new \RuntimeException('Worker lease directory is unavailable or unsafe.');
      }

      $names = scandir($directory);
      if ($names === false) {
         throw new \RuntimeException('Worker lease directory could not be read.');
      }

      $leases = [];
      foreach ($names as $name) {
         if ($name === '.' || $name === '..') {
            continue;
         }
         if (preg_match('/\Aworker-([0-9a-f]{64})\.lease\z/D', $name, $matches) !== 1) {
            throw new \RuntimeException('Worker lease directory contains an unexpected entry.');
         }

         $fingerprint = 'sha256:' . $matches[1];
         $file = rtrim($directory, DIRECTORY_SEPARATOR) . '/' . $name;
         $lease = $this->probe($file, $name, $fingerprint);
         if ($lease['locked'] === true) {
            try {
               $lease['process'] = $this->inspect($lease['pid']);
               $lease['error'] = null;
            }
            catch (\Throwable $Throwable) {
               $lease['process'] = null;
               $lease['error'] = $Throwable->getMessage();
            }
         }
         else {
            $lease['process'] = null;
            $lease['error'] = null;
         }
         $leases[$fingerprint] = $lease;
      }
      ksort($leases, SORT_STRING);

      return $leases;
   }

   /**
    * Read and lock-probe one race-checked regular lease file.
    *
    * @return array{fingerprint:string,file:string,pid:int,locked:bool}
    */
   private function probe (string $file, string $name, string $fingerprint): array
   {
      if (is_link($file)) {
         throw new \RuntimeException('Worker lease must not be a symbolic link.');
      }
      $before = @lstat($file);
      $Handle = @fopen($file, 'r+b');
      if ($Handle === false) {
         throw new \RuntimeException('Worker lease could not be opened.');
      }

      try {
         $opened = fstat($Handle);
         $after = @lstat($file);
         if (
            !is_array($before)
            || !is_array($opened)
            || !is_array($after)
            || ($opened['mode'] & 0o170000) !== 0o100000
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || $after['dev'] !== $opened['dev']
            || $after['ino'] !== $opened['ino']
         ) {
            throw new \RuntimeException('Worker lease changed while being opened.');
         }

         $contents = stream_get_contents($Handle, 65_537, 0);
         if (!is_string($contents) || strlen($contents) > 65_536) {
            throw new \RuntimeException('Worker lease metadata is unavailable or oversized.');
         }
         try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
         }
         catch (\JsonException) {
            throw new \RuntimeException('Worker lease metadata is not valid JSON.');
         }
         if (
            !is_array($metadata)
            || ($metadata['schema'] ?? null) !== 'bootgly.worker-lease'
            || ($metadata['version'] ?? null) !== 1
            || ($metadata['fingerprint'] ?? null) !== $fingerprint
            || !is_int($metadata['pid'] ?? null)
            || $metadata['pid'] < 2
         ) {
            throw new \RuntimeException('Worker lease metadata is invalid.');
         }

         $wouldBlock = 0;
         $acquired = @flock($Handle, LOCK_EX | LOCK_NB, $wouldBlock);
         if ($acquired) {
            flock($Handle, LOCK_UN);
         }
         elseif ($wouldBlock !== 1) {
            throw new \RuntimeException('Worker lease lock state could not be established.');
         }

         return [
            'fingerprint' => $fingerprint,
            'file' => $name,
            'pid' => $metadata['pid'],
            'locked' => !$acquired,
         ];
      }
      finally {
         fclose($Handle);
      }
   }

   /**
    * Read one live worker plus its current parent process generation.
    *
    * @return Process
    */
   private function inspect (int $PID): array
   {
      $process = $this->read($PID);
      if (in_array($process['state'], ['X', 'x', 'Z'], true)) {
         throw new \RuntimeException('worker_not_live');
      }
      if ($process['ppid'] < 1) {
         throw new \RuntimeException('worker_parent_invalid');
      }

      $parent = $this->read($process['ppid']);
      if (in_array($parent['state'], ['X', 'x', 'Z'], true)) {
         throw new \RuntimeException('parent_not_live');
      }

      return [
         'state' => $process['state'],
         'start_ticks' => $process['start_ticks'],
         'ppid' => $process['ppid'],
         'parent_state' => $parent['state'],
         'parent_start_ticks' => $parent['start_ticks'],
      ];
   }

   /** @return array{state:string,ppid:int,start_ticks:string} */
   private function read (int $PID): array
   {
      if ($PID < 1) {
         throw new \RuntimeException('process_pid_invalid');
      }
      $stat = @file_get_contents($this->proc . '/' . $PID . '/stat');
      if (!is_string($stat) || $stat === '' || strlen($stat) > 65_536) {
         throw new \RuntimeException('process_stat_unavailable');
      }

      $open = strpos($stat, ' (');
      $close = strrpos($stat, ') ');
      if ($open === false || $close === false || $close <= $open + 2) {
         throw new \RuntimeException('process_stat_malformed');
      }
      $recorded = substr($stat, 0, $open);
      $fields = preg_split('/\s+/', trim(substr($stat, $close + 2)));
      if (
         $recorded !== (string) $PID
         || !is_array($fields)
         || count($fields) < 20
         || preg_match('/\A[A-Za-z]\z/D', $fields[0]) !== 1
         || !ctype_digit($fields[1])
         || !ctype_digit($fields[19])
      ) {
         throw new \RuntimeException('process_stat_malformed');
      }

      return [
         'state' => $fields[0],
         'ppid' => (int) $fields[1],
         'start_ticks' => $fields[19],
      ];
   }

   /** Hash the kernel boot ID so start ticks cannot be compared across boots. */
   private function boot (): string
   {
      $value = @file_get_contents($this->proc . '/sys/kernel/random/boot_id');
      $value = is_string($value) ? trim($value) : '';
      if ($value === '' || strlen($value) > 256 || str_contains($value, "\0")) {
         throw new \RuntimeException('Kernel boot identity is unavailable.');
      }

      return 'sha256:' . hash('sha256', $value);
   }

   /**
    * Flatten one live lease into persistable process evidence.
    *
    * @param array{fingerprint:string,file:string,pid:int,process:Process} $lease
    * @return Worker
    */
   private function export (array $lease): array
   {
      $process = $lease['process'];

      return [
         'fingerprint' => $lease['fingerprint'],
         'file' => $lease['file'],
         'pid' => $lease['pid'],
         'state' => $process['state'],
         'start_ticks' => $process['start_ticks'],
         'ppid' => $process['ppid'],
         'parent_state' => $process['parent_state'],
         'parent_start_ticks' => $process['parent_start_ticks'],
      ];
   }

   /**
    * Remove internal path/state fields before evidence is persisted.
    *
    * @param array<string,mixed> $data
    * @return array<string,mixed>
    */
   private function sanitize (array $data): array
   {
      unset($data['directory'], $data['expected']);

      return $data;
   }
}
