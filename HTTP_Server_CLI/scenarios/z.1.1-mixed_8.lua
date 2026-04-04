-- @label: Mixed (5 static + 3 dynamic)
-- @group: Mixed workloads
-- @competitors: all
-- Realistic workload combining both route types.

local counter = 0

request = function()
   counter = counter + 1
   local mod = counter % 8

   if mod == 0 then return wrk.format("GET", "/")
   elseif mod == 1 then return wrk.format("GET", "/about")
   elseif mod == 2 then return wrk.format("GET", "/contact")
   elseif mod == 3 then return wrk.format("GET", "/blog")
   elseif mod == 4 then return wrk.format("GET", "/pricing")
   elseif mod == 5 then return wrk.format("GET", "/user/" .. (counter % 100))
   elseif mod == 6 then return wrk.format("GET", "/post/article-" .. (counter % 100))
   else return wrk.format("GET", "/api/v1/resource-" .. (counter % 100))
   end
end
