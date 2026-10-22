-- Dedicated product-media-scan-v1 protocol. Never touches auth/session keys.
-- KEYS: live, terminal, lease, items, issuer, gc-cursor. ARGV: op, base, id, issuer, root, limits JSON, payload JSON.
local op, base, id, issuer, root = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
local function hex(v,n) return type(v)=='string' and #v==n and not string.find(v,'[^0-9a-f]') end
local function uint(v,max) return type(v)=='string' and string.match(v,'^%d+$') and (#v==1 or string.sub(v,1,1)~='0') and tonumber(v)<=max end
if #KEYS~=6 or #ARGV~=7 or type(base)~='string' or #base>256 or not hex(id,32) or not uint(issuer,4294967295) or not hex(root,64) then return {'INVALID'} end
if KEYS[1]~=base..'live' or KEYS[2]~=base..'terminal' or KEYS[3]~=base..'lease:'..id or KEYS[4]~=base..'items:'..id or KEYS[5]~=base..'issuer:'..issuer or KEYS[6]~=base..'gc-cursor' then return {'INVALID'} end
local ok, limits=pcall(cjson.decode,ARGV[6]); local okp,p=pcall(cjson.decode,ARGV[7])
if not ok or not okp or type(limits)~='table' or type(p)~='table' then return {'INVALID'} end
for _,k in ipairs({'open','issuer','terminal','batch'}) do if type(limits[k])~='number' or limits[k]<1 or limits[k]~=math.floor(limits[k]) or limits[k]>2147483647 then return {'INVALID'} end end
local function typ(k,t) local v=redis.call('TYPE',k).ok;return v=='none' or v==t end
if not typ(KEYS[1],'zset') or not typ(KEYS[2],'zset') or not typ(KEYS[3],'hash') or not typ(KEYS[4],'hash') or not typ(KEYS[5],'set') or not typ(KEYS[6],'string') then return {'INVALID'} end
local clock=redis.call('TIME');local now=tonumber(clock[1])*1000+math.floor(tonumber(clock[2])/1000)
if now<1 or now>9007199254140991 then return {'INVALID'} end
local function text(n) return string.format('%.0f',n) end
local function get(k) return redis.call('HGET',KEYS[3],k) end
local function set(k,v) redis.call('HSET',KEYS[3],k,v) end
local function result(code,doc) return {code,cjson.encode(doc or {})} end
local function snapshot()
 local d={lease_id=id,items={},now_ms=text(now)};local h=redis.call('HGETALL',KEYS[3]);for i=1,#h,2 do d[h[i]]=h[i+1] end
 d.binding=cjson.decode(d.binding_json);d.binding_json=nil
 local vals=redis.call('HVALS',KEYS[4]);if #vals>50 then error('bounded items') end
 for _,v in ipairs(vals) do table.insert(d.items,cjson.decode(v)) end
 table.sort(d.items,function(a,b)return a.sequence<b.sequence end)
 return d
end
local function bump() redis.call('HINCRBY',KEYS[3],'revision',1) end
local function validLease()
 return get('schema')=='1' and get('purpose')=='product-media-scan-v1' and get('root_id')==root and (get('issuer_id')==issuer or (issuer=='0' and op=='read'))
  and hex(get('binding_digest'),64) and uint(get('expires_at_ms'),9007199254740991)
end
if op=='gc_due' then
 if type(p.limit)~='number' or p.limit<1 or p.limit>limits.batch or p.limit>256 or p.limit~=math.floor(p.limit) then return {'INVALID'} end
 local expired=redis.call('ZRANGEBYSCORE',KEYS[2],'-inf',now,'LIMIT',0,256)
 for _,v in ipairs(expired) do
  if not hex(v,32) then return {'INVALID'} end;local k=base..'lease:'..v
  if redis.call('HGET',k,'state')~='CLOSED' or redis.call('HGET',k,'cleanup_complete')~='1' then return {'INVALID'} end
  if redis.call('HGET',k,'root_id')==root then redis.call('ZREM',KEYS[2],v);redis.call('DEL',k,base..'items:'..v) end
 end
 local due=redis.call('ZCOUNT',KEYS[1],'-inf',now);if due==0 then return result('OK',{ids={},cursor='0'}) end
 local cursor=redis.call('GET',KEYS[6]) or '0';if not uint(cursor,2147483647) then return {'INVALID'} end
 local start=tonumber(cursor)%due;local n=math.min(p.limit,256,due-start)
 local ids=redis.call('ZRANGE',KEYS[1],start,start+n-1);for _,v in ipairs(ids) do if not hex(v,32) then return {'INVALID'} end end
 local next=(start+n)%due;redis.call('SET',KEYS[6],text(next));return result('OK',{ids=ids,cursor=text(next)})
end
if op=='reserve_lease' then
 if redis.call('EXISTS',KEYS[3])~=0 or redis.call('ZSCORE',KEYS[1],id) or redis.call('ZSCORE',KEYS[2],id) then return {'INVALID'} end
 if redis.call('ZCARD',KEYS[1])>=limits.open or redis.call('SCARD',KEYS[5])>=limits.issuer then return result('CAPACITY') end
 if not hex(p.cap_hash,64) or not hex(p.binding_digest,64) or type(p.binding_json)~='string' or #p.binding_json>4096 then return {'INVALID'} end
 local expires=now+600000
 redis.call('HSET',KEYS[3],'schema','1','purpose','product-media-scan-v1','root_id',root,'issuer_id',issuer,
  'binding_json',p.binding_json,'binding_digest',p.binding_digest,'cap_hash',p.cap_hash,'issued_at_ms',text(now),'expires_at_ms',text(expires),
  'state','ALLOCATING','revision','1','count','0','bytes','0','next_sequence','1','claim_id','','selection_digest','','destination_pid','',
  'plan_hash','','commit_hash','','receipt_json','','manifest_hash','','cleanup_complete','','close_requested','0')
 redis.call('ZADD',KEYS[1],expires,id);redis.call('SADD',KEYS[5],id);return result('OK',snapshot())
end
if redis.call('EXISTS',KEYS[3])==0 then return result('GONE') end
if not validLease() then return result('FOREIGN') end
if op=='read' then return result('OK',snapshot()) end
if p.binding_digest~=get('binding_digest') then return {'INVALID'} end
local state=get('state');local expired=now>=tonumber(get('expires_at_ms'))
local function pending()
 for _,v in ipairs(redis.call('HVALS',KEYS[4])) do local item=cjson.decode(v);if item.state=='PENDING' or item.state=='CANCELLING' then return true end end
 return false
end
if op=='private_unknown' then
 if state=='CLOSED' then return result('CLOSED',snapshot()) end
 if type(p.receipt_json)~='string' or #p.receipt_json>2048 then return {'INVALID'} end
 set('receipt_json',p.receipt_json);set('state','UNKNOWN');bump();return result('OK',snapshot())
elseif op=='publish_lease' then
 if state~='ALLOCATING' or expired or not hex(p.manifest_hash,64) then return result('UNKNOWN',snapshot()) end
 set('manifest_hash',p.manifest_hash);set('state','OPEN');bump();return result('OK',snapshot())
elseif op=='reserve_upload' then
 if state~='OPEN' or expired then return result('GONE',snapshot()) end
 if not hex(p.stage_id,32) or not hex(p.upload_op_id,32) or not uint(p.size,52428800) or tonumber(p.size)<1 or not hex(p.sha256,64) then return {'INVALID'} end
 if redis.call('HEXISTS',KEYS[4],p.stage_id)~=0 then return {'INVALID'} end
 if tonumber(get('count'))>=50 or tonumber(get('bytes'))+tonumber(p.size)>67108864 then return result('CAPACITY') end
 local seq=tonumber(get('next_sequence'));if seq>2147483647 then return {'INVALID'} end
 local item={stage_id=p.stage_id,upload_op_id=p.upload_op_id,sequence=seq,state='PENDING',size=tonumber(p.size),sha256=p.sha256,qualified=p.qualified,original_name=p.original_name}
 redis.call('HSET',KEYS[4],p.stage_id,cjson.encode(item));redis.call('HINCRBY',KEYS[3],'count',1);redis.call('HINCRBY',KEYS[3],'bytes',p.size);redis.call('HINCRBY',KEYS[3],'next_sequence',1);bump()
 return result('OK',{item=item,lease=snapshot()})
elseif op=='publish_upload' or op=='cancel_check' or op=='cancel_confirmed' then
 if not hex(p.stage_id,32) or not hex(p.upload_op_id,32) then return {'INVALID'} end
 local raw=redis.call('HGET',KEYS[4],p.stage_id);if not raw then return result('GONE') end
 local item=cjson.decode(raw);if item.upload_op_id~=p.upload_op_id then return {'INVALID'} end
 if op=='publish_upload' then
  if item.state~='PENDING' then return result('UNKNOWN',snapshot()) end
  if state~='OPEN' or expired then return result('GONE',snapshot()) end
  if type(p.proof)~='table' or p.proof.sha256~=item.sha256 or p.proof.size~=item.size then return {'INVALID'} end
  item.state='READY';item.proof=p.proof;redis.call('HSET',KEYS[4],p.stage_id,cjson.encode(item));bump();return result('OK',{stage_id=p.stage_id,sequence=item.sequence,state='ready'})
 elseif op=='cancel_check' then
  if item.state~='PENDING' and item.state~='CANCELLING' then return result('UNKNOWN',snapshot()) end
  item.state='CANCELLING';redis.call('HSET',KEYS[4],p.stage_id,cjson.encode(item));bump();return result('OK',{item=item,lease=snapshot()})
 else
  if item.state~='CANCELLING' or p.cleanup_complete~=true then return result('UNKNOWN') end
  redis.call('HDEL',KEYS[4],p.stage_id);redis.call('HINCRBY',KEYS[3],'count',-1);redis.call('HINCRBY',KEYS[3],'bytes',-item.size);bump();return result('OK',snapshot())
 end
elseif op=='begin_claim' then
 if not hex(p.claim_id,32) or not hex(p.selection_digest,64) or type(p.ids)~='table' or #p.ids<1 or #p.ids>50 or not uint(p.destination_pid,2147483647) then return {'INVALID'} end
 if state=='CLAIMED' or state=='CLAIMING' or state=='UNKNOWN' then
  if state=='UNKNOWN' and get('claim_id')=='' then return result('UNKNOWN',snapshot()) end
  if get('selection_digest')~=p.selection_digest or get('destination_pid')~=p.destination_pid then return result('MISMATCH') end
  local code=state=='CLAIMED' and 'DONE' or (state=='CLAIMING' and 'PROCESSING' or 'UNKNOWN');return result(code,snapshot())
 end
 if state~='OPEN' or expired then return result('GONE',snapshot()) end
 if pending() then return result('PROCESSING',snapshot()) end
 local seen={};for _,v in ipairs(p.ids) do
  if not hex(v,32) or seen[v] then return {'INVALID'} end;seen[v]=true
  local raw=redis.call('HGET',KEYS[4],v);if not raw or cjson.decode(raw).state~='READY' then return result('MISMATCH') end
 end
 set('claim_id',p.claim_id);set('selection_digest',p.selection_digest);set('destination_pid',p.destination_pid);set('selection_json',cjson.encode(p.ids));set('state','CLAIMING');set('claim_phase','START');bump();return result('START',snapshot())
elseif op=='seal_plan' or op=='sql_attempted' or op=='claimed' or op=='unknown' then
 if not hex(p.claim_id,32) or get('claim_id')~=p.claim_id or get('selection_digest')~=p.selection_digest then return {'INVALID'} end
 if op=='unknown' then
  if state~='CLAIMING' and state~='UNKNOWN' then return result('UNKNOWN',snapshot()) end
  if type(p.receipt_json)~='string' or #p.receipt_json>2048 then return {'INVALID'} end
  set('receipt_json',p.receipt_json);set('state','UNKNOWN');bump();return result('OK',snapshot())
 end
 if state~='CLAIMING' then return result('UNKNOWN',snapshot()) end
 if op=='seal_plan' then
  if get('claim_phase')~='START' or not hex(p.plan_hash,64) then return {'INVALID'} end
  set('plan_hash',p.plan_hash);set('claim_phase','PREPARED')
 elseif op=='sql_attempted' then
  if get('claim_phase')~='PREPARED' or get('plan_hash')~=p.plan_hash then return {'INVALID'} end
  set('claim_phase','SQL_ATTEMPTED')
 else
  if get('claim_phase')~='SQL_ATTEMPTED' or get('plan_hash')~=p.plan_hash or not hex(p.commit_hash,64) then return {'INVALID'} end
  set('commit_hash',p.commit_hash);set('claim_phase','COMMITTED');set('state','CLAIMED')
  if get('close_requested')=='1' then redis.call('ZADD',KEYS[1],now,id) end
 end
 bump();return result('OK',snapshot())
elseif op=='begin_close' then
 if state=='CLOSED' then return result('CLOSED',snapshot()) end
 set('close_requested','1')
 if state=='CLAIMING' or state=='UNKNOWN' then bump();return result('PENDING_CLAIM',snapshot()) end
 if state=='ALLOCATING' and p.owned_allocation~=true then return result('UNKNOWN',snapshot()) end
 set('state','CLOSING');redis.call('ZADD',KEYS[1],now,id);bump()
 return result(pending() and 'PENDING_UPLOAD' or 'OK',snapshot())
elseif op=='close_confirmed' then
 if state=='CLOSED' then return result('CLOSED',snapshot()) end
 if state~='CLOSING' or pending() or p.cleanup_complete~=true then return result('UNKNOWN',snapshot()) end
 set('state','CLOSED');set('cleanup_complete','1');set('cap_hash','');bump()
 redis.call('ZREM',KEYS[1],id);redis.call('SREM',KEYS[5],id);redis.call('ZADD',KEYS[2],tonumber(get('expires_at_ms')),id);redis.call('DEL',KEYS[4])
 local size=redis.call('ZCARD',KEYS[2]);local excess=math.max(0,size-limits.terminal)
 -- Bounded terminal eviction; no active/unknown responsibility is evicted.
 if excess>0 then
  local ids=redis.call('ZRANGE',KEYS[2],0,math.min(excess,256)-1)
  for _,v in ipairs(ids) do
   if not hex(v,32) then return {'INVALID'} end
   local k=base..'lease:'..v
   if redis.call('HGET',k,'state')~='CLOSED' or redis.call('HGET',k,'cleanup_complete')~='1' then return {'INVALID'} end
   if redis.call('HGET',k,'root_id')==root then redis.call('ZREM',KEYS[2],v);redis.call('DEL',k,base..'items:'..v) end
  end
 end
 return result('CLOSED',{lease_id=id,state='CLOSED',cleanup_complete=true})
end
return {'INVALID'}
