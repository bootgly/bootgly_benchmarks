<?php
// @label: Database micro query
// @group: Database microbenchmarks
// @opponents: Bootgly
// Minimal SELECT 1 helper overhead.

return [
   'method' => 'GET',
   'paths' => ['/database/native/ping'],
   'readiness' => [
      'resources' => ['database'],
   ],
   'expect' => [
      'status' => 200,
      'contains' => ['"ok":1'],
   ],
];
