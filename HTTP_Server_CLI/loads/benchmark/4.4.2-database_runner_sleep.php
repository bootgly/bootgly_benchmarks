<?php
// @label: Database resource sleep
// @group: Database async probe
// @competitors: Bootgly
// Response resource ADI PostgreSQL slow query with event-loop wait.

return [
   'method' => 'GET',
   'paths' => ['/database/resource/sleep'],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42'],
   ],
];
