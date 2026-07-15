<?php
// @label: Database resource cached
// @group: Database Bootgly resources
// @opponents: Bootgly
// Route response cache probe: same deferred DB handler as resource ping, but
// the route opts in with `cache: 1` — hits are served from stored wire bytes.

return [
   'method' => 'GET',
   'paths' => ['/database/resource/cached'],
   'readiness' => [
      'resources' => ['database'],
   ],
   'expect' => [
      'status' => 200,
      'contains' => ['"ok":1'],
   ],
];
