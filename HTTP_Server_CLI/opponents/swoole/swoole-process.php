<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Process) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Swoole in SWOOLE_PROCESS mode inside the shared `bootgly-swoole` Docker
 * image (one image, every Swoole opponent — the bootable is chosen by env).
 *
 * Always runs the generic process bootable (swoole-process-routes.php, which
 * hardcodes SWOOLE_PROCESS) — matching the native script, which did not branch
 * on the router. The bootable self-daemonizes; the entrypoint keeps the
 * container (PID 1) alive while its master lives.
 *
 * Container uses --network host: binds :8082 directly (no NAT — fair).
 *
 * Usage:
 *   php swoole-process.php start
 *   php swoole-process.php stop
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

$bootable = 'swoole-process-routes.php';

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

      exec(
         "docker run -d --network host --name {$container} "
         . "-e SWOOLE_BOOTABLE={$bootable} "
         . "-e SERVER_WORKER_NUM={$workers} -e SERVER_PORT=8082 {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
