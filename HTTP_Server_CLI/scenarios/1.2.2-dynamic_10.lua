-- @label: 10 dynamic routes
-- @group: Dynamic routes
-- @competitors: all
-- Round-robins through all 10 registered dynamic routes.

local counter = 0

request = function()
   counter = counter + 1
   local mod = counter % 10

   if mod == 0 then
      return wrk.format("GET", "/user/" .. (counter % 100))
   elseif mod == 1 then
      return wrk.format("GET", "/post/article-" .. (counter % 100))
   elseif mod == 2 then
      return wrk.format("GET", "/api/v1/resource-" .. (counter % 100))
   elseif mod == 3 then
      return wrk.format("GET", "/category/cat-" .. (counter % 100))
   elseif mod == 4 then
      return wrk.format("GET", "/tag/tag-" .. (counter % 100))
   elseif mod == 5 then
      return wrk.format("GET", "/product/sku-" .. (counter % 100))
   elseif mod == 6 then
      return wrk.format("GET", "/order/ord-" .. (counter % 100))
   elseif mod == 7 then
      return wrk.format("GET", "/invoice/inv-" .. (counter % 100))
   elseif mod == 8 then
      return wrk.format("GET", "/review/rev-" .. (counter % 100))
   else
      return wrk.format("GET", "/comment/cmt-" .. (counter % 100))
   end
end
