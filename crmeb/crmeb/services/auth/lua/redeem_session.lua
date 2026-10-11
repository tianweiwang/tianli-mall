-- KEYS: account, old session, destination.
-- ARGV: id, version, type, audience, new digest, stamp, new expiry, previous digest.
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

if #KEYS ~= 3 or #ARGV ~= 8 or KEYS[1] == KEYS[2] or KEYS[1] == KEYS[3]
    or KEYS[2] == KEYS[3] then
    return {'AUTH'}
end
if redis.call('TYPE', KEYS[1]).ok ~= 'hash' or redis.call('TYPE', KEYS[2]).ok ~= 'hash'
    or redis.call('TYPE', KEYS[3]).ok ~= 'none' then
    return {'AUTH'}
end
if redis.call('PTTL', KEYS[1]) ~= -1 then return {'AUTH'} end
local account = redis.call('HMGET', KEYS[1], 'initialized', 'blocked', 'session_version', 'owner')
if account[1] ~= '1' or account[2] ~= '0' or not hex(account[3], 32)
    or account[3] ~= ARGV[2] or account[4] ~= false then
    return {'AUTH'}
end
if ARGV[1] == '' or ARGV[3] == '' or ARGV[4] == '' or not hex(ARGV[2], 32)
    or not hex(ARGV[5], 64) or not hex(ARGV[6], 64) or not hex(ARGV[8], 64)
    or ARGV[5] == ARGV[8] then
    return {'AUTH'}
end

local old = redis.call('HMGET', KEYS[2], 'subject_id', 'type', 'audience',
    'session_version', 'token_digest', 'credential_stamp', 'expires_at', 'status')
if old[1] ~= ARGV[1] or old[2] ~= ARGV[3] or old[3] ~= ARGV[4]
    or old[4] ~= ARGV[2] or old[5] ~= ARGV[8] or old[6] ~= ARGV[6]
    or old[8] ~= 'active' or not hex(old[4], 32)
    or not hex(old[5], 64) or not hex(old[6], 64) then
    return {'AUTH'}
end
local oldExpires = expiry(old[7])
local newExpires = expiry(ARGV[7])
if not oldExpires or not newExpires then return {'AUTH'} end
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local oldRemaining = redis.call('PTTL', KEYS[2])
local oldDeadline = (oldExpires + 60) * 1000
local newDeadline = (newExpires + 60) * 1000
if oldRemaining <= 0 or oldRemaining > 2147483647000 or oldDeadline <= now
    or oldRemaining > oldDeadline - now + 1 or newDeadline <= now
    or newDeadline - now > 2147483647000 then
    return {'AUTH'}
end

-- Every precondition has passed. The old key keeps its original expiry.
redis.call('HSET', KEYS[2], 'status', 'consumed')
redis.call('HSET', KEYS[3],
    'subject_id', ARGV[1], 'session_version', ARGV[2], 'type', ARGV[3],
    'audience', ARGV[4], 'token_digest', ARGV[5], 'credential_stamp', ARGV[6],
    'expires_at', ARGV[7], 'status', 'active')
redis.call('EXPIREAT', KEYS[3], newExpires + 60)
return {'OK'}
