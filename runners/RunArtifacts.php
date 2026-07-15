<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Per-invocation artifacts
 * --------------------------------------------------------------------------
 * Creates one exclusive directory for each runner subprocess invocation and
 * keeps its input, stdout, stderr and exit metadata together. When the harness
 * exports BENCHMARK_RUN_DIR, every artifact remains inside that run workspace.
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;

use RuntimeException;


final class RunArtifacts
{
   private const GROUP_LAUNCHER = <<<'PHP'
$command = array_slice($argv, 1);
$executable = array_shift($command);
$ready = getenv('BOOTGLY_PROCESS_GROUP_READY');

if (!is_string($executable) || $executable === '' || !is_string($ready) || $ready === '') {
   fwrite(STDERR, "Invalid isolated benchmark subprocess launch.\n");
   exit(125);
}
if (posix_setsid() < 0) {
   fwrite(STDERR, "Could not create isolated benchmark process group.\n");
   exit(125);
}

$temporary = $ready . '.tmp';
if (file_put_contents($temporary, (string) getmypid(), LOCK_EX) === false || !rename($temporary, $ready)) {
   fwrite(STDERR, "Could not acknowledge isolated benchmark process group.\n");
   exit(125);
}

putenv('BOOTGLY_PROCESS_GROUP_READY');
unset($_ENV['BOOTGLY_PROCESS_GROUP_READY'], $_SERVER['BOOTGLY_PROCESS_GROUP_READY']);
pcntl_exec($executable, $command);
fwrite(STDERR, "Could not execute isolated benchmark subprocess.\n");
exit(127);
PHP;

   public readonly string $directory;
   public readonly bool $scoped;


   private function __construct (string $directory, bool $scoped)
   {
      $this->directory = $directory;
      $this->scoped = $scoped;
   }

   public static function create (string $scope): self
   {
      $scope = \preg_replace('/[^a-z0-9_-]+/i', '-', $scope) ?? 'runner';
      $scope = \trim($scope, '-');
      $scope = $scope !== '' ? \strtolower($scope) : 'runner';
      $runDirectory = \getenv('BENCHMARK_RUN_DIR');
      $scoped = \is_string($runDirectory) && $runDirectory !== '';
      $root = $scoped
         ? \rtrim($runDirectory, \DIRECTORY_SEPARATOR) . '/runners/' . $scope
         : \sys_get_temp_dir() . '/bootgly-benchmark-runners/' . $scope;

      if (!\is_dir($root) && !@\mkdir($root, 0o755, true) && !\is_dir($root)) {
         throw new RuntimeException("Could not create benchmark artifact root: {$root}");
      }

      for ($attempt = 0; $attempt < 16; $attempt++) {
         $ID = \getmypid() . '-' . \bin2hex(\random_bytes(16));
         $directory = $root . '/' . $ID;

         if (@\mkdir($directory, 0o755)) {
            return new self($directory, $scoped);
         }

         if (!\is_dir($directory)) {
            throw new RuntimeException("Could not create benchmark invocation directory: {$directory}");
         }
      }

      throw new RuntimeException("Could not allocate a unique benchmark invocation under: {$root}");
   }

   public function resolve (string $name): string
   {
      if (
         $name === ''
         || $name[0] === '/'
         || \str_contains($name, "\0")
         || \preg_match('#(?:^|/)\.\.(?:/|$)#', $name) === 1
      ) {
         throw new RuntimeException('Invalid benchmark artifact name.');
      }

      return $this->directory . '/' . $name;
   }

   public function write (string $name, string $contents): string
   {
      $file = $this->resolve($name);
      self::commit($file, $contents);

      return $file;
   }

