<?php
// @label: Database micro parameterized
// @group: Database microbenchmarks
// @competitors: Bootgly,Swoole Database
// Low-level ADI PostgreSQL parameterized query.

return [
   'method' => 'GET',
   'paths' => ['/database/native/parameters'],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42', '"label":"bootgly"'],
   ],
];
