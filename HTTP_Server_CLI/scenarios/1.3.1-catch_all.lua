-- @label: Catch-all 404
-- @group: Catch-all
-- @competitors: all
-- All requests hit non-existent paths, triggering the catch-all handler.

local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", "/not-found-" .. (counter % 1000))
end
