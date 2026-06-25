<?php
// @label: Cached queries
// @group: TechEmpower
// @opponents: all
// TechEmpower-style /cached-queries?count=20: 20 random CachedWorld rows from an in-memory cache.

return [
   'method' => 'GET',
   'paths' => ['/cached-queries?count=20'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
