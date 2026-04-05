#!/usr/bin/env php
<?php
/**
 * Hyperf HTTP Server — Benchmark entry point
 *
 * Usage: php bin/hyperf.php start
 */

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__));
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

require BASE_PATH . '/vendor/autoload.php';

(function () {
   Hyperf\Di\ClassLoader::init();
   $container = require BASE_PATH . '/config/container.php';
   $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
   $application->run();
})();