   public static function commit (string $file, string $contents): void
   {
      $parent = \dirname($file);

      if (!\is_dir($parent) && !@\mkdir($parent, 0o755, true) && !\is_dir($parent)) {
         throw new RuntimeException("Could not create benchmark artifact directory: {$parent}");
      }

      do {
         $temporary = $file . '.tmp-' . \bin2hex(\random_bytes(12));
         $handle = @\fopen($temporary, 'x+b');
      } while ($handle === false && \file_exists($temporary));

      if ($handle === false) {
         throw new RuntimeException("Could not create temporary benchmark artifact: {$temporary}");
      }

      $committed = false;

      try {
         $length = \strlen($contents);
         $offset = 0;

         while ($offset < $length) {
            $written = \fwrite($handle, \substr($contents, $offset));

            if ($written === false || $written === 0) {
               throw new RuntimeException("Could not write benchmark artifact: {$temporary}");
            }

            $offset += $written;
         }

         if (!\fflush($handle)) {
            throw new RuntimeException("Could not flush benchmark artifact: {$temporary}");
         }
         if (\function_exists('fsync') && !\fsync($handle)) {
            throw new RuntimeException("Could not sync benchmark artifact: {$temporary}");
         }

         \fclose($handle);
         $handle = null;

         if (!@\rename($temporary, $file)) {
            throw new RuntimeException("Could not commit benchmark artifact: {$file}");
         }

         $committed = true;
      }
      finally {
         if (\is_resource($handle)) {
            \fclose($handle);
         }
         if (!$committed) {
            @\unlink($temporary);
         }
      }
   }

