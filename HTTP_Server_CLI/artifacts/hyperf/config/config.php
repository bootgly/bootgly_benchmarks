<?php
/**
 * Minimal Hyperf config for benchmark.
 */

declare(strict_types=1);

use Hyperf\Contract\StdoutLoggerInterface;

return [
   'app_name'  => 'hyperf-benchmark',
   'app_env'   => 'production',
   'scan_cacheable' => true,
   StdoutLoggerInterface::class => [
      'log_level' => [
         Psr\Log\LogLevel::ERROR,
      ],
   ],
];
