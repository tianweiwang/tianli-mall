// PRIVATE RAM Source checkpoint. Import has no file/process/network/browser action.
import { createHash } from 'node:crypto';
import { isDeepStrictEqual as equal, TextDecoder } from 'node:util';
import { UiNormalNetworkCapture } from './ms4-ui-network-capture.mjs';
import { UiWindowObserverBridge } from './ms4-ui-window-native-bridge.mjs';
import { UiPrivateCaller } from './ms4-ui-private-caller.mjs';
import { UiBrowserNormalClose } from './ms4-ui-browser-close.mjs';

const ADOPTION='FINAL_REVIEWED_R4_SOURCE_SELECTED';
const OBSERVER_SOURCE_REFERENCE=Object.freeze([
 {path:'/workspace/help/audit-regressions/isolated/ms4-ui-backend.php',bytes:3034,sha256:'ae4ebd8dcd8db0369276bf219250ca2f018147c3424bbbc9e21534b536c8bec1'},
 {path:'/workspace/help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php',bytes:132938,sha256:'a517ca25cfe2d86d89e0630b35398a56fa07c67f92c0ef54ed3bf265bee994b0'},
 {path:'/workspace/help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',bytes:243930,sha256:'e61184d208d9f6f0dfbfad9eb5eb32635ed8fd103e62f7abe5852712f5ee9be3'}
]);
const TICKET_HEADER='x-aftersale-read-ticket';
const FINAL_PROJECTION='FINAL_REVIEWED_R3_FINITE_PROJECTION';
const PROJECTION_SOURCE=Object.freeze({bytes:12777,sha256:'37411823c005ee7179ee1b8d10b958bef64237f0bbf94a6f0aa5481b5fc279e5'});
const STEPS=Object.freeze(['login-customer','login-merchant','login-platform','n1-initial-read',
 'n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read','n1-merchant-approve',
 'ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1','receive-q1-all-eligible',
 'n2-public-image-upload','n2-review-submit','n2-pending-public-read','n2-platform-moderate',
 'n2-merchant-reply','n2-public-review-read','n2-public-image-read']);
const ROLES=Object.freeze(['customer','merchant','platform','customer','customer','customer','customer','merchant',
 'merchant','customer','customer','merchant','customer','customer','customer','public','platform','merchant','public','public']);
const MATERIALS=Object.freeze({
 h5:{bytes:49501,sha256:'df48f2e9160e8fd3d9f0bde5b9cdf633f268cf8f43d24da8fd9701b933a0e876'},
 merchant:{bytes:4174,sha256:'0f916caf675fde4ef5460030f21769b1a2357e98b8acbee625b56e2b444be8f3'},
 admin:{bytes:87054,sha256:'0fa82aebab70161d16d48e9a7f6bed45d7e45232ef19dd153c46ecc0ced810e7'},
 profile:{bytes:534684,sha256:'bdade907675b73a5be1fccceb4449306f33f2acbc3242bf449ce4dddd87970a2'},
 png:{bytes:234,sha256:'1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8'}});
const ARTIFACTS={h5:'manifests/ui-h5-dist.json',merchant:'manifests/ui-merchant-dist.json',
 admin:'manifests/ui-admin-dist.json',profile:'manifests/ui-public-copy-profile.json',png:'ms4-order.png'};
const HISTORY=new Set(['/','/index.html','/pages/order_addcart/order_addcart','/pages/users/login/index',
 '/pages/store/index','/pages/goods_details/index','/pages/goods/order_confirm/index','/pages/goods/order_details/index',
 '/pages/goods/order_list/index','/pages/goods/marketplace_aftersale/index','/pages/goods/marketplace_review/index',
 '/pages/goods/goods_comment_list/index']);
const fail=code=>{throw new Error('MS4_UI_PRODUCER_'+code);};
const need=(v,code)=>{if(!v)fail(code);};
const object=v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
const integer=(v,min=0)=>Number.isSafeInteger(v)&&v>=min;
const hex=v=>typeof v==='string'&&/^[a-f0-9]{64}$/.test(v);
const sha=v=>createHash('sha256').update(v).digest('hex');
const pin=v=>({bytes:v.length,sha256:sha(v)});
function keys(v,names){need(object(v)&&equal(Object.keys(v).sort(),[...names].sort()),'EXACT_KEYS');}
function clone(v,d=0){
 need(d<=128,'JSON_DEPTH');
 if(v===null||typeof v==='string'||typeof v==='boolean')return v;
 if(typeof v==='number'){need(Number.isFinite(v),'FINITE_NUMBER');return v;}
 need(v!==null&&typeof v==='object','JSON_ONLY');
 const ds=Object.getOwnPropertyDescriptors(v);
 if(Array.isArray(v)){
  need(Reflect.ownKeys(ds).length===v.length+1,'DENSE_ARRAY');
  return Array.from({length:v.length},(_,i)=>{need(ds[i]&&'value'in ds[i]&&ds[i].enumerable,'ARRAY_DATA');return clone(ds[i].value,d+1);});
 }
 need([Object.prototype,null].includes(Object.getPrototypeOf(v)),'PLAIN_JSON');
 const r={};
 for(const k of Reflect.ownKeys(ds)){
  need(typeof k==='string'&&ds[k].enumerable&&'value'in ds[k],'JSON_DATA');
  Object.defineProperty(r,k,{value:clone(ds[k].value,d+1),enumerable:true,writable:true,configurable:true});
 }
 return r;
}
const stringJSON=v=>JSON.stringify(v).replace(/\u2028/g,'\\u2028').replace(/\u2029/g,'\\u2029');
function canonical(v){
 if(Array.isArray(v))return '['+v.map(canonical).join(',')+']';
 if(!object(v))return stringJSON(v);
 return '{'+Object.keys(v).sort((a,b)=>Buffer.compare(Buffer.from(a),Buffer.from(b)))
  .map(k=>stringJSON(k)+':'+canonical(v[k])).join(',')+'}';
}
const digest=v=>sha(Buffer.from(canonical(v)));
function parse(raw){try{return clone(JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(raw)));}catch{fail('ACTUAL_UTF8_JSON_REQUIRED');}}
function safe(v){
 if(Array.isArray(v)){v.forEach(safe);return v;}
 if(object(v))for(const[k,r]of Object.entries(v)){
  need(!/^(token|access_token|refresh_token|read_ticket|request_ticket|password|passwd|pwd|authorization|cookie|set-cookie|credential_stamp|sid|session_version)$/i.test(k),'UNMODELED_PRIVATE_FIELD');
  safe(r);
 }
 return v;
}
function headers(v){
 need(object(v),'ACTUAL_HEADERS');const r=Object.create(null);
 for(const[k,value]of Object.entries(v)){const n=k.toLowerCase();need(typeof value==='string'&&!Object.hasOwn(r,n)&&!/[\0\r\n]/.test(n),'UNAMBIGUOUS_HEADERS');r[n]=value;}
 return r;
}
function relative(p){need(typeof p==='string'&&!p.startsWith('/')&&!p.includes('\\')&&!p.includes('\0')&&p.split('/').every(x=>x!==''&&x!=='.'&&x!=='..'),'RELATIVE_MEMBER');}
function rows(v){
 need(Array.isArray(v),'FULL_FILES_LIST');const r=new Map();
 for(const x of v){keys(x,['path','bytes','sha256']);relative(x.path);need(integer(x.bytes)&&hex(x.sha256)&&!r.has(x.path),'UNIQUE_FULL_FILE');r.set(x.path,clone(x));}
 return r;
}
function materials(raws,b){
 keys(raws,['h5','merchant','admin','profile','png']);const values={},buffers={};
 try{
  for(const n of Object.keys(MATERIALS)){
   need(Buffer.isBuffer(raws[n])||raws[n] instanceof Uint8Array,'ACTUAL_MATERIAL_RAW');
   const raw=Buffer.from(raws[n]);buffers[n]=raw;
   need(equal(pin(raw),MATERIALS[n])&&equal(b.source_closure.artifacts[ARTIFACTS[n]],MATERIALS[n]),'FULL_FROZEN_MATERIAL_'+n.toUpperCase());
   if(n!=='png')values[n]=parse(raw);
  }
  const p=values.profile;keys(p,['public_source_files','public_source_directories','h5_dist_files','h5_dist_directories',
   'merchant_dist_files','merchant_dist_directories','admin_dist_files','admin_dist_directories','synthetic_png','builds']);
  keys(p.builds,['h5','merchant','admin']);const builds={};
  for(const n of ['h5','merchant','admin']){
   keys(values[n],['files','directories']);
   need(equal(values[n].files,p[n+'_dist_files'])&&equal(values[n].directories,p[n+'_dist_directories']),'COMPLETE_MANIFEST_PROFILE_JOIN');
   const d=p.builds[n];keys(d,['path','bytes','sha256']);need(d.path==='/run/ms4-prep/manifests/ui-'+n+'-dist.json'&&equal({bytes:d.bytes,sha256:d.sha256},MATERIALS[n]),'BUILD_DESCRIPTOR');
   need(Array.isArray(values[n].directories)&&new Set(values[n].directories).size===values[n].directories.length,'FULL_DIRS');values[n].directories.forEach(relative);
   builds[n]=rows(values[n].files);
  }
  const original=rows(p.public_source_files);
  need(builds.h5.size===337&&builds.merchant.size===30&&builds.admin.size===603&&original.size===2536,'ACTUAL_FULL_COUNTS');
  need(Array.isArray(p.public_source_directories)&&new Set(p.public_source_directories).size===p.public_source_directories.length,'FULL_PUBLIC_DIRS');p.public_source_directories.forEach(relative);
  keys(p.synthetic_png,['path','bytes','sha256']);need(p.synthetic_png.path==='/run/ms4-prep/ms4-order.png'&&equal({bytes:p.synthetic_png.bytes,sha256:p.synthetic_png.sha256},MATERIALS.png),'FROZEN_PNG');
  return{builds,original};
 }finally{Object.values(buffers).forEach(v=>v.fill(0));}
}
// Same exact routing precedence as the closed Nginx Source; no suffix-based exclusion from business.
function materialFor(path,m){
 if(path==='/admin/system_static')return null;
 let row=null,source=null;
 if(path==='/merchant/'||path==='/merchant/index.html'){source='merchant';row=m.builds.merchant.get('index.html');}
 else if(path.startsWith('/merchant/')){source='merchant';row=m.builds.merchant.get(path.slice(10));}
 else if(path==='/admin/'||path==='/admin/index.html'){source='admin';row=m.builds.admin.get('index.html');}
 else if(path.startsWith('/admin/')){
  source='admin';const p=path.slice(7);row=m.builds.admin.get(p);
  if(!row&&!p.startsWith('system_static/')&&!/\.[^/]+$/.test(p))row=m.builds.admin.get('index.html');
 }else if(path==='/__ms4_fixture__/product.png'){source='synthetic_png';row={path:'__ms4_fixture__/product.png',...MATERIALS.png};}
 else if(path==='/favicon.ico'||path.startsWith('/statics/')){source='original_public';row=m.original.get(path.slice(1));}
 else if(HISTORY.has(path)){source='h5';row=m.builds.h5.get('index.html');}
 else if(path.startsWith('/assets/')||path.startsWith('/static/')||/^\/pages\/.+\/static\//.test(path)||/^\/pages\/.*\.(js|css|map)$/.test(path)){
  source='h5';row=m.builds.h5.get(path.slice(1));
  if(!row&&path.startsWith('/static/')&&!/\.(js|css|map)$/.test(path)){source='original_public';row=m.original.get(path.slice(1));}
 }
 return row?{source,path:row.path,bytes:row.bytes,sha256:row.sha256}:null;
}
function body(r){
 const v=r.get_response_body?.result;
 need(r.get_response_body?.command_error===null,'COMPLETE_RESPONSE_BODY_COMMAND');keys(v,['body','base64Encoded']);
 need(typeof v.body==='string'&&typeof v.base64Encoded==='boolean','ACTUAL_RESPONSE_BODY');
 const raw=Buffer.from(v.body,v.base64Encoded?'base64':'utf8');
 need(!v.base64Encoded||raw.toString('base64')===v.body,'FULL_BASE64');
 need(raw.length<=268435456,'RESPONSE_RAM_LIMIT');return raw;
}
function envelope(r){
 const raw=body(r);try{const v=parse(raw);keys(v,['status','msg','data']);need(v.status===200&&typeof v.msg==='string','ORDINARY_APP_200');return v.data;}finally{raw.fill(0);}
}
function reqHeaders(r){need(r.request_extra_info.length===1,'ACTUAL_REQUEST_EXTRA_INFO');return headers(r.request_extra_info[0].params.headers);}
function resHeaders(r){need(r.response_extra_info.length===1,'ACTUAL_RESPONSE_EXTRA_INFO');return headers(r.response_extra_info[0].params.headers);}
function cacheKind(r){
 need(Array.isArray(r.request_served_from_cache),'SEVENTH_CACHE_EVENT_REQUIRED');
 const v=r.response_received[0]?.params.response;need(object(v),'ACTUAL_RESPONSE');
 for(const k of ['fromDiskCache','fromPrefetchCache','fromServiceWorker'])need(v[k]===undefined||typeof v[k]==='boolean','CACHE_FLAGS');
 if(r.response_extra_info.some(x=>x.params.statusCode===304))return'revalidation304';
 if(v.fromServiceWorker===true)return'unknown_service_worker';
 const local=r.request_served_from_cache.length>0||v.fromDiskCache===true||v.fromPrefetchCache===true;
 if(local)return r.response_extra_info.length===0?'explicit_local_cache':'cache_with_network_status_unknown';
 return r.response_extra_info.length===1&&r.response_extra_info[0].params.statusCode===200&&v.status===200?'network200':'unknown';
}
function fresh(r){need(cacheKind(r)==='network200','CACHE_OR_304_NOT_A_NEW_SERVER_REQUEST');reqHeaders(r);resHeaders(r);}
function requestBytes(r){
 const q=r.request_will_be_sent[0].params.request;
 if(Array.isArray(q.postDataEntries)&&q.postDataEntries.length){
  const parts=q.postDataEntries.map(x=>{keys(x,['bytes']);need(typeof x.bytes==='string','ACTUAL_POST_ENTRY');const b=Buffer.from(x.bytes,'base64');need(b.toString('base64')===x.bytes,'POST_ENTRY_BASE64');return b;});
  try{const b=Buffer.concat(parts);need(b.length<=1048576,'PRIVATE_REQUEST_LIMIT');return b;}finally{parts.forEach(b=>b.fill(0));}
 }
 need(q.hasPostData!==true||typeof q.postData==='string','MISSING_ACTUAL_POST_DATA');
 return Buffer.from(q.postData??'','utf8');
}
function requestJSON(r){const raw=requestBytes(r);try{return parse(raw);}finally{raw.fill(0);}}

