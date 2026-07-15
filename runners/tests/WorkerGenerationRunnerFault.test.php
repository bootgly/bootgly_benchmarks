<?php

declare(strict_types=1);

use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\Benchmarks\Runners\WorkerWarmup;
use Bootgly\ACI\Tests\Suite\Test\Specification;


require_once dirname(__DIR__) . '/RunArtifacts.php';
require_once dirname(__DIR__) . '/WorkerWarmup.php';
require_once dirname(__DIR__, 2) . '/HTTP_Server_CLI/bootables/WorkerEvidence.php';


return new Specification(
   description: 'It should reject both client runners when the worker generation changes during measurement',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$Await = static function (string $file, float $timeout = 5.0): string {
   $deadline = microtime(true) + $timeout;
   do {
      $contents = @file_get_contents($file);
      if (is_string($contents) && trim($contents) !== '') {
         return trim($contents);
      }
      usleep(1_000);
   } while (microtime(true) < $deadline);

   throw new RuntimeException("Timed out waiting for fault-test artifact: {$file}");
};

/**
 * Start one real HTTP worker process. After its marker state has been sealed,
 * the first ordinary measured request forks an additional live worker which
 * registers a distinct WorkerEvidence lease without serving traffic.
 *
 * @return array{pid:int,port:int,added:string,error:string}
 */
$Start = static function (string $directory, string $token, string $scope) use ($Await): array {
   $portFile = $directory . "/{$scope}.port";
   $addedFile = $directory . "/{$scope}.added";
   $errorFile = $directory . "/{$scope}.error";
   $PID = pcntl_fork();
   if ($PID < 0) {
      throw new RuntimeException('Could not fork the worker-generation fault server.');
   }
   if ($PID === 0) {
      try {
         putenv("BENCHMARK_SERVER_DIR={$directory}");
         putenv("BENCHMARK_WARMUP_TOKEN={$token}");
         WorkerEvidence::$enabled = true;
         WorkerEvidence::boot();

         $error = 0;
         $message = '';
         $Server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
         if ($Server === false) {
            throw new RuntimeException("Could not listen: {$error} {$message}");
         }
         stream_set_blocking($Server, false);
         $address = stream_socket_get_name($Server, false);
         if (!is_string($address) || file_put_contents($portFile, $address, LOCK_EX) === false) {
            throw new RuntimeException('Could not publish the fault-server address.');
         }

         $running = true;
         $sealed = false;
         $addedPID = null;
         $Clients = [];
         $buffers = [];
         pcntl_async_signals(true);
         pcntl_signal(SIGTERM, static function () use (&$running): void {
            $running = false;
         });
         pcntl_signal(SIGINT, static function () use (&$running): void {
            $running = false;
         });

         while ($running) {
            $read = [$Server, ...array_values($Clients)];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, 100_000);
            if ($selected === false) {
               continue;
            }

            foreach ($read as $Socket) {
               if ($Socket === $Server) {
                  while (($Client = @stream_socket_accept($Server, 0)) !== false) {
                     stream_set_blocking($Client, false);
                     $id = (int) $Client;
                     $Clients[$id] = $Client;
                     $buffers[$id] = '';
                  }

                  continue;
               }

               $id = (int) $Socket;
               $chunk = @fread($Socket, 65_536);
               if ($chunk === false || ($chunk === '' && feof($Socket))) {
                  @fclose($Socket);
                  unset($Clients[$id], $buffers[$id]);
                  continue;
               }
               if ($chunk === '') {
                  continue;
               }
               $buffers[$id] .= $chunk;

               while (($end = strpos($buffers[$id], "\r\n\r\n")) !== false) {
                  $head = substr($buffers[$id], 0, $end + 4);
                  $buffers[$id] = substr($buffers[$id], $end + 4);
                  $lines = explode("\r\n", rtrim($head, "\r\n"));
                  array_shift($lines);
                  $headers = [];
                  foreach ($lines as $line) {
                     $colon = strpos($line, ':');
                     if ($colon === false) {
                        continue;
                     }
                     $name = strtolower(trim(substr($line, 0, $colon)));
                     $headers[$name] = trim(substr($line, $colon + 1));
                  }

                  $marker = $headers['x-bootgly-benchmark-warmup'] ?? null;
                  $nonce = $headers['x-bootgly-benchmark-nonce'] ?? null;
                  $seal = $headers['x-bootgly-benchmark-seal'] ?? null;
                  $identity = WorkerEvidence::$enabled
                     ? WorkerEvidence::identify($marker, $nonce, $seal)
                     : null;
                  if (is_string($seal) && hash_equals($token, $seal)) {
                     $sealed = true;
                  }

                  if (
                     $sealed
                     && $marker === null
                     && $nonce === null
                     && $seal === null
                     && $addedPID === null
                  ) {
                     $addedPID = pcntl_fork();
                     if ($addedPID < 0) {
                        throw new RuntimeException('Could not fork the additional worker.');
                     }
                     if ($addedPID === 0) {
                        foreach ($Clients as $Inherited) {
                           @fclose($Inherited);
                        }
                        @fclose($Server);
                        pcntl_async_signals(true);
                        pcntl_signal(SIGTERM, static function (): void {
                           exit(0);
                        });
                        pcntl_signal(SIGINT, static function (): void {
                           exit(0);
                        });
                        WorkerEvidence::boot();
                        if (file_put_contents($addedFile, (string) getmypid(), LOCK_EX) === false) {
                           exit(71);
                        }
                        while (true) {
                           usleep(100_000);
                        }
                     }

                     $deadline = microtime(true) + 3.0;
                     while (!is_file($addedFile) && microtime(true) < $deadline && $running) {
                        usleep(1_000);
                     }
                     if (!is_file($addedFile)) {
                        throw new RuntimeException('Additional worker did not publish its lease.');
                     }
                  }

                  $body = 'Hello, World!';
                  $close = strtolower($headers['connection'] ?? '') === 'close';
                  $response = "HTTP/1.1 200 OK\r\n"
                     . 'Content-Type: text/plain' . "\r\n"
                     . 'Content-Length: ' . strlen($body) . "\r\n"
                     . ($identity === null ? '' : "X-Bootgly-Benchmark-Worker: {$identity}\r\n")
                     . 'Connection: ' . ($close ? 'close' : 'keep-alive') . "\r\n\r\n"
                     . $body;
                  $offset = 0;
                  $length = strlen($response);
                  while ($offset < $length) {
                     $written = @fwrite($Socket, substr($response, $offset));
                     if ($written === false) {
                        break 2;
                     }
                     if ($written === 0) {
                        usleep(100);
                        continue;
                     }
                     $offset += $written;
                  }

                  if ($close) {
                     @fclose($Socket);
                     unset($Clients[$id], $buffers[$id]);
                     break;
                  }
               }
            }
         }

         foreach ($Clients as $Client) {
            @fclose($Client);
         }
         @fclose($Server);
         if (is_int($addedPID) && $addedPID > 0) {
            @posix_kill($addedPID, SIGTERM);
            pcntl_waitpid($addedPID, $status);
         }
         exit(0);
      }
      catch (Throwable $Throwable) {
         @file_put_contents($errorFile, $Throwable->getMessage() . "\n", LOCK_EX);
         exit(70);
      }
   }

   try {
      $address = $Await($portFile);
   }
   catch (Throwable $Throwable) {
      @posix_kill($PID, SIGKILL);
      pcntl_waitpid($PID, $status);
      $detail = is_file($errorFile)
         ? trim((string) file_get_contents($errorFile))
         : $Throwable->getMessage();

      throw new RuntimeException("Fault server did not start: {$detail}", 0, $Throwable);
   }
   $colon = strrpos($address, ':');
   $port = $colon === false ? 0 : (int) substr($address, $colon + 1);
   if ($port < 1) {
      throw new RuntimeException("Fault server published an invalid address: {$address}");
   }

   return [
      'pid' => $PID,
      'port' => $port,
      'added' => $addedFile,
      'error' => $errorFile,
   ];
};

