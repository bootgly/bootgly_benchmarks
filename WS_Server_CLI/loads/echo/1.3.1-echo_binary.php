<?php
// @label: Echo 32B (binary)
// @group: Echo
// @opponents: all
// Same closed loop as Echo 32B, but a BINARY frame — the server skips UTF-8
// validation (text frames only), isolating that cost (~10% on the reference
// machine). Otherwise identical: 32-byte payload, 1 message in flight.

return [
   'mode'    => 'echo',
   'payload' => str_repeat('x', 32),
   'binary'  => true,
];