function multipart(r,kind){
 const type=reqHeaders(r)['content-type'],m=/^multipart\/form-data;\s*boundary=(?:"([^"\r\n]+)"|([^;\s\r\n]+))$/i.exec(type??'');
 need(m,'ACTUAL_MULTIPART_BOUNDARY');
 const q=r.request_will_be_sent[0].params.request;
 need(Array.isArray(q.postDataEntries)&&q.postDataEntries.length>0,'COMPLETE_FILE_BYTES_NOT_OBSERVED');
 const raw=requestBytes(r),boundary=Buffer.from('--'+(m[1]??m[2])),fields={};let file=null;
 try{
  let p=0;
  while(true){
   need(raw.subarray(p,p+boundary.length).equals(boundary),'MULTIPART_BOUNDARY');p+=boundary.length;
   if(raw.subarray(p,p+4).equals(Buffer.from('--\r\n'))){p+=4;break;}
   need(raw.subarray(p,p+2).equals(Buffer.from('\r\n')),'MULTIPART_CRLF');p+=2;
   const endHeader=raw.indexOf(Buffer.from('\r\n\r\n'),p);need(endHeader>=p&&endHeader-p<=8192,'MULTIPART_FULL_HEADERS');
   const h={};
   for(const line of new TextDecoder('utf-8',{fatal:true}).decode(raw.subarray(p,endHeader)).split('\r\n')){
    const at=line.indexOf(':');need(at>0,'MULTIPART_HEADER');const k=line.slice(0,at).toLowerCase();need(!Object.hasOwn(h,k),'MULTIPART_UNIQUE_HEADER');h[k]=line.slice(at+1).trim();
   }
   const d=/^form-data; name="([^"\r\n]+)"(?:; filename="([^"\r\n]+)")?$/.exec(h['content-disposition']??'');
   need(d,'MULTIPART_DISPOSITION');
   const end=raw.indexOf(Buffer.concat([Buffer.from('\r\n'),boundary]),endHeader+4);need(end>=0,'COMPLETE_MULTIPART_PART');
   const bytes=raw.subarray(endHeader+4,end),name=d[1];
   if(d[2]!==undefined){
    keys(h,['content-disposition','content-type']);need(file===null&&name==='file'&&h['content-type']==='image/png'&&equal(pin(bytes),MATERIALS.png),'ACTUAL_UPLOAD_FULL_PNG');
    file={...pin(bytes),mime_type:'image/png'};
    if(kind==='review'){
     const original=d[2];
     need([...original].length>=1&&[...original].length<=255&&!original.includes('\0')&&!original.includes('/')
      &&!original.includes('\\')&&/\.png$/i.test(original),'ACTUAL_REVIEW_ORIGINAL_UTF8_FILENAME');
     file.original_name=original;
    }
   }else{
    keys(h,['content-disposition']);need(!Object.hasOwn(fields,name),'MULTIPART_UNIQUE_FIELD');
    fields[name]=new TextDecoder('utf-8',{fatal:true}).decode(bytes);
   }
   p=end+2;
  }
  need(p===raw.length&&file!==null,'MULTIPART_COMPLETE_EOF');
  if(kind==='private'){keys(fields,['schema','upload_key']);return{schema:fields.schema,upload_key:fields.upload_key,file};}
  if(kind==='review'){keys(fields,[]);return{file};}
  keys(fields,['filename']);return{filename:fields.filename,file};
 }finally{raw.fill(0);}
}
function observedBearer(h){
 const values=[h['authori-zation'],h.authorization].filter(v=>v!==undefined);
 need(values.every(v=>typeof v==='string')&&new Set(values).size<=1,'CONFLICTING_ACTUAL_AUTH_HEADERS');
 return values.length===0?null:values[0];
}
function merchantMe(data){
 need(object(data)&&object(data.employee)&&object(data.merchant)&&object(data.role)
  &&integer(data.employee.id,1)&&integer(data.merchant.mer_id,1)&&data.employee.mer_id===data.merchant.mer_id
  &&integer(data.role.id,1)&&Array.isArray(data.role.permissions),'ACTUAL_MERCHANT_ME_NESTED_DTO');
 return{employee_id:data.employee.id,merchant_id:data.merchant.mer_id,role_id:data.role.id,permissions:clone(data.role.permissions)};
}
function observedLogin(records,i,origin,privateValue,tokens){
 const logins=records.filter(r=>isPrimary(i,info(r,origin),null));need(logins.length===1,'UNIQUE_ACTUAL_LOGIN');
 const record=logins[0];fresh(record);const data=envelope(record);
 need(object(data)&&typeof data.token==='string'&&data.token.length>=32&&Buffer.byteLength(data.token)<=8192,'REAL_LOGIN_DATA_TOKEN');
 const token=data.token;let id;
 if(i===3){need(object(data.user_info)&&integer(data.user_info.id,1),'ACTUAL_PLATFORM_USER_INFO_ID');id=data.user_info.id;}
 else{
  const path=i===1?'/api/user':'/merchantapi/me';
  const reads=records.filter(r=>{const q=info(r,origin);return q.method==='GET'&&q.path===path;});
  need(reads.length>0,'SAME_STEP_REAL_IDENTITY_READ_REQUIRED');
  const ids=reads.map(r=>{
   fresh(r);need(observedBearer(reqHeaders(r))==='Bearer '+token,'SAME_STEP_NORMAL_TOKEN_HEADER_JOIN');
   const v=envelope(r);
   if(i===1){need(object(v)&&integer(v.uid,1),'ACTUAL_CUSTOMER_USER_UID');return v.uid;}
   return merchantMe(v).employee_id;
  });
  need(new Set(ids).size===1,'ONE_ACTUAL_PHASE_SUBJECT');id=ids[0];
 }
 privateValue.login_token=token;tokens[ROLES[i-1]]={token,subject_id:id};
}
function auth(r,tokens){
 const v=observedBearer(reqHeaders(r));if(v===null)return{present:false,kind:'none',subject_id:null};
 need(typeof v==='string'&&v.startsWith('Bearer ')&&v.length>7,'ACTUAL_BEARER_HEADER');
 const matches=Object.values(tokens).filter(x=>x&&x.token===v.slice(7));
 need(matches.length===1,'HEADER_MATCHES_ACTUAL_NORMAL_LOGIN_RESPONSE');
 return{present:true,kind:'Bearer',subject_id:matches[0].subject_id};
}
function info(r,origin){
 const q=r.request_will_be_sent[0]?.params.request;
 need(object(q)&&['GET','POST','PUT'].includes(q.method),'ACTUAL_SUPPORTED_HTTP_METHOD');const u=new URL(q.url);
 need(['http:','https:'].includes(u.protocol)&&u.origin===origin&&u.username===''&&u.password===''&&u.hash==='','FROZEN_ORIGIN');
 const path=u.pathname+u.search;
 for(const k of u.searchParams.keys())need(!/^(?:token|access_token|refresh_token|read_ticket|request_ticket|password|pwd|authorization|session|credential)$/i.test(k),'NO_SECRET_QUERY_PROJECTION');
 need(path.length<=2048&&!path.includes('..')&&!path.includes('\\')&&!/%(?:2f|5c|00|2e)/i.test(path),'UNAMBIGUOUS_PATH');
 return{method:q.method,path,pathname:u.pathname};
}
const apiRules=[
 ['GET',/^\/api\/order\/refund\/list\?contract=2&page=1&limit=20$/],
 ['GET',/^\/api\/order\/refund\/cart_info\/[1-9][0-9]*\?contract=2&fulfillment_page=1&fulfillment_limit=20$/],
 ['GET',/^\/api\/order\/refund\/detail\/[A-Za-z0-9_-]+$/],
 ['POST',/^\/api\/order\/refund\/files\/[1-9][0-9]*$/],
 ['POST',/^\/api\/order\/refund\/apply\/[1-9][0-9]*$/],
 ['POST',/^\/(?:api\/order\/refund\/files|merchantapi\/aftersale-files)\/[1-9][0-9]*\/read-ticket$/],
 ['GET',/^\/(?:api\/order\/refund\/files|merchantapi\/aftersale-files)\/[1-9][0-9]*\/content$/],
 ['GET',/^\/merchantapi\/aftersales\/[1-9][0-9]*$/],
 ['POST',/^\/merchantapi\/aftersales\/[1-9][0-9]*\/approve$/],
 ['GET',/^\/merchantapi\/orders\/[1-9][0-9]*\/packages\?page=1&limit=20$/],
 ['POST',/^\/merchantapi\/orders\/[1-9][0-9]*\/shipments$/],
 ['GET',/^\/merchantapi\/packages\/[1-9][0-9]*$/],
 ['GET',/^\/api\/order\/fulfillment\/packages\/[A-Za-z0-9_-]+$/],
 ['POST',/^\/api\/order\/fulfillment\/packages\/[A-Za-z0-9_-]+\/receipt$/],
 ['GET',/^\/api\/order\/detail\/[A-Za-z0-9_-]+\?fulfillment_page=1&fulfillment_limit=20$/],
 ['GET',/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*$/],
 ['POST',/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*$/],
 ['POST',/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*\/files$/],
 ['GET',/^\/(?:adminapi\/marketplace|merchantapi)\/reviews\/[1-9][0-9]*$/],
 ['PUT',/^\/adminapi\/marketplace\/reviews\/[1-9][0-9]*\/review$/],
 ['PUT',/^\/merchantapi\/reviews\/[1-9][0-9]*\/reply$/],
 ['GET',/^\/api\/reply\/list\/[1-9][0-9]*\?page=1&limit=20&type=0$/],
 ['GET',/^\/api\/reply\/config\/[1-9][0-9]*$/]];
const apiExact=new Set(['POST /api/login','POST /merchantapi/login','POST /adminapi/login','GET /api/user','GET /merchantapi/me',
 'GET /adminapi/menus','GET /api/logout','GET /api/get_lang_type_list','GET /api/theme_info/home','GET /api/theme_info/theme',
 'GET /api/theme/navigation','GET /api/diy_version']);
const startupPaths=new Set(['/api/basic_config','/api/copyright','/api/share','/api/wechat/get_logo','/api/lang_version',
 '/api/get_lang_json','/api/get_lang_type_list','/api/theme_info/home','/api/theme_info/theme','/api/theme/navigation',
 '/api/cart/count','/api/v2/get_today_coupon','/api/v2/new_coupon','/api/products','/api/get_script']);
const finiteAPI=(m,p)=>apiExact.has(m+' '+p)||apiRules.some(([method,re])=>method===m&&re.test(p))
 ||(m==='GET'&&startupPaths.has(p.split('?')[0]));
