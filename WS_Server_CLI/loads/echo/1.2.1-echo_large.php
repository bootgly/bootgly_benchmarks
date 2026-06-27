<?php
// @label: Echo 4KB
// @group: Echo
// @opponents: all
// Same closed loop with a 4 KiB text payload — measures framing plus payload
// copy cost at size.

return [
   'mode'    => 'echo',
   'payload' => str_repeat('x', 4096),
   'binary'  => false,
];
