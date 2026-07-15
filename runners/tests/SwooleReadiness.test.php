<?php

use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\Benchmarks\Runners\RunArtifacts;


$framework = dirname(__DIR__, 3) . '/bootgly/Bootgly';
require_once "{$framework}/ABI/Data/__String/Escapeable.php";
require_once "{$framework}/ABI/Data/__String/Escapeable/Text.php";
require_once "{$framework}/ABI/Data/__String/Escapeable/Text/Formattable.php";
require_once "{$framework}/ACI/Tests/Benchmark/Configs.php";
require_once "{$framework}/ACI/Tests/Benchmark/Opponent.php";
require_once "{$framework}/ACI/Tests/Benchmark/Runner.php";
require_once dirname(__DIR__) . '/RunArtifacts.php';
require_once dirname(__DIR__) . '/ServerReadiness.php';

$root = sys_get_temp_dir() . '/bootgly-swoole-readiness-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0o755)) {
   throw new RuntimeException("Could not create Swoole readiness proof directory: {$root}");
}

$previous = getenv('BENCHMARK_RUN_DIR');
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};
$StaleArtifacts = null;
$StaleProcess = null;

try {
   putenv("BENCHMARK_RUN_DIR={$root}");

   // @ This listener represents an unrelated server left on the benchmark
   //   port. It deliberately returns a valid HTTP response, exactly the signal
   //   that the old readiness check incorrectly accepted as the new Swoole.
   $readyFile = "{$root}/stale.ready";
   $staleCode = <<<'PHP'
$Server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if ($Server === false) {
   fwrite(STDERR, "listener: {$error} {$message}\n");
   exit(1);
}
file_put_contents(getenv('PROOF_READY_FILE'), stream_socket_get_name($Server, false), LOCK_EX);
while ($Socket = stream_socket_accept($Server, 1)) {
   fread($Socket, 4096);
   fwrite($Socket, "HTTP/1.0 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
   fclose($Socket);
}
PHP;
   $StaleArtifacts = RunArtifacts::create('swoole-readiness-stale-listener');
   $StaleProcess = $StaleArtifacts->start(
      [PHP_BINARY, '-r', $staleCode],
      ['PROOF_READY_FILE' => $readyFile],
   );

   $deadline = microtime(true) + 2.0;
   while (!is_file($readyFile) && microtime(true) < $deadline) {
      usleep(20_000);
   }
   $Check(is_file($readyFile), 'The stale readiness proof listener did not start.');

   $address = trim((string) file_get_contents($readyFile));
   $separator = strrpos($address, ':');
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
   $Check($port > 0, 'The stale readiness proof listener did not publish a port.');

   foreach (['TCP_Client.php', 'HTTP_Client.php'] as $runnerFile) {
      $Runner = include dirname(__DIR__) . "/{$runnerFile}";
      $Runner->port = $port;
      $Runner->readyTimeout = 2;

      $ServerArtifacts = RunArtifacts::create('swoole-readiness-current-server');
      $ServerProcess = $ServerArtifacts->start([
         PHP_BINARY,
         '-r',
         'usleep(150000); exit(98);',
      ]);

      $Reflection = new ReflectionObject($Runner);
      $ArtifactsProperty = $Reflection->getProperty('ServerArtifacts');
      $ArtifactsProperty->setValue($Runner, $ServerArtifacts);
      $ProcessProperty = $Reflection->getProperty('ServerProcess');
      $ProcessProperty->setValue($Runner, $ServerProcess);
      $Method = $Reflection->getMethod('waitForServer');
      $Opponent = new Opponent(
         'Swoole',
         dirname(__DIR__, 2) . '/HTTP_Server_CLI/opponents/swoole/swoole.php',
      );

      $started = microtime(true);
      $ready = $Method->invoke($Runner, $Opponent);
      $elapsed = microtime(true) - $started;

      $Check(!$ready, "{$runnerFile} accepted an unrelated listener as the current Swoole server.");
      $Check($elapsed < 1.5, "{$runnerFile} did not fail promptly after the current Swoole wrapper exited.");
      $Check($StaleProcess->check(), "{$runnerFile} confused the unrelated listener with the failed wrapper.");
      $Check($ServerProcess->wait()['exit'] === 98, "{$runnerFile} lost the current wrapper's bind-failure status.");

      $ServerArtifacts->clean();
   }
}
finally {
   if ($StaleProcess !== null) {
      $StaleProcess->wait(0.0, 0.2);
   }
   $StaleArtifacts?->clean();

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

fwrite(STDOUT, "Swoole readiness ownership proof: OK\n");