const publicImage=(p,image)=>image!==null&&p===image.reference;
function isPrimary(i,r,image){
 const m=r.method,p=r.path;
 if(i<=3)return m==='POST'&&p===['/api/login','/merchantapi/login','/adminapi/login'][i-1];
 switch(i){
  case 4:return m==='GET'&&/^\/api\/order\/refund\/cart_info\/[1-9][0-9]*\?contract=2&fulfillment_page=1&fulfillment_limit=20$/.test(p);
  case 5:return m==='POST'&&/^\/api\/order\/refund\/files\/[1-9][0-9]*$/.test(p);
  case 6:return m==='POST'&&/^\/api\/order\/refund\/apply\/[1-9][0-9]*$/.test(p);
  case 7:return m==='GET'&&/^\/api\/order\/refund\/detail\/[A-Za-z0-9_-]+$/.test(p);
  case 8:return m==='POST'&&/^\/merchantapi\/aftersales\/[1-9][0-9]*\/approve$/.test(p);
  case 9:case 12:return m==='POST'&&/^\/merchantapi\/orders\/[1-9][0-9]*\/shipments$/.test(p);
  case 10:case 11:case 13:return m==='POST'&&/^\/api\/order\/fulfillment\/packages\/[A-Za-z0-9_-]+\/receipt$/.test(p);
  case 14:return m==='POST'&&/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*\/files$/.test(p);
  case 15:return m==='POST'&&/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*$/.test(p);
  case 16:case 19:return m==='GET'&&/^\/api\/reply\/list\/[1-9][0-9]*\?page=1&limit=20&type=0$/.test(p);
  case 17:return m==='PUT'&&/^\/adminapi\/marketplace\/reviews\/[1-9][0-9]*\/review$/.test(p);
  case 18:return m==='PUT'&&/^\/merchantapi\/reviews\/[1-9][0-9]*\/reply$/.test(p);
  case 20:return m==='GET'&&publicImage(p,image);
  default:return false;
 }
}
function projectedRequest(r,q,i){
 if(i<=3&&isPrimary(i,q,null)){requestJSON(r);return{};}
 if(q.method==='GET'){
  const v=r.request_will_be_sent[0].params.request;
  need(v.hasPostData!==true&&(v.postData===undefined||v.postData===''),'ACTUAL_EMPTY_GET_BODY');
  if(q.pathname==='/api/get_lang_json'){
   const h=reqHeaders(r);return{headers:{cb_lang:h.cb_lang??null,accept_language:h['accept-language']??null}};
  }
  return{};
 }
 if(/^\/api\/order\/refund\/files\/[1-9][0-9]*$/.test(q.path))return multipart(r,'private');
 if(/^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*\/files$/.test(q.path))return multipart(r,'review');
 const value=requestJSON(r);need(object(value),'ACTUAL_JSON_REQUEST_OBJECT');return safe(value);
}
function projectedResponse(r,q,i,privateValue,tokens){
 if(i<=3&&isPrimary(i,q,null)){
  const data=envelope(r),actual=tokens[ROLES[i-1]];
  need(object(data)&&actual&&data.token===actual.token&&integer(actual.subject_id,1),'SAME_PHASE_REAL_LOGIN_PROJECTION');
  return{subject_id:actual.subject_id};
 }
 if(/\/read-ticket$/.test(q.path)){
  const data=envelope(r);keys(data,['schema','scope','file','refund_id','issued_at','expires_at','read_ticket']);
  need(typeof data.read_ticket==='string'&&/^[A-Za-z0-9_-]{43}$/.test(data.read_ticket),'ACTUAL_READ_TICKET');
  const{read_ticket,...rest}=data;return safe(rest);
 }
 if(q.path==='/api/user'){const data=envelope(r);need(object(data)&&integer(data.uid,1),'ACTUAL_UID');return{uid:data.uid};}
 if(q.path==='/merchantapi/me')return merchantMe(envelope(r));
 if(q.path==='/adminapi/menus'||/^\/api\/order\/detail\/[A-Za-z0-9_-]+\?fulfillment_page=1&fulfillment_limit=20$/.test(q.path)){envelope(r);return null;}
 return safe(envelope(r));
}
function imageWire(r){return{actor_role:'public',method:r.method,path:r.path,body_public:clone(r.request_public),
 body_public_sha256:r.request_public_sha256,response_status:r.http_status,response_public:clone(r.response_public),
 response_public_sha256:r.response_public_sha256};}
function clean(w){
 need(w.scope==='MS4_UI_PASSIVE_NETWORK_RAM_ONLY'&&w.status.enabled===true&&w.status.halted===false&&w.status.pending.length===0
  &&w.status.unresolved.length===0&&w.status.incomplete.length===0&&w.unknown.length===0,'ACTUAL_CAPTURE_INCOMPLETE');
 need(w.http_requests.length+w.local_non_HTTP.length===w.requests.length,'COMPLETE_CAPTURE_PARTITION');
 for(const r of w.requests)need(r.relation==='started_after_checkpoint'&&r.unsupported===false,'NO_PREEXISTING_OR_UNRESOLVED');
}
function time(r,start,end){
 const t=r.timing;need(t&&Number.isFinite(t.derived_request_wall_time_ms)&&Number.isFinite(t.derived_finished_wall_time_ms),'REAL_WALL_MONOTONIC_JOIN');
 const a=Math.floor(t.derived_request_wall_time_ms),b=Math.ceil(t.derived_finished_wall_time_ms);
 need(integer(a,start)&&integer(b,a)&&b<=end,'ACTUAL_REQUEST_INSIDE_STEP');return{started_at_ms:a,finished_at_ms:b};
}
function binding(b){
 keys(b,['run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin','source_closure']);
 need(typeof b.run_id==='string'&&/^[A-Za-z0-9_-]{1,128}$/.test(b.run_id)&&/^[a-f0-9]{40}$/.test(b.source_sha)
  &&[b.freeze_sha256,b.context_sha256,b.ready_sha256,b.backend_cid,b.gateway_cid].every(hex),'ACTUAL_BINDING');
 const u=new URL(b.origin);need(['http:','https:'].includes(u.protocol)&&u.origin===b.origin,'CANONICAL_ORIGIN');
 keys(b.source_closure,['context','freeze','artifacts','reader','caller','browser_close']);
}
function sameCaller(c,b){
 const actual=UiPrivateCaller.prototype.publicBinding.call(c),wanted=clone(b);
 delete wanted.gateway_cid;delete wanted.source_closure.browser_close;
 need(equal(actual,wanted),'GENUINE_CALLER_SAME_RUN_SOURCE');
}

function sameBridgeSource(bridge,b){
 const actual=UiWindowObserverBridge.prototype.sourceBinding.call(bridge);
 keys(actual,['schema','scope','binding','files']);
 need(actual.schema===1&&actual.scope==='MS4_UI_FROZEN_PHP_SOURCE_BINDING'
  &&equal(actual.binding,b)&&equal(actual.files,OBSERVER_SOURCE_REFERENCE),'GENUINE_BRIDGE_FROZEN_CURRENT_PHP_SOURCE3');
 return actual;
}

