<?php
// @label: Database micro sleep
// @group: Database async probe
// @opponents: Bootgly
// Low-level ADI PostgreSQL slow query with event-loop wait.

return [
   'method' => 'GET',
   'paths' => ['/database/native/sleep'],
   'readiness' => [
      'resources' => ['database'],
   ],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42'],
   ],
];
