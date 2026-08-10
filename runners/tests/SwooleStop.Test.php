<?php

use Bootgly\Benchmarks\Runners\RunArtifacts;

require_once dirname(__DIR__) . '/RunArtifacts.php';

$wrapper = dirname(__DIR__, 2) . '/HTTP_Server_CLI/opponents/swoole/swoole.php';
$root = sys_get_temp_dir() . '/bootgly-swoole-stop-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0o755)) {
   throw new RuntimeException("Could not create Swoole stop proof directory: {$root}");
}
$environment = [
   'BENCHMARK_LOAD_SET' => getenv('BENCHMARK_LOAD_SET'),
   'BENCHMARK_PORT' => getenv('BENCHMARK_PORT'),
];
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};

$port = 19_082;
$serverDirectory = "{$root}/server";
if (!mkdir($serverDirectory, 0o755)) {
   throw new RuntimeException("Could not create Swoole stop proof server directory: {$serverDirectory}");
}
$listenerFile = "{$serverDirectory}/swoole.pid";
$decoyFile = "{$root}/decoy.ready";

$ListenerArtifacts = RunArtifacts::create('swoole-stop-listener');
$DecoyArtifacts = RunArtifacts::create('swoole-stop-decoy');
$StopArtifacts = RunArtifacts::create('swoole-stop-command');
$MissingArtifacts = RunArtifacts::create('swoole-stop-missing-pid');
$Listener = null;
$Decoy = null;

try {
   putenv('BENCHMARK_LOAD_SET=benchmark');
   putenv("BENCHMARK_PORT={$port}");

   $listenerCode = <<<'PHP'
$listenerFile = getenv('PROOF_LISTENER_FILE');
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($listenerFile): void {
   unlink($listenerFile);
   exit(0);
});
file_put_contents($listenerFile, (string) getmypid(), LOCK_EX);
while (true) {
   usleep(100_000);
}
PHP;
   $Listener = $ListenerArtifacts->start(
      [PHP_BINARY, '-r', $listenerCode, 'swoole-base-routes.php'],
      ['PROOF_LISTENER_FILE' => $listenerFile],
   );

   $deadline = microtime(true) + 2.0;
   do {
      if (is_file($listenerFile)) {
         break;
      }
      usleep(20_000);
   } while (microtime(true) < $deadline);
   $Check(is_file($listenerFile), 'The Swoole stop proof listener did not become ready.');

   // @ This unrelated process intentionally contains the old pkill pattern in
   //   argv. PID-scoped cleanup must not terminate it.
   $Decoy = $DecoyArtifacts->start([
      PHP_BINARY,
      '-r',
      'file_put_contents(getenv("PROOF_DECOY_FILE"), "ready", LOCK_EX); usleep(30_000_000);',
      'swoole-base-routes.php',
   ], ['PROOF_DECOY_FILE' => $decoyFile]);

   $deadline = microtime(true) + 2.0;
   while (!is_file($decoyFile) && microtime(true) < $deadline) {
      usleep(20_000);
   }
   $Check(is_file($decoyFile), 'The Swoole stop decoy did not become ready.');
   $Check($Decoy->check(), 'The Swoole stop decoy did not start.');

   $result = $StopArtifacts->start(
      [PHP_BINARY, $wrapper, 'stop'],
      [
         'BENCHMARK_PORT' => (string) $port,
         'BENCHMARK_LOAD_SET' => 'benchmark',
         'BENCHMARK_SERVER_DIR' => $serverDirectory,
      ],
   )->wait(5.0, 0.5);

   $Check($result['exit'] === 0, 'The Swoole stop wrapper returned a failure exit status.');
   $Check(!$result['timed_out'], 'The Swoole stop wrapper leaked a process-group descendant.');
   $Check($Decoy->check(), 'PID-scoped cleanup killed an unrelated argv match.');

   $listenerResult = $Listener->wait(2.0, 0.5);
   $Listener = null;
   $Check(!$listenerResult['timed_out'], 'The listener did not stop after SIGTERM.');
   $Check(!is_file($listenerFile), 'The synthetic listener remained registered after cleanup.');

   $missing = $MissingArtifacts->start(
      [PHP_BINARY, $wrapper, 'stop'],
      [
         'BENCHMARK_PORT' => (string) $port,
         'BENCHMARK_LOAD_SET' => 'benchmark',
         'BENCHMARK_SERVER_DIR' => $serverDirectory,
      ],
   )->wait(5.0, 0.5);
   $Check($missing['exit'] !== 0, 'Managed cleanup accepted a missing master PID artifact.');
   $Check(!$missing['timed_out'], 'Missing-PID validation leaked a process-group descendant.');
   $Check($Decoy->check(), 'Missing-PID validation killed the unrelated argv match.');
}
finally {
   if ($Listener !== null) {
      $Listener->wait(0.0, 0.2);
   }
   if ($Decoy !== null) {
      $Decoy->wait(0.0, 0.2);
   }

   $ListenerArtifacts->clean();
   $DecoyArtifacts->clean();
   $StopArtifacts->clean();
   $MissingArtifacts->clean();

   foreach ($environment as $name => $value) {
      $value === false ? putenv($name) : putenv("{$name}={$value}");
   }

   $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
   $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
   foreach ($Iterator as $Child) {
      $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
   }
   rmdir($root);
}

fwrite(STDOUT, "Swoole stop proof: OK\n");
