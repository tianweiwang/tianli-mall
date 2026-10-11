-- KEYS: account, destination. ARGV: id, version, type, audience, digest, stamp, expiry.
-- Validate all structure and expiry parameters before the first write.
local function hex(value, length)
    return type(value) == 'string' and #value == length and not string.find(value, '[^0-9a-f]')
end

local function expiry(value)
    if type(value) ~= 'string' or not string.match(value, '^[1-9]%d*$') or #value > 12 then
        return nil
    end
    local number = tonumber(value)
    if not number or number > 999999999999 then return nil end
    return number
end

if #KEYS ~= 2 or #ARGV ~= 7 or KEYS[1] == KEYS[2] then return {'AUTH'} end
if redis.call('TYPE', KEYS[1]).ok ~= 'hash' or redis.call('TYPE', KEYS[2]).ok ~= 'none' then
    return {'AUTH'}
end
if redis.call('PTTL', KEYS[1]) ~= -1 then return {'AUTH'} end

local account = redis.call('HMGET', KEYS[1], 'initialized', 'blocked', 'session_version', 'owner')
if account[1] ~= '1' or account[2] ~= '0' or not hex(account[3], 32)
    or account[3] ~= ARGV[2] or account[4] ~= false then
    return {'AUTH'}
end
if ARGV[1] == '' or ARGV[3] == '' or ARGV[4] == '' or not hex(ARGV[2], 32)
    or not hex(ARGV[5], 64) or not hex(ARGV[6], 64) then
    return {'AUTH'}
end

local expires = expiry(ARGV[7])
if not expires then return {'AUTH'} end
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local deadline = (expires + 60) * 1000
if deadline <= now or deadline - now > 2147483647000 then return {'AUTH'} end

redis.call('HSET', KEYS[2],
    'subject_id', ARGV[1], 'session_version', ARGV[2], 'type', ARGV[3],
    'audience', ARGV[4], 'token_digest', ARGV[5], 'credential_stamp', ARGV[6],
    'expires_at', ARGV[7], 'status', 'active')
redis.call('EXPIREAT', KEYS[2], expires + 60)
return {'OK'}
