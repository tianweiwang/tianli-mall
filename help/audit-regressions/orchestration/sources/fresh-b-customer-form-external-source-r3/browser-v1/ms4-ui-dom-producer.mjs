/** Finite normal DOM producer. Root supplies genuine Tab and real observer ACKs. */
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { inflateSync } from 'node:zlib';
const ARTIFACTS=['compose.yml','interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','README.md','seed-source-inverse.json','source-derivation.json','verify-zero-ms4-fulfillment.py','ms4-ui-nginx.conf','ms4-ui-public-copy.php','ms4-ui-copy.php','ms4-order.png','manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json','manifests/ui-admin-dist.json','manifests/ui-public-copy-profile.json'];
const STEPS=['login-customer','login-merchant','login-platform','n1-initial-read','n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read','n1-merchant-approve','ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1','receive-q1-all-eligible','n2-public-image-upload','n2-review-submit','n2-pending-public-read','n2-platform-moderate','n2-merchant-reply','n2-public-review-read','n2-public-image-read'];
const ROLES=['customer','merchant','platform'];
const WINDOW_KEYS=['schema','scope','status','binding','baseline','steps','final','observer_source','observer_native','preservation','diagnostic','full_acceptance'];
const BINDING_KEYS=['run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin','source_closure'];
const LEDGER_KEYS=['schema','scope','binding','index','point','database','redis','files'];
const need=(ok)=>{if(!ok)throw new Error('DOM_PRODUCER_UNPROVED');};
const object=v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
const hex=(v,n=64)=>typeof v==='string'&&new RegExp('^[a-f0-9]{'+n+'}$').test(v);
const integer=(v,min=0)=>Number.isSafeInteger(v)&&v>=min;
const sha=v=>createHash('sha256').update(v).digest('hex');
const pin=v=>({bytes:v.length,sha256:sha(v)});
function copy(v,depth=0){
  need(depth<=64);
  if(v===null||typeof v==='string'||typeof v==='boolean')return v;
  if(typeof v==='number'){need(Number.isFinite(v));return v;}
  need(v&&typeof v==='object');const d=Object.getOwnPropertyDescriptors(v);
  if(Array.isArray(v)){need(Reflect.ownKeys(d).length===v.length+1);return Array.from({length:v.length},(_,i)=>{need(d[i]&&'value'in d[i]&&d[i].enumerable);return copy(d[i].value,depth+1);});}
  need(Object.getPrototypeOf(v)===Object.prototype||Object.getPrototypeOf(v)===null);const out=Object.create(null);
  for(const k of Reflect.ownKeys(d)){need(typeof k==='string'&&d[k].enumerable&&'value'in d[k]);out[k]=copy(d[k].value,depth+1);}return out;
}
function canonical(v){if(Array.isArray(v))return v.map(canonical);if(!object(v))return v;return Object.fromEntries(Object.keys(v).sort().map(k=>[k,canonical(v[k])]));}
const same=(a,b)=>JSON.stringify(canonical(a))===JSON.stringify(canonical(b));
const keys=(v,k)=>need(object(v)&&same(Object.keys(v).sort(),[...k].sort()));
const json=raw=>{try{return JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(raw));}catch{throw new Error('DOM_PUBLIC_JSON_UNPROVED');}};
function desc(v,expected){keys(v,['path','bytes','sha256']);need(v.path===expected&&integer(v.bytes)&&hex(v.sha256));}
function validPin(v){keys(v,['bytes','sha256']);need(integer(v.bytes,1)&&hex(v.sha256));}
function binding(v){
  keys(v,BINDING_KEYS);need(/^\d{14}[a-f0-9]{6}$/.test(v.run_id)&&hex(v.source_sha,40)&&[v.freeze_sha256,v.context_sha256,v.backend_cid,v.gateway_cid,v.ready_sha256].every(x=>hex(x))&&v.backend_cid!==v.gateway_cid);
  const u=new URL(v.origin);need(u.origin===v.origin&&u.protocol==='http:'&&u.hostname==='127.0.0.1'&&integer(Number(u.port),1024)&&Number(u.port)<=65535&&!u.username&&!u.password);
  const c=v.source_closure;keys(c,['context','freeze','artifacts','reader','caller','browser_close']);for(const k of ['context','freeze','reader','caller','browser_close'])validPin(c[k]);
  need(c.context.sha256===v.context_sha256&&c.freeze.sha256===v.freeze_sha256);keys(c.artifacts,ARTIFACTS);for(const p of Object.values(c.artifacts))validPin(p);
}
const stable=(a,b)=>['dev','ino','mode','nlink','size','mtimeMs','ctimeMs'].every(k=>a[k]===b[k]);
async function ordinary(name,max=33554432,empty=false){
  need(typeof name==='string'&&path.isAbsolute(name));
  for(let folder=path.dirname(name);;){const s=await fs.lstat(folder);need(s.isDirectory()&&!s.isSymbolicLink());const next=path.dirname(folder);if(next===folder)break;folder=next;}
  const before=await fs.lstat(name);need(before.isFile()&&!before.isSymbolicLink()&&before.nlink===1&&before.size>=(empty?0:1)&&before.size<=max);
  const f=await fs.open(name,'r');let raw;try{need(stable(before,await f.stat()));raw=await f.readFile();const tail=Buffer.alloc(1);need((await f.read(tail,0,1,null)).bytesRead===0&&raw.length===before.size&&stable(before,await f.stat()));}finally{await f.close();}
  need(stable(before,await fs.lstat(name)));return raw;
}
function png(raw){
  need(Buffer.isBuffer(raw)&&raw.length>45&&raw.length<=33554432&&raw.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])));
  const crc=bytes=>{let v=0xffffffff;for(const byte of bytes){v^=byte;for(let b=0;b<8;b++)v=(v>>>1)^((v&1)?0xedb88320:0);}return(v^0xffffffff)>>>0;};
  let at=8,header=null,ended=false,seen=false,dataEnded=false,palette=false;const data=[];
  while(at<raw.length){
    need(at+12<=raw.length);const n=raw.readUInt32BE(at),end=at+12+n;need(end<=raw.length);
    const type=raw.subarray(at+4,at+8).toString('ascii'),value=raw.subarray(at+8,at+8+n);need(/^[A-Za-z]{4}$/.test(type)&&crc(raw.subarray(at+4,at+8+n))===raw.readUInt32BE(at+8+n));
    if(header===null)need(type==='IHDR'&&n===13);
    if(type==='IHDR'){need(header===null&&at===8&&n===13);header=Buffer.from(value);}
    else if(type==='PLTE'){need(!palette&&!seen&&n>0&&n<=768&&n%3===0);palette=true;}
    else if(type==='IDAT'){need(!dataEnded);seen=true;data.push(value);}
    else if(type==='IEND'){need(n===0&&seen&&end===raw.length);ended=true;at=end;break;}
    else{need(type[0]===type[0].toLowerCase());if(seen)dataEnded=true;}
    at=end;
  }
  need(ended&&at===raw.length&&header!==null);const width=header.readUInt32BE(0),height=header.readUInt32BE(4),depth=header[8],color=header[9],interlace=header[12];
  const allowed={0:[1,2,4,8,16],2:[8,16],3:[1,2,4,8],4:[8,16],6:[8,16]},channels={0:1,2:3,3:1,4:2,6:4};
  need(width>0&&height>0&&width*height<=16777216&&allowed[color]?.includes(depth)&&header[10]===0&&header[11]===0&&[0,1].includes(interlace)&&(color!==3||palette));
  const passes=interlace===0?[[0,0,1,1]]:[[0,0,8,8],[4,0,8,8],[0,4,4,8],[2,0,4,4],[0,2,2,4],[1,0,2,2],[0,1,1,2]],rows=[];let total=0;
  for(const[x,y,dx,dy]of passes){const w=width<=x?0:Math.ceil((width-x)/dx),h=height<=y?0:Math.ceil((height-y)/dy);if(!w||!h)continue;const bytes=Math.ceil(w*channels[color]*depth/8);rows.push([h,bytes]);total+=h*(bytes+1);}
  need(total>0&&total<=134217728);const decoded=inflateSync(Buffer.concat(data),{maxOutputLength:total});need(decoded.length===total);let pos=0;
  for(const[count,bytes]of rows)for(let i=0;i<count;i++){need(decoded[pos]<=4);pos+=bytes+1;}need(pos===total);return{width,height};
}
/** Self-contained read-only DOM scope: no input value, hidden state, mutation or network. */
function measureVisible(arg){
  const fail=()=>{throw new Error('DOM_VISIBLE_PROBE_UNPROVED');};
  const bounds=el=>{const r=el.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height};};
  const viewport={width:window.innerWidth,height:window.innerHeight};
  const visible=el=>{const r=el.getBoundingClientRect(),s=window.getComputedStyle(el);return r.width>0&&r.height>0&&r.right>0&&r.bottom>0&&r.left<viewport.width&&r.top<viewport.height&&s.display!=='none'&&s.visibility!=='hidden'&&s.visibility!=='collapse'&&Number(s.opacity)!==0;};
  const one=s=>{const found=document.querySelectorAll(s);if(found.length!==1)fail();return found[0];};
  const norm=s=>s.replace(/\s+/g,' ').trim();
  const kind=el=>{const t=el.tagName.toLowerCase(),r=el.getAttribute('role');if(t==='button'||r==='button'||t==='input'&&['button','submit','reset'].includes(el.type))return'button';if(['input','textarea','select'].includes(t)||el.isContentEditable)return'input';if(t==='a'||r==='link')return'link';if(t==='img')return'image';if(r==='status'||r==='alert')return'status';return'text';};
  const controls=arg.probes.map(p=>{const el=one(p.selector),parent=one(p.parent_selector);if(!visible(el)||!visible(parent)||parent===el||!parent.contains(el))fail();
    const k=kind(el);if(!(k===p.kind||p.kind==='status'&&k==='text'&&el.tabIndex<0&&window.getComputedStyle(el).cursor!=='pointer'))fail();
    let label=null;if(k!=='input'&&k!=='image'){if(typeof el.innerText!=='string')fail();label=norm(el.innerText);if(label!==p.label)fail();}else if(p.label!==null)fail();
    return{key:p.key,kind:p.kind,label,visible:true,enabled:!el.disabled&&el.getAttribute('aria-disabled')!=='true',bounds:bounds(el),parent_bounds:bounds(parent)};
  });
  const blocked=[],mark=c=>{if(!blocked.includes(c))blocked.push(c);};
  for(const s of arg.private_selectors)for(const el of document.querySelectorAll(s))if(visible(el))mark('PRIVATE_VISIBLE_MARKER');
  const all=document.querySelectorAll('*');if(all.length>12000)mark('VISIBLE_SCAN_LIMIT');
  const approved=new Set(arg.public_image_urls),approvedImage=u=>{try{const v=new URL(u,window.location.href);return ['http:','https:'].includes(v.protocol)&&v.origin===arg.origin&&!/^\/(?:api\/order\/refund\/files|merchantapi\/aftersale-files)\//.test(v.pathname)&&approved.has(v.href);}catch{return false;}};
  for(const el of Array.from(all).slice(0,12000)){
    if(!visible(el))continue;const t=el.tagName.toLowerCase();if(el.isContentEditable)mark('EDITABLE_VISIBLE');
    if(['iframe','canvas','video','object','embed'].includes(t))mark('OPAQUE_MEDIA_VISIBLE');
    if(t==='input'||t==='textarea'||t==='select'){
      const type=t==='input'?el.type.toLowerCase():t,ac=(el.getAttribute('autocomplete')||'').toLowerCase();
      if(type==='password'||/password|one-time-code|username/.test(ac))mark('AUTH_CONTROL_VISIBLE');
      else if(!['button','submit','reset','checkbox','radio','range','color'].includes(type)){
        let empty=false;try{empty=el.hasAttribute('placeholder')&&el.matches(':placeholder-shown');}catch{empty=false;}if(!empty)mark('INPUT_EMPTY_NOT_OBSERVED');
      }
    }
    if(kind(el)==='button'&&typeof el.innerText==='string'&&/^(立即)?(登\s*录|注\s*册|获取验证码|发送验证码|Sign in|Log in|Register|Get code)$/i.test(norm(el.innerText)))mark('AUTH_ACTION_VISIBLE');
    if(t==='img'&&!approvedImage(el.currentSrc||el.src))mark('UNAPPROVED_IMAGE_VISIBLE');
    for(const m of window.getComputedStyle(el).backgroundImage.matchAll(/url\(\s*(['"]?)(.*?)\1\s*\)/g))if(!approvedImage(m[2]))mark('UNAPPROVED_BACKGROUND_VISIBLE');
  }
  let image=null;if(arg.image!==null){const el=one(arg.image.selector);if(el.tagName.toLowerCase()!=='img')fail();image={url:el.currentSrc||el.src,complete:el.complete,natural_width:el.naturalWidth,natural_height:el.naturalHeight,visible:visible(el),bounds:bounds(el)};}
  return{url:window.location.href,viewport,controls,image,blocked:blocked.sort()};
}
function identity(v,role){
  keys(v,['application','subject_id','merchant_id','verified_by']);
  if(role==='public')need(same(v,{application:'none',subject_id:null,merchant_id:null,verified_by:'actual-anonymous-normal-response'}));
  else need(v.application==={customer:'api',merchant:'merchantapi',platform:'adminapi'}[role]&&integer(v.subject_id,1)&&(role==='merchant'?integer(v.merchant_id,1):v.merchant_id===null)&&v.verified_by==='native-session-and-normal-UI');
}
function requestJoin(v,fixture){
  if(v===null)return null;keys(v,['sequence','method','path','started_at_ms','finished_at_ms','http_status','app_status','request_public','response_public','request_public_sha256','response_public_sha256','authentication']);
  need(integer(v.sequence)&&['GET','POST','PUT'].includes(v.method)&&typeof v.path==='string'&&v.path.startsWith('/')&&integer(v.started_at_ms,1)&&integer(v.finished_at_ms,v.started_at_ms)&&v.http_status===200&&(v.app_status===200||v.app_status===null)&&hex(v.request_public_sha256)&&hex(v.response_public_sha256)&&sha(Buffer.from(JSON.stringify(canonical(v.request_public))))===v.request_public_sha256&&sha(Buffer.from(JSON.stringify(canonical(v.response_public))))===v.response_public_sha256);
  if(v.app_status===null){if(v.path==='/api/get_script')need(v.method==='GET'&&same(v.request_public,{})&&v.response_public==='');else{keys(v.response_public,['bytes','sha256','mime_type']);need(v.method==='GET'&&same(v.request_public,{})&&v.response_public.bytes===fixture.bytes&&v.response_public.sha256===fixture.sha256&&v.response_public.mime_type==='image/png');}}
  const a=v.authentication;keys(a,['present','kind','subject_id']);need(typeof a.present==='boolean'&&(a.present?a.kind==='Bearer'&&integer(a.subject_id,1):a.kind==='none'&&a.subject_id===null));
  return{sequence:v.sequence,method:v.method,path_sha256:sha(v.path),started_at_ms:v.started_at_ms,finished_at_ms:v.finished_at_ms,http_status:v.http_status,app_status:v.app_status,request_public_sha256:v.request_public_sha256,response_public_sha256:v.response_public_sha256,authentication:a};
}
export class UiNormalDomProducer{
  #config;#binding;#owned;#proof=null;#phase='NEW';#busy=false;#frames=[];#active=null;#observations=[];#image20=null;
  constructor(value){const c=copy(value);keys(c,['context_path','binding','owned']);need(typeof c.context_path==='string'&&path.isAbsolute(c.context_path));binding(c.binding);keys(c.owned,ROLES);need(Object.values(c.owned).every(x=>hex(x))&&new Set(Object.values(c.owned)).size===3);this.#config={context_path:c.context_path};this.#binding=c.binding;this.#owned=c.owned;}
  async #exclusive(action){need(!this.#busy&&this.#phase!=='FAILED'&&this.#phase!=='RELEASED');this.#busy=true;try{return await action();}catch{this.#phase='FAILED';throw new Error('DOM_PRODUCER_UNPROVED');}finally{this.#busy=false;}}
  async #source(){
    const raw=await ordinary(this.#config.context_path),c=json(raw),b=this.#binding;
    need(path.isAbsolute(c.run_dir)&&path.isAbsolute(c.prep_dir)&&path.resolve(this.#config.context_path)===path.resolve(c.run_dir,'actual-run-context.json')&&path.basename(c.run_dir)===b.run_id&&c.run_id===b.run_id&&c.source_sha===b.source_sha&&c.freeze_sha256===b.freeze_sha256&&same(pin(raw),b.source_closure.context));
    const fr=await ordinary(path.join(c.run_dir,'preparation-source-binding.json')),f=json(fr);need(same(pin(fr),b.source_closure.freeze)&&f.status==='CLEAN_SOURCE_FROZEN_ONLY'&&f.source_sha===b.source_sha&&f.ui_web_origin===b.origin&&same(f.artifact_files.map(r=>r.path),ARTIFACTS));
    let fixture=null;
    for(const r of f.artifact_files){keys(r,['path','bytes','sha256']);const bytes=await ordinary(path.join(c.prep_dir,...r.path.split('/')));need(same(pin(bytes),{bytes:r.bytes,sha256:r.sha256})&&same(pin(bytes),b.source_closure.artifacts[r.path]));if(r.path==='ms4-order.png')fixture={...pin(bytes),...png(bytes)};}
    need(fixture&&fixture.bytes===234&&fixture.width===96&&fixture.height===96);
    const own=fileURLToPath(import.meta.url),folder=path.dirname(own);
    for(const[k,leaf]of[['reader','reader/ms4-ui-private-reader.mjs'],['caller','ms4-ui-private-caller.mjs'],['browser_close','ms4-ui-browser-close.mjs']])need(same(pin(await ordinary(path.join(folder,...leaf.split('/')))),b.source_closure[k]));
    const e=path.join(c.run_dir,'evidence');need(sha(await ordinary(path.join(e,'ui-web-control-ui-backend-ready.json')))===b.ready_sha256);
    const gateway=json(await ordinary(path.join(e,'ui-gateway-created.json'))),h=json(await ordinary(path.join(e,'ui-private-caller-handoff.json')));
    need(gateway.id===b.gateway_cid&&gateway.run_id===b.run_id&&gateway.source_sha===b.source_sha&&h.run_id===b.run_id&&h.source_sha===b.source_sha&&h.freeze_sha256===b.freeze_sha256&&h.container_id===b.backend_cid);
    const closure={...b.source_closure};delete closure.browser_close;need(same(h.source_closure,closure));
    return{evidence:e,fixture,producer_source:{path:'browser-v1/ms4-ui-dom-producer.mjs',...pin(await ordinary(own))}};
  }
  async begin(){return this.#exclusive(async()=>{need(this.#phase==='NEW');this.#proof=await this.#source();this.#phase='READY';return{scope:'DOM_PRODUCER_SOURCE_BOUND_ONLY',actual_DOM:'NOT_RUN'};});}
  acceptFrame(value){
    need(!this.#busy&&this.#phase==='READY');const f=copy(value);keys(f,['schema','scope','binding','sequence','command','step','receipt']);
    need(f.schema===1&&f.scope==='MS4_UI_OBSERVER_SAFE_FRAME'&&same(f.binding,this.#binding)&&f.sequence===this.#frames.length&&f.sequence<=41);
    const seq=f.sequence,command=seq===0?'BEGIN':seq===41?'FINISH':seq%2?'STEP_BEGIN':'STEP_VERIFY',index=seq===0?0:seq===41?21:Math.ceil(seq/2);
    need(f.command===command&&f.step===index);keys(f.receipt,{BEGIN:['baseline'],STEP_BEGIN:['before'],STEP_VERIFY:['after','verified_after','expected_delta','oracle'],FINISH:['final','preservation']}[command]);
    for(const k of ['baseline','before','after','verified_after','final'])if(k in f.receipt){const l=f.receipt[k];keys(l,LEDGER_KEYS);need(l.schema===1&&l.scope==='MS4_UI_COMPLETE_NATIVE_WINDOW_LEDGER'&&same(l.binding,this.#binding)&&l.index===index&&l.point===(k==='verified_after'?'verified':k));}
    if(command==='STEP_BEGIN'){need(this.#active===null);this.#active={index,opened_at_ms:Date.now()};}
    if(command==='STEP_VERIFY'){need(this.#active?.index===index);this.#active=null;}
    if(command==='FINISH'){need(this.#active===null);this.#phase='FINISHED';}
    this.#frames.push(f);return{accepted_sequence:seq,active_step:this.#active?.index??null};
  }
  async #create(leaf,value,bytes=false){
    need(/^[a-z0-9][a-z0-9.-]{0,120}$/.test(leaf));const raw=bytes?value:Buffer.from(JSON.stringify(value)+'\n'),name=path.join(this.#proof.evidence,leaf),f=await fs.open(name,'wx',0o600);
    try{await f.writeFile(raw);await f.sync();}finally{await f.close();}need((await ordinary(name,Math.max(raw.length,1),raw.length===0)).equals(raw));return{path:'/run/audit/evidence/'+leaf,...pin(raw)};
  }
  async #read(v,leaf,max=33554432,empty=false){desc(v,'/run/audit/evidence/'+leaf);const raw=await ordinary(path.join(this.#proof.evidence,leaf),max,empty);need(same(pin(raw),{bytes:v.bytes,sha256:v.sha256}));return raw;}
  async observe(tab,value){return this.#exclusive(async()=>{
    need(this.#phase==='READY'&&this.#active&&this.#observations.length<128);const p=copy(value);
    keys(p,['role','window_step','width','page','identity','request','probes','image','screenshot_policy','public_image_urls','private_selectors']);
    need([...ROLES,'public'].includes(p.role)&&p.window_step===this.#active.index&&[320,390,1440].includes(p.width)&&['omit','capture-if-safe'].includes(p.screenshot_policy)&&tab&&typeof tab.id==='string'&&sha(tab.id)===this.#owned[p.role==='public'?'customer':p.role]&&typeof tab.playwright?.evaluate==='function'&&typeof tab.screenshot==='function');
    keys(p.page,['kind','name','url']);need(['normal','authentication','private-preview'].includes(p.page.kind)&&/^[a-z][a-z0-9-]{0,63}$/.test(p.page.name)&&typeof p.page.url==='string'&&['http:','https:'].includes(new URL(p.page.url).protocol)&&new URL(p.page.url).origin===this.#binding.origin);identity(p.identity,p.role);const request=requestJoin(p.request,this.#proof.fixture);
    need(Array.isArray(p.probes)&&p.probes.length>0&&p.probes.length<=64&&Array.isArray(p.private_selectors)&&p.private_selectors.length<=32&&Array.isArray(p.public_image_urls)&&p.public_image_urls.length<=256&&new Set(p.public_image_urls).size===p.public_image_urls.length);
    const selector=s=>typeof s==='string'&&s.length>0&&s.length<=2048&&!/[\x00-\x1f\x7f]/.test(s);for(const s of p.private_selectors)need(selector(s));
    for(const url of p.public_image_urls){const u=new URL(url);need(u.href===url&&['http:','https:'].includes(u.protocol)&&u.origin===this.#binding.origin&&!/^\/(?:api\/order\/refund\/files|merchantapi\/aftersale-files)\//.test(u.pathname)&&!u.search&&!u.hash&&!u.username&&!u.password);}
    const names=new Set();
    for(const q of p.probes){keys(q,['key','selector','parent_selector','kind','label']);need(/^[a-z][a-z0-9-]{0,63}$/.test(q.key)&&!names.has(q.key)&&selector(q.selector)&&selector(q.parent_selector)&&['button','input','status','link','image','text'].includes(q.kind));names.add(q.key);need(['input','image'].includes(q.kind)?q.label===null:typeof q.label==='string'&&q.label.length>0&&q.label.length<=1024&&q.label===q.label.replace(/\s+/g,' ').trim());}
    if(p.image!==null){keys(p.image,['kind','selector','url']);need(['public','private'].includes(p.image.kind)&&selector(p.image.selector)&&typeof p.image.url==='string');if(p.image.kind==='private')need(p.page.kind==='private-preview'&&p.screenshot_policy==='omit');else{const u=new URL(p.image.url);need(['http:','https:'].includes(u.protocol)&&u.origin===this.#binding.origin&&!/^\/(?:api\/order\/refund\/files|merchantapi\/aftersale-files)\//.test(u.pathname)&&!u.search&&!u.hash&&!u.username&&!u.password&&p.public_image_urls.includes(u.href));}}
    const started=Date.now();need(integer(started,1)&&started>=this.#active.opened_at_ms);
    const arg={origin:this.#binding.origin,probes:p.probes,image:p.image,private_selectors:p.private_selectors,public_image_urls:p.public_image_urls};
    const first=copy(await tab.playwright.evaluate(measureVisible,arg));need(first.url===p.page.url&&first.viewport.width===p.width&&integer(first.viewport.height,1));
    let last=first,screenshot=null,screenshotCall=null;const guards=[...first.blocked];
    if(p.page.kind!=='normal')guards.push(p.page.kind==='authentication'?'AUTHENTICATION_POLICY':'PRIVATE_PREVIEW_POLICY');
    if(p.screenshot_policy==='omit')guards.push('SCREENSHOT_OMITTED');
    if(guards.length===0){
      const callStart=Date.now(),returned=await tab.screenshot({fullPage:false}),callEnd=Date.now();need(ArrayBuffer.isView(returned)&&Object.prototype.toString.call(returned)==='[object Uint8Array]');const raw=Buffer.from(returned);
      try{const pixels=png(raw);last=copy(await tab.playwright.evaluate(measureVisible,arg));
        if(!same(first,last))guards.push('VISIBLE_STATE_CHANGED_DURING_SCREENSHOT');
        else{screenshot=await this.#create('ui-dom-shot-'+String(this.#observations.length+1).padStart(3,'0')+'.png',raw,true);screenshotCall={started_at_ms:callStart,finished_at_ms:callEnd,descriptor:screenshot,pixel_width:pixels.width,pixel_height:pixels.height,scale:null};}
      }finally{raw.fill(0);}
    }
    need(last.url===p.page.url&&last.viewport.width===p.width&&integer(last.viewport.height,1));let image=null;
    if(p.image!==null){const m=last.image;need(m&&m.url===p.image.url&&m.complete===true&&m.visible===true&&m.natural_width===this.#proof.fixture.width&&m.natural_height===this.#proof.fixture.height);image={kind:p.image.kind,url_sha256:sha(m.url),reference:p.image.kind==='public'?m.url:null,bytes:this.#proof.fixture.bytes,sha256:this.#proof.fixture.sha256,media_type:'image/png',complete:m.complete,natural_width:m.natural_width,natural_height:m.natural_height,visible:m.visible,bounds:m.bounds,content_verification:'FIXTURE_REFERENCE_REQUIRES_NATIVE_HTTP_JOIN'};}
    const ended=Date.now();need(integer(ended,started));if(screenshotCall)need(integer(screenshotCall.started_at_ms,started)&&integer(screenshotCall.finished_at_ms,screenshotCall.started_at_ms)&&screenshotCall.finished_at_ms<=ended);
    const number=String(this.#observations.length+1).padStart(3,'0'),tabHash=sha(tab.id);
    const measurement={schema:1,scope:'MS4_UI_NORMAL_VISIBLE_MEASUREMENT',binding:this.#binding,role:p.role,tab_id_sha256:tabHash,window_step:p.window_step,started_at_ms:started,finished_at_ms:ended,viewport:last.viewport,image_bounds:image?.bounds??null,controls:last.controls,screenshot_call:screenshotCall};
    const DOM=await this.#create('ui-dom-measure-'+number+'.json',measurement);
    const observation={schema:1,scope:'MS4_UI_NORMAL_DOM_OBSERVATION',binding:this.#binding,role:p.role,tab_id_sha256:tabHash,url_sha256:sha(last.url),viewport:last.viewport,state:{page:p.page.name,screenshot_policy:p.screenshot_policy,screenshot_guard:[...new Set(guards)].sort()},identity:p.identity,request,DOM,screenshot,image,window_step:p.window_step};
    const d=await this.#create('ui-dom-observation-'+number+'.json',observation);this.#observations.push({descriptor:d,observation,measurement});return copy({descriptor:d,observation,measurement});
  });}
  case20ImageDom(){
    need(!this.#busy&&this.#phase==='READY'&&this.#active?.index===20);const rows=this.#observations.filter(r=>r.observation.window_step===20&&r.observation.role==='public'&&r.observation.image?.kind==='public');
    need(rows.length===3&&same(rows.map(r=>r.observation.viewport.width),[320,390,1440]));const url=rows[0].observation.image.reference;
    const viewports=rows.map(({observation:o})=>{const i=o.image,b=i.bounds;need(o.screenshot!==null&&o.state.screenshot_guard.length===0&&i.reference===url&&i.complete===true&&i.visible===true&&b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=o.viewport.width&&b.y+b.height<=o.viewport.height);
      keys(o.screenshot,['path','bytes','sha256']);need(typeof o.screenshot.path==='string');
      const matched=/^\/run\/audit\/evidence\/(ui-dom-shot-([0-9]{3})\.png)$/.exec(o.screenshot.path);
      need(matched!==null&&matched[0]===o.screenshot.path&&integer(Number(matched[2]),1)&&Number(matched[2])<=128&&integer(o.screenshot.bytes,1)&&hex(o.screenshot.sha256));
      const screenshot={path:matched[1],bytes:o.screenshot.bytes,sha256:o.screenshot.sha256};
      return{viewport_width:o.viewport.width,viewport_height:o.viewport.height,image_url:url,complete:i.complete,natural_width:i.natural_width,natural_height:i.natural_height,visible:i.visible,bounds:b,screenshot};
    });
    this.#image20={schema:1,scope:'MS4_UI_NORMAL_PUBLIC_IMAGE_DOM',image_url:url,complete:true,natural_width:this.#proof.fixture.width,natural_height:this.#proof.fixture.height,visible:true,viewports};return copy(this.#image20);
  }
  async finalize(){return this.#exclusive(async()=>{
    need(this.#phase==='FINISHED'&&this.#frames.length===42&&this.#image20!==null&&same(await this.#source(),this.#proof));
    for(const role of ROLES)for(const width of [320,390,1440])need(this.#observations.some(r=>r.observation.role===role&&r.observation.viewport.width===width&&r.observation.screenshot!==null&&r.observation.state.screenshot_guard.length===0));
    const windowRaw=await ordinary(path.join(this.#proof.evidence,'ui-business-window.json')),w=json(windowRaw);keys(w,WINDOW_KEYS);
    need(w.schema===1&&w.scope==='MS4_UI_NORMAL_BUSINESS_WINDOW_ONLY'&&w.status==='VERIFIED_MS4_UI_NORMAL_BUSINESS_WINDOW'&&same(w.binding,this.#binding)&&w.diagnostic===null&&w.full_acceptance==='INCOMPLETE'&&Array.isArray(w.steps)&&w.steps.length===20);
    for(let i=0;i<20;i++){const leaf='ui-business-step-'+String(i+1).padStart(2,'0')+'.json',s=json(await this.#read(w.steps[i],leaf));need(s.index===i+1&&s.name===STEPS[i]&&same(s.binding,this.#binding));}
    const n=w.observer_native;keys(n,['created','terminal','stdout','stderr']);const created=json(await this.#read(n.created,'ui-window-observer-created.json')),terminal=json(await this.#read(n.terminal,'ui-window-observer-terminal.json'));
    keys(created,['label','executable','argv','pid']);keys(terminal,['schema','scope','executable','argv','pid','close_code','close_signal','stdout','stderr']);
    const argv=['exec','-i',this.#binding.backend_cid,'php','/workspace/help/audit-regressions/isolated/ms4-ui-backend.php','observe'];
    need(created.label==='ui-window-observer'&&created.executable==='docker'&&same(created.argv,argv)&&integer(created.pid,1)&&terminal.schema===1&&terminal.scope==='MS4_UI_OBSERVER_NODE_NATIVE'&&terminal.executable===created.executable&&terminal.pid===created.pid&&same(terminal.argv,argv)&&terminal.close_code===0&&terminal.close_signal===null);
    const stdout=await this.#read(n.stdout,'ui-window-observer.stdout',268435456),stderr=await this.#read(n.stderr,'ui-window-observer.stderr',1,true);need(stderr.length===0);
    for(const[field,raw]of[['stdout',stdout],['stderr',stderr]]){const s=terminal[field];keys(s,['bytes','sha256','stored_bytes','stored_sha256','complete']);need(s.complete===true&&s.bytes===raw.length&&s.stored_bytes===raw.length&&s.sha256===sha(raw)&&s.stored_sha256===sha(raw));}
    const text=new TextDecoder('utf-8',{fatal:true}).decode(stdout);need(text.endsWith('\n'));const lines=text.slice(0,-1).split('\n');need(lines.length===42);
    for(let i=0;i<42;i++){const frame=JSON.parse(lines[i]);need(Buffer.byteLength(lines[i]+'\n')<=16777216&&lines[i]===JSON.stringify(canonical(frame))&&same(frame,this.#frames[i]));}
    keys(w.preservation,['source','predecessors','private_inputs','public_materials']);
    for(const[k,leaf]of[['baseline','ui-window-baseline.json'],['final','ui-window-final.json']])need(same(json(await this.#read(w[k],leaf)),k==='baseline'?this.#frames[0].receipt.baseline:this.#frames[41].receipt.final));
    for(const row of this.#observations){need(same(json(await this.#read(row.descriptor,path.basename(row.descriptor.path))),row.observation));need(same(json(await this.#read(row.observation.DOM,path.basename(row.observation.DOM.path))),row.measurement));if(row.observation.screenshot){const size=png(await this.#read(row.observation.screenshot,path.basename(row.observation.screenshot.path)));need(size.width===row.measurement.screenshot_call.pixel_width&&size.height===row.measurement.screenshot_call.pixel_height);}}
    const value={schema:1,scope:'MS4_UI_NORMAL_DOM_ONLY',status:'VERIFIED_MS4_UI_NORMAL_DOM',binding:this.#binding,window:{path:'/run/audit/evidence/ui-business-window.json',...pin(windowRaw)},observations:this.#observations.map(r=>r.descriptor),producer_source:this.#proof.producer_source,preservation:w.preservation,diagnostic:null,full_acceptance:'INCOMPLETE'};
    const d=await this.#create('ui-business-dom.json',value);this.#phase='PUBLISHED';return copy({descriptor:d,value});
  });}
  releasePrivateReferences(){need(!this.#busy);this.#config=null;this.#binding=null;this.#owned=null;this.#proof=null;this.#frames=[];this.#observations=[];this.#active=null;this.#image20=null;this.#phase='RELEASED';return{managed_references_released:true,immutable_JS_strings_securely_erased:false,external_references:'NOT_ATTESTED'};}
}


// Task2 finite DOM18. Original UI20 class and image behavior above are untouched.
import { UiTask2BusinessProducer } from './ms4-ui-business-producer.mjs';
import { UiBrowserNormalClose } from './ms4-ui-browser-close.mjs';
import { TASK2_READ_MODES,TASK2_READ_STEPS,TASK2_READ_ROLES,TASK2_READ_VIEWS,task2Source } from './ms4-ui-window-native-bridge.mjs';
const TASK2_AUX_NAMES=['reader/ms4-ui-private-reader.mjs','ms4-ui-private-caller.mjs','ms4-ui-browser-close.mjs','ms4-ui-business-producer.mjs','ms4-ui-window-native-bridge.mjs','ms4-ui-network-capture.mjs','ms4-ui-dom-producer.mjs','ui-business-consumer-contract.json'];
const task2DomJson=v=>v===null||typeof v!=='object'?JSON.stringify(v).replace(/\u2028/g,'\\u2028').replace(/\u2029/g,'\\u2029'):Array.isArray(v)?'['+v.map(task2DomJson).join(',')+']':'{'+Object.keys(v).sort((a,b)=>Buffer.compare(Buffer.from(a),Buffer.from(b))).map(k=>JSON.stringify(k)+':'+task2DomJson(v[k])).join(',')+'}';
const task2DomHash=v=>sha(Buffer.from(task2DomJson(v)));
// Same ECMAScript whitespace display rule as the rendered-only probe; original DTO values are never rewritten.
const task2DomText=value=>{need(typeof value==='string');return value.replace(/\s+/g,' ').trim();};
const task2Selector=v=>typeof v==='string'&&v.length>0&&v.length<=2048&&!/[\u0000-\u001f\u007f]/.test(v);
const task2Box=v=>object(v)&&Object.keys(v).length===4&&['x','y','width','height'].every(k=>typeof v[k]==='number'&&Number.isFinite(v[k]))&&v.width>0&&v.height>0;
function task2DomProbe(arg){
 const demand=x=>{if(!x)throw new Error('TASK2_VISIBLE_DOM_UNPROVED');};
 const all=document.querySelectorAll(arg.selector),parents=document.querySelectorAll(arg.parent_selector);demand(all.length===1&&parents.length===1);
 const el=all[0],parent=parents[0];demand(parent!==el&&parent.contains(el));
 const box=e=>{const r=e.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height};};
 const bounds=box(el),parent_bounds=box(parent),style=getComputedStyle(el),ps=getComputedStyle(parent);
 const visible=bounds.width>0&&bounds.height>0&&bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=innerWidth+0.5&&bounds.y+bounds.height<=innerHeight+0.5
  &&style.display!=='none'&&style.visibility!=='hidden'&&style.visibility!=='collapse'&&Number(style.opacity)!==0&&ps.display!=='none'&&ps.visibility!=='hidden';demand(visible);
 demand(bounds.x>=parent_bounds.x-0.5&&bounds.y>=parent_bounds.y-0.5&&bounds.x+bounds.width<=parent_bounds.x+parent_bounds.width+0.5&&bounds.y+bounds.height<=parent_bounds.y+parent_bounds.height+0.5);
 const tag=el.tagName.toLowerCase(),role=el.getAttribute('role'),input=['input','textarea','select'].includes(tag)||el.isContentEditable;
 const interactive=input||tag==='button'||tag==='a'||['button','link'].includes(role);demand(!input);
 let value,kind='text';
 if(arg.kind==='image'){demand(tag==='img'&&el.complete&&el.naturalWidth>0&&el.naturalHeight>0);value=el.currentSrc||el.src;kind='image';}
 else{demand(typeof el.innerText==='string');value=el.innerText.replace(/\s+/g,' ').trim();if(arg.kind==='status'){demand(!interactive&&el.tabIndex<0&&style.cursor!=='pointer');kind='status';}
  else if(arg.kind==='button'){demand(tag==='button'||role==='button');kind='button';}else demand(!interactive);}
 let content_bounds=null;
 if(kind==='button'){const range=document.createRange();range.selectNodeContents(el);const r=range.getBoundingClientRect();content_bounds={x:r.x,y:r.y,width:r.width,height:r.height};
  demand(r.width>0&&r.height>0&&Math.abs(r.x+r.width/2-(bounds.x+bounds.width/2))<=4&&Math.abs(r.y+r.height/2-(bounds.y+bounds.height/2))<=4);}
 return{url:location.href,viewport:{width:innerWidth,height:innerHeight},kind,value,bounds,parent_bounds,content_bounds,visible:true,enabled:!el.disabled&&el.getAttribute('aria-disabled')!=='true'};
}
/** Expected hashes come only from the same fully validated native independent read receipt. */
export function task2DomExpectedFields(projection,view,b){
 keys(projection,['view','selection','target','rows','private_display_digests']);need(projection.view===view&&TASK2_READ_VIEWS.slice(3).includes(view)&&Array.isArray(projection.rows));
 const out=[],add=(key,value,kind='text',encoding='canonical-json-string')=>{need(typeof value==='string');const display=encoding==='utf8'||kind==='image'?value:task2DomText(value);out.push({key,kind,encoding,expected_sha256:encoding==='utf8'?sha(display):task2DomHash(display)});};
 const image=(key,value)=>{const u=new URL(value,b.origin);need(u.origin===b.origin&&['http:','https:'].includes(u.protocol)&&!u.username&&!u.password);add(key,u.href,'image');};
 for(const row of projection.rows){const r='r:'+row.root_id+':';
  if(view!=='customer_list')out.push({key:r+'order_no',kind:'text',encoding:'utf8',expected_sha256:row.order_no_sha256});
  if(view.endsWith('_list')&&row.marketplace_trade_version===1)add(r+'details_action',view==='platform_list'?'详情':'查看详情','button');
  if(view==='merchant_detail')add(r+'back_action','返回订单列表','button');
  const money=view==='customer_list'?['pay_price']:view==='merchant_list'||view==='merchant_detail'?['total_price','pay_price','pay_postage']
   :view==='platform_list'?(row.paid===1?['pay_price']:[]):['pay_price','pay_postage'];
  for(const k of money)if(Object.hasOwn(row.money,k)&&!(view==='customer_detail'&&k==='pay_postage'&&row.money[k]==='0.00'))add(r+'money:'+k,row.money[k]);
  for(const item of row.items){const k=r+'i:'+item.id+':';add(k+'product_name',item.product_name);image(k+'image',item.image);
   if(view!=='customer_list')add(k+'sku_name',view==='platform_detail'?'规格：'+item.sku_name:item.sku_name);
   if(view==='merchant_list')add(k+'unit_quantity','¥'+item.unit_price+' × '+item.quantity);
   else{add(k+'quantity',view==='customer_list'?'x'+item.quantity:view==='customer_detail'?'x '+item.quantity:String(item.quantity));add(k+'unit_price',item.unit_price);}
   if(view==='merchant_detail'){add(k+'line_price',item.line_price);add(k+'postage_price',item.postage_price);}
  }
 }
 if(projection.rows.length===0){need(view.endsWith('_list'));add('collection_state',view==='merchant_list'?'暂无符合条件的订单':view==='platform_list'?'暂无数据':projection.selection.count===0?'暂无订单':'没有更多内容啦~','status');}
 for(const d of projection.private_display_digests){keys(d,['kind','index','value_sha256','visible_required']);if(d.visible_required){need(integer(d.index)&&hex(d.value_sha256));out.push({key:'private:'+d.index,kind:/^(?:order_state|fulfillment_notice|payment_status)\./.test(d.kind)?'status':'text',encoding:'canonical-json-string',expected_sha256:d.value_sha256});}}
 need(new Set(out.map(x=>x.key)).size===out.length);return out;
}
async function task2DomSource(config,b,observer){
 const raw=await ordinary(config.context_path),c=json(raw);need(same(pin(raw),b.source_closure.context)&&c.run_id===b.run_id&&c.source_sha===b.source_sha&&c.freeze_sha256===b.freeze_sha256
  &&path.isAbsolute(c.run_dir)&&path.isAbsolute(c.prep_dir)&&path.resolve(config.context_path)===path.resolve(c.run_dir,'actual-run-context.json'));
 const frozenRaw=await ordinary(path.join(c.run_dir,'preparation-source-binding.json')),f=json(frozenRaw);need(same(pin(frozenRaw),b.source_closure.freeze)&&f.source_sha===b.source_sha&&f.status==='CLEAN_SOURCE_FROZEN_ONLY'&&same(f.task2_observer_source,observer));
 need(Array.isArray(f.artifact_files)&&same(f.artifact_files.map(r=>r.path),ARTIFACTS));for(const r of f.artifact_files){keys(r,['path','bytes','sha256']);need(same(pin(await ordinary(path.join(c.prep_dir,...r.path.split('/')))),{bytes:r.bytes,sha256:r.sha256})&&same(b.source_closure.artifacts[r.path],{bytes:r.bytes,sha256:r.sha256}));}
 const group=f.task2_browser_source;keys(group,['schema','scope','members']);need(group.schema===1&&group.scope==='MS5_TASK2_BROWSER_SOURCE_ONLY'&&Array.isArray(group.members)&&group.members.length===8&&same(group.members.map(r=>r.path),TASK2_AUX_NAMES));
 const folder=path.dirname(fileURLToPath(import.meta.url)),actual=[];
 for(const r of group.members){keys(r,['path','bytes','sha256']);need(integer(r.bytes,1)&&hex(r.sha256));for(const root of [folder,path.join(path.dirname(c.prep_dir),'browser-v1')])need(same(pin(await ordinary(path.join(root,...r.path.split('/')))),{bytes:r.bytes,sha256:r.sha256}));actual.push(copy(r));}
 for(const root of [folder,path.join(path.dirname(c.prep_dir),'browser-v1')]){need(same((await fs.readdir(root)).sort(),['PINS.json','reader',...TASK2_AUX_NAMES.filter(p=>!p.includes('/'))].sort())&&same(await fs.readdir(path.join(root,'reader')),['ms4-ui-private-reader.mjs']));const manifest=json(await ordinary(path.join(root,'PINS.json')));keys(manifest,['members']);keys(manifest.members,TASK2_AUX_NAMES);for(const r of actual)need(same(manifest.members[r.path],{bytes:r.bytes,sha256:r.sha256}));}
 const own=actual.find(r=>r.path==='ms4-ui-dom-producer.mjs');need(own);const e=path.join(c.run_dir,'evidence');need(sha(await ordinary(path.join(e,'ui-web-control-ui-backend-ready.json')))===b.ready_sha256);
 const coreNames=['help/audit-regressions/isolated/ms4-ui-backend.php','help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php','help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php'];need(Array.isArray(f.ui_backend_source_files));const files=coreNames.map(name=>{const rows=f.ui_backend_source_files.filter(r=>r.path===name);need(rows.length===1);const r=rows[0];keys(r,['path','bytes','sha256']);return{path:'/workspace/'+name,bytes:r.bytes,sha256:r.sha256};});for(const member of observer.members)files.push({path:observer.container_root+'/'+member.path,bytes:member.bytes,sha256:member.sha256});
 return{evidence:e,producer_source:{path:'/run/ms4-browser-source/'+own.path,bytes:own.bytes,sha256:own.sha256},browser_source:copy(group),php_source_files:files};
}
function task2NativeNames(mode){return{created:mode.prefix+'-observer-created.json',terminal:mode.prefix+'-observer-terminal.json',stdout:mode.prefix+'-observer.stdout',stderr:mode.prefix+'-observer.stderr'};}
export class UiTask2DomProducer{
 #config;#binding;#observer;#mode;#modeName;#producer;#companion;#tabs;#proof=null;#phase='NEW';#busy=false;#samples=[];#observations=[];#verified=new Set();#privateBytes=0;
 constructor(config){keys(config,['context_path','binding','observer_source','argv_mode','producer','companion','owned_tabs']);const{context_path,binding:b,observer_source:source,argv_mode,producer,companion,owned_tabs}=config;
  binding(b);task2Source(source,b);need(path.isAbsolute(context_path)&&Object.hasOwn(TASK2_READ_MODES,argv_mode)&&Object.getPrototypeOf(producer)===UiTask2BusinessProducer.prototype&&Object.getPrototypeOf(companion)===UiBrowserNormalClose.prototype);keys(owned_tabs,ROLES);
  const tabs={};for(const role of ROLES){const tab=owned_tabs[role],owned=UiBrowserNormalClose.prototype.ownedTabIdentity.call(companion,role);need(tab&&typeof tab.id==='string'&&sha(tab.id)===owned.tab_id_sha256);tabs[role]=tab;}
  this.#config={context_path};this.#binding=copy(b);this.#observer=copy(source);this.#modeName=argv_mode;this.#mode=TASK2_READ_MODES[argv_mode];this.#producer=producer;this.#companion=companion;this.#tabs=tabs;
 }
 async #exclusive(action){need(!this.#busy&&!['FAILED','RELEASED','PUBLISHED'].includes(this.#phase));this.#busy=true;try{return await action();}catch{this.#phase='FAILED';throw new Error('TASK2_DOM_PRODUCER_UNPROVED');}finally{this.#busy=false;}}
 async begin(){return this.#exclusive(async()=>{need(this.#phase==='NEW');this.#proof=await task2DomSource(this.#config,this.#binding,this.#observer);this.#phase='READY';return{scope:'MS5_TASK2_DOM_SOURCE_BOUND',actual_DOM:'NOT_RUN'};});}
 /** Root-provided selectors identify rendered nodes only; expected values are never caller inputs. */
 async captureSample(tab,value,actualReveal){return this.#exclusive(async()=>{need(this.#phase==='READY'&&typeof actualReveal==='function');const v=copy(value);keys(v,['view','width','query','probes']);
  const index=TASK2_READ_VIEWS.indexOf(v.view)+1,role=TASK2_READ_ROLES[index-1],status=UiTask2BusinessProducer.prototype.status.call(this.#producer);
  need(index>=4&&index<=9&&[320,390,1440].includes(v.width)&&status.phase==='ACTION_WINDOW'&&status.verified_steps===index-1&&status.scenario===this.#mode.scenario&&tab===this.#tabs[role]);
  const before=UiTask2BusinessProducer.prototype.validatedFrame.call(this.#producer,2*index-1);need(before.command==='STEP_BEGIN'&&before.step===index&&same(before.observer_source,this.#observer));
  need(object(v.query)&&Object.values(v.query).every(x=>typeof x==='string')&&Array.isArray(v.probes)&&v.probes.length<=4096&&new Set(v.probes.map(x=>x.key)).size===v.probes.length);
  const owner=UiBrowserNormalClose.prototype.ownedTabIdentity.call(this.#companion,role);need(owner.tab_id_sha256===sha(tab.id)&&typeof tab.playwright?.evaluate==='function');
  const started=Date.now(),rows=[];let url=null,viewport=null;
  for(const p of v.probes){keys(p,['key','kind','selector','parent_selector','format']);need(typeof p.key==='string'&&p.key.length<=160&&['text','image','status','button'].includes(p.kind)&&task2Selector(p.selector)&&task2Selector(p.parent_selector)&&['plain','currency'].includes(p.format));
   await UiTask2BusinessProducer.prototype.performRootAction.call(this.#producer,{role,tab,kind:'DOM-observe',target_public:'dom-order-read'},()=>actualReveal({key:p.key,selector:p.selector}));
   const measured=copy(await tab.playwright.evaluate(task2DomProbe,{selector:p.selector,parent_selector:p.parent_selector,kind:p.kind}));
   keys(measured,['url','viewport','kind','value','bounds','parent_bounds','content_bounds','visible','enabled']);need(typeof measured.url==='string'&&new URL(measured.url).origin===this.#binding.origin&&measured.viewport.width===v.width&&integer(measured.viewport.height,1)
    &&measured.visible===true&&measured.enabled===true&&task2Box(measured.bounds)&&task2Box(measured.parent_bounds)&&measured.kind===p.kind&&typeof measured.value==='string');
   if(url===null){url=measured.url;viewport=measured.viewport;}else need(measured.url===url&&same(measured.viewport,viewport));
   let text=measured.value;if(p.format==='currency'){need(p.kind==='text'&&/^[¥￥]\s*(?:0|[1-9][0-9]*)\.[0-9]{2}$/.test(text));text=text.replace(/^[¥￥]\s*/,'');}
   rows.push({key:p.key,kind:p.kind,format:p.format,value:text,bounds:measured.bounds,parent_bounds:measured.parent_bounds,content_bounds:measured.content_bounds,visible:true,enabled:true});
  }
  // A genuinely empty list still needs its actual visible empty-state node; no zero-probe success.
  need(rows.length>0&&url!==null);const ended=Date.now(),sample={view:v.view,index,role,width:v.width,query:v.query,started_at_ms:started,finished_at_ms:ended,url_sha256:sha(url),viewport,rows};
  const bytes=Buffer.byteLength(task2DomJson(sample));need(this.#privateBytes+bytes<=268435456);this.#privateBytes+=bytes;this.#samples.push(sample);
  return{view:v.view,width:v.width,query_sha256:task2DomHash(v.query),probes:rows.length,private_values:'RAM_ONLY_PENDING_NATIVE_ACK'};
 });}
 async #create(leaf,value){need(/^[a-z0-9][a-z0-9.-]{0,120}$/.test(leaf));const raw=Buffer.from(task2DomJson(value)+'\n'),name=path.join(this.#proof.evidence,leaf),f=await fs.open(name,'wx',0o600);
  try{await f.writeFile(raw);await f.sync();}finally{await f.close();}need((await ordinary(name,Math.max(raw.length,1))).equals(raw));return{path:'/run/audit/evidence/'+leaf,...pin(raw)};}
 async #read(d,leaf,max=33554432,empty=false){desc(d,'/run/audit/evidence/'+leaf);const raw=await ordinary(path.join(this.#proof.evidence,leaf),max,empty);need(same(pin(raw),{bytes:d.bytes,sha256:d.sha256}));return raw;}
 async verifyStep(index){return this.#exclusive(async()=>{need(this.#phase==='READY'&&integer(index,4)&&index<=9&&!this.#verified.has(index));const status=UiTask2BusinessProducer.prototype.status.call(this.#producer);
  need(status.verified_steps===index&&['ARMED','NINE_ACKS_ONLY'].includes(status.phase));const frame=UiTask2BusinessProducer.prototype.validatedFrame.call(this.#producer,2*index),view=TASK2_READ_VIEWS[index-1],role=TASK2_READ_ROLES[index-1];
  const reads=frame.receipt.expected_delta.independent.reads;need(Array.isArray(reads)&&reads.length>0&&reads.every(r=>r.view===view));const queryGroups=new Map();
  for(const r of reads){const key=task2DomHash(r.query);if(!queryGroups.has(key))queryGroups.set(key,[]);queryGroups.get(key).push(r);}
  const samples=this.#samples.filter(s=>s.index===index),required=[];need(samples.every(s=>queryGroups.has(task2DomHash(s.query))));for(const width of [320,390,1440]){
   const output=[];for(const[queryHash,group]of queryGroups){const matching=samples.filter(s=>s.width===width&&task2DomHash(s.query)===queryHash);need(matching.length>=1);const sample=matching.at(-1),reference=group.at(-1),expected=task2DomExpectedFields(reference.safe_projection,view,this.#binding);
    // Every actual duplicate/page was independently checked; DOM samples cover each actual query.
    need(group.every(r=>same(r.safe_projection,reference.safe_projection))&&same(sample.rows.map(x=>x.key).sort(),expected.map(x=>x.key).sort()));const fields=[];
    for(const e of expected){const m=sample.rows.find(x=>x.key===e.key);need(m&&m.kind===e.kind);const actual=e.encoding==='utf8'?sha(m.value):task2DomHash(m.value);need(actual===e.expected_sha256);
     fields.push({key:e.key,kind:e.kind,encoding:e.encoding,expected_sha256:e.expected_sha256,actual_sha256:actual,matched:true,bounds:m.bounds,parent_bounds:m.parent_bounds,content_bounds:m.content_bounds,visible:true,enabled:true});}
    output.push({query_sha256:queryHash,requests:group.map(r=>({sequence:r.sequence,capture_ref:r.capture_ref,response_body:r.response_body,full_dto:r.full_dto})),started_at_ms:sample.started_at_ms,finished_at_ms:sample.finished_at_ms,url_sha256:sample.url_sha256,viewport:sample.viewport,fields});
   }
   const ordinal=(index-4)*3+[320,390,1440].indexOf(width)+1,number=String(ordinal).padStart(3,'0'),leaf=this.#mode.prefix+'-dom-measure-'+number+'.json';
   const value={schema:1,scope:'MS5_TASK2_VISIBLE_READ_MEASUREMENT',binding:this.#binding,scenario:this.#mode.scenario,view,role,tab_id_sha256:sha(this.#tabs[role].id),window_step:index,width,samples:output,screenshot:null,screenshot_policy:'OMIT_PRIVATE_ORDER_CONTENT',diagnostic:null};
   const measurement=await this.#create(leaf,value),obsLeaf=this.#mode.prefix+'-dom-observation-'+number+'.json',observation={schema:1,scope:'MS5_TASK2_NORMAL_READ_DOM_OBSERVATION',binding:this.#binding,scenario:this.#mode.scenario,view,role,tab_id_sha256:value.tab_id_sha256,window_step:index,width,measurement,
    business_step:null,native_ack:{sequence:frame.sequence,sha256:sha(Buffer.from(task2DomJson(frame)+'\n')),stdout_start:Array.from({length:frame.sequence},(_,n)=>Buffer.byteLength(task2DomJson(UiTask2BusinessProducer.prototype.validatedFrame.call(this.#producer,n))+'\n')).reduce((a,n)=>a+n,0),stdout_end:Array.from({length:frame.sequence+1},(_,n)=>Buffer.byteLength(task2DomJson(UiTask2BusinessProducer.prototype.validatedFrame.call(this.#producer,n))+'\n')).reduce((a,n)=>a+n,0)},coverage:{query_sha256:[...queryGroups.keys()],all_expected_fields:true},screenshot:null,diagnostic:null};
   this.#observations.push({leaf:obsLeaf,descriptor:null,observation,measurement,value});required.push(measurement);
  }
  // Release every full private visible string after its independent native read has bound the result.
  for(const sample of samples)sample.rows.forEach(x=>{x.value='';});this.#samples=this.#samples.filter(s=>s.index!==index);this.#verified.add(index);return{view,observations:required,DOM_acceptance:'WINDOW_AND_NATIVE_FINAL_READBACK_REQUIRED'};
 });}
 async finalize(){return this.#exclusive(async()=>{need(this.#phase==='READY'&&this.#verified.size===6&&this.#observations.length===18&&this.#samples.length===0&&same(await task2DomSource(this.#config,this.#binding,this.#observer),this.#proof));
  const stateName={test_paid:'paid',unpaid:'unpaid',cancelled:'cancelled'}[this.#mode.state],windowLeaf='ui-task2-'+stateName+'-read-window.json',windowRaw=await ordinary(path.join(this.#proof.evidence,windowLeaf)),w=json(windowRaw);
  keys(w,['schema','scope','status','binding','baseline','steps','final','observer_source','observer_native','preservation','coverage','diagnostic','full_acceptance']);need(w.schema===1&&same(w.binding,this.#binding)&&w.scope==='MS5_TASK2_'+stateName.toUpperCase()+'_READ_WINDOW_ONLY'&&w.status==='VERIFIED_MS5_TASK2_'+stateName.toUpperCase()+'_READ_NATIVE_WINDOW_ONLY'&&w.diagnostic===null&&w.full_acceptance==='INCOMPLETE'&&Array.isArray(w.steps)&&w.steps.length===9);
  const sourceRecord=json(await this.#read(w.observer_source,this.#mode.prefix+'-observer-source.json'));keys(sourceRecord,['schema','scope','binding','files','observer_source','scenario']);need(sourceRecord.schema===1&&sourceRecord.scope==='MS5_TASK2_FROZEN_PHP_SOURCE_BINDING'&&same(sourceRecord.binding,this.#binding)&&same(sourceRecord.observer_source,this.#observer)&&sourceRecord.scenario===this.#mode.scenario&&same(sourceRecord.files,this.#proof.php_source_files));
  const frames=Array.from({length:20},(_,i)=>UiTask2BusinessProducer.prototype.validatedFrame.call(this.#producer,i)),names=task2NativeNames(this.#mode),n=w.observer_native;keys(n,['created','terminal','stdout','stderr']);
  const created=json(await this.#read(n.created,names.created)),terminal=json(await this.#read(n.terminal,names.terminal));keys(created,['label','executable','argv','pid']);keys(terminal,['schema','scope','executable','argv','pid','close_code','close_signal','stdout','stderr']);
  const argv=['exec','-i',this.#binding.backend_cid,'php','/run/audit/task2-observer/entry.php',this.#modeName];need(created.label===this.#mode.prefix+'-observer'&&created.executable==='docker'&&same(created.argv,argv)&&integer(created.pid,1)&&terminal.schema===1&&terminal.scope===this.#mode.terminal&&terminal.executable==='docker'&&same(terminal.argv,argv)&&terminal.pid===created.pid&&terminal.close_code===0&&terminal.close_signal===null);
  const stdout=await this.#read(n.stdout,names.stdout,268435456),stderr=await this.#read(n.stderr,names.stderr,1,true);need(stderr.length===0);
  for(const[k,raw]of[['stdout',stdout],['stderr',stderr]]){keys(terminal[k],['bytes','sha256','stored_bytes','stored_sha256','complete']);need(same(terminal[k],{...pin(raw),stored_bytes:raw.length,stored_sha256:sha(raw),complete:true}));}
  const text=new TextDecoder('utf8',{fatal:true}).decode(stdout);need(text.endsWith('\n'));const lines=text.slice(0,-1).split('\n');need(lines.length===20);let offset=0;
  for(let i=0;i<20;i++){need(Buffer.byteLength(lines[i]+'\n')<=16777216&&lines[i]===task2DomJson(frames[i]));offset+=Buffer.byteLength(lines[i]+'\n');}need(offset===stdout.length);
  for(const[k,leaf,frameKey]of[['baseline',this.#mode.prefix+'-baseline.json','baseline'],['final',this.#mode.prefix+'-final.json','final']])need(same(json(await this.#read(w[k],leaf)),frames[k==='baseline'?0:19].receipt[frameKey]));
  let previous=w.baseline.sha256;
  for(let i=1;i<=9;i++){const leaf=this.#mode.prefix+'-business-step-'+String(i).padStart(2,'0')+'.json',s=json(await this.#read(w.steps[i-1],leaf)),f=frames[2*i];
   keys(s,['schema','scope','scenario','binding','observer_source','index','name','previous_step_sha256','wire','before','after','verified_after','expected_delta','oracle','observer_event','browser_event','diagnostic']);need(s.schema===1&&s.scope==='MS5_TASK2_NORMAL_READ_BUSINESS_STEP'&&s.scenario===this.#mode.scenario&&same(s.binding,this.#binding)&&same(s.observer_source,this.#observer)&&s.index===i&&s.name===TASK2_READ_STEPS[i-1]&&s.previous_step_sha256===previous&&s.diagnostic===null);
   const number=String(i).padStart(2,'0');for(const[k,part,value]of[['before','before',frames[2*i-1].receipt.before],['after','after',f.receipt.after],['verified_after','verified',f.receipt.verified_after],['expected_delta','expected',f.receipt.expected_delta]])need(same(json(await this.#read(s[k],this.#mode.prefix+'-'+number+'-'+part+'.json')),value));
   const e=json(await this.#read(s.browser_event,this.#mode.prefix+'-browser-step-'+number+'.json'));need(e.index===i&&same(e.binding,this.#binding));need(same(s.oracle,{source:f.receipt.oracle.source,case_name:f.receipt.oracle.case_name,expected:s.expected_delta,actual:s.after,readback:s.verified_after,observer_source_sha256:f.receipt.oracle.observer_source_sha256}));
   const exchange=UiTask2BusinessProducer.prototype.validatedExchange.call(this.#producer,2*i);need(same(s.observer_event,exchange));
   need(exchange.request_sha256===sha(Buffer.from(task2DomJson({schema:1,binding:this.#binding,sequence:2*i,command:'STEP_VERIFY',step:i,wire_public:{wire:s.wire,browser_event:e},observer_source:this.#observer}))));
   const start=lines.slice(0,2*i).reduce((a,x)=>a+Buffer.byteLength(x+'\n'),0),raw=Buffer.from(lines[2*i]+'\n');keys(s.observer_event,['observer_pid','request_index','request_sha256','response_index','response_sha256','stdout_start','stdout_end','terminal_required']);
   need(s.observer_event.observer_pid===created.pid&&s.observer_event.request_index===2*i&&s.observer_event.response_index===2*i&&hex(s.observer_event.request_sha256)&&s.observer_event.response_sha256===sha(raw)&&s.observer_event.stdout_start===start&&s.observer_event.stdout_end===start+raw.length&&s.observer_event.terminal_required==='SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME');
   for(const row of this.#observations.filter(x=>x.observation.window_step===i)){for(const sample of row.value.samples)need(sample.started_at_ms>=e.started_at_ms&&sample.finished_at_ms<=e.finished_at_ms);need(row.observation.business_step===null);row.observation.business_step=copy(w.steps[i-1]);need(row.observation.native_ack.sha256===sha(raw)&&row.observation.native_ack.stdout_start===start&&row.observation.native_ack.stdout_end===start+raw.length);}
   previous=w.steps[i-1].sha256;
  }
  for(const row of this.#observations){need(row.descriptor===null);row.descriptor=await this.#create(row.leaf,row.observation);need(same(json(await this.#read(row.descriptor,path.basename(row.descriptor.path))),row.observation)&&same(json(await this.#read(row.measurement,path.basename(row.measurement.path))),row.value));}
  need(same(w.coverage,frames[18].receipt.expected_delta.independent.state_coverage));keys(w.preservation,['source','predecessors','private_inputs','public_materials','observer_tools']);
  for(const[k,d]of Object.entries(w.preservation))need(same(json(await this.#read(d,this.#mode.prefix+'-'+k.replaceAll('_','-')+'-preserved.json')),frames[19].receipt.preservation[k]));
  const value={schema:1,scope:'MS5_TASK2_NORMAL_READ_DOM_ONLY',status:'VERIFIED_MS5_TASK2_NORMAL_READ_DOM',binding:this.#binding,scenario:this.#mode.scenario,window:{path:'/run/audit/evidence/'+windowLeaf,...pin(windowRaw)},observer_native:copy(n),observations:this.#observations.map(x=>x.descriptor),coverage:{views:TASK2_READ_VIEWS.slice(3),widths:[320,390,1440],observations:18},producer_source:this.#proof.producer_source,preservation:copy(w.preservation),diagnostic:null,full_acceptance:'INCOMPLETE'};
  const descriptor=await this.#create(this.#mode.prefix+'-dom.json',value);this.#phase='PUBLISHED';return copy({descriptor,value});
 });}
 releasePrivateReferences(){need(!this.#busy);this.#samples.forEach(s=>s.rows.forEach(x=>{x.value='';}));this.#samples=[];this.#config=null;this.#producer=null;this.#companion=null;this.#tabs=null;this.#proof=null;this.#phase='RELEASED';return{managed_references_released:true,immutable_JS_strings_securely_erased:false};}
}
