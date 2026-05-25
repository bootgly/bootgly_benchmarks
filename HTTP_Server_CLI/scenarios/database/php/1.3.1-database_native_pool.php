<?php
// @label: Database micro multi-query
// @group: Database microbenchmarks
// @competitors: Bootgly,Swoole Database
// Three independent SELECT operations in one request.

return [
   'method' => 'GET',
   'paths' => ['/database/native/pool'],
   'expect' => [
      'status' => 200,
      'contains' => ['"errors":[]', '"value":1', '"value":2', '"value":3'],
   ],
];
