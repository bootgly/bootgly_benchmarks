<?php
// @label: Broadcast fan-out
// @group: Broadcast
// @opponents: all
// Every connection joins one server channel; two "ping-pong" senders keep the
// fan-out alive (each resends on receipt) while every connection counts the
// frames it receives. Measures server-side broadcast throughput — the received
// fan-out rate scales with the connection count.

return [
   'mode'    => 'broadcast',
   'payload' => str_repeat('x', 32),
   'binary'  => false,
];
