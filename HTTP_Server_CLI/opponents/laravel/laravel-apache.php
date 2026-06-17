<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel (Apache + PHP-FPM) Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop a Laravel app served by Apache (mpm_event) → PHP-FPM 8.4 via
 * mod_proxy_fcgi over the same unix socket as the nginx variant (per-request).
 *
 * Usage:
 *   php laravel-apache.php start
 *   php laravel-apache.php stop
 *
 * Environment variables:
 *   BENCHMARK_PORT   — port to listen on (default: 8082)
 *   BOOTGLY_WORKERS  — reserved (Apache mpm_event sizing is fixed in template)
 *   FPM_MAX_CHILDREN — php-fpm static children (default: 64; < PG max_connections)
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$configsDir = $bootablesDir . '/configs';
$runDir = $bootablesDir . '/run';
$rootDir = $bootablesDir . '/public';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$children = getenv('FPM_MAX_CHILDREN') ?: '64';
$jit = getenv('FPM_JIT') ?: 'tracing';
$appUser = (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '') ?: (get_current_user() ?: 'nobody');

$fpmBin = '/usr/sbin/php-fpm8.4';
$apacheBin = '/usr/sbin/apache2';
$apacheModules = '/usr/lib/apache2/modules';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $configsDir, $runDir, $rootDir, $port, $children, $jit, $appUser, $fpmBin, $apacheBin, $apacheModules) {
      @mkdir($runDir, 0777, true);

      $vars = [
         '{{RUN}}' => $runDir,
         '{{ROOT}}' => $rootDir,
         '{{PORT}}' => $port,
         '{{CHILDREN}}' => $children,
         '{{JIT}}' => $jit,
         '{{APP}}' => $bootablesDir,
         '{{USER}}' => $appUser,
         '{{MODULES}}' => $apacheModules,
      ];

      file_put_contents("{$runDir}/php-fpm.conf", strtr(file_get_contents("{$configsDir}/php-fpm.conf.tpl"), $vars));
      file_put_contents("{$runDir}/apache.conf", strtr(file_get_contents("{$configsDir}/apache.conf.tpl"), $vars));

      // php-fpm daemonizes itself; apache -k start daemonizes.
      // XDEBUG_MODE=off prevents Xdebug from overriding zend_execute_ex (keeps JIT).
      exec("XDEBUG_MODE=off {$fpmBin} --fpm-config {$runDir}/php-fpm.conf > /dev/null 2>&1");
      exec("{$apacheBin} -f {$runDir}/apache.conf -k start > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($runDir, $apacheBin, $port) {
      if (is_file("{$runDir}/apache.conf")) {
         exec("{$apacheBin} -f {$runDir}/apache.conf -k stop > /dev/null 2>&1");
      }
      if (is_file("{$runDir}/php-fpm.pid")) {
         exec('kill ' . (int) file_get_contents("{$runDir}/php-fpm.pid") . ' > /dev/null 2>&1');
      }

      usleep(400_000);

      // Fallbacks: stray apache for this config, stray php-fpm, then the port
      exec("pkill -9 -f '{$runDir}/apache.conf' > /dev/null 2>&1");
      exec("pkill -9 -f '{$runDir}/php-fpm.conf' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