$Stop = static function (array $server): void {
   $PID = $server['pid'] ?? 0;
   if (!is_int($PID) || $PID < 1) {
      return;
   }

   @posix_kill($PID, SIGTERM);
   $deadline = microtime(true) + 5.0;
   $stopped = false;
   do {
      $waited = pcntl_waitpid($PID, $status, WNOHANG);
      if ($waited === $PID || $waited === -1) {
         $stopped = true;
         break;
      }
      usleep(10_000);
   } while (microtime(true) < $deadline);

   if (!$stopped) {
      @posix_kill($PID, SIGKILL);
      pcntl_waitpid($PID, $status);
   }

   $added = $server['added'] ?? null;
   if (is_string($added) && is_file($added)) {
      $addedPID = (int) trim((string) file_get_contents($added));
      if ($addedPID > 0 && @posix_kill($addedPID, 0)) {
         @posix_kill($addedPID, SIGKILL);
      }
   }
};

$environment = [
   'BENCHMARK_RUN_DIR' => getenv('BENCHMARK_RUN_DIR'),
   'BENCHMARK_SERVER_DIR' => getenv('BENCHMARK_SERVER_DIR'),
   'BENCHMARK_WARMUP_TOKEN' => getenv('BENCHMARK_WARMUP_TOKEN'),
];
$root = sys_get_temp_dir() . '/bootgly-worker-generation-runner-fault-' . bin2hex(random_bytes(12));
$servers = [];
if (!mkdir($root, 0o700)) {
   throw new RuntimeException('Could not create the runner fault-proof directory.');
}

