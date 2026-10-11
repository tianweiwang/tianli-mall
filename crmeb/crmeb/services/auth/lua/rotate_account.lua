-- KEYS: account. ARGV: mode, new version, owner.
-- Only explicit credential-login init may initialize authorization state.
local function hex(value, length)
    return type(value) == 'string' and #value == length and not string.find(value, '[^0-9a-f]')
end

if #KEYS ~= 1 or #ARGV ~= 3 then return {'AUTH'} end
local mode = ARGV[1]
if mode ~= 'init' and mode ~= 'rotate' and mode ~= 'block' and mode ~= 'unblock'
    and mode ~= 'begin' and mode ~= 'complete' then
    return {'AUTH'}
end
if (mode == 'init' or mode == 'rotate' or mode == 'begin') and not hex(ARGV[2], 32) then
    return {'AUTH'}
end
if (mode == 'begin' or mode == 'complete') and not hex(ARGV[3], 32) then
    return {'AUTH'}
end

local kind = redis.call('TYPE', KEYS[1]).ok
if kind == 'none' then
    if mode == 'init' then
        redis.call('HSET', KEYS[1], 'initialized', '1', 'blocked', '0', 'session_version', ARGV[2])
        return {'OK', ARGV[2]}
    elseif mode == 'begin' then
        redis.call('HSET', KEYS[1], 'initialized', '0', 'blocked', '1', 'owner', ARGV[3])
        return {'OK', ARGV[3]}
    end
    return {'AUTH'}
end
if kind ~= 'hash' or redis.call('PTTL', KEYS[1]) ~= -1 then return {'AUTH'} end

local account = redis.call('HMGET', KEYS[1], 'initialized', 'blocked', 'session_version', 'owner')
local initialized, blocked, version, owner = account[1], account[2], account[3], account[4]
if (initialized ~= '0' and initialized ~= '1') or (blocked ~= '0' and blocked ~= '1')
    or (initialized == '1' and not hex(version, 32))
    or (initialized == '0' and version ~= false)
    or (owner ~= false and (not hex(owner, 32) or blocked ~= '1')) then
    return {'AUTH'}
end

if mode == 'complete' then
    if blocked ~= '1' or owner ~= ARGV[3] then return {'AUTH'} end
    -- Preserve initialized/version exactly; completion cannot mint authorization.
    redis.call('HDEL', KEYS[1], 'owner')
    redis.call('HSET', KEYS[1], 'blocked', '0')
    return {'OK'}
end
-- Ordinary operations must not alter an owner-held change protection.
if owner ~= false then return {'AUTH'} end

if mode == 'init' then
    if blocked ~= '0' then return {'AUTH'} end
    if initialized == '1' then return {'OK', version} end
    redis.call('HSET', KEYS[1], 'initialized', '1', 'session_version', ARGV[2])
    return {'OK', ARGV[2]}
elseif mode == 'rotate' then
    if initialized ~= '1' or version == ARGV[2] then return {'AUTH'} end
    redis.call('HSET', KEYS[1], 'session_version', ARGV[2])
    return {'OK', ARGV[2]}
elseif mode == 'block' then
    if blocked ~= '1' then redis.call('HSET', KEYS[1], 'blocked', '1') end
    return {'OK'}
elseif mode == 'unblock' then
    if blocked ~= '0' then redis.call('HSET', KEYS[1], 'blocked', '0') end
    return {'OK'}
elseif mode == 'begin' then
    if blocked ~= '0' or (initialized == '1' and version == ARGV[2]) then return {'AUTH'} end
    if initialized == '1' then
        redis.call('HSET', KEYS[1], 'blocked', '1', 'session_version', ARGV[2], 'owner', ARGV[3])
    else
        redis.call('HSET', KEYS[1], 'blocked', '1', 'owner', ARGV[3])
    end
    return {'OK', ARGV[3]}
end
return {'AUTH'}
