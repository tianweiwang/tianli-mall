-- KEYS: one purpose-bound account/IP failure counter. ARGV: read/failure/clear.
-- Raw digits only; the first genuine credential failure starts a fixed 60s window.
if #KEYS ~= 1 or #ARGV ~= 1 or KEYS[1] == '' then return {'UNAVAILABLE'} end
local mode = ARGV[1]
if mode ~= 'read' and mode ~= 'failure' and mode ~= 'clear' then return {'UNAVAILABLE'} end

local kind = redis.call('TYPE', KEYS[1]).ok
if kind == 'none' then
    if mode == 'failure' then
        redis.call('SETEX', KEYS[1], 60, '1')
        return {'OK', '1'}
    end
    return {'OK', '0'}
end
if kind ~= 'string' then return {'UNAVAILABLE'} end

local value = redis.call('GET', KEYS[1])
if type(value) ~= 'string' or not string.match(value, '^[1-9]%d*$') or #value > 19
    or (#value == 19 and value > '9223372036854775807') then
    return {'UNAVAILABLE'}
end
local remaining = redis.call('PTTL', KEYS[1])
if remaining < 0 or remaining > 60000 then return {'UNAVAILABLE'} end

if mode == 'read' then return {'OK', value} end
if mode == 'clear' then
    return {'OK', tostring(redis.call('DEL', KEYS[1]))}
end

-- INCR preserves the existing deadline; GET keeps large counts as exact text.
redis.call('INCR', KEYS[1])
return {'OK', redis.call('GET', KEYS[1])}
