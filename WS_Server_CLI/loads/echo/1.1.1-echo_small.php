<?php
// @label: Echo 32B
// @group: Echo
// @opponents: all
// Persistent connections; each sends a 32-byte text frame, the server echoes
// it, the client resends on receipt (1 message in flight per connection).
// Baseline WebSocket framing throughput with a minimal payload.

return [
   'mode'    => 'echo',
   'payload' => str_repeat('x', 32),
   'binary'  => false,
];
