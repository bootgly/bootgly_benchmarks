<?php
// @label: Deferred upstream fan-out
// @group: Reactor async probes
// @opponents: Bootgly
// One deferred response per request, each parked on its own upstream socket.

return [
   'method' => 'GET',
   'paths' => ['/deferred/fanout'],
   'expect' => [
      'status' => 200,
      'contains' => ['upstream'],
   ],
];
