<?php
// @label: Plaintext
// @group: TechEmpower
// @competitors: all
// TechEmpower-style /plaintext: text/plain "Hello, World!".

return [
   'method' => 'GET',
   'paths' => ['/plaintext'],
   'expect' => [
      'status' => 200,
      'contains' => ['Hello, World!'],
   ],
];
