-- @label: 10 static routes
-- @group: Static routes
-- @competitors: all
-- Round-robins through all 10 registered static routes.

local paths = {"/", "/about", "/contact", "/blog", "/pricing", "/docs", "/faq", "/terms", "/privacy", "/status"}
local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", paths[(counter % #paths) + 1])
end
