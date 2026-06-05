<?php
// @label: Database resource parameterized
// @group: Database Bootgly resources
// @competitors: Bootgly
// Response resource ADI PostgreSQL parameterized query.

return [
   'method' => 'GET',
   'paths' => ['/database/resource/parameters'],
   'expect' => [
      'status' => 200,
      'contains' => ['"value":42', '"label":"bootgly"'],
   ],
];
