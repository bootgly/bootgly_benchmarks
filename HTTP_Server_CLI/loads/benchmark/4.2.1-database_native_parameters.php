<?php
// @label: Database micro parameterized
// @group: Database microbenchmarks
// @opponents: Bootgly
// Low-level ADI PostgreSQL parameterized query.

return [
   'method' => 'GET',
   'paths' => ['/database/native/parameters'],
   'readiness' => [
      'resources' => ['database'],
   ],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42', '"label":"bootgly"'],
   ],
];
