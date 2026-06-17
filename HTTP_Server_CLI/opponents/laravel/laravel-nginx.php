<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel (nginx + PHP-FPM) Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop a Laravel app served by nginx → PHP-FPM 8.4 (per-request mode).
 *
 * Usage:
 *   php laravel-nginx.php start
 *   php laravel-nginx.php stop
 *
 * Environment variables:
 *   BENCHMARK_PORT   — port to listen on (default: 8082)
 *   BOOTGLY_WORKERS  — php-fpm children + nginx workers (default: nproc / 2)
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$configsDir = $bootablesDir . '/configs';
$runDir = $bootablesDir . '/run';
$rootDir = $bootablesDir . '/public';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// PHP-FPM is per-request (1 child = 1 blocking request), so it needs a real process
// pool, not nproc/2. Cap below Postgres max_connections (default 100).
$children = getenv('FPM_MAX_CHILDREN') ?: '64';

// OPcache JIT mode for the pool: 'tracing' | 'function' | 'off'. JIT can help or
// hurt a stateless per-request workload — toggle here to A/B it.
$jit = getenv('FPM_JIT') ?: 'tracing';

// opcache.preload_user must match the user running php-fpm (non-root).
$appUser = (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '') ?: (get_current_user() ?: 'nobody');

$fpmBin = '/usr/sbin/php-fpm8.4';
$nginxBin = '/usr/sbin/nginx';
$nginxPrefix = '/etc/nginx';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $configsDir, $runDir, $rootDir, $port, $workers, $children, $jit, $appUser, $fpmBin, $nginxBin, $nginxPrefix) {
      @mkdir($runDir, 0777, true);
      foreach (['body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'] as $tempDir) {
         @mkdir("{$runDir}/{$tempDir}", 0777, true);
      }

      $vars = [
         '{{RUN}}' => $runDir,
         '{{ROOT}}' => $rootDir,
         '{{PORT}}' => $port,
         '{{WORKERS}}' => $workers,
         '{{CHILDREN}}' => $children,
         '{{JIT}}' => $jit,
         '{{APP}}' => $bootablesDir,
         '{{USER}}' => $appUser,
         '{{NGINX_PREFIX}}' => $nginxPrefix,
      ];

      file_put_contents("{$runDir}/php-fpm.conf", strtr(file_get_contents("{$configsDir}/php-fpm.conf.tpl"), $vars));
      file_put_contents("{$runDir}/nginx.conf", strtr(file_get_contents("{$configsDir}/nginx.conf.tpl"), $vars));

      // php-fpm daemonizes itself; nginx daemonizes via `daemon on`.
      // XDEBUG_MODE=off prevents Xdebug from overriding zend_execute_ex (keeps JIT).
      exec("XDEBUG_MODE=off {$fpmBin} --fpm-config {$runDir}/php-fpm.conf > /dev/null 2>&1");
      exec("{$nginxBin} -p {$runDir} -c {$runDir}/nginx.conf > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($runDir, $nginxBin, $port) {
      if (is_file("{$runDir}/nginx.pid")) {
         exec("{$nginxBin} -p {$runDir} -c {$runDir}/nginx.conf -s quit > /dev/null 2>&1");
      }
      else {
         exec("pkill -9 -f 'nginx.*{$runDir}/nginx.conf' > /dev/null 2>&1");
      }
      if (is_file("{$runDir}/php-fpm.pid")) {
         exec('kill ' . (int) file_get_contents("{$runDir}/php-fpm.pid") . ' > /dev/null 2>&1');
      }

      usleep(400_000);

      // Fallbacks: stray php-fpm by config path, then anything still on the port
      exec("pkill -9 -f '{$runDir}/php-fpm.conf' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