/** Producer-owned RAM only; all callbacks are explicit Root ordinary actions, never native/auth proof. */
export class UiNormalBusinessProducer{
 #binding;#bridge;#capture;#caller;#companion;#materials;#phase='NEW';#busy=false;#index=0;
 #active=null;#pending=null;#tokens={};#merchant=null;#image=null;#witnesses=[];#windows=[];#partitions=[];#accepted=[];#readiness=null;#consumed=null;#ram=0;#fault=null;#publicPlanIssued=false;
 constructor({binding:b,bridge,captures,caller,companion,material_bytes}){
  binding(b);
  keys(captures,['customer','merchant','platform']);
  need(Object.getPrototypeOf(bridge)===UiWindowObserverBridge.prototype
   &&Object.values(captures).every(c=>Object.getPrototypeOf(c)===UiNormalNetworkCapture.prototype)
   &&new Set(Object.values(captures)).size===3&&Object.getPrototypeOf(caller)===UiPrivateCaller.prototype
   &&Object.getPrototypeOf(companion)===UiBrowserNormalClose.prototype,'ACTUAL_IMPORTED_CLASSES');
  this.#binding=clone(b);this.#bridge=bridge;this.#capture={...captures};this.#caller=caller;this.#companion=companion;
  this.#materials=materials(material_bytes,this.#binding);
 }
 #retain(v,destination){
  const bytes=Buffer.byteLength(JSON.stringify(v));need(this.#ram+bytes<=268435456,'PRODUCER_RAM_LIMIT_INCOMPLETE');
  this.#ram+=bytes;destination.push(v);
 }
 status(){return{scope:'MS4_UI_NORMAL_PRODUCER_SOURCE_ONLY',phase:this.#phase,index:this.#index,adoption:ADOPTION,
  required_steps:20,verified_steps:this.#index,diagnostic:this.#fault,projection_source:{...PROJECTION_SOURCE},actual_authentication:'INDEPENDENT_PHP_REQUIRED',
  DOM:'EXTERNAL_REAL_DESCRIPTOR_REQUIRED',full_acceptance:'NOT_RUN',server_quiet:'NOT_CLAIMED'};}
 arm(){
  need(ADOPTION==='FINAL_REVIEWED_R4_SOURCE_SELECTED'&&typeof TICKET_HEADER==='string'
   &&FINAL_PROJECTION==='FINAL_REVIEWED_R3_FINITE_PROJECTION','FINAL_SOURCE_SELECTION_REQUIRED');
  need(this.#phase==='NEW'&&!this.#busy,'ARM_ONCE');sameCaller(this.#caller,this.#binding);
  sameBridgeSource(this.#bridge,this.#binding);
  const p=UiWindowObserverBridge.prototype.poll.call(this.#bridge);
  need(p.phase==='BEGIN_ACK'&&p.required_steps===20&&p.verified_steps===0,'ACTUAL_BEGIN_ACK_BEFORE_UI');
  const r=UiWindowObserverBridge.prototype.response.call(this.#bridge,0);
  need(r.command==='BEGIN'&&r.step===0&&equal(r.receipt.baseline.binding,this.#binding),'ACTUAL_BEGIN_BINDING');
  this.#phase='ARMED';return this.status();
 }
 async captureReadiness(){
  need(this.#phase==='ARMED'&&!this.#busy&&this.#index===0&&this.#readiness===null,'READINESS_ONCE_AFTER_NATIVE_BEGIN');this.#busy=true;
  try{
   const p=UiWindowObserverBridge.prototype.poll.call(this.#bridge);
   need(['BEGIN_ACK','STEP_READY'].includes(p.phase)&&p.verified_steps===0,'REAL_NATIVE_GATE_BEFORE_CAPTURE_READINESS');
   const actual={},consumed={};
   for(const role of ['customer','merchant','platform']){
    const initial=UiNormalNetworkCapture.prototype.initialCheckpoint.call(this.#capture[role]);
    await UiNormalNetworkCapture.prototype.poll.call(this.#capture[role]);
    const window=UiNormalNetworkCapture.prototype.readWindow.call(this.#capture[role],initial.boundary);
    this.#retain({kind:'INITIAL_CAPTURE_BOUNDARY',role,window},this.#windows);clean(window);
    need(integer(initial.boundary.after_sequence)&&window.after_sequence===initial.boundary.after_sequence
     &&integer(window.covered_through_sequence,window.after_sequence),'REAL_INITIAL_CAPTURE_CURSOR');
    need(window.events.length===0&&window.requests.length===0,'UNASSIGNED_EVENT_BEFORE_FIRST_ACTION');
    actual[role]={enabled:window.status.enabled,covered_through_sequence:window.covered_through_sequence};
    consumed[role]={boundary:initial.boundary,covered_through_sequence:window.after_sequence};
   }
   this.#readiness=actual;this.#consumed=consumed;
   return{captures:clone(actual),bootstrap_before_enable:'ROOT_EXTERNAL_ACTUAL_BOUNDARY_NOT_CAPTURED_HERE',
    business_and_quiet:'NOT_PROVEN'};
  }catch{this.#phase='INCOMPLETE';this.#fault='CAPTURE_INITIAL_BOUNDARY_INCOMPLETE';fail(this.#fault);}
  finally{this.#busy=false;}
 }
 // Optional Task2-only handoff; the original UI20 methods and default cursor path remain unchanged.
 admitTask2Predecessor(predecessor){
  need(this.#phase==='ARMED'&&!this.#busy&&this.#index===0&&this.#readiness===null,'UI20_ONLY_BEFORE_FIRST_ACTION');
  need(Object.getPrototypeOf(predecessor)===UiTask2BusinessProducer.prototype,'UI20_GENUINE_TASK2_PREDECESSOR');
  UiTask2BusinessProducer.prototype.transferToOriginalUi20.call(predecessor,{consumer:this,bridge:this.#bridge,binding:this.#binding,captures:this.#capture,caller:this.#caller,companion:this.#companion});
  const state=task2OriginalHandoffs.get(this);need(state,'UI20_PRIVATE_NATIVE_CONTINUOUS_HANDOFF');
  this.#readiness=state.readiness;this.#consumed=state.consumed;task2OriginalHandoffs.delete(this);
  return{captures:clone(this.#readiness),source:'THREE_COMPLETED_TASK2_NATIVE_SCENES',DOM:'NATIVE_ENTRY_ADMISSION_STILL_REQUIRED'};
 }
 async beginStep({tab}){
  need(this.#phase==='ARMED'&&!this.#busy&&this.#index<20&&this.#readiness!==null,'SERIAL_STEP_WITH_ACTUAL_CAPTURE_READINESS');this.#busy=true;
  try{
   sameCaller(this.#caller,this.#binding);const p=UiWindowObserverBridge.prototype.poll.call(this.#bridge),i=this.#index+1;
   need(p.ready_for_UI_action===true&&p.verified_steps===this.#index&&p.required_steps===20,'GENUINE_BRIDGE_STEP_READY');
   const r=UiWindowObserverBridge.prototype.response.call(this.#bridge,2*i-1);
   need(r.command==='STEP_BEGIN'&&r.step===i&&equal(r.receipt.before.binding,this.#binding),'ACTUAL_NATIVE_STEP_BEGIN');
   const owner=UiBrowserNormalClose.prototype.status.call(this.#companion),role=ROLES[i-1]==='public'?'customer':ROLES[i-1];
   need(owner.owned_roles.includes(role)&&owner.private_references_released===false,'COMPANION_ROLE_OPEN');
   need(tab&&typeof tab.id==='string'&&tab.id.length>0&&typeof tab.url==='function','ROOT_ACTUAL_TAB');
   need(new URL(await tab.url()).origin===this.#binding.origin,'ACTUAL_TAB_ORIGIN');
   const boundaries={};
   for(const role of ['customer','merchant','platform']){
    const consumed=this.#consumed[role];
    await UiNormalNetworkCapture.prototype.poll.call(this.#capture[role]);
    const window=UiNormalNetworkCapture.prototype.readWindow.call(this.#capture[role],consumed.boundary);
    this.#retain({kind:'INTERPHASE_CAPTURE_BOUNDARY',index:i,role,window},this.#windows);clean(window);
    need(window.after_sequence===consumed.covered_through_sequence
     &&integer(window.covered_through_sequence,consumed.covered_through_sequence),'CONTINUOUS_CONSUMED_CAPTURE_CURSOR');
    need(window.events.length===0&&window.requests.length===0,'UNASSIGNED_INTERPHASE_EVENT');
    boundaries[role]=consumed.boundary;
   }
   this.#active={index:i,tab_id_sha256:sha(Buffer.from(tab.id)),boundaries,started_at_ms:Date.now(),actions:[]};
   this.#phase='ACTION_WINDOW';return{index:i,name:STEPS[i-1],role:ROLES[i-1],authentication:'NOT_PROVEN'};
  }catch{this.#phase='INCOMPLETE';this.#fault='CAPTURE_INTERPHASE_BOUNDARY_INCOMPLETE';fail(this.#fault);}
  finally{this.#busy=false;}
 }
 async performRootAction({kind,target_public},actualRootAction){
  need(this.#phase==='ACTION_WINDOW'&&!this.#busy&&typeof actualRootAction==='function','SERIAL_ROOT_ACTION');
  need(['navigate','click','fill','select-files','scroll','viewport','DOM-observe'].includes(kind)&&typeof target_public==='string'
   &&target_public.length>0&&target_public.length<=256&&!/[\0\r\n]/.test(target_public),'PUBLIC_CONTROL_LABEL_ONLY');
  const p=UiWindowObserverBridge.prototype.poll.call(this.#bridge);
  need(p.ready_for_UI_action===true&&p.verified_steps===this.#index,'BRIDGE_READY_BEFORE_ACTION');this.#busy=true;
  try{
   await actualRootAction();this.#active.actions.push({kind,target_public,outcome:'API_RETURNED'});
   return{kind,target_public,outcome:'API_RETURNED',authentication:'NOT_PROVEN',DOM:'NOT_PROVEN'};
  }catch{
   this.#active.actions.push({kind,target_public,outcome:'API_THROWN'});this.#fault='ROOT_ACTION_INCOMPLETE';this.#phase='INCOMPLETE';fail('ROOT_ACTION_INCOMPLETE');
  }finally{this.#busy=false;}
 }
 async prepareStep({DOM=null,preview=false}={}){
  need(typeof preview==='boolean'&&this.#phase==='ACTION_WINDOW'&&!this.#busy&&this.#active.actions.length>0,'ACTUAL_ACTION_WINDOW_REQUIRED');
  need(!preview||this.#active.previewed!==true,'ONE_PASSIVE_PREVIEW_PER_STEP');this.#busy=true;
  try{
   sameCaller(this.#caller,this.#binding);const observed={},cutoffs={};
   for(const role of ['customer','merchant','platform']){
    const checkpoint=await UiNormalNetworkCapture.prototype.checkpoint.call(this.#capture[role]);
    const cutoff_at_ms=Date.now();
    const actual=UiNormalNetworkCapture.prototype.readWindow.call(this.#capture[role],this.#active.boundaries[role]);
    observed[role]=actual;
    this.#retain({kind:'STEP_CAPTURE_CUTOFF',index:this.#active.index,role,cutoff_at_ms,window:actual},this.#windows);
    clean(actual);need(actual.origin===this.#binding.origin,'ACTUAL_CAPTURE_ORIGIN');
    need(actual.after_sequence===this.#consumed[role].covered_through_sequence
     &&actual.covered_through_sequence===checkpoint.status.covered_through_sequence
     &&checkpoint.boundary.after_sequence===actual.covered_through_sequence,'ACTUAL_INDIVIDUAL_CAPTURE_CUTOFF');
    for(const record of actual.http_requests)time(record,this.#active.started_at_ms,cutoff_at_ms);
    cutoffs[role]={boundary:checkpoint.boundary,covered_through_sequence:actual.covered_through_sequence,cutoff_at_ms};
   }
   this.#retain(observed,this.#windows);
   const window={http_requests:Object.values(observed).flatMap(w=>w.http_requests),
    local_non_HTTP:Object.values(observed).flatMap(w=>w.local_non_HTTP)};
   const i=this.#active.index,role=ROLES[i-1],privateValue={login_token:null,ticket_pairs:[],content_responses:[]};
   const tokens={...this.#tokens},requests=[],recordJoins=[],statics=[],cached=[],locals=clone(window.local_non_HTTP);
   const ordered=[...window.http_requests].sort((a,b)=>{
    const delta=a.timing.derived_request_wall_time_ms-b.timing.derived_request_wall_time_ms;
    if(delta!==0)return delta;
    need(equal(a.source,b.source),'AMBIGUOUS_CROSS_TAB_REQUEST_CHRONOLOGY');
    return a.request_will_be_sent[0].sequence-b.request_will_be_sent[0].sequence;
   });
   let image=this.#image;
   if(i===14){
    const initial=this.#accepted.find(step=>step.index===4),quote=initial?.wire.response_public;
    need(quote&&integer(quote.root?.id,1)&&Array.isArray(quote.root_items)&&quote.root_items.length===2,'EARLIER_NATIVE_ACK_ROOT_QUOTE');
    const q2=quote.root_items.filter(row=>row.item?.quantity===2),q1=quote.root_items.filter(row=>row.item?.quantity===1);
    need(q2.length===1&&q1.length===1&&integer(q2[0].item.id,1)&&integer(q1[0].item.id,1)&&q2[0].item.id!==q1[0].item.id
     &&initial.wire.path==='/api/order/refund/cart_info/'+quote.root.id+'?contract=2&fulfillment_page=1&fulfillment_limit=20','EARLIER_NATIVE_ACK_ORIGINAL_Q2_ITEM');
    const expectedPath='/api/order/reviews/'+quote.root.id+'/'+q2[0].item.id+'/files',uid=this.#tokens.customer?.subject_id;
    need(integer(uid,1),'EARLIER_NATIVE_ACK_CUSTOMER_UID');
    const found=ordered.filter(r=>{const q=info(r,this.#binding.origin);return q.method==='POST'&&q.path===expectedPath;});
    need(found.length===1,'UNIQUE_PUBLIC_UPLOAD');fresh(found[0]);const data=envelope(found[0]);keys(data,['name','url','attachment_ref']);
    need(typeof data.attachment_ref==='string'&&typeof data.url==='string','REAL_UPLOAD_RESPONSE_FIELDS');
    const actualURL=new URL(data.url),reference=/^\/uploads\/customer\/([1-9][0-9]*)\/[a-f0-9]{32}\/[a-f0-9]{32}\.png$/.exec(data.attachment_ref);
    need(reference&&reference[0]===data.attachment_ref&&reference[1]===String(uid)
     &&actualURL.origin===this.#binding.origin&&actualURL.username===''&&actualURL.password===''&&actualURL.search===''&&actualURL.hash===''
     &&actualURL.pathname===data.attachment_ref&&data.url===this.#binding.origin+data.attachment_ref
     &&data.name===data.attachment_ref.split('/').at(-1),'ACTUAL_PUBLIC_IMAGE_URL');
    image={reference:data.attachment_ref,url:data.url,image:clone(MATERIALS.png)};
   }
   if(i<=3)observedLogin(ordered,i,this.#binding.origin,privateValue,tokens);
   const finished=Date.now();
   for(const record of ordered){
    const q=info(record,this.#binding.origin),timing=time(record,this.#active.started_at_ms,finished);
    if(!/^\/(?:api|merchantapi|adminapi)(?:\/|$)/.test(q.pathname)&&!publicImage(q.path,image)){
     const material=materialFor(q.pathname,this.#materials);need(q.method==='GET'&&material!==null,'UNKNOWN_HTTP_NOT_STATIC');
     const raw=body(record);
     try{
      need(record.response_received[0].params.response.status===200&&equal(pin(raw),{bytes:material.bytes,sha256:material.sha256}),'FULL_STATIC_RESPONSE_MATCH');
      statics.push({request_id:record.request_id,source:clone(record.source),url:record.request_will_be_sent[0].params.request.url,
       observed:pin(raw),material,cache_events:clone(record.request_served_from_cache),
       response:clone(record.response_received[0].params.response),response_extra_info:clone(record.response_extra_info),...timing});
     }finally{raw.fill(0);}
     continue;
    }
    // Only explicit cache evidence and an earlier genuine native-ACK anonymous image witness permit local reuse.
    if(publicImage(q.path,image)&&cacheKind(record)==='explicit_local_cache'){
     need(q.method==='GET','CACHE_IMAGE_GET_ONLY');
     const observedHeaders=headers(record.request_will_be_sent[0].params.request.headers);
     need(observedBearer(observedHeaders)===null&&record.request_extra_info.every(x=>observedBearer(headers(x.params.headers))===null),
      'CACHED_PUBLIC_IMAGE_ANONYMOUS_RESOURCE');
     const witness=this.#witnesses.find(w=>w.origin===this.#binding.origin&&w.reference===q.path&&equal(w.binding,this.#binding)
      &&equal(w.image,MATERIALS.png));need(witness,'PRIOR_PHP_ACK_IMAGE_REQUIRED_FOR_CACHE');
     const raw=body(record);
     try{
      need(equal(pin(raw),MATERIALS.png)&&record.response_received[0].params.response.status===200,'CACHE_FULL_IMAGE_MATCH');
      cached.push({request_id:record.request_id,source:clone(record.source),url:record.request_will_be_sent[0].params.request.url,
       observed:pin(raw),witness:{step:witness.step,name:witness.name,sequence:witness.sequence,wire_sha256:witness.wire_sha256,
        PHP_response_sha256:witness.PHP_response_sha256},cache_events:clone(record.request_served_from_cache),
       response:clone(record.response_received[0].params.response),response_extra_info:clone(record.response_extra_info),
       interpretation:'LOCAL_CACHE_NOT_A_SERVER_GET',...timing});
     }finally{raw.fill(0);}
     continue;
    }
    need(finiteAPI(q.method,q.path)||publicImage(q.path,image),'FINAL_FINITE_PROJECTION_PENDING_OR_UNKNOWN_API');
    fresh(record);const authentication=auth(record,tokens),request=projectedRequest(record,q,i);
    let response,app_status=200;
    if(/\/content$/.test(q.path)||publicImage(q.path,image)){
     const raw=body(record);
     try{
      const h=resHeaders(record);need(h['content-type']==='image/png'&&equal(pin(raw),MATERIALS.png),'ACTUAL_IMAGE_FULL_BYTES');
      response={...pin(raw),mime_type:h['content-type']};app_status=null;
      privateValue.content_responses.push({sequence:requests.length,headers:h,body_base64:raw.toString('base64')});
     }finally{raw.fill(0);}
     if(publicImage(q.path,image))need(authentication.present===false,'PUBLIC_IMAGE_ALWAYS_ANONYMOUS');
    }else if(q.method==='GET'&&q.pathname==='/api/get_script'){
     const raw=body(record);try{need(raw.length===0,'ACTUAL_RAW_EMPTY_STATISTIC_SCRIPT');response='';app_status=null;}finally{raw.fill(0);}
    }else response=projectedResponse(record,q,i,privateValue,tokens);
    recordJoins.push(record);
    requests.push({sequence:requests.length,method:q.method,path:q.path,...timing,http_status:200,app_status,
     request_public:request,response_public:response,request_public_sha256:digest(request),response_public_sha256:digest(response),authentication});
   }

   need(privateValue.content_responses.length<=16,'FINITE_CONTENT_RESPONSES');
   const used=new Set();
   for(const issue of requests.filter(r=>/\/read-ticket$/.test(r.path))){
    need(typeof TICKET_HEADER==='string','EXACT_TICKET_HEADER_SOURCE');
    const observed=envelope(recordJoins[issue.sequence]).read_ticket;
    const contentPath=issue.path.slice(0,-'read-ticket'.length)+'content';
    const matches=requests.filter(r=>r.method==='GET'&&r.path===contentPath&&r.started_at_ms>=issue.finished_at_ms
     &&!used.has(r.sequence)&&reqHeaders(recordJoins[r.sequence])[TICKET_HEADER]===observed);
    need(matches.length===1&&!used.has(issue.sequence),'ACTUAL_UNIQUE_TICKET_PAIR');
    const read=matches[0],actualHeader=reqHeaders(recordJoins[read.sequence])[TICKET_HEADER];
    need(actualHeader===observed,'OBSERVED_RESPONSE_TICKET_EQUALS_REAL_REQUEST_HEADER');
    used.add(issue.sequence);used.add(read.sequence);
    privateValue.ticket_pairs.push({issued_sequence:issue.sequence,read_sequence:read.sequence,
     read_ticket:observed,request_ticket:actualHeader});
   }
   need(privateValue.ticket_pairs.length<=16,'FINITE_PRIVATE_TICKETS');
   for(const r of requests)if(/\/(?:read-ticket|content)$/.test(r.path))need(used.has(r.sequence),'EVERY_PRIVATE_REQUEST_ACCOUNTED');
   const matches=requests.filter(r=>isPrimary(i,r,image));let wire,historical=null;
   if(i===20&&matches.length===0){
    need(image!==null,'PRIOR_UPLOAD_REQUIRED');
    historical=this.#witnesses.find(w=>w.origin===this.#binding.origin&&w.reference===image.reference
     &&equal(w.binding,this.#binding)&&equal(w.image,MATERIALS.png));
    need(historical,'ACTUAL_PRIOR_ACK_ANONYMOUS_IMAGE_WITNESS');wire=clone(historical.wire);
   }else{
    need(matches.length===1,'UNIQUE_ACTUAL_PRIMARY');
    const r=matches[0];wire={actor_role:role,method:r.method,path:r.path,body_public:clone(r.request_public),
     body_public_sha256:r.request_public_sha256,response_status:r.http_status,response_public:clone(r.response_public),
     response_public_sha256:r.response_public_sha256};
   }
   let identity;
   if(role==='public')identity={application:'none',subject_id:null,merchant_id:null,verified_by:'actual-anonymous-normal-response'};
   else{
    const actual=tokens[role];need(actual&&integer(actual.subject_id,1),'OBSERVED_PHASE_OR_ACK_SUBJECT');let merchant=this.#merchant;
    if(role==='merchant'&&merchant===null){
     const me=requests.filter(r=>r.method==='GET'&&r.path==='/merchantapi/me');
     need(me.length>0&&me.every(r=>integer(r.response_public.merchant_id,1)
      &&r.response_public.employee_id===actual.subject_id&&r.response_public.merchant_id===me[0].response_public.merchant_id),
      'REAL_SAME_STEP_MERCHANT_ME');merchant=me[0].response_public.merchant_id;
    }
    // Required protocol predicate proposed to the independent native verifier; not producer-side proof.
    identity={application:{customer:'api',merchant:'merchantapi',platform:'adminapi'}[role],subject_id:actual.subject_id,
     merchant_id:role==='merchant'?merchant:null,verified_by:'native-session-and-normal-UI'};
   }
   const event={schema:1,scope:'MS4_UI_NORMAL_BROWSER_STEP',binding:clone(this.#binding),index:i,name:STEPS[i-1],role,
    tab_id_sha256:this.#active.tab_id_sha256,started_at_ms:this.#active.started_at_ms,finished_at_ms:finished,
    actions:clone(this.#active.actions),requests,identity,DOM:clone(DOM),diagnostic:null};
   if(i===20&&!preview)need(object(DOM)&&DOM.scope==='MS4_UI_NORMAL_PUBLIC_IMAGE_DOM'&&DOM.image_url===image.url,
    'EXTERNAL_REAL_BOUND_IMAGE_DOM_REQUIRED');
   const candidate={wire_public:{wire,browser_event:event},private:privateValue};
   need(Buffer.byteLength(JSON.stringify({schema:1,binding:this.#binding,sequence:2*i,command:'STEP_VERIFY',step:i,...candidate}))+1<=1048576,
    'FULL_PRIVATE_FRAME_1MIB');
   need(statics.length+cached.length+requests.length===ordered.length,'ALL_HTTP_RECORDS_EXACTLY_PARTITIONED');
   this.#retain({index:i,binding:clone(this.#binding),source:clone(MATERIALS),static_source_boundary:statics,
    cached_public_images:cached,local_non_HTTP:locals,server_quiet:'NOT_CLAIMED',full_acceptance:false},this.#partitions);
   if(preview){
    this.#active.previewed=true;
    return{index:i,name:STEPS[i-1],preview_only:true,requests:clone(requests),identity:clone(identity),
     binding:clone(this.#binding),tab_id_sha256:this.#active.tab_id_sha256,
     started_at_ms:this.#active.started_at_ms,observed_through_ms:finished,
     actual_authentication:'INDEPENDENT_PHP_REQUIRED',root_action_window:'STILL_OPEN',full_acceptance:'NOT_PROVEN'};
   }
   this.#pending={candidate,tokens,image,historical,cutoffs};this.#phase='CANDIDATE_RAM_ONLY';
   return{index:i,name:STEPS[i-1],candidate_ready:true,static_observations:statics.length,cached_public_images:cached.length,
    local_non_HTTP:locals.length,authentication:'INDEPENDENT_PHP_REQUIRED',full_acceptance:'NOT_PROVEN'};
  }catch(error){
   this.#fault=error instanceof Error&&/^MS4_UI_PRODUCER_[A-Z0-9_]+$/.test(error.message)
    ?error.message.slice('MS4_UI_PRODUCER_'.length):'PROJECTION_INCOMPLETE';
   this.#phase='INCOMPLETE';throw new Error('MS4_UI_PRODUCER_'+this.#fault);
  }finally{this.#busy=false;}
 }
 submitStep(){
  need(this.#phase==='CANDIDATE_RAM_ONLY'&&!this.#busy&&this.#pending,'COMPLETE_RAM_CANDIDATE');
  this.#phase='AWAIT_PHP_ACK';
  try{return UiWindowObserverBridge.prototype.stepVerify.call(this.#bridge,this.#pending.candidate);}
  catch{this.#phase='INCOMPLETE';this.#fault='BRIDGE_SUBMISSION_INCOMPLETE';fail('BRIDGE_SUBMISSION_INCOMPLETE');}
 }
 acceptStep(){
  need(this.#phase==='AWAIT_PHP_ACK'&&!this.#busy&&this.#pending,'REAL_ACK_REQUIRED');
  const i=this.#active.index,p=UiWindowObserverBridge.prototype.poll.call(this.#bridge);
  need(p.phase==='STEP_VERIFIED'&&p.verified_steps===i,'REAL_VALIDATED_BRIDGE_ACK');
  const row=UiWindowObserverBridge.prototype.response.call(this.#bridge,2*i);
  need(row.command==='STEP_VERIFY'&&row.step===i&&hex(row.response_sha256)&&equal(row.receipt.after.binding,this.#binding)
   &&equal(row.receipt.verified_after.binding,this.#binding)&&row.receipt.expected_delta.name===STEPS[i-1],'ACK_SAME_RUN_STEP');
  const facts=row.receipt.expected_delta.independent,e=this.#pending.candidate.wire_public.browser_event;
  if(i<=3){
   need(facts.login?.subject_id===e.identity.subject_id&&facts.login.native_session_verified===true
    &&facts.login.signed_claims_verified===true&&facts.login.browser_token_join===true,'REAL_NATIVE_LOGIN_ACK');
   this.#tokens[ROLES[i-1]]=this.#pending.tokens[ROLES[i-1]];
   if(ROLES[i-1]==='merchant')this.#merchant=e.identity.merchant_id;
  }
  if(i===14)this.#image=clone(this.#pending.image);
  if(this.#pending.image)for(const r of e.requests){
   if(!publicImage(r.path,this.#pending.image))continue;
   const content=this.#pending.candidate.private.content_responses.find(c=>c.sequence===r.sequence);
   need(content&&r.method==='GET'&&r.http_status===200&&equal(r.authentication,{present:false,kind:'none',subject_id:null}),'ACK_REAL_ANONYMOUS_IMAGE');
   const raw=Buffer.from(content.body_base64,'base64');
   try{
    need(equal(pin(raw),MATERIALS.png),'ACK_FULL_IMAGE_BYTES');
    this.#witnesses.push({binding:clone(this.#binding),origin:this.#binding.origin,step:i,name:STEPS[i-1],sequence:r.sequence,
     reference:this.#pending.image.reference,image:clone(MATERIALS.png),wire:imageWire(r),wire_sha256:digest(imageWire(r)),
     PHP_response_sha256:row.response_sha256});
   }finally{raw.fill(0);}
  }
  const begin=UiWindowObserverBridge.prototype.response.call(this.#bridge,2*i-1);
  this.#retain({index:i,wire:clone(this.#pending.candidate.wire_public.wire),browser_event:clone(e),begin,verify:row},this.#accepted);
  this.#consumed=this.#pending.cutoffs;
  this.#index=i;this.#active=null;this.#pending=null;this.#phase=i===20?'TWENTY_ACKS_ONLY':'ARMED';return this.status();
 }
 // Return the decoded actual ACK only after matching its original full canonical frame slice hash and length.
 validatedFrame(sequence){
  need(integer(sequence)&&sequence<=41,'ACTUAL_FRAME_SEQUENCE');
  const r=UiWindowObserverBridge.prototype.response.call(this.#bridge,sequence);
  const frame={schema:1,scope:'MS4_UI_OBSERVER_SAFE_FRAME',binding:clone(this.#binding),
   sequence:r.sequence,command:r.command,step:r.step,receipt:clone(r.receipt)};
  const raw=Buffer.from(canonical(frame)+'\n');
  try{
   need(r.sequence===sequence&&hex(r.response_sha256)&&sha(raw)===r.response_sha256
    &&integer(r.stdout_start)&&r.stdout_end-r.stdout_start===raw.length,'EXACT_ORIGINAL_FRAME_SLICE_HASH_LENGTH');
   return frame;
  }finally{raw.fill(0);}
 }
 // This is a proposed payload plan from actual parsed receipts, not proof that Root wrote/read these leaves.
 materializePublicPlan(){
  need(ADOPTION==='FINAL_REVIEWED_R4_SOURCE_SELECTED'&&FINAL_PROJECTION==='FINAL_REVIEWED_R3_FINITE_PROJECTION',
   'FINAL_SOURCE_SELECTION_REQUIRED');
  need(this.#phase==='TWENTY_ACKS_ONLY'&&this.#accepted.length===20&&!this.#busy&&!this.#publicPlanIssued,'TWENTY_ACTUAL_ACKS_ONCE');
  const currentSource=sameBridgeSource(this.#bridge,this.#binding);
  const p=UiWindowObserverBridge.prototype.poll.call(this.#bridge);
  need(p.native_complete===true&&p.closed===true&&p.close_code===0&&p.close_signal===null
   &&integer(p.actual_pid,1)&&p.verified_steps===20&&p.published?.native_complete===true,'SAME_BRIDGE_NATURAL_ZERO_AND_PUBLIC_RAW');
  const published=p.published,native={};
  for(const[k,n]of Object.entries({created:'ui-window-observer-created.json',terminal:'ui-window-observer-terminal.json',
   stdout:'ui-window-observer.stdout',stderr:'ui-window-observer.stderr'})){
   const d=published[k];keys(d,['path','bytes','sha256']);
   need(d.path==='/run/audit/evidence/'+n&&integer(d.bytes)&&hex(d.sha256),'ACTUAL_BRIDGE_PUBLIC_DESCRIPTOR');
   if(k==='stderr')need(d.bytes===0&&d.sha256===sha(Buffer.alloc(0)),'REAL_EMPTY_NATIVE_STDERR');
   native[k]=clone(d);
  }
  const first=this.validatedFrame(0),last=this.validatedFrame(41);
  for(let sequence=0;sequence<=41;sequence++)this.validatedFrame(sequence);
  need(first.command==='BEGIN'&&last.command==='FINISH'&&last.step===21
   &&equal(first.receipt.baseline.binding,this.#binding)&&equal(last.receipt.final.binding,this.#binding),'REAL_COMPLETE_FRAME_CHAIN');
  this.#publicPlanIssued=true;
  const files=[];let total=0;
  const emit=(name,payload)=>{
   need(/^[a-z0-9.-]+$/.test(name)&&!files.some(f=>f.name===name),'FIXED_UNIQUE_PUBLIC_LEAF');
   const cleanPayload=clone(payload);safe(cleanPayload);
   const raw=Buffer.from(canonical(cleanPayload)+'\n');total+=raw.length;need(total<=268435456,'PUBLIC_PLAN_RAM_LIMIT');
   const descriptor={path:'/run/audit/evidence/'+name,...pin(raw)};
   files.push({name,raw,descriptor});return clone(descriptor);
  };
  const baseline=emit('ui-window-baseline.json',first.receipt.baseline);
  const final=emit('ui-window-final.json',last.receipt.final);
  const observerSource=emit('ui-window-observer-source.json',{schema:1,scope:'MS4_UI_WINDOW_OBSERVER_SOURCE',
   binding:clone(this.#binding),files:clone(currentSource.files)});
  const preservation={};
  for(const[k,n]of Object.entries({source:'ui-window-source-preserved.json',predecessors:'ui-window-predecessors-preserved.json',
   private_inputs:'ui-window-private-inputs-preserved.json',public_materials:'ui-window-public-materials-preserved.json'}))
   preservation[k]=emit(n,last.receipt.preservation[k]);
  const selected=[];let previous=baseline.sha256;
  for(const a of this.#accepted){
   const n=String(a.index).padStart(2,'0'),r=a.verify.receipt;
   const before=emit('ui-window-'+n+'-before.json',a.begin.receipt.before);
   const after=emit('ui-window-'+n+'-after.json',r.after);
   const verified=emit('ui-window-'+n+'-verified.json',r.verified_after);
   const expected=emit('ui-window-'+n+'-expected.json',r.expected_delta);
   const browser=emit('ui-browser-step-'+n+'.json',a.browser_event);
   const step=emit('ui-business-step-'+n+'.json',{schema:1,scope:'MS4_UI_NORMAL_BUSINESS_STEP',binding:clone(this.#binding),
    index:a.index,name:STEPS[a.index-1],previous_step_sha256:previous,wire:clone(a.wire),before,after,verified_after:verified,
    expected_delta:expected,oracle:{source:clone(r.oracle.source),case_name:r.oracle.case_name,expected,actual:after,readback:verified},
    observer_event:{observer_pid:p.actual_pid,request_index:a.verify.sequence,request_sha256:a.verify.request_sha256,
     response_index:a.verify.sequence,response_sha256:a.verify.response_sha256,stdout_start:a.verify.stdout_start,
     stdout_end:a.verify.stdout_end,terminal_required:'SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME'},browser_event:browser,diagnostic:null});
   selected.push(step);previous=step.sha256;
  }
  const window={schema:1,scope:'MS4_UI_NORMAL_BUSINESS_WINDOW_ONLY',status:'VERIFIED_MS4_UI_NORMAL_BUSINESS_WINDOW',
   binding:clone(this.#binding),baseline,steps:selected,final,observer_source:observerSource,observer_native:native,
   preservation,diagnostic:null,full_acceptance:'INCOMPLETE'};
  const windowDescriptor=emit('ui-business-window.json',window);
  need(files.length===128,'EXACT_PUBLIC_PLAN_COUNT');
  return{scope:'MS4_UI_PUBLIC_PAYLOAD_PLAN_NOT_FILESYSTEM_PROOF',files,window:windowDescriptor,
   already_published_native:native,resolver_whitelist:[...files.map(f=>f.name),
    'ui-window-observer-created.json','ui-window-observer-terminal.json','ui-window-observer.stdout','ui-window-observer.stderr'],
   required_Root_action:'CREATE_ONLY_CLOSE_FULL_READBACK_EACH_PAYLOAD_AND_REVERIFY_ORIGINAL_NATIVE_RAW',
   full_acceptance:'INCOMPLETE',DOM_browser_close_and_private_transport:'SEPARATE_PENDING_ACCEPTANCE'};
 }
 // Explicit PRIVATE RAM return, never console/public-file/report input. Raw full headers/bodies may contain secrets.
 readPrivateObservations(){return{windows:clone(this.#windows),partitions:clone(this.#partitions),stage:this.#phase,
  server_quiet:'NOT_CLAIMED',native_and_DOM_full_acceptance:'NOT_PROVEN'};}
 forget(){
  need(!this.#busy,'SERIAL_FORGET');
  this.#tokens={};this.#merchant=null;this.#pending=null;this.#active=null;this.#image=null;this.#windows=[];this.#partitions=[];this.#witnesses=[];this.#accepted=[];this.#readiness=null;
  this.#capture=null;this.#caller=null;this.#companion=null;this.#bridge=null;this.#consumed=null;
  this.#phase='PRODUCER_REFERENCES_RELEASED';
  return{producer_references_released:true,caller_capture_bridge_owned_RAM:'ROOT_STILL_OWNS',secure_string_erasure:'NOT_CLAIMED',
   full_acceptance:'NOT_PROVEN'};
 }
}


// Task2 additive ordinary-read branch; original UI20 producer body above is unchanged.
import { task2OwnedCaptureSource } from './ms4-ui-network-capture.mjs';
import { UiTask2ObserverBridge,TASK2_READ_MODES,TASK2_READ_STEPS,TASK2_READ_ROLES,TASK2_READ_VIEWS,TASK2_ACTION_TARGETS,
 task2Source,task2View,task2Primary,task2SetDigests } from './ms4-ui-window-native-bridge.mjs';
function task2Query(q,origin){const u=new URL(q.path,origin),query={};for(const[k,v]of u.searchParams){need(!Object.hasOwn(query,k)&&!/[\0\r\n]/.test(k+v),'TASK2_REAL_UNAMBIGUOUS_QUERY');query[k]=v;}return query;}
function task2Language(record){const h=reqHeaders(record),out={'cb-lang':h['cb-lang']??null,'accept-language':h['accept-language']??null};
 for(const v of Object.values(out))need(v===null||(typeof v==='string'&&Buffer.byteLength(v)<=8192&&!/[\0\r\n]/.test(v)),'TASK2_SAFE_ACTUAL_LANGUAGE_HEADERS');return out;}
const task2Logout={customer:['GET','/api/logout'],merchant:['POST','/merchantapi/logout'],platform:['GET','/adminapi/setting/admin/logout']};
function task2API(method,route){return (method==='GET'&&(startupPaths.has(route)||['/api/user','/merchantapi/me','/adminapi/menus','/api/logout','/adminapi/setting/admin/logout','/api/order/list','/api/order/data','/merchantapi/orders','/adminapi/order/list','/adminapi/order/chart'].includes(route)
 ||/^\/api\/order\/detail\/cp[1-9][0-9]*$/.test(route)||/^\/merchantapi\/orders\/[1-9][0-9]*\/packages$/.test(route)||/^\/adminapi\/order\/info\/[1-9][0-9]*$/.test(route)))
 ||(method==='POST'&&['/api/login','/merchantapi/login','/adminapi/login','/merchantapi/logout'].includes(route));}
function task2Request(record,q,index){if(index<=3&&q.method==='POST'&&q.pathname===['/api/login','/merchantapi/login','/adminapi/login'][index-1]){requestJSON(record);return{};}
 if(q.method==='GET'){const request=record.request_will_be_sent[0].params.request;need(request.hasPostData!==true&&(request.postData===undefined||request.postData===''),'TASK2_GET_HAS_NO_BODY');return{};}
 need(q.method==='POST'&&q.pathname==='/merchantapi/logout','TASK2_ONLY_NORMAL_LOGOUT_POST');const raw=requestBytes(record);
 try{if(raw.length){const value=parse(raw);keys(value,[]);}return{};}finally{raw.fill(0);}}
function task2SourceBinding(bridge,b,source,mode){const actual=UiTask2ObserverBridge.prototype.sourceBinding.call(bridge);
 keys(actual,['schema','scope','binding','files','observer_source','scenario']);need(actual.schema===1&&actual.scope==='MS5_TASK2_FROZEN_PHP_SOURCE_BINDING'
  &&equal(actual.binding,b)&&equal(actual.observer_source,source)&&actual.scenario===TASK2_READ_MODES[mode].scenario&&Array.isArray(actual.files)&&actual.files.length===6,'TASK2_GENUINE_SOURCE_BOUND_BRIDGE');
 for(const r of actual.files){keys(r,['path','bytes','sha256']);need(typeof r.path==='string'&&integer(r.bytes,1)&&hex(r.sha256),'TASK2_CURRENT_SOURCE_MEMBER');}return actual;}

const task2OriginalHandoffs=new WeakMap();
export class UiTask2BusinessProducer{
 #binding;#observer;#mode;#modeName;#bridge;#capture;#caller;#companion;#materials;#tabs;#predecessor;#phase='NEW';#busy=false;#index=0;
 #active=null;#pending=null;#tokens={};#merchant=null;#windows=[];#partitions=[];#accepted=[];#readiness=null;#consumed=null;#ram=0;#fault=null;#publicPlanIssued=false;#transferred=false;
 constructor(config){keys(config,['binding','observer_source','argv_mode','bridge','captures','caller','companion','material_bytes','owned_tabs','predecessor']);
  const{binding:b,observer_source:source,argv_mode:mode,bridge,captures,caller,companion,material_bytes,owned_tabs,predecessor}=config;
  binding(b);task2Source(source,b);need(Object.hasOwn(TASK2_READ_MODES,mode),'TASK2_EXACT_ARGV_MODE');keys(captures,['customer','merchant','platform']);keys(owned_tabs,['customer','merchant','platform']);
  need(Object.getPrototypeOf(bridge)===UiTask2ObserverBridge.prototype&&Object.values(captures).every(c=>Object.getPrototypeOf(c)===UiNormalNetworkCapture.prototype)
   &&new Set(Object.values(captures)).size===3&&Object.getPrototypeOf(caller)===UiPrivateCaller.prototype&&Object.getPrototypeOf(companion)===UiBrowserNormalClose.prototype,'TASK2_ACTUAL_IMPORTED_CLASSES');
  const tabs={};for(const role of ['customer','merchant','platform']){const tab=owned_tabs[role],owner=UiBrowserNormalClose.prototype.ownedTabIdentity.call(companion,role);
   need(tab&&typeof tab.id==='string'&&typeof tab.url==='function'&&owner.role===role&&owner.tab_id_sha256===sha(Buffer.from(tab.id)),'TASK2_GENUINE_OWNED_TAB_REFERENCE');tabs[role]=tab;}
  need(new Set(Object.values(tabs).map(t=>t.id)).size===3,'TASK2_DISTINCT_OWNED_TABS');
  this.#binding=clone(b);this.#observer=clone(source);this.#modeName=mode;this.#mode=TASK2_READ_MODES[mode];this.#bridge=bridge;this.#capture={...captures};this.#caller=caller;this.#companion=companion;this.#tabs=tabs;
  this.#materials=materials(material_bytes,b);this.#predecessor=predecessor;
  const n=Object.keys(TASK2_READ_MODES).indexOf(mode);need(n===0?predecessor===null:Object.getPrototypeOf(predecessor)===UiTask2BusinessProducer.prototype,'TASK2_ORDERED_GENUINE_PREDECESSOR');
  if(n>0)need(predecessor.#modeName===Object.keys(TASK2_READ_MODES)[n-1]&&equal(predecessor.#binding,b)&&equal(predecessor.#observer,source)
   &&predecessor.#caller===caller&&predecessor.#companion===companion&&Object.keys(captures).every(r=>predecessor.#capture[r]===captures[r]&&predecessor.#tabs[r]===tabs[r]),'TASK2_SAME_PRIVATE_CALLER_TABS_CAPTURE_CHAIN');
 }
 #retain(v,destination){const bytes=Buffer.byteLength(JSON.stringify(v));need(this.#ram+bytes<=268435456,'TASK2_RAM_LIMIT_NO_TRUNCATION');this.#ram+=bytes;destination.push(v);}
 status(){return{scope:'MS5_TASK2_PRODUCER_SOURCE_ONLY',scenario:this.#mode.scenario,phase:this.#phase,index:this.#index,required_steps:9,verified_steps:this.#index,
  diagnostic:this.#fault,actual_authentication:'INDEPENDENT_PHP_REQUIRED',DOM:'SEPARATE_PENDING',full_acceptance:'NOT_RUN'};}
 arm(){need(this.#phase==='NEW'&&!this.#busy,'TASK2_ARM_ONCE');sameCaller(this.#caller,this.#binding);task2SourceBinding(this.#bridge,this.#binding,this.#observer,this.#modeName);
  const p=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);need(p.phase==='BEGIN_ACK'&&p.required_steps===9&&p.verified_steps===0,'TASK2_ACTUAL_BEGIN_ACK');
  const r=UiTask2ObserverBridge.prototype.response.call(this.#bridge,0);need(r.command==='BEGIN'&&r.step===0&&equal(r.receipt.baseline.binding,this.#binding),'TASK2_BEGIN_BINDING');
  if(this.#predecessor!==null){const previous=this.#predecessor;need(previous.#publicPlanIssued&&!previous.#transferred&&previous.#phase==='NINE_ACKS_ONLY','TASK2_PREVIOUS_NATIVE_PUBLICATION_COMPLETE');
   UiTask2ObserverBridge.prototype.completedLedger.call(previous.#bridge,this.#binding,this.#modeName);this.#consumed=previous.#consumed;this.#readiness=previous.#readiness;previous.#transferred=true;}
  UiPrivateCaller.prototype.activateTask2LoginScene.call(this.#caller,{bridge:this.#bridge,predecessor:this.#predecessor===null?null:this.#predecessor.#bridge,
   owned_tab_sha256:Object.fromEntries(['customer','merchant','platform'].map(role=>[role,UiBrowserNormalClose.prototype.ownedTabIdentity.call(this.#companion,role).tab_id_sha256]))});
  this.#phase='ARMED';return this.status();}
 async captureReadiness(){need(this.#phase==='ARMED'&&!this.#busy&&this.#index===0&&this.#readiness===null&&this.#predecessor===null,'TASK2_FIRST_CAPTURE_READINESS');this.#busy=true;
  try{const actual={},consumed={};for(const role of ['customer','merchant','platform']){const initial=UiNormalNetworkCapture.prototype.initialCheckpoint.call(this.#capture[role]);await UiNormalNetworkCapture.prototype.poll.call(this.#capture[role]);
    const window=UiNormalNetworkCapture.prototype.readWindow.call(this.#capture[role],initial.boundary);this.#retain({kind:'TASK2_INITIAL_CAPTURE',role,window},this.#windows);clean(window);
    need(window.events.length===0&&window.requests.length===0&&window.after_sequence===initial.boundary.after_sequence,'TASK2_NO_UNASSIGNED_INITIAL_EVENT');
    actual[role]={enabled:window.status.enabled,covered_through_sequence:window.covered_through_sequence};consumed[role]={boundary:initial.boundary,covered_through_sequence:window.after_sequence};}
   this.#readiness=actual;this.#consumed=consumed;return{captures:clone(actual),business_and_quiet:'NOT_PROVEN'};
  }finally{this.#busy=false;}}
 async beginStep(){need(this.#phase==='ARMED'&&!this.#busy&&this.#index<9&&this.#readiness!==null,'TASK2_SERIAL_REAL_CAPTURE_WINDOW');this.#busy=true;
  try{sameCaller(this.#caller,this.#binding);const i=this.#index+1,p=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);
   need(p.ready_for_UI_action===true&&p.required_steps===9&&p.verified_steps===this.#index,'TASK2_NATIVE_READY_BEFORE_ACTION');
   const r=UiTask2ObserverBridge.prototype.response.call(this.#bridge,2*i-1);need(r.command==='STEP_BEGIN'&&r.step===i,'TASK2_ACTUAL_NATIVE_STEP_BEGIN');
   const boundaries={};for(const role of ['customer','merchant','platform']){const tab=this.#tabs[role],owner=UiBrowserNormalClose.prototype.ownedTabIdentity.call(this.#companion,role);
    need(owner.tab_id_sha256===sha(Buffer.from(tab.id))&&new URL(await tab.url()).origin===this.#binding.origin,'TASK2_SAME_OWNED_TAB_AND_ORIGIN');
    const consumed=this.#consumed[role];await UiNormalNetworkCapture.prototype.poll.call(this.#capture[role]);const w=UiNormalNetworkCapture.prototype.readWindow.call(this.#capture[role],consumed.boundary);
    clean(w);need(w.after_sequence===consumed.covered_through_sequence&&w.events.length===0&&w.requests.length===0,'TASK2_NO_UNASSIGNED_INTERPHASE_TRAFFIC');
    boundaries[role]=consumed.boundary;this.#retain({kind:'TASK2_INTERPHASE_CAPTURE',index:i,role,window:w},this.#windows);}
   const role=TASK2_READ_ROLES[i-1];this.#active={index:i,tab_id_sha256:sha(Buffer.from(this.#tabs[role].id)),boundaries,started_at_ms:Date.now(),actions:[]};this.#phase='ACTION_WINDOW';
   return{index:i,name:TASK2_READ_STEPS[i-1],role};
  }finally{this.#busy=false;}}
 async performRootAction({role,tab,kind,target_public},actualRootAction){need(this.#phase==='ACTION_WINDOW'&&!this.#busy&&typeof actualRootAction==='function','TASK2_REAL_SERIAL_ACTION');
  const index=this.#active.index;need(['customer','merchant','platform'].includes(role)&&tab===this.#tabs[role]&&(index===9||role===TASK2_READ_ROLES[index-1])
   &&['navigate','click','fill','scroll','viewport','DOM-observe'].includes(kind)&&TASK2_ACTION_TARGETS.includes(target_public),'TASK2_CLOSED_SAFE_ACTION_LABEL');
  const owner=UiBrowserNormalClose.prototype.ownedTabIdentity.call(this.#companion,role);need(owner.tab_id_sha256===sha(Buffer.from(tab.id)),'TASK2_ACTUAL_ACTION_OWNED_TAB');
  const p=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);need(p.ready_for_UI_action===true&&p.verified_steps===this.#index,'TASK2_NATIVE_READY_FOR_ACTION');this.#busy=true;
  try{await actualRootAction();this.#active.actions.push({kind,target_public,tab_id_sha256:owner.tab_id_sha256,outcome:'API_RETURNED'});return{kind,target_public,outcome:'API_RETURNED',DOM:'NOT_PROVEN'};}
  catch{this.#active.actions.push({kind,target_public,tab_id_sha256:owner.tab_id_sha256,outcome:'API_THROWN'});this.#phase='INCOMPLETE';this.#fault='TASK2_ROOT_ACTION_FAILED';fail(this.#fault);}finally{this.#busy=false;}}
 async prepareStep({DOM=null}={}){need(DOM===null,'TASK2_DOM_SEPARATE_TYPED18_NO_ARBITRARY_PUBLIC_VALUE');need(this.#phase==='ACTION_WINDOW'&&!this.#busy&&this.#active.actions.length>0,'TASK2_COMPLETE_ACTION_WINDOW');this.#busy=true;
  try{const i=this.#active.index,role=TASK2_READ_ROLES[i-1],observed={},cutoffs={},ordered=[];
   for(const captureRole of ['customer','merchant','platform']){const capture=this.#capture[captureRole],checkpoint=await UiNormalNetworkCapture.prototype.checkpoint.call(capture),end=Date.now();
    const w=UiNormalNetworkCapture.prototype.readWindow.call(capture,this.#active.boundaries[captureRole]);clean(w);
    need(w.origin===this.#binding.origin&&w.after_sequence===this.#consumed[captureRole].covered_through_sequence&&w.covered_through_sequence===checkpoint.status.covered_through_sequence
     &&checkpoint.boundary.after_sequence===w.covered_through_sequence,'TASK2_CONTINUOUS_REAL_TAB_CUTOFF');
    if(i!==9&&captureRole!==role)need(w.events.length===0&&w.requests.length===0,'TASK2_ONLY_CURRENT_ROLE_ACTIVITY');
    this.#retain({kind:'TASK2_PHASE_CAPTURE',index:i,role:captureRole,cutoff_at_ms:end,window:w},this.#windows);observed[captureRole]=w;
    for(const record of w.http_requests){time(record,this.#active.started_at_ms,end);ordered.push({record,captureRole});}
    cutoffs[captureRole]={boundary:checkpoint.boundary,covered_through_sequence:w.covered_through_sequence,cutoff_at_ms:end};}
   ordered.sort((a,b)=>{const delta=a.record.timing.derived_request_wall_time_ms-b.record.timing.derived_request_wall_time_ms;if(delta!==0)return delta;
    need(a.captureRole===b.captureRole&&equal(a.record.source,b.record.source),'TASK2_AMBIGUOUS_CROSS_TAB_CHRONOLOGY');return a.record.request_will_be_sent[0].sequence-b.record.request_will_be_sent[0].sequence;});
   const requests=[],statics=[],tokens={...this.#tokens},privateValue={login_token:null,ticket_pairs:[],content_responses:[]};
   if(i<=3)observedLogin(ordered.map(x=>x.record),i,this.#binding.origin,privateValue,tokens);
   const finished=Date.now(),loggedOut=new Set();
   for(const{record,captureRole}of ordered){const q=info(record,this.#binding.origin),timing=time(record,this.#active.started_at_ms,finished),tab=this.#tabs[captureRole];
    const mapping=task2OwnedCaptureSource(this.#capture[captureRole],{tab,companion:this.#companion,role:captureRole,origin:this.#binding.origin,source:record.source});
    need(mapping.tab_id_sha256===sha(Buffer.from(tab.id)),'TASK2_GENUINE_RAW_SOURCE_OWNED_TAB_MAPPING');
    if(!/^\/(?:api|merchantapi|adminapi)(?:\/|$)/.test(q.pathname)){
     const material=materialFor(q.pathname,this.#materials);need(q.method==='GET'&&material!==null,'TASK2_UNKNOWN_NOT_STATIC');const raw=body(record);
     try{need(record.response_received[0].params.response.status===200&&equal(pin(raw),{bytes:material.bytes,sha256:material.sha256}),'TASK2_FULL_STATIC_RESPONSE_MATCH');
      statics.push({capture_role:captureRole,tab_id_sha256:sha(Buffer.from(tab.id)),request_id:record.request_id,source:clone(record.source),observed:pin(raw),material,...timing});}finally{raw.fill(0);}continue;
    }
    need(task2API(q.method,q.pathname),'TASK2_FINITE_API_REQUIRED');fresh(record);
    const applicationRole=q.pathname.startsWith('/merchantapi/')?'merchant':q.pathname.startsWith('/adminapi/')?'platform':'customer';need(applicationRole===captureRole,'TASK2_REQUEST_APP_AND_TRUE_TAB_ROLE');
    const authentication=auth(record,tokens);if(loggedOut.has(captureRole))need(authentication.present===false,'TASK2_POST_LOGOUT_REAL_HEADERS_ANONYMOUS');
    const request=task2Request(record,q,i),query=task2Query(q,this.#binding.origin);let response=null,app_status=200,responseBody;
    const view=task2View({method:q.method,path:q.path,query},this.#binding),raw=body(record);
    try{const h=resHeaders(record),mime=record.response_received[0].params.response.mimeType;need(typeof mime==='string'&&mime.length>0,'TASK2_ACTUAL_RESPONSE_MIME');responseBody={...pin(raw),mime_type:mime};
     if(view!==null&&view!=='login'){
      need(/application\/(?:json|[^;]+\+json)/i.test(h['content-type']??''),'TASK2_COMPLETE_JSON_CONTENT');envelope(record);
      privateValue.content_responses.push({sequence:requests.length,headers:h,body_base64:raw.toString('base64')});response=null;
     }else if(i<=3&&task2Primary({method:q.method,path:q.path,query},i,this.#binding))response={subject_id:tokens[role].subject_id};
     else if(q.pathname==='/api/user'){const data=envelope(record);need(object(data)&&integer(data.uid,1),'TASK2_ACTUAL_USER_ID');response={uid:data.uid};}
     else if(q.pathname==='/merchantapi/me')response=merchantMe(envelope(record));
     else if(q.pathname==='/adminapi/menus'){envelope(record);response=null;}
     else if(q.pathname==='/api/get_script'){need(raw.length===0,'TASK2_ACTUAL_EMPTY_SCRIPT');response='';app_status=null;}
     else response=safe(envelope(record));
    }finally{raw.fill(0);}
    const events=record.events;need(Array.isArray(events)&&events.length>0,'TASK2_REAL_REQUEST_EVENT_SEQUENCE');
    const capture_ref={tab_id_sha256:sha(Buffer.from(tab.id)),request_id_sha256:sha(Buffer.from(record.request_id)),first_event_sequence:events[0].sequence,last_event_sequence:events.at(-1).sequence};
    requests.push({sequence:requests.length,method:q.method,path:q.path,query,capture_ref,...timing,http_status:200,app_status,request_public:request,response_public:response,
     response_body:responseBody,request_public_sha256:digest(request),response_public_sha256:digest(response),authentication,language_headers:task2Language(record)});
    if(q.method===task2Logout[captureRole][0]&&q.pathname===task2Logout[captureRole][1]){need(i===9&&!loggedOut.has(captureRole)&&authentication.present&&authentication.subject_id===tokens[captureRole].subject_id,'TASK2_OWN_ONCE_LOGOUT_AT_TAIL');loggedOut.add(captureRole);}
   }
   need(privateValue.content_responses.length<=16,'TASK2_FULL_CONTENT_SET_LIMIT');if(i===9)need(loggedOut.size===3,'TASK2_ALL_THREE_NORMAL_LOGOUTS');
   const primary=requests.filter(r=>task2Primary(r,i,this.#binding)).map(r=>r.sequence);need(primary.length>0&&(i>3||primary.length===1),'TASK2_ALL_REAL_PRIMARY_REQUESTS');
   let identity;const actual=tokens[role];need(actual&&integer(actual.subject_id,1),'TASK2_REAL_LOGIN_SUBJECT');let merchant=this.#merchant;
   if(role==='merchant'&&merchant===null){const reads=requests.filter(r=>r.method==='GET'&&r.path==='/merchantapi/me');need(reads.length>0,'TASK2_NORMAL_MERCHANT_ME');
    merchant=reads[0].response_public.merchant_id;need(reads.every(r=>r.response_public.employee_id===actual.subject_id&&r.response_public.merchant_id===merchant),'TASK2_MERCHANT_IDENTITY_JOIN');}
   identity={application:{customer:'api',merchant:'merchantapi',platform:'adminapi'}[role],subject_id:actual.subject_id,merchant_id:role==='merchant'?merchant:null,verified_by:'native-session-and-normal-UI'};
   const event={schema:1,scope:this.#mode.scope,binding:clone(this.#binding),index:i,name:TASK2_READ_STEPS[i-1],role,tab_id_sha256:this.#active.tab_id_sha256,
    started_at_ms:this.#active.started_at_ms,finished_at_ms:finished,actions:clone(this.#active.actions),requests,identity,DOM:clone(DOM),diagnostic:null};
   const wire={actor_role:role,view:TASK2_READ_VIEWS[i-1],primary_sequences:primary,...task2SetDigests(requests,primary)},candidate={wire_public:{wire,browser_event:event},private:privateValue};
   need(Buffer.byteLength(JSON.stringify({schema:1,binding:this.#binding,sequence:2*i,command:'STEP_VERIFY',step:i,...candidate,observer_source:this.#observer}))+1<=1048576,'TASK2_COMPLETE_PRIVATE_FRAME_LIMIT_NO_TRUNCATION');
   need(statics.length+requests.length===ordered.length,'TASK2_FULL_HTTP_PARTITION');this.#retain({index:i,static_source_boundary:statics,local_non_HTTP:Object.values(observed).flatMap(x=>x.local_non_HTTP),full_acceptance:false},this.#partitions);
   this.#pending={candidate,tokens,merchant,cutoffs};this.#phase='CANDIDATE_RAM_ONLY';return{index:i,name:TASK2_READ_STEPS[i-1],candidate_ready:true,full_acceptance:'NOT_PROVEN'};
  }catch{this.#phase='INCOMPLETE';this.#fault='TASK2_CAPTURE_OR_PROJECTION_INCOMPLETE';fail(this.#fault);}finally{this.#busy=false;}}
 submitStep(){need(this.#phase==='CANDIDATE_RAM_ONLY'&&!this.#busy&&this.#pending,'TASK2_RAM_CANDIDATE');this.#phase='AWAIT_PHP_ACK';return UiTask2ObserverBridge.prototype.stepVerify.call(this.#bridge,this.#pending.candidate);}
 acceptStep(){need(this.#phase==='AWAIT_PHP_ACK'&&!this.#busy&&this.#pending,'TASK2_REAL_ACK_REQUIRED');const i=this.#active.index,p=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);
  need(p.phase==='STEP_VERIFIED'&&p.verified_steps===i,'TASK2_VALIDATED_NATIVE_ACK');const verify=UiTask2ObserverBridge.prototype.response.call(this.#bridge,2*i),begin=UiTask2ObserverBridge.prototype.response.call(this.#bridge,2*i-1);
  need(verify.receipt.expected_delta.name===TASK2_READ_STEPS[i-1],'TASK2_SAME_CASE_ACK');
  this.#retain({index:i,wire:clone(this.#pending.candidate.wire_public.wire),browser_event:clone(this.#pending.candidate.wire_public.browser_event),begin,verify},this.#accepted);
  this.#tokens=i===9?{}:this.#pending.tokens;this.#merchant=this.#pending.merchant;this.#consumed=this.#pending.cutoffs;
  // Release full private response strings after the same native ACK; no file/console return exists.
  this.#pending.candidate.private.content_responses.forEach(r=>{r.headers=null;r.body_base64='';});this.#pending.candidate.private.login_token=null;
  this.#index=i;this.#pending=null;this.#active=null;this.#phase=i===9?'NINE_ACKS_ONLY':'ARMED';return this.status();}
 validatedFrame(sequence){need(integer(sequence)&&sequence<=19,'TASK2_REAL_FRAME_SEQUENCE');const row=UiTask2ObserverBridge.prototype.response.call(this.#bridge,sequence);
  const frame={schema:1,scope:'MS5_TASK2_OBSERVER_SAFE_FRAME',binding:clone(this.#binding),sequence:row.sequence,command:row.command,step:row.step,receipt:clone(row.receipt),observer_source:clone(this.#observer)};
  const raw=Buffer.from(canonical(frame)+'\n');try{need(row.sequence===sequence&&sha(raw)===row.response_sha256&&row.stdout_end-row.stdout_start===raw.length,'TASK2_ORIGINAL_FULL_ACK_SLICE');return frame;}finally{raw.fill(0);}}
 validatedExchange(sequence){this.validatedFrame(sequence);const row=UiTask2ObserverBridge.prototype.response.call(this.#bridge,sequence),native=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);
  need(integer(native.actual_pid,1),'TASK2_ACTUAL_NATIVE_EXCHANGE_PID');return{observer_pid:native.actual_pid,request_index:row.sequence,request_sha256:row.request_sha256,response_index:row.sequence,response_sha256:row.response_sha256,stdout_start:row.stdout_start,stdout_end:row.stdout_end,terminal_required:'SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME'};}
 materializePublicPlan(){need(this.#phase==='NINE_ACKS_ONLY'&&this.#accepted.length===9&&!this.#busy&&!this.#publicPlanIssued,'TASK2_NINE_REAL_ACKS_ONCE');
  const source=task2SourceBinding(this.#bridge,this.#binding,this.#observer,this.#modeName),poll=UiTask2ObserverBridge.prototype.poll.call(this.#bridge);
  need(poll.native_complete&&poll.closed&&poll.close_code===0&&poll.close_signal===null&&poll.published?.native_complete===true&&integer(poll.actual_pid,1),'TASK2_REAL_SPAWN_EOF_NATURAL_ZERO');
  const first=this.validatedFrame(0),last=this.validatedFrame(19);for(let n=0;n<20;n++)this.validatedFrame(n);
  const files=[],prefix=this.#mode.prefix;let total=0;const emit=(name,value)=>{need(/^[a-z0-9.-]+$/.test(name)&&!files.some(x=>x.name===name),'TASK2_PUBLIC_LEAF');const v=clone(value);safe(v);const raw=Buffer.from(canonical(v)+'\n');
   total+=raw.length;need(total<=268435456,'TASK2_PUBLIC_PLAN_RAM_LIMIT');const descriptor={path:'/run/audit/evidence/'+name,...pin(raw)};files.push({name,raw,descriptor});return clone(descriptor);};
  const baseline=emit(prefix+'-baseline.json',first.receipt.baseline),final=emit(prefix+'-final.json',last.receipt.final),observerSource=emit(prefix+'-observer-source.json',source),preservation={};
  for(const kind of ['source','predecessors','private_inputs','public_materials','observer_tools'])preservation[kind]=emit(prefix+'-'+kind.replaceAll('_','-')+'-preserved.json',last.receipt.preservation[kind]);
  const steps=[];let previous=baseline.sha256;
  for(const a of this.#accepted){const n=String(a.index).padStart(2,'0'),r=a.verify.receipt,before=emit(prefix+'-'+n+'-before.json',a.begin.receipt.before),after=emit(prefix+'-'+n+'-after.json',r.after),verified=emit(prefix+'-'+n+'-verified.json',r.verified_after),expected=emit(prefix+'-'+n+'-expected.json',r.expected_delta),browser=emit(prefix+'-browser-step-'+n+'.json',a.browser_event);
   const step=emit(prefix+'-business-step-'+n+'.json',{schema:1,scope:'MS5_TASK2_NORMAL_READ_BUSINESS_STEP',scenario:this.#mode.scenario,binding:clone(this.#binding),observer_source:clone(this.#observer),index:a.index,name:TASK2_READ_STEPS[a.index-1],previous_step_sha256:previous,wire:clone(a.wire),before,after,verified_after:verified,expected_delta:expected,
    oracle:{source:clone(r.oracle.source),case_name:r.oracle.case_name,expected,actual:after,readback:verified,observer_source_sha256:r.oracle.observer_source_sha256},observer_event:{observer_pid:poll.actual_pid,request_index:a.verify.sequence,request_sha256:a.verify.request_sha256,response_index:a.verify.sequence,response_sha256:a.verify.response_sha256,stdout_start:a.verify.stdout_start,stdout_end:a.verify.stdout_end,terminal_required:'SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME'},browser_event:browser,diagnostic:null});steps.push(step);previous=step.sha256;}
  const coverage=clone(this.#accepted.at(-1).verify.receipt.expected_delta.independent.state_coverage),native=clone(poll.published);delete native.native_complete;delete native.diagnostic;delete native.full_acceptance;
  const stateName={test_paid:'paid',unpaid:'unpaid',cancelled:'cancelled'}[this.#mode.state],window=emit('ui-task2-'+stateName+'-read-window.json',{schema:1,scope:'MS5_TASK2_'+stateName.toUpperCase()+'_READ_WINDOW_ONLY',status:'VERIFIED_MS5_TASK2_'+stateName.toUpperCase()+'_READ_NATIVE_WINDOW_ONLY',binding:clone(this.#binding),baseline,steps,final,observer_source:observerSource,observer_native:native,preservation,coverage,diagnostic:null,full_acceptance:'INCOMPLETE'});
  need(files.length===63,'TASK2_EXACT_PUBLIC_PLAN_63');this.#publicPlanIssued=true;
  return{scope:'MS5_TASK2_PUBLIC_PAYLOAD_PLAN_NOT_FILESYSTEM_PROOF',scenario:this.#mode.scenario,files,window,already_published_native:native,
   resolver_whitelist:[...files.map(x=>x.name),...Object.values(native).map(x=>x.path.split('/').at(-1))],required_Root_action:'CREATE_ONLY_CLOSE_FULL_READBACK_EACH_PAYLOAD_AND_REVERIFY_ORIGINAL_NATIVE_RAW',full_acceptance:'INCOMPLETE',DOM_browser_close_and_private_transport:'SEPARATE_PENDING_ACCEPTANCE'};
 }
 transferToOriginalUi20({consumer,bridge,binding:b,captures,caller,companion}){
  need(this.#modeName==='observe-cancelled-read'&&this.#phase==='NINE_ACKS_ONLY'&&this.#publicPlanIssued&&!this.#transferred&&!this.#busy
   &&Object.getPrototypeOf(consumer)===UiNormalBusinessProducer.prototype&&Object.getPrototypeOf(bridge)===UiWindowObserverBridge.prototype
   &&equal(b,this.#binding)&&caller===this.#caller&&companion===this.#companion&&Object.keys(this.#capture).every(r=>captures[r]===this.#capture[r]),'TASK2_GENUINE_FINAL_UI20_HANDOFF');
  const final=UiTask2ObserverBridge.prototype.completedLedger.call(this.#bridge,b,'ORIGINAL_MS4_UI20'),p=UiWindowObserverBridge.prototype.poll.call(bridge);
  need(p.phase==='BEGIN_ACK'&&p.verified_steps===0&&p.required_steps===20,'UI20_GENUINE_NATIVE_ADMISSION_BEGIN');
  const baseline=UiWindowObserverBridge.prototype.response.call(bridge,0).receipt.baseline;
  need(equal(final.binding,baseline.binding)&&equal(final.database,baseline.database)&&equal(final.redis.keys,baseline.redis.keys)&&equal(final.files,baseline.files),'TASK2_FINAL_TO_UI20_BASELINE_FULL_NATIVE_JOIN');
  need(!task2OriginalHandoffs.has(consumer),'UI20_HANDOFF_ONCE');
  UiPrivateCaller.prototype.activateTask2LoginScene.call(caller,{bridge,predecessor:this.#bridge,
   owned_tab_sha256:Object.fromEntries(['customer','merchant','platform'].map(role=>[role,UiBrowserNormalClose.prototype.ownedTabIdentity.call(companion,role).tab_id_sha256]))});
  task2OriginalHandoffs.set(consumer,{readiness:this.#readiness,consumed:this.#consumed});this.#transferred=true;
 }
 readPrivateObservations(){return{windows:clone(this.#windows),partitions:clone(this.#partitions),stage:this.#phase,server_quiet:'NOT_CLAIMED',native_and_DOM_full_acceptance:'NOT_PROVEN'};}
 forget(){need(!this.#busy,'TASK2_SERIAL_FORGET');this.#tokens={};this.#pending=null;this.#active=null;this.#windows=[];this.#partitions=[];this.#accepted=[];this.#capture=null;this.#caller=null;this.#companion=null;this.#tabs=null;this.#bridge=null;this.#consumed=null;this.#phase='PRODUCER_REFERENCES_RELEASED';return{producer_references_released:true,secure_string_erasure:'NOT_CLAIMED',full_acceptance:'NOT_PROVEN'};}
}
