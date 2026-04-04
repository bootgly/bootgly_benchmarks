-- @label: Mixed (10 static + 10 dynamic)
-- @group: Mixed workloads
-- @competitors: all
-- Realistic heavy workload combining all route types.

local statics = {"/", "/about", "/contact", "/blog", "/pricing", "/docs", "/faq", "/terms", "/privacy", "/status"}
local counter = 0

request = function()
   counter = counter + 1
   local mod = counter % 20

   -- 10 static routes
   if mod < 10 then
      return wrk.format("GET", statics[mod + 1])
   end

   -- 10 dynamic routes
   local dyn = mod - 10
   if dyn == 0 then
      return wrk.format("GET", "/user/" .. (counter % 100))
   elseif dyn == 1 then
      return wrk.format("GET", "/post/article-" .. (counter % 100))
   elseif dyn == 2 then
      return wrk.format("GET", "/api/v1/resource-" .. (counter % 100))
   elseif dyn == 3 then
      return wrk.format("GET", "/category/cat-" .. (counter % 100))
   elseif dyn == 4 then
      return wrk.format("GET", "/tag/tag-" .. (counter % 100))
   elseif dyn == 5 then
      return wrk.format("GET", "/product/sku-" .. (counter % 100))
   elseif dyn == 6 then
      return wrk.format("GET", "/order/ord-" .. (counter % 100))
   elseif dyn == 7 then
      return wrk.format("GET", "/invoice/inv-" .. (counter % 100))
   elseif dyn == 8 then
      return wrk.format("GET", "/review/rev-" .. (counter % 100))
   else
      return wrk.format("GET", "/comment/cmt-" .. (counter % 100))
   end
end
