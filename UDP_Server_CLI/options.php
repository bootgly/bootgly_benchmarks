<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Server_CLI case options
 * --------------------------------------------------------------------------
 */

return [
   'server-workers' => [
      'type' => 'int',
      'default' => null,   // auto
      'vary' => true,
      'description' => 'Number of server workers (default: auto)',
   ],
];
