<?php
// @label: Database micro query
// @group: Database microbenchmarks
// @competitors: Bootgly,Swoole Database
// Minimal SELECT 1 helper overhead.

return [
   'method' => 'GET',
   'paths' => ['/database/native/ping'],
   'expect' => [
      'status' => 200,
      'contains' => ['"ok":1'],
   ],
];
