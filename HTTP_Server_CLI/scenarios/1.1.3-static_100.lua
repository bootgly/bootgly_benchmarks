-- @label: 100 static routes
-- @group: Static routes
-- @competitors: all
-- Round-robins through all 100 registered static routes.

local paths = {"/", "/about", "/contact", "/blog", "/pricing", "/docs", "/faq", "/terms", "/privacy", "/status"}
for i = 11, 100 do
   paths[#paths + 1] = "/static/" .. i
end

local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", paths[(counter % #paths) + 1])
end
