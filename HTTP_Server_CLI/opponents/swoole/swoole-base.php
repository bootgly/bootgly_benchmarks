<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Base) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Swoole in SWOOLE_BASE mode inside the shared `bootgly-swoole` Docker
 * image (one image, every Swoole opponent — the bootable is chosen by env).
 *
 * The TechEmpower loads (/plaintext, /json, /db, ...) are not in the base
 * router set, so when the active load set (BENCHMARK_LOAD_SET) is `techempower`
 * this opponent swaps to the TechEmpower bootable — still in SWOOLE_BASE mode
 * (SWOOLE_SERVER_MODE=base), so it stays a base-mode opponent.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php swoole-base.php start
 *   php swoole-base.php stop
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

// # In-process mode: inside the self-contained bench image (bootgly/bootgly_benchmarks:<opp>)
//   Swoole is installed natively, so the opponent runs the bootable directly — no
//   docker-in-docker. Set by the image (ENV BOOTGLY_BENCH_INPROCESS=1). On a host
//   runner (env unset) it keeps using the shared `bootgly-swoole` Docker image.
$inprocess = getenv('BOOTGLY_BENCH_INPROCESS') === '1';

// @ swoole-base serves the TechEmpower routes (its only cross-framework load set;
//   the `benchmark` set is Bootgly-only). Derive the bootable from the active load
//   set (BENCHMARK_LOAD_SET, set by `--loads=<set>:<indexes>`) — TechEmpower runs the
//   TechEmpower bootable in SWOOLE_BASE mode (SWOOLE_SERVER_MODE=base), so this
//   opponent stays base-mode.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower ? 'swoole-techempower-postgres.php' : 'swoole-base-routes.php';
$mode = $techempower ? 'base' : '';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $port, $workers, $bootable, $mode, $inprocess) {
      // ? In-process: launch the bootable directly (the runner backgrounds this with `&`).
      if ($inprocess === true) {
         // # TechEmpower DB env (local PG) — only read by the TechEmpower bootable.
         $db = sprintf(
            'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
            escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
            escapeshellarg(getenv('DB_PORT') ?: '5432'),
            escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
            escapeshellarg(getenv('DB_USER') ?: 'postgres'),
            escapeshellarg(getenv('DB_PASS') ?: '')
         );
         $modeEnv = $mode !== '' ? "SWOOLE_SERVER_MODE={$mode} " : '';

         exec(
            "cd {$bootablesDir} && {$db}{$modeEnv}"
            . "SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
            . "php {$bootable} > /dev/null 2>&1"
         );
         return;
      }

      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # TechEmpower DB env (host PG via host network) — only relevant when the
      //   TechEmpower bootable is selected; harmless otherwise.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # SWOOLE_SERVER_MODE=base only matters for the TechEmpower bootable; the
      //   *-routes.php bootables hardcode their own mode and ignore it.
      $modeEnv = $mode !== '' ? "-e SWOOLE_SERVER_MODE={$mode} " : '';

      exec(
         "docker run -d --network host --name {$container} "
         . "-e SWOOLE_BOOTABLE={$bootable} {$modeEnv}"
         . "-e SERVER_WORKER_NUM={$workers} -e SERVER_PORT=8082 {$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port, $bootable, $inprocess) {
      // ? In-process: kill the bootable by argv pattern (it does not own the port alone).
      if ($inprocess === true) {
         exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
         exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
         return;
      }

      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
