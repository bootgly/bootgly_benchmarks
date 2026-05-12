<?php
// @label: Database native sleep
// @group: Database async slow query
// @competitors: Bootgly
// Low-level ADI PostgreSQL slow query with event-loop wait.

return [
   'method' => 'GET',
   'paths'  => ['/database/native/sleep'],
];
