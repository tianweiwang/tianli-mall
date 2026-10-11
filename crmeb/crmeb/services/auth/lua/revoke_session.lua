-- KEYS: account, session. ARGV: id, type, audience, version, exact token digest.
-- A signed identifier alone cannot authorize deletion of another binding.
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

if #KEYS ~= 2 or #ARGV ~= 5 or KEYS[1] == KEYS[2] then return {'AUTH'} end
if redis.call('TYPE', KEYS[1]).ok ~= 'hash' or redis.call('TYPE', KEYS[2]).ok ~= 'hash'
    or redis.call('PTTL', KEYS[1]) ~= -1 then
    return {'AUTH'}
end
local account = redis.call('HMGET', KEYS[1], 'initialized', 'blocked', 'session_version', 'owner')
if account[1] ~= '1' or account[2] ~= '0' or not hex(account[3], 32)
    or account[3] ~= ARGV[4] or account[4] ~= false then
    return {'AUTH'}
end
local session = redis.call('HMGET', KEYS[2], 'subject_id', 'type', 'audience',
    'session_version', 'token_digest', 'credential_stamp', 'expires_at', 'status')
if session[1] ~= ARGV[1] or session[2] ~= ARGV[2] or session[3] ~= ARGV[3]
    or session[4] ~= ARGV[4] or session[5] ~= ARGV[5] or session[8] ~= 'active'
    or not hex(session[4], 32) or not hex(session[5], 64) or not hex(session[6], 64) then
    return {'AUTH'}
end
local expires = expiry(session[7])
if not expires then return {'AUTH'} end
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local remaining = redis.call('PTTL', KEYS[2])
local deadline = (expires + 60) * 1000
if remaining <= 0 or remaining > 2147483647000 or deadline <= now
    or remaining > deadline - now + 1 then
    return {'AUTH'}
end
redis.call('DEL', KEYS[2])
return {'OK'}
