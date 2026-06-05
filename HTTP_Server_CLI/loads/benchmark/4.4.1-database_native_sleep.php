<?php
// @label: Database micro sleep
// @group: Database async probe
// @competitors: Bootgly
// Low-level ADI PostgreSQL slow query with event-loop wait.

return [
   'method' => 'GET',
   'paths' => ['/database/native/sleep'],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42'],
   ],
];
