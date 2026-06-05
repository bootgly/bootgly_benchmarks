<?php
// @label: Database fortunes
// @group: TechEmpower
// @competitors: all
// TechEmpower-style /fortunes: read Fortune table, append a runtime row,
// sort by message, render as HTML table.

return [
   'method' => 'GET',
   'paths' => ['/fortunes'],
   'expect' => [
      'status' => 200,
      'contains' => ['<table>', 'fortune'],
   ],
];
