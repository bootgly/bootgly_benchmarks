-- @label: 1 static route
-- @group: Static routes
-- @competitors: all
-- Hits "/" on every request (best-case single-route lookup).

request = function()
   return wrk.format("GET", "/")
end
