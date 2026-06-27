<?php
// @label: Connect rate
// @group: Connect
// @opponents: all
// Repeated open -> upgrade handshake -> close, in batches, until the duration
// elapses. Measures WebSocket handshake throughput — the "msg/s" column reports
// completed handshakes per second (conn/s).

return [
   'mode' => 'connect',
];
