-- @label: 6 nested routes (2 groups)
-- @group: Nested routes
-- @competitors: all
-- Round-robins through admin and account group routes.

local paths = {
   "/admin/dashboard", "/admin/settings", "/admin/users",
   "/account/profile", "/account/billing", "/account/security"
}
local counter = 0

request = function()
   counter = counter + 1
   return wrk.format("GET", paths[(counter % #paths) + 1])
end
