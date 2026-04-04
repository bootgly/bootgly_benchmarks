-- @label: 3 dynamic routes
-- @group: Dynamic routes
-- @competitors: all
-- Round-robins through /user/:id, /post/:slug, /api/v1/:resource.

local counter = 0

request = function()
   counter = counter + 1
   local mod = counter % 3

   if mod == 0 then
      return wrk.format("GET", "/user/" .. (counter % 100))
   elseif mod == 1 then
      return wrk.format("GET", "/post/article-" .. (counter % 100))
   else
      return wrk.format("GET", "/api/v1/resource-" .. (counter % 100))
   end
end
