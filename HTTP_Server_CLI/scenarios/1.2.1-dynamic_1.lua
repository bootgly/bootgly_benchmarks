-- @label: 1 dynamic route
-- @group: Dynamic routes
-- @competitors: all
-- Hits /user/:id with rotating IDs.

local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", "/user/" .. (counter % 100))
end
