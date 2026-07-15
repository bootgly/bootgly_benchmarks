<?php
/**
 * Hyperf server config for benchmark.
 *
 * PORT and WORKER_NUM are read from environment variables
 * so the benchmark driver can control them.
 */

declare(strict_types=1);

use Swoole\Constant;

$workers = (int) (getenv('SERVER_WORKER_NUM') ?: ((int) (shell_exec('nproc') / 2) ?: 1));
$port    = (int) (getenv('SERVER_PORT') ?: 8082);
$serverDirectory = getenv('BENCHMARK_SERVER_DIR');
$logFile = is_string($serverDirectory) && $serverDirectory !== ''
   ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/hyperf.swoole.log'
   : '/dev/null';

return [
   'mode'    => SWOOLE_BASE,
   'servers' => [
      [
         'name'       => 'http',
         'type'       => Hyperf\Server\Server::SERVER_HTTP,
         'host'       => '0.0.0.0',
         'port'       => $port,
         'sock_type'  => SWOOLE_SOCK_TCP,
         'callbacks'  => [
            Hyperf\Server\Event::ON_REQUEST => [
               Hyperf\HttpServer\Server::class, 'onRequest',
            ],
         ],
      ],
   ],
   'settings' => [
      Constant::OPTION_WORKER_NUM       => $workers,
      Constant::OPTION_DAEMONIZE        => (bool) (getenv('SERVER_DAEMONIZE') ?: false),
      Constant::OPTION_LOG_FILE         => $logFile,
      Constant::OPTION_LOG_LEVEL        => SWOOLE_LOG_ERROR,
      Constant::OPTION_OPEN_TCP_NODELAY => true,
      // Parity with the standalone Swoole (base) opponent: each worker accepts
      // via SO_REUSEPORT, and HTTP compression off (the Swoole opponent sets
      // both; hook_flags=SWOOLE_HOOK_ALL is already applied by Hyperf's
      // StartServer via Coroutine::set).
      Constant::OPTION_ENABLE_REUSE_PORT => true,
      Constant::OPTION_HTTP_COMPRESSION => false,
      Constant::OPTION_MAX_COROUTINE    => 100000,
   ],
];