   /**
    * @param list<string> $command
    * @param array<string,string|null> $environment
    */
   public function start (array $command, array $environment = []): RunProcess
   {
      if ($command === []) {
         throw new RuntimeException('Benchmark subprocess command cannot be empty.');
      }

      foreach ($command as $argument) {
         if (!\is_string($argument)) {
            throw new RuntimeException('Benchmark subprocess arguments must be strings.');
         }
      }

      $capture = $this->resolve('process.capture');
      $output = $this->resolve('process');

      if (!@\mkdir($capture, 0o755)) {
         throw new RuntimeException("Could not create benchmark process capture directory: {$capture}");
      }

      $stdoutCapture = "{$capture}/stdout.log";
      $stderrCapture = "{$capture}/stderr.log";
      $groupReady = "{$capture}/.group.ready";
      $stdout = "{$output}/stdout.log";
      $stderr = "{$output}/stderr.log";
      $descriptors = [
         0 => ['file', '/dev/null', 'r'],
         1 => ['file', $stdoutCapture, 'xb'],
         2 => ['file', $stderrCapture, 'xb'],
      ];
      $inherited = \getenv();
      $inherited = \is_array($inherited) ? $inherited : [];

      foreach ($environment as $name => $value) {
         if ($value === null) {
            unset($inherited[$name]);
         }
         else {
            $inherited[$name] = $value;
         }
      }

      $inherited['BENCHMARK_INVOCATION_DIR'] = $this->directory;
      $inherited['BOOTGLY_PROCESS_GROUP_READY'] = $groupReady;
      $processCommand = self::isolate($command);
      $process = @\proc_open(
         $processCommand,
         $descriptors,
         $pipes,
         null,
         $inherited,
         ['bypass_shell' => true]
      );

      if (!\is_resource($process)) {
         self::commit($stdoutCapture, '');
         self::commit($stderrCapture, "Could not start benchmark subprocess.\n");

         if (\file_exists($output) || !@\rename($capture, $output)) {
            throw new RuntimeException("Could not publish failed benchmark process capture: {$output}");
         }

         $metadata = \json_encode([
            'command' => self::redact($command),
            'state' => 'start-failed',
            'exit' => 127,
            'pid' => null,
            'timed_out' => false,
            'signal' => null,
         ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
         $this->write('status.json', $metadata . "\n");

         throw new RuntimeException('Could not start benchmark subprocess.');
      }

      $status = \proc_get_status($process);
      $PID = \is_array($status) && isset($status['pid']) ? (int) $status['pid'] : null;
      $groupDeadline = \microtime(true) + 2.0;
      $groupStarted = false;

      do {
         if (
            $PID !== null
            && \is_file($groupReady)
            && \trim((string) @\file_get_contents($groupReady)) === (string) $PID
         ) {
            $groupStarted = true;
            break;
         }

         $status = \proc_get_status($process);
         if (!\is_array($status) || !$status['running']) {
            break;
         }

         \usleep(1_000);
      } while (\microtime(true) < $groupDeadline);

      @\unlink($groupReady . '.tmp');
      @\unlink($groupReady);

      if (!$groupStarted || $PID === null) {
         @\proc_terminate($process, 9);
         $closedExit = \proc_close($process);

         if (\file_exists($output) || !@\rename($capture, $output)) {
            throw new RuntimeException("Could not publish failed benchmark process capture: {$output}");
         }

         $metadata = \json_encode([
            'command' => self::redact($command),
            'state' => 'process-group-failed',
            'exit' => $closedExit >= 0 ? $closedExit : 125,
            'pid' => $PID,
            'process_group' => null,
            'timed_out' => false,
            'signal' => null,
         ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
         $this->write('status.json', $metadata . "\n");

         throw new RuntimeException('Could not establish isolated benchmark process group.');
      }

      return new RunProcess(
         Artifacts: $this,
         process: $process,
         command: $command,
         capture: $capture,
         output: $output,
         stdout: $stdout,
         stderr: $stderr,
         PGID: $PID,
      );
   }

   /** @param list<string> $command @return list<string> */
   public static function isolate (array $command): array
   {
      foreach (['pcntl_exec', 'posix_kill', 'posix_setsid'] as $function) {
         if (!\function_exists($function)) {
            throw new RuntimeException("Benchmark process-group isolation requires {$function}().");
         }
      }

      return [
         \PHP_BINARY,
         '-r',
         self::GROUP_LAUNCHER,
         '--',
         ...$command,
      ];
   }

   /** @param list<string> $arguments @return list<string> */
   public static function redact (array $arguments): array
   {
      $redacted = [];
      $hideNext = false;

      foreach ($arguments as $index => $argument) {
         if ($hideNext) {
            $redacted[] = '<redacted>';
            $hideNext = false;
            continue;
         }

         if (\preg_match('#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^@\s/]+@#i', $argument) === 1) {
            $equals = \strpos($argument, '=');
            $redacted[] = $equals === false
               ? '<redacted-uri>'
               : \substr($argument, 0, $equals + 1) . '<redacted-uri>';
            continue;
         }
         if (\preg_match('/\A(--?[A-Za-z0-9][A-Za-z0-9._-]*)=.*/', $argument, $matches) === 1) {
            $redacted[] = $matches[1] . '=<redacted>';
            continue;
         }
         if (\preg_match('/\A([A-Za-z_][A-Za-z0-9_.-]*)=.*/', $argument, $matches) === 1) {
            $redacted[] = $matches[1] . '=<redacted>';
            continue;
         }
         if (\preg_match('/\A--?[A-Za-z0-9][A-Za-z0-9._-]*\z/', $argument) === 1) {
            $redacted[] = $argument;
            $next = $arguments[$index + 1] ?? null;
            $hideNext = \is_string($next) && !\str_starts_with($next, '-');
            continue;
         }

         $redacted[] = $argument;
      }

      return $redacted;
   }

   /**
    * @param list<string> $command
    *
    * @return array{exit:int,stdout:string,stderr:string}
    */
   public function run (
      array $command,
      ?float $timeout = null,
      float $grace = 2.0,
   ): array
   {
      if ($timeout !== null && $timeout < 0) {
         throw new \InvalidArgumentException('Benchmark subprocess timeout cannot be negative.');
      }
      if ($grace < 0) {
         throw new \InvalidArgumentException('Benchmark subprocess termination grace cannot be negative.');
      }

      return $this->start($command)->wait($timeout, $grace);
   }

   public function clean (): void
   {
      if ($this->scoped || !\is_dir($this->directory)) {
         return;
      }

      $files = \scandir($this->directory);

      foreach ($files === false ? [] : $files as $name) {
         if ($name === '.' || $name === '..') {
            continue;
         }

         $file = $this->directory . '/' . $name;
         if (\is_dir($file)) {
            $Children = new \RecursiveDirectoryIterator($file, \FilesystemIterator::SKIP_DOTS);
            $Iterator = new \RecursiveIteratorIterator($Children, \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($Iterator as $Child) {
               $Child->isDir() ? @\rmdir($Child->getPathname()) : @\unlink($Child->getPathname());
            }

            @\rmdir($file);
         }
         else {
            @\unlink($file);
         }
      }

      @\rmdir($this->directory);
   }
}


/**
 * One tracked benchmark subprocess whose output is published only after the
 * exact child has exited and been reaped.
 */
final class RunProcess
{
   /** @var resource|null */
   private mixed $process;
   /** @var list<string> */
   private readonly array $command;
   private readonly RunArtifacts $Artifacts;
   private readonly string $capture;
   private readonly string $output;
   private readonly string $stdout;
   private readonly string $stderr;
   private readonly float $started;
   private readonly ?int $PID;
   private readonly int $PGID;
   private ?int $observedExit = null;
   private ?int $signal = null;
   private ?string $manifestError = null;
   /** @var array{exit:int,stdout:string,stderr:string,timed_out:bool,state:string,signal:int|null}|null */
   private ?array $result = null;


   /**
    * @param resource $process
    * @param list<string> $command
    */
   public function __construct (
      RunArtifacts $Artifacts,
      mixed $process,
      array $command,
      string $capture,
      string $output,
      string $stdout,
      string $stderr,
      int $PGID,
   )
   {
      $this->Artifacts = $Artifacts;
      $this->process = $process;
      $this->command = RunArtifacts::redact($command);
      $this->capture = $capture;
      $this->output = $output;
      $this->stdout = $stdout;
      $this->stderr = $stderr;
      $this->PGID = $PGID;
      $this->started = \microtime(true);
      $status = \proc_get_status($process);
      $this->PID = \is_array($status) && isset($status['pid'])
         ? (int) $status['pid']
         : null;
      $this->inspect($status);

      try {
         $this->record([
            'command' => $this->command,
            'state' => 'running',
            'exit' => null,
            'pid' => $this->PID,
            'process_group' => $this->PGID,
            'isolation' => 'session-process-group',
            'timed_out' => false,
            'signal' => null,
            'started_at' => $this->started,
            'finished_at' => null,
            'duration_seconds' => null,
         ]);
      }
      catch (\Throwable $Throwable) {
         // ! The process handle must still reach the caller so it can be
         //   terminated and reaped even if the initial manifest cannot publish.
         $this->manifestError = $Throwable->getMessage();
      }
   }

   /**
    * Check whether the tracked subprocess is still running without reaping it.
    */
   public function check (): bool
   {
      if ($this->result !== null || !\is_resource($this->process)) {
         return false;
      }

      $status = \proc_get_status($this->process);
      $this->inspect($status);

      return \is_array($status) && $status['running'];
   }

   /**
    * Wait for and reap the exact subprocess. A finite timeout escalates from
    * SIGTERM to SIGKILL; captures are retained and published in every case.
    *
    * @return array{exit:int,stdout:string,stderr:string,timed_out:bool,state:string,signal:int|null}
    */
   public function wait (?float $timeout = null, float $grace = 2.0): array
   {
      if ($this->result !== null) {
         return $this->result;
      }
      if ($timeout !== null && $timeout < 0) {
         throw new \InvalidArgumentException('Benchmark subprocess timeout cannot be negative.');
      }
      if ($grace < 0) {
         throw new \InvalidArgumentException('Benchmark subprocess termination grace cannot be negative.');
      }
      if (!\is_resource($this->process)) {
         throw new RuntimeException('Benchmark subprocess is no longer available.');
      }

      $deadline = $timeout === null ? null : \microtime(true) + $timeout;
      $timedOut = false;
      $termination = null;
      $cleanupFailed = false;

      while ($this->poll()) {
         if ($deadline !== null && \microtime(true) >= $deadline) {
            $timedOut = true;
            $termination = 'term';
            $this->signal(15);
            $termDeadline = \microtime(true) + $grace;

            do {
               \usleep(10_000);
               $running = $this->poll();
            } while ($running && \microtime(true) < $termDeadline);

            if ($running) {
               $termination = 'kill';
               $this->signal(9);
               $killDeadline = \microtime(true) + \max(1.0, $grace);

               do {
                  \usleep(10_000);
                  $running = $this->poll();
               } while ($running && \microtime(true) < $killDeadline);
            }

            $cleanupFailed = $running;
            break;
         }

         \usleep(10_000);
      }

      $exit = $timedOut
         ? 124
         : ($this->observedExit ?? ($this->signal !== null ? 128 + $this->signal : 255));
      $state = $timedOut
         ? ($termination === 'kill' ? 'killed' : 'terminated')
         : ($this->signal !== null ? 'signaled' : 'exited');

      $finished = \microtime(true);
      $metadata = [
         'command' => $this->command,
         'state' => $state,
         'exit' => $exit,
         'process_exit' => $this->observedExit,
         'pid' => $this->PID,
         'process_group' => $this->PGID,
         'isolation' => 'session-process-group',
         'timed_out' => $timedOut,
         'termination' => $termination,
         'signal' => $this->signal,
         'started_at' => $this->started,
         'finished_at' => $finished,
         'duration_seconds' => $finished - $this->started,
         'initial_status_error' => $this->manifestError,
      ];

      if ($cleanupFailed) {
         $metadata['state'] = 'process-group-cleanup-failed';
         $metadata['stdout'] = "{$this->capture}/stdout.log";
         $metadata['stderr'] = "{$this->capture}/stderr.log";
         $this->record($metadata);

         throw new RuntimeException(
            "Benchmark process group {$this->PGID} survived TERM/KILL escalation; staged output was not published."
         );
      }

      try {
         $this->publish();
      }
      catch (\Throwable $PublishThrowable) {
         $metadata['state'] = 'publication-failed';
         $metadata['stdout'] = "{$this->capture}/stdout.log";
         $metadata['stderr'] = "{$this->capture}/stderr.log";
         $metadata['publication_error'] = $PublishThrowable->getMessage();

         try {
            $this->record($metadata);
         }
         catch (\Throwable $StatusThrowable) {
            $metadata['status_error'] = $StatusThrowable->getMessage();
            try {
               $this->record($metadata, 'status.failure.json');
            }
            catch (\Throwable) {
               // The complete staging pair remains primary evidence.
            }
         }

         throw $PublishThrowable;
      }

      try {
         $this->record($metadata);
      }
      catch (\Throwable $StatusThrowable) {
         // ! The child is already reaped and the output pair is final. Preserve
         //   terminal evidence under a distinct name when status.json itself
         //   is the failing path (for example, an unexpected directory).
         $metadata['status_error'] = $StatusThrowable->getMessage();

         try {
            $this->record($metadata, 'status.failure.json');
         }
         catch (\Throwable) {
            // @ Best effort only; retain and rethrow the original write error.
         }

         throw $StatusThrowable;
      }

      return $this->result = [
         'exit' => $exit,
         'stdout' => $this->stdout,
         'stderr' => $this->stderr,
         'timed_out' => $timedOut,
         'state' => $state,
         'signal' => $this->signal,
      ];
   }

   /** @param array<string,mixed>|false $status */
   private function inspect (array|false $status): void
   {
      if (!\is_array($status) || $status['running']) {
         return;
      }

      if (isset($status['exitcode']) && $status['exitcode'] >= 0) {
         $this->observedExit ??= (int) $status['exitcode'];
      }
      if (($status['signaled'] ?? false) && isset($status['termsig'])) {
         $this->signal ??= (int) $status['termsig'];
      }
   }

   /**
    * Reap an exited group leader, then report whether it or any descendant in
    * the isolated process group remains alive.
    */
   private function poll (): bool
   {
      if (\is_resource($this->process)) {
         $status = \proc_get_status($this->process);
         $this->inspect($status);

         if (\is_array($status) && $status['running']) {
            return true;
         }

         $closedExit = \proc_close($this->process);
         $this->process = null;
         $this->observedExit ??= $closedExit >= 0 ? $closedExit : null;
      }

      return @\posix_kill(-$this->PGID, 0);
   }

   /** Signal the complete isolated process group, with a leader fallback. */
   private function signal (int $signal): void
   {
      if (@\posix_kill(-$this->PGID, $signal)) {
         return;
      }

      if (\is_resource($this->process)) {
         @\proc_terminate($this->process, $signal);
      }
   }

   private function publish (): void
   {
      if (\file_exists($this->output)) {
         throw new RuntimeException("Benchmark process output already exists: {$this->output}");
      }
      if (!@\rename($this->capture, $this->output)) {
         // ! Keep the complete staging directory as evidence on failure.
         throw new RuntimeException("Could not publish benchmark process output: {$this->output}");
      }
   }

   /** @param array<string,mixed> $metadata */
   private function record (array $metadata, string $name = 'status.json'): void
   {
      $JSON = \json_encode(
         $metadata,
         \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
      );
      $this->Artifacts->write($name, $JSON . "\n");
   }
}