try {
   putenv("BENCHMARK_RUN_DIR={$root}");
   $loadData = [
      'method' => 'GET',
      'paths' => ['/plaintext'],
      'expect' => [
         'status' => 200,
         'contains' => ['Hello, World!'],
      ],
   ];
   $loadFile = $root . '/load.php';
   $source = "<?php\n\nreturn " . var_export($loadData, true) . ";\n";
   if (file_put_contents($loadFile, $source, LOCK_EX) === false) {
      throw new RuntimeException('Could not publish the runner fault-proof load.');
   }

   foreach (['tcp' => 'TCP_Client.php', 'http' => 'HTTP_Client.php'] as $kind => $runnerFile) {
      $token = WorkerWarmup::issue();
      $ServerArtifacts = RunArtifacts::create("{$kind}-generation-fault-server");
      $server = $Start($ServerArtifacts->directory, $token, $kind);
      $servers[$server['pid']] = $server;

      $Runner = include dirname(__DIR__) . "/{$runnerFile}";
      $Runner->port = $server['port'];
      $Runner->connections = 1;
      $Runner->workers = 1;
      $Runner->duration = 1;
      $Runner->warmupDuration = 1;
      if ($kind === 'tcp') {
         $Runner->pipeline = 1;
         $Runner->preflightRetries = 0;
      }

      $Reflection = new ReflectionObject($Runner);
      $ArtifactsProperty = $Reflection->getProperty('ServerArtifacts');
      $ArtifactsProperty->setValue($Runner, $ServerArtifacts);
      $Command = $Reflection->getMethod('command');
      $Load = new Load(
         label: "{$kind} generation fault",
         group: '',
         file: $loadFile,
      );

      ob_start();
      try {
         $Result = $Command->invoke(
            $Runner,
            $Load,
            $token,
            1,
            'Synthetic fault server',
            1,
            1,
         );
      }
      finally {
         $console = (string) ob_get_clean();
      }

      $Check($Result instanceof Result, "{$runnerFile} did not return a benchmark Result.");
      $Check($Result->rps === null, "{$runnerFile} accepted throughput after a terminal generation change.");
      $Check(
         str_contains($console, 'Worker generation changed during measurement:'),
         "{$runnerFile} did not surface the terminal generation rejection.",
      );
      $Check(is_file($server['added']), "{$runnerFile} never induced the additional worker.");

      $measuredFiles = glob("{$root}/runners/{$kind}-client-load/*/process/stdout.log") ?: [];
      $Check(count($measuredFiles) === 1, "{$runnerFile} did not retain exactly one measured stdout artifact.");
      $measured = file_get_contents($measuredFiles[0]);
      $Check(is_string($measured) && $measured !== '', "{$runnerFile} retained no measured stdout.");
      $measuredData = json_decode($measured, true, flags: JSON_THROW_ON_ERROR);
      $Check(
         is_array($measuredData)
            && (is_int($measuredData['rps'] ?? null) || is_float($measuredData['rps'] ?? null))
            && (float) $measuredData['rps'] > 0,
         "{$runnerFile} measured child did not produce positive parseable throughput.",
      );

      $Parser = $Reflection->getMethod('parse');
      $Parsed = $Parser->invoke($Runner, $measured);
      $Check(
         $Parsed instanceof Result && $Parsed->rps !== null && $Parsed->rps > 0,
         "{$runnerFile} exact parser did not accept the retained measured stdout.",
      );
      if ($kind === 'tcp') {
         $Check($Parsed->accounting === true, 'TCP measured stdout did not close exact accounting.');
      }

      $generationFiles = glob("{$root}/runners/{$kind}-client-generation/*/generation.json") ?: [];
      $Check(count($generationFiles) === 1, "{$runnerFile} did not retain exactly one generation artifact.");
      $generation = json_decode(
         (string) file_get_contents($generationFiles[0]),
         true,
         flags: JSON_THROW_ON_ERROR,
      );
      $Check(
         is_array($generation)
            && ($generation['schema'] ?? null) === 'bootgly.worker-generation'
            && ($generation['validated'] ?? null) === false
            && ($generation['stable'] ?? null) === false
            && is_array($generation['changes']['added'] ?? null)
            && $generation['changes']['added'] !== [],
         "{$runnerFile} did not persist the induced added-worker rejection.",
      );

      $warmupFiles = glob("{$root}/runners/{$kind}-client-warmup/*/evidence.json") ?: [];
      $Check(count($warmupFiles) === 1, "{$runnerFile} did not retain exactly one warmup artifact.");
      $warmup = json_decode(
         (string) file_get_contents($warmupFiles[0]),
         true,
         flags: JSON_THROW_ON_ERROR,
      );
      $contextFiles = glob("{$root}/runners/{$kind}-client-load/*/context.json") ?: [];
      $Check(count($contextFiles) === 1, "{$runnerFile} did not retain exactly one measurement context.");
      $measurement = json_decode(
         (string) file_get_contents($contextFiles[0]),
         true,
         flags: JSON_THROW_ON_ERROR,
      );
      $measurementID = is_array($measurement)
         ? ($measurement['measurement_id'] ?? null)
         : null;
      $Check(
         is_string($measurementID) && preg_match('/\A[0-9a-f]{32}\z/D', $measurementID) === 1,
         "{$runnerFile} measurement context has no valid correlation ID.",
      );
      $Check(
         is_array($measurement)
            && ($measurement['schema'] ?? null) === 'bootgly.measurement-context'
            && ($measurement['version'] ?? null) === 1
            && ($measurement['runner'] ?? null) === "{$kind}_client",
         "{$runnerFile} measurement context schema is invalid.",
      );
      $Check(
         is_array($warmup)
            && ($warmup['context']['measurement_id'] ?? null) === $measurementID,
         "{$runnerFile} warmup evidence is not correlated to the measured invocation.",
      );
      $Check(
         ($generation['context']['measurement_id'] ?? null) === $measurementID,
         "{$runnerFile} generation evidence is not correlated to the measured invocation.",
      );

      $Stop($server);
      unset($servers[$server['pid']]);
      $Check(
         !is_file($server['error']),
         "{$runnerFile} synthetic fault server failed: " . (string) @file_get_contents($server['error']),
      );
   }

   return true;
}
finally {
   foreach ($servers as $server) {
      $Stop($server);
      $added = $server['added'] ?? null;
      if (is_string($added) && is_file($added)) {
         $addedPID = (int) trim((string) file_get_contents($added));
         if ($addedPID > 0) {
            @posix_kill($addedPID, SIGKILL);
            pcntl_waitpid($addedPID, $status, WNOHANG);
         }
      }
   }
   foreach ($environment as $name => $value) {
      $value === false ? putenv($name) : putenv("{$name}={$value}");
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
