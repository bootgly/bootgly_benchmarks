<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks - HTTP_Server_CLI - Swoole TechEmpower Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Swoole HTTP Server serving the canonical TechEmpower routes
 * (/plaintext, /json, /db, /query, /fortunes, /updates, /cached-queries) inside
 * the shared `bootgly-swoole` Docker image (one image, every Swoole opponent —
 * the bootable is chosen by env).
 *
 * Backed by the native per-worker PDO PostgreSQL pool. Runs in SWOOLE_BASE
 * (SWOOLE_SERVER_MODE=base): every worker accepts its own connections via
 * SO_REUSEPORT, which scales keep-alive throughput far better than PROCESS
 * mode's single master dispatcher (+~27% on /plaintext, /json) while leaving
 * the per-worker PDO pool — and the DB routes — unchanged. The bootable
 * self-daemonizes; the entrypoint keeps the container (PID 1) alive while its
 * master lives.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php swoole-techempower.php start
 *   php swoole-techempower.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.swoole -t bootgly-swoole \
 *     ../../bootables/swoole
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/swoole');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.swoole';
$image = 'bootgly-swoole';
$container = 'bench-swoole';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$bootable = 'swoole-techempower-postgres.php';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers, $bootable) {
      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # TechEmpower DB env (host PG via host network).
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "docker run -d --network host --name {$container} "
         . "-e SWOOLE_BOOTABLE={$bootable} -e SWOOLE_SERVER_MODE=base "
         . "-e SERVER_WORKER_NUM={$workers} -e SERVER_PORT=8082 {$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
