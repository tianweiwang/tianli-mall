-- One merchant, one policy, one expiring hash. Existing windows never extend.
-- Reply: outcome (1 admitted, 2 limited), used, remaining TTL, log event (0/1).
local function positive(value, maximum)
    if type(value) ~= 'string' or #value > #tostring(maximum)
        or not string.match(value, '^[1-9][0-9]*$') then return nil end
    local number = tonumber(value)
    if not number or number < 1 or number > maximum then return nil end
    return number
end

local function retryAfter(key, window)
    local milliseconds = redis.call('PTTL', key)
    if milliseconds < 0 or milliseconds > window * 1000 then return nil end
    return math.max(1, math.ceil(milliseconds / 1000))
end

if #KEYS ~= 1 or #ARGV ~= 2 then return {0} end
local window = positive(ARGV[1], 86400)
local maximum = positive(ARGV[2], 1000000)
if not window or not maximum then return {0} end

local key = KEYS[1]
local kind = redis.call('TYPE', key).ok
if kind == 'none' then
    if redis.call('HSET', key, 'used', '1', 'limited_logged', '0') ~= 2 then return {0} end
    if redis.call('EXPIRE', key, window) ~= 1 then return {0} end
    local ttl = retryAfter(key, window)
    if not ttl then return {0} end
    return {1, 1, ttl, 1}
end
if kind ~= 'hash' or redis.call('HLEN', key) ~= 2 then return {0} end
local values = redis.call('HMGET', key, 'used', 'limited_logged')
local used = positive(values[1], maximum)
local logged = values[2]
local ttl = retryAfter(key, window)
if not used or (logged ~= '0' and logged ~= '1') or not ttl
    or (logged == '1' and used ~= maximum) then return {0} end

if used < maximum then
    local nextUsed = redis.call('HINCRBY', key, 'used', 1)
    if nextUsed ~= used + 1 then return {0} end
    return {1, nextUsed, ttl, 1}
end

local log = 0
if logged == '0' then
    if redis.call('HSET', key, 'limited_logged', '1') ~= 0 then return {0} end
    log = 1
end
return {2, used, ttl, log}
