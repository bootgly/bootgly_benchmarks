<?php
// @label: Catch-all 404
// @group: Catch-all
// @competitors: all
// All requests hit non-existent paths, triggering the catch-all handler.

return [
   'method' => 'GET',
   'paths'  => array_map(fn ($i) => "/not-found-{$i}", range(0, 999)),
];
