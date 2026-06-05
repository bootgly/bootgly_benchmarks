<?php
// @label: Echo 32 bytes
// @group: Echo
// @competitors: all
// Sends a 32-byte newline-terminated datagram. Server echoes it back.
// Measures raw UDP framework overhead with minimal payload.

return [
   'message' => str_repeat('x', 31) . "\n",
];
