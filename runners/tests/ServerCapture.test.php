<?php

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__) . '/ServerCapture.php';

$root = sys_get_temp_dir() . '/bootgly-server-capture-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0o755)) {
   throw new RuntimeException("Could not create proof directory: {$root}");
}

$previous = getenv('BENCHMARK_SERVER_DIR');
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};

try {
   putenv('BENCHMARK_SERVER_DIR');
   $marker = "{$root}/standalone.marker";
   $payload = 'file_put_contents(getenv("PROOF_FILE"), "ran"); '
      . 'fwrite(STDOUT, "discarded-stdout"); fwrite(STDERR, "discarded-stderr"); exit(7);';
   $command = 'PROOF_FILE=' . escapeshellarg($marker)
      . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($payload);
   $exit = ServerCapture::run($command);

   $Check($exit === 7, 'Standalone execution did not propagate the internal server exit status.');
   $Check(file_get_contents($marker) === 'ran', 'Standalone execution did not run the server command.');

   $success = "{$root}/success";
   mkdir($success, 0o755);
   putenv("BENCHMARK_SERVER_DIR={$success}");

   $payload = 'fwrite(STDOUT, "stdout-proof"); fwrite(STDERR, "stderr-proof"); exit(23);';
   $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($payload);
   $exit = ServerCapture::run($command);

   $Check($exit === 23, 'The internal server exit status was not propagated.');
   $Check(file_get_contents("{$success}/daemon/stdout.log") === 'stdout-proof', 'stdout was not isolated.');
   $Check(file_get_contents("{$success}/daemon/stderr.log") === 'stderr-proof', 'stderr was not isolated.');
   $Check(!file_exists("{$success}/daemon.capture"), 'A temporary capture survived the atomic commit.');

   // @ Force the directory commit to fail and prove neither stream can appear
   //   outside the existing final pair.
   $collision = "{$root}/collision";
   mkdir($collision, 0o755);
   mkdir("{$collision}/daemon", 0o755);
   file_put_contents("{$collision}/daemon/sentinel", 'sentinel');
   putenv("BENCHMARK_SERVER_DIR={$collision}");

   try {
      ServerCapture::run($command);
      throw new RuntimeException('The forced pair-commit failure was not reported.');
   }
   catch (RuntimeException $Exception) {
      $Check(
         str_contains($Exception->getMessage(), 'capture already exists'),
         'The proof failed for an unexpected reason.'
      );
   }

   $Check(!file_exists("{$collision}/daemon/stdout.log"), 'A partial stdout final survived collision handling.');
   $Check(!file_exists("{$collision}/daemon/stderr.log"), 'A partial stderr final survived collision handling.');
   $Check(file_get_contents("{$collision}/daemon/sentinel") === 'sentinel', 'Commit replaced an existing final.');
   $Check(file_get_contents("{$collision}/daemon.capture/stdout.log") === 'stdout-proof', 'Failed publication lost stdout evidence.');
   $Check(file_get_contents("{$collision}/daemon.capture/stderr.log") === 'stderr-proof', 'Failed publication lost stderr evidence.');
   $failure = json_decode(
      (string) file_get_contents("{$collision}/daemon.failure.json"),
      true,
      flags: JSON_THROW_ON_ERROR,
   );
   $Check(
      $failure['state'] === 'publication-failed'
         && $failure['exit'] === 23
         && $failure['stdout'] === "{$collision}/daemon.capture/stdout.log"
         && $failure['stderr'] === "{$collision}/daemon.capture/stderr.log",
      'Failed publication did not retain attributable status metadata.'
   );
}
finally {
   $previous === false
      ? putenv('BENCHMARK_SERVER_DIR')
      : putenv("BENCHMARK_SERVER_DIR={$previous}");

   $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
   $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
   foreach ($Iterator as $Child) {
      $Child->isDir() ? rmdir($Child->getPathname()) : unlink($Child->getPathname());
   }
   rmdir($root);
}

fwrite(STDOUT, "ServerCapture proof: OK\n");
