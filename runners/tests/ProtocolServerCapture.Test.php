<?php

$root = sys_get_temp_dir() . '/bootgly-protocol-server-capture-' . bin2hex(random_bytes(12));
$checkout = "{$root}/checkout";
if (!mkdir($checkout, 0o755, true)) {
   throw new RuntimeException("Could not create proof checkout: {$checkout}");
}

$fake = <<<'PHP'
<?php

$action = $argv[3] ?? '';
if ($action === 'stop') {
   exit(0);
}

fwrite(STDOUT, getenv('PROOF_PROTOCOL') . '-stdout');
fwrite(STDERR, getenv('PROOF_PROTOCOL') . '-stderr');
exit(23);
PHP;
file_put_contents("{$checkout}/bootgly", $fake);

$wrappers = [
   'tcp' => dirname(__DIR__, 2) . '/TCP_Server_CLI/opponents/bootgly/bootgly.php',
   'udp' => dirname(__DIR__, 2) . '/UDP_Server_CLI/opponents/bootgly/bootgly.php',
   'ws' => dirname(__DIR__, 2) . '/WS_Server_CLI/opponents/bootgly/bootgly.php',
];
$environment = [
   'BENCHMARK_SERVER_DIR' => getenv('BENCHMARK_SERVER_DIR'),
   'BOOTGLY_DIR' => getenv('BOOTGLY_DIR'),
   'PROOF_PROTOCOL' => getenv('PROOF_PROTOCOL'),
];
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};

try {
   putenv("BOOTGLY_DIR={$checkout}");

   foreach ($wrappers as $protocol => $wrapper) {
      $directory = "{$root}/{$protocol}";
      mkdir($directory, 0o755);
      putenv("BENCHMARK_SERVER_DIR={$directory}");
      putenv("PROOF_PROTOCOL={$protocol}");

      $output = [];
      $exit = 0;
      exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper) . ' start', $output, $exit);

      $Check($exit === 23, "{$protocol} wrapper did not propagate the inner server exit status.");
      $Check($output === [], "{$protocol} wrapper leaked the inner server stdout.");
      $Check(
         file_get_contents("{$directory}/daemon/stdout.log") === "{$protocol}-stdout",
         "{$protocol} wrapper did not isolate the inner server stdout."
      );
      $Check(
         file_get_contents("{$directory}/daemon/stderr.log") === "{$protocol}-stderr",
         "{$protocol} wrapper did not isolate the inner server stderr."
      );
      $Check(
         !file_exists("{$directory}/daemon.capture"),
         "{$protocol} wrapper left an unpublished capture directory."
      );
   }
}
finally {
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

fwrite(STDOUT, "Protocol ServerCapture proof: OK\n");
