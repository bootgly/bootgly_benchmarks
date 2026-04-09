-- @label: 100 dynamic routes
-- @group: Dynamic routes
-- @competitors: all
-- Round-robins through all 100 registered dynamic routes.

local prefixes = {"/user/", "/post/article-", "/api/v1/resource-", "/category/cat-", "/tag/tag-", "/product/sku-", "/order/ord-", "/invoice/inv-", "/review/rev-", "/comment/cmt-"}
for i = 11, 100 do
   prefixes[#prefixes + 1] = "/d" .. i .. "/"
end

local counter = 0

request = function()
   counter = counter + 1
   local idx = (counter % #prefixes) + 1
   return wrk.format("GET", prefixes[idx] .. (counter % 100))
end
