<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — ReactPHP Opponent (Docker)
 * --------------------------------------------------------------------------
 *
 * Runs the ReactPHP bootable inside a Docker container — kept off the host to
 * avoid polluting it with the composer vendor tree (PgAsync is pure PHP, so no
 * build toolchain is needed beyond ext-pcntl/ext-sockets baked into the
 * image). The container runs the async TechEmpower server in the FOREGROUND
 * (the container IS the daemon).
 *
 * This opponent only serves the TechEmpower route set — there is no generic
 * router branch — so no BOOTGLY_HTTP_SERVER_CLI_ROUTER is forwarded.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php reactphp.php start
 *   php reactphp.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.reactphp -t bootgly-reactphp \
 *     ../../bootables/reactphp
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/reactphp');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.reactphp';
$image = 'bootgly-reactphp';
$container = 'bench-reactphp';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers) {
      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # DB env the bootable reads (DB_HOST/PORT/NAME/USER/PASS); host PG via host network.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # Worker count → BOOTGLY_WORKERS (the bootable forks this many children).
      exec(
         "docker run -d --network host --name {$container} "
         . '-e BOOTGLY_WORKERS=' . escapeshellarg($workers) . ' '
         . "{$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
