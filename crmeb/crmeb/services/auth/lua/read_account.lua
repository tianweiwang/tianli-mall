-- KEYS: account. No arguments. Never initialize or renew account state.
if #KEYS ~= 1 or #ARGV ~= 0 then return {'AUTH'} end
if redis.call('TYPE', KEYS[1]).ok ~= 'hash' or redis.call('PTTL', KEYS[1]) ~= -1 then
    return {'AUTH'}
end
local account = redis.call('HMGET', KEYS[1], 'initialized', 'blocked', 'session_version', 'owner')
if account[1] ~= '1' or account[2] ~= '0'
    or type(account[3]) ~= 'string' or #account[3] ~= 32
    or string.find(account[3], '[^0-9a-f]') or account[4] ~= false then
    return {'AUTH'}
end
return {'OK', account[3]}
