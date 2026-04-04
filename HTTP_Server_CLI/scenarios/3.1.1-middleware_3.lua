-- @label: 3 middleware routes
-- @group: Middleware routes
-- @competitors: bootgly
-- Round-robins through protected routes that apply middleware.

local paths = {"/protected/dashboard", "/protected/settings", "/protected/profile"}
local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", paths[(counter % #paths) + 1])
end
