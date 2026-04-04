-- @label: Full mix (all types)
-- @group: Mixed workloads
-- @competitors: all
-- Realistic workload: static + dynamic + nested + middleware + catch-all.

local counter = 0

request = function()
   counter = counter + 1
   local mod = counter % 15

   -- Static routes (5)
   if mod == 0 then return wrk.format("GET", "/")
   elseif mod == 1 then return wrk.format("GET", "/about")
   elseif mod == 2 then return wrk.format("GET", "/blog")
   elseif mod == 3 then return wrk.format("GET", "/docs")
   elseif mod == 4 then return wrk.format("GET", "/status")
   -- Dynamic routes (3)
   elseif mod == 5 then return wrk.format("GET", "/user/" .. (counter % 100))
   elseif mod == 6 then return wrk.format("GET", "/post/article-" .. (counter % 100))
   elseif mod == 7 then return wrk.format("GET", "/product/sku-" .. (counter % 100))
   -- Nested routes (3)
   elseif mod == 8 then return wrk.format("GET", "/admin/dashboard")
   elseif mod == 9 then return wrk.format("GET", "/admin/users")
   elseif mod == 10 then return wrk.format("GET", "/account/profile")
   -- Middleware routes (2)
   elseif mod == 11 then return wrk.format("GET", "/protected/dashboard")
   elseif mod == 12 then return wrk.format("GET", "/protected/settings")
   -- Catch-all (2)
   elseif mod == 13 then return wrk.format("GET", "/unknown-" .. (counter % 100))
   else return wrk.format("GET", "/missing-page")
   end
end
