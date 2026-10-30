// Standalone Root tooling Source. Import has no process, file, or browser action.
// Reviewed PHP r4 Source selection only; actual full-profile/P17/Host/native gates remain required.
import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { isDeepStrictEqual as equal, TextDecoder } from 'node:util';

const fail = () => { throw new Error('MS4_UI_OBSERVER_BRIDGE_UNPROVED'); };
const demand = value => { if (!value) fail(); };
const sha = value => createHash('sha256').update(value).digest('hex');
const pin = value => ({ bytes: value.length, sha256: sha(value) });
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const keys = (value, names) => demand(record(value) && equal(Object.keys(value).sort(), [...names].sort()));
const hex = (value, n = 64) => typeof value === 'string' && new RegExp('^[a-f0-9]{' + n + '}$').test(value);
const integer = (value, minimum = 0) => Number.isSafeInteger(value) && value >= minimum;
const ownJson = (value, depth = 0) => {
  demand(depth <= 128);
  if (value === null || typeof value === 'boolean' || typeof value === 'string') return;
  if (typeof value === 'number') { demand(Number.isFinite(value)); return; }
  demand(typeof value === 'object' && Object.getOwnPropertySymbols(value).length === 0);
  demand(Array.isArray(value) || [Object.prototype, null].includes(Object.getPrototypeOf(value)));
  for (const name of Object.keys(value)) {
    const d = Object.getOwnPropertyDescriptor(value, name); demand(d && 'value' in d);
    ownJson(d.value, depth + 1);
  }
  if (Array.isArray(value)) demand(equal(Object.keys(value),Array.from({length:value.length},(_,i) => String(i))));
};
const copy = value => { ownJson(value); return JSON.parse(JSON.stringify(value)); };
const stringJson = value => JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
// PHP SORT_STRING ordering, including integer-looking map keys; no reserialization is called raw stdout.
function canonical(value) {
  if (value === null || typeof value !== 'object') return stringJson(value);
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  return '{' + Object.keys(value).sort((a,b) => Buffer.compare(Buffer.from(a),Buffer.from(b)))
    .map(k => stringJson(k) + ':' + canonical(value[k])).join(',') + '}';
}
const steps = Object.freeze(['login-customer','login-merchant','login-platform','n1-initial-read',
  'n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read','n1-merchant-approve',
  'ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1','receive-q1-all-eligible',
  'n2-public-image-upload','n2-review-submit','n2-pending-public-read','n2-platform-moderate',
  'n2-merchant-reply','n2-public-review-read','n2-public-image-read']);
const artifacts = Object.freeze(['compose.yml','interface-v1.json','ms4-host.py','ms4-initialize.php',
  'normal-close-ms4-fulfillment.py','README.md','seed-source-inverse.json','source-derivation.json',
  'verify-zero-ms4-fulfillment.py','ms4-ui-nginx.conf','ms4-ui-public-copy.php','ms4-ui-copy.php','ms4-order.png',
  'manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json','manifests/ui-admin-dist.json',
  'manifests/ui-public-copy-profile.json']);
const phpPins = Object.freeze({
  'help/audit-regressions/isolated/ms4-ui-backend.php':
    Object.freeze({bytes:3034,sha256:'ae4ebd8dcd8db0369276bf219250ca2f018147c3424bbbc9e21534b536c8bec1'}),
  'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php':
    Object.freeze({bytes:132938,sha256:'a517ca25cfe2d86d89e0630b35398a56fa07c67f92c0ef54ed3bf265bee994b0'}),
  'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php':
    Object.freeze({bytes:243930,sha256:'e61184d208d9f6f0dfbfad9eb5eb32635ed8fd103e62f7abe5852712f5ee9be3'}),
});
const PHP_SOURCE_ADOPTION = 'FINAL_REVIEWED_R4_SOURCE_SELECTED';
const IN_MAX = 1048576, LINE_MAX = 16777216, OUT_MAX = 268435456, ERROR_RAM_MAX = 1048576;
const names = Object.freeze({created:'ui-window-observer-created.json',terminal:'ui-window-observer-terminal.json',
  stdout:'ui-window-observer.stdout',stderr:'ui-window-observer.stderr'});

function descriptor(value, withPath = false) {
  keys(value,withPath ? ['path','bytes','sha256'] : ['bytes','sha256']);
  demand(integer(value.bytes) && hex(value.sha256));
  if (withPath) demand(typeof value.path === 'string' && value.path.startsWith('/') && !value.path.includes('\0')
    && !value.path.split('/').includes('..') && !value.path.includes('\\'));
}
function binding(value) {
  keys(value,['run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid',
    'ready_sha256','origin','source_closure']);
  demand(/^\d{14}[a-f0-9]{6}$/.test(value.run_id) && hex(value.source_sha,40)
    && [value.freeze_sha256,value.context_sha256,value.backend_cid,value.gateway_cid,value.ready_sha256].every(v => hex(v))
    && value.backend_cid !== value.gateway_cid && /^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(value.origin));
  const port = Number(new URL(value.origin).port); demand(port > 1023 && port <= 65535);
  const c = value.source_closure; keys(c,['context','freeze','artifacts','reader','caller','browser_close']);
  for (const d of [c.context,c.freeze,c.reader,c.caller,c.browser_close]) descriptor(d);
  demand(c.context.sha256 === value.context_sha256 && c.freeze.sha256 === value.freeze_sha256
    && record(c.artifacts) && equal(Object.keys(c.artifacts),artifacts));
  for (const d of Object.values(c.artifacts)) descriptor(d);
}
const stable = (a,b) => ['dev','ino','mode','nlink','size','mtimeMs','ctimeMs'].every(k => a[k] === b[k]);
async function ordinary(name, maximum = OUT_MAX, empty = false) {
  demand(typeof name === 'string' && path.isAbsolute(name));
  for (let p = path.dirname(name);;) {
    const d = await fs.lstat(p); demand(d.isDirectory() && !d.isSymbolicLink());
    const next = path.dirname(p); if (next === p) break; p = next;
  }
  const before = await fs.lstat(name);
  demand(before.isFile() && !before.isSymbolicLink() && before.nlink === 1
    && before.size >= (empty ? 0 : 1) && before.size <= maximum);
  const handle = await fs.open(name,'r'); let raw;
  try {
    demand(stable(before,await handle.stat())); raw = await handle.readFile();
    const extra = Buffer.alloc(1);
    demand((await handle.read(extra,0,1,null)).bytesRead === 0 && raw.length === before.size
      && stable(before,await handle.stat()));
  } finally { await handle.close(); }
  demand(stable(before,await fs.lstat(name))); return raw;
}
function metadata(raw) { try { return JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(raw)); } catch { fail(); } }
function parse(raw) { const value = metadata(raw); ownJson(value); return value; }
async function create(name, raw) {
  const handle = await fs.open(name,'wx',0o600);
  try { await handle.writeFile(raw); await handle.sync(); } finally { await handle.close(); }
  demand((await ordinary(name,raw.length,raw.length === 0)).equals(raw));
  return {path:'/run/audit/evidence/'+path.basename(name),...pin(raw)};
}
function projection(value) {
  keys(value,['encoding','rows','sha256']);
  demand(value.encoding === 'php-serialize-row-sha256-sorted-multiset-v1'
    && integer(value.rows) && hex(value.sha256));
}
function tree(value) {
  if (value === null) return;
  demand(record(value) && Object.hasOwn(value,''));
  for (const [name,r] of Object.entries(value)) {
    demand(!name.includes('\0') && !name.includes('\\') && !name.startsWith('/') && !name.split('/').includes('..'));
    const base = ['kind','dev','ino','mode','uid','gid','nlink'];
    keys(r,r.kind === 'file' ? [...base,'bytes','sha256','mtime','ctime'] : base);
    demand(['file','directory'].includes(r.kind) && ['dev','ino','mode','uid','gid','nlink'].every(k => integer(r[k]))
      && r.mode <= 4095 && r.nlink > 0);
    if (r.kind === 'file') demand(r.nlink === 1 && integer(r.bytes) && hex(r.sha256)
      && Number.isSafeInteger(r.mtime) && Number.isSafeInteger(r.ctime));
  }
}
function ledger(value,b,index,point) {
  keys(value,['schema','scope','binding','index','point','database','redis','files']);
  demand(value.schema === 1 && value.scope === 'MS4_UI_COMPLETE_NATIVE_WINDOW_LEDGER'
    && equal(value.binding,b) && value.index === index && value.point === point);
  const d = value.database; keys(d,['names','namespace_sha256','schema_modes_sha256','tables']);
  demand(Array.isArray(d.names) && d.names.every(n => typeof n === 'string' && /^[A-Za-z0-9_]+$/.test(n))
    && equal(d.names,[...new Set(d.names)].sort()) && hex(d.namespace_sha256) && hex(d.schema_modes_sha256)
    && record(d.tables) && equal(Object.keys(d.tables).sort(),d.names));
  for (const t of Object.values(d.tables)) {
    keys(t,['metadata_sha256','ddl_sha256','projected_columns_sha256','projection']);
    demand([t.metadata_sha256,t.ddl_sha256,t.projected_columns_sha256].every(v => hex(v))); projection(t.projection);
  }
  keys(value.redis,['time_ms','keys']);
  demand(typeof value.redis.time_ms === 'string' && /^(0|[1-9][0-9]*)$/.test(value.redis.time_ms) && record(value.redis.keys));
  for (const [k,r] of Object.entries(value.redis.keys)) {
    demand(hex(k)); keys(r,['type','sha256','expires','dump_sha256']);
    demand(['string','hash','set','list','zset','stream'].includes(r.type) && hex(r.sha256) && hex(r.dump_sha256)
      && Number.isSafeInteger(r.expires) && (r.expires === -1 || r.expires > 0));
  }
  keys(value.files,['roots']); demand(record(value.files.roots));
  const roots = ['/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence','/workspace/crmeb/public',
    '/workspace/crmeb/runtime','/tmp/ms4-fulfillment-normal-'+b.run_id,'/run/audit-ui-control','/run/audit-app/config.json','/tmp'];
  demand(equal(Object.keys(value.files.roots).sort(),roots.sort()));
  Object.values(value.files.roots).forEach(tree);
}
const roles = Object.freeze(['customer','merchant','platform','customer','customer','customer','customer',
  'merchant','merchant','customer','customer','merchant','customer','customer','customer','public','platform',
  'merchant','public','public']);
const decimal = value => typeof value === 'string' && /^(0|[1-9][0-9]*)\.[0-9]{2}$/.test(value);
const nativeId = value => typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value)
  && BigInt(value) <= 18446744073709551615n;
const wireHash = value => sha(Buffer.from(canonical(value)));
function wireShape(value) {
  keys(value,['actor_role','method','path','body_public','body_public_sha256','response_status',
    'response_public','response_public_sha256']);
  demand(['customer','merchant','platform','public'].includes(value.actor_role)
    && ['GET','POST','PUT'].includes(value.method) && typeof value.path === 'string'
    && value.path.startsWith('/') && value.path.length <= 2048 && !value.path.includes('://')
    && !value.path.includes('..') && !value.path.includes('#') && !value.path.includes('\\')
    && !value.path.includes('\0') && value.response_status === 200
    && value.body_public_sha256 === wireHash(value.body_public)
    && value.response_public_sha256 === wireHash(value.response_public));
}
function publicImageWire(request) {
  return {actor_role:'public',method:request.method,path:request.path,body_public:copy(request.request_public),
    body_public_sha256:request.request_public_sha256,response_status:request.http_status,
    response_public:copy(request.response_public),response_public_sha256:request.response_public_sha256};
}
function imageContext(wire,b,uid) {
  keys(wire.response_public,['name','url','attachment_ref']);
  const v = wire.response_public;
  demand(integer(uid,1) && typeof v.attachment_ref === 'string' && typeof v.url === 'string');
  const route = /^\/api\/order\/reviews\/[1-9][0-9]*\/[1-9][0-9]*\/files$/.exec(wire.path);
  const reference = /^\/uploads\/customer\/([1-9][0-9]*)\/[a-f0-9]{32}\/[a-f0-9]{32}\.png$/.exec(v.attachment_ref);
  const actualURL = new URL(v.url);
  demand(wire.method === 'POST' && route && route[0] === wire.path
    && reference && reference[0] === v.attachment_ref && reference[1] === String(uid)
    && actualURL.origin === b.origin && actualURL.username === '' && actualURL.password === ''
    && actualURL.search === '' && actualURL.hash === '' && actualURL.pathname === v.attachment_ref
    && v.name === v.attachment_ref.split('/').at(-1) && v.url === b.origin+v.attachment_ref);
  return {reference:v.attachment_ref,url:v.url,image:copy(b.source_closure.artifacts['ms4-order.png'])};
}
function screenshot(value) {
  keys(value,['path','bytes','sha256']);
  demand(typeof value.path === 'string' && value.path.length > 0 && value.path.length <= 512
    && /^[A-Za-z0-9_./-]+\.png$/.test(value.path) && !value.path.startsWith('/')
    && !value.path.includes('\\') && value.path.split('/').every(p => p !== '' && p !== '.' && p !== '..')
    && integer(value.bytes,1) && hex(value.sha256));
}
function imageDOM(value,image) {
  keys(value,['schema','scope','image_url','complete','natural_width','natural_height','visible','viewports']);
  demand(value.schema === 1 && value.scope === 'MS4_UI_NORMAL_PUBLIC_IMAGE_DOM'
    && value.image_url === image.url && value.complete === true && value.visible === true
    && value.natural_width === 96 && value.natural_height === 96
    && Array.isArray(value.viewports) && value.viewports.length === 3);
  value.viewports.forEach((v,i) => {
    keys(v,['viewport_width','viewport_height','image_url','complete','natural_width','natural_height',
      'visible','bounds','screenshot']);
    demand(v.viewport_width === [320,390,1440][i] && Number.isFinite(v.viewport_height) && v.viewport_height > 0
      && v.image_url === image.url && v.complete === true && v.visible === true
      && v.natural_width === 96 && v.natural_height === 96);
    keys(v.bounds,['x','y','width','height']);
    const r = v.bounds;
    demand(Object.values(r).every(Number.isFinite) && r.x >= 0 && r.y >= 0 && r.width > 0 && r.height > 0
      && r.x+r.width <= v.viewport_width && r.y+r.height <= v.viewport_height);
    screenshot(v.screenshot);
  });
}
function privatePhase(value,index,event,b,wire,state) {
  const p = value === null ? {login_token:null,ticket_pairs:[],content_responses:[]} : value;
  keys(p,['login_token','ticket_pairs','content_responses']);
  demand(p.login_token === null || (typeof p.login_token === 'string'
    && p.login_token.length >= 32 && Buffer.byteLength(p.login_token) <= 8192));
  demand(index <= 3 || p.login_token === null);
  if (index >= 1 && index <= 3) demand(p.login_token !== null);
  demand(Array.isArray(p.ticket_pairs) && p.ticket_pairs.length <= 16
    && Array.isArray(p.content_responses) && p.content_responses.length <= 16);
  if (![5,6,7,8].includes(index)) demand(p.ticket_pairs.length === 0);
  const pairs = [], used = new Set();
  for (const r of p.ticket_pairs) {
    keys(r,['issued_sequence','read_sequence','read_ticket','request_ticket']);
    demand(integer(r.issued_sequence) && integer(r.read_sequence) && r.issued_sequence < r.read_sequence
      && r.read_sequence < event.requests.length && !used.has(r.issued_sequence) && !used.has(r.read_sequence)
      && typeof r.read_ticket === 'string' && /^[A-Za-z0-9_-]{43}$/.test(r.read_ticket)
      && r.request_ticket === r.read_ticket);
    used.add(r.issued_sequence); used.add(r.read_sequence);
    const issue = event.requests[r.issued_sequence], read = event.requests[r.read_sequence];
    const fileId = index === 5 ? wire.response_public.id : state.fileId;
    demand(nativeId(fileId));
    const prefix = event.role === 'merchant' ? '/merchantapi/aftersale-files/' : '/api/order/refund/files/';
    demand(issue.method === 'POST' && read.method === 'GET'
      && issue.path === prefix+fileId+'/read-ticket' && read.path === prefix+fileId+'/content'
      && issue.finished_at_ms <= read.started_at_ms && issue.app_status === 200);
    demand(equal(issue.request_public,{schema:1}));
    pairs.push({issued_sequence:r.issued_sequence,read_sequence:r.read_sequence});
  }
  const content = [], sequences = new Set(), image = b.source_closure.artifacts['ms4-order.png'];
  for (const r of p.content_responses) {
    keys(r,['sequence','headers','body_base64']);
    demand(integer(r.sequence) && r.sequence < event.requests.length && !sequences.has(r.sequence)
      && record(r.headers) && Object.values(r.headers).every(v => typeof v === 'string')
      && r.headers['content-type'] === 'image/png' && typeof r.body_base64 === 'string'
      && Buffer.byteLength(r.body_base64) <= IN_MAX);
    sequences.add(r.sequence);
    if (pairs.some(p => p.read_sequence === r.sequence)) demand(
      r.headers['content-length'] === String(image.bytes)
      && r.headers['cache-control'] === 'private, no-store, max-age=0'
      && r.headers['x-content-type-options'] === 'nosniff');
    const raw = Buffer.from(r.body_base64,'base64');
    try {
      demand(raw.toString('base64') === r.body_base64 && equal(pin(raw),image));
      content.push({sequence:r.sequence,...pin(raw)});
    } finally { raw.fill(0); }
  }
  demand(pairs.every(r => sequences.has(r.read_sequence)));
  return {login_observed:p.login_token !== null,ticket_pairs:pairs,content_responses:content};
}
function verifyInput(value,index,b,state) {
  ownJson(value); keys(value,['wire_public','private']); const w = value.wire_public;
  keys(w,['wire','browser_event']); wireShape(w.wire);
  const e = w.browser_event;
  keys(e,['schema','scope','binding','index','name','role','tab_id_sha256','started_at_ms','finished_at_ms',
    'actions','requests','identity','DOM','diagnostic']);
  demand(e.schema === 1 && e.scope === 'MS4_UI_NORMAL_BROWSER_STEP' && equal(e.binding,b)
    && e.index === index && e.name === steps[index-1] && e.role === roles[index-1]
    && w.wire.actor_role === e.role && hex(e.tab_id_sha256) && integer(e.started_at_ms,1)
    && integer(e.finished_at_ms,e.started_at_ms) && Array.isArray(e.actions) && e.actions.length > 0
    && Array.isArray(e.requests) && (index === 20 || e.requests.length > 0) && e.diagnostic === null);
  e.requests.forEach((r,j) => {
    keys(r,['sequence','method','path','started_at_ms','finished_at_ms','http_status','app_status',
      'request_public','response_public','request_public_sha256','response_public_sha256','authentication']);
    wireShape({actor_role:e.role,method:r.method,path:r.path,body_public:r.request_public,
      body_public_sha256:r.request_public_sha256,response_status:r.http_status,response_public:r.response_public,
      response_public_sha256:r.response_public_sha256});
    demand(r.sequence === j && integer(r.started_at_ms,e.started_at_ms)
      && integer(r.finished_at_ms,r.started_at_ms) && r.finished_at_ms <= e.finished_at_ms
      && (r.app_status === null || integer(r.app_status)));
    keys(r.authentication,['present','kind','subject_id']);
    demand(r.authentication.present === false
      ? r.authentication.kind === 'none' && r.authentication.subject_id === null
      : r.authentication.present === true && r.authentication.kind === 'Bearer' && integer(r.authentication.subject_id,1));
  });
  keys(e.identity,['application','subject_id','merchant_id','verified_by']);
  if (e.role === 'public') demand(equal(e.identity,{application:'none',subject_id:null,merchant_id:null,
    verified_by:'actual-anonymous-normal-response'}));
  else {
    demand(e.identity.application === {customer:'api',merchant:'merchantapi',platform:'adminapi'}[e.role]
      && integer(e.identity.subject_id,1) && e.identity.verified_by === 'native-session-and-normal-UI'
      && (e.role === 'merchant' ? integer(e.identity.merchant_id,1) : e.identity.merchant_id === null));
    if (state.actors[e.role] !== undefined) demand(e.identity.subject_id === state.actors[e.role]);
    if (e.role === 'merchant' && state.merchant !== null) demand(e.identity.merchant_id === state.merchant);
  }
  const privateFacts = privatePhase(value.private,index,e,b,w.wire,state);
  const matches = e.requests.filter(r => r.method === w.wire.method && r.path === w.wire.path
    && r.request_public_sha256 === w.wire.body_public_sha256
    && r.response_public_sha256 === w.wire.response_public_sha256);
  demand(matches.length === 1 || (index === 20 && matches.length === 0));
  if (index <= 3) {
    keys(w.wire.body_public,[]); keys(w.wire.response_public,['subject_id']);
    demand(w.wire.method === 'POST' && w.wire.path === ['/api/login','/merchantapi/login','/adminapi/login'][index-1]
      && w.wire.response_public.subject_id === e.identity.subject_id
      && matches[0].authentication.present === false);
  }
  let image = state.image, imageMode = null, historical = null;
  if (index === 14) image = imageContext(w.wire,b,state.actors.customer);
  if (index === 20) {
    demand(image !== null && w.wire.actor_role === 'public' && w.wire.method === 'GET'
      && w.wire.path === image.reference);
    keys(w.wire.body_public,[]); imageDOM(e.DOM,image);
    if (matches.length === 1) {
      demand(matches[0].authentication.present === false
        && privateFacts.content_responses.some(r => r.sequence === matches[0].sequence));
      imageMode = 'fresh-normal-response';
    } else {
      demand(!e.requests.some(r => r.method === 'GET' && r.path === image.reference));
      historical = state.witnesses.filter(r => equal(r.wire,w.wire) && r.reference === image.reference
        && r.origin === b.origin && equal(r.image,image.image));
      demand(historical.length > 0); imageMode = 'historical-normal-response-reused';
    }
  }
  return {private_facts:privateFacts,primary_sequence:matches.length === 1 ? matches[0].sequence : null,
    image, image_mode:imageMode, historical};
}
function sequenceFact(value,index,pending) {
  keys(value,['schema','scope','number_sha256','key_sha256','initial_present','samples','natural_final_absent','elapsed_ns']);
  const answer = pending.wire_public.wire.response_public;
  const number = index === 6 ? answer.refund?.refund_no : answer.child_order_no;
  demand(typeof number === 'string' && /^(cp|cf)[1-9][0-9]{0,18}$/.test(number));
  const key = (1640966400000n + BigInt(number.slice(2))/4194304n).toString();
  demand(value.schema === 1 && value.scope === 'PASSIVE_REAL_NORMAL_SEQUENCE_COUNTER_ONLY'
    && value.number_sha256 === sha(Buffer.from(number)) && value.key_sha256 === sha(Buffer.from(key))
    && typeof value.initial_present === 'boolean' && Array.isArray(value.samples) && value.samples.length > 0
    && value.natural_final_absent === true && integer(value.elapsed_ns) && value.elapsed_ns <= 10000000000);
  let previous = null, other = null;
  value.samples.forEach((r,i) => {
    keys(r,['time_ms','present','other_native_sha256']);
    demand(typeof r.time_ms === 'string' && /^[1-9][0-9]*$/.test(r.time_ms) && hex(r.other_native_sha256)
      && r.present === (i !== value.samples.length-1));
    if (previous !== null) demand(BigInt(r.time_ms) >= previous);
    if (other !== null) demand(r.other_native_sha256 === other);
    previous = BigInt(r.time_ms); other = r.other_native_sha256;
  });
  demand(value.samples[0].present === value.initial_present);
}
function fulfillmentFact(value,index,pending,state) {
  keys(value,['summary','aftersale','child_count']);
  const n = index-9, shipped = [2,2,2,3,3][n], received = [0,1,2,2,3][n], children = n < 3 ? 1 : 2;
  const expected = {shipping_state:shipped === 2 ? 'partial' : 'shipped',
    receipt_state:received === shipped ? 'received' : received === 0 ? 'pending' : 'partial',
    closed:index === 13,original:3,shipped,received,cancelled_unshipped:0,returned_accepted:0,
    to_ship:3-shipped,to_receive:shipped-received,child_count:children};
  demand(equal(value.summary,expected) && value.child_count === children
    && equal(value.summary,pending.wire_public.wire.response_public.fulfillment_summary));
  const a = value.aftersale;
  keys(a,['schema','revision','confirmed_amount','held_amount','available_amount','pending_cancel_quantity',
    'pending_return_quantity','returned_after_receipt','fulfillment_completed_at']);
  demand(a.schema === 1 && nativeId(a.revision) && a.revision === state.approval
    && a.confirmed_amount === '0.00' && a.held_amount === '0.01' && decimal(a.available_amount)
    && a.pending_cancel_quantity === 0 && a.pending_return_quantity === 0 && a.returned_after_receipt === 0
    && (index === 13 ? integer(a.fulfillment_completed_at,1)
      && a.fulfillment_completed_at === pending.wire_public.wire.response_public.created_at : a.fulfillment_completed_at === null)
    && equal(a,pending.wire_public.wire.response_public.aftersale));
  if (state.available !== null) demand(a.available_amount === state.available);
}
function expectedNormal(value,index,pending,b,state) {
  keys(value,['schema','scope','name','tables','independent','all_other_database_redis_files_verified',
    'transient_ticket_native_observation','full_twenty_case_acceptance']);
  demand(value.schema === 2 && value.scope === 'MS4_UI_INDEPENDENT_NORMAL_CASE_EXPECTATION'
    && value.name === steps[index-1] && index >= 1 && index <= 20 && record(value.tables)
    && value.all_other_database_redis_files_verified === true
    && value.transient_ticket_native_observation === 'NOT_CLAIMED' && value.full_twenty_case_acceptance === false);
  for (const [table,r] of Object.entries(value.tables)) {
    demand(/^[A-Za-z0-9_]+$/.test(table)); keys(r,['projection','natural_insert_count']);
    projection(r.projection); demand(integer(r.natural_insert_count));
  }
  const v = value.independent;
  const perStep = [['login'],['login'],['login'],[],['private_file_id','private_pairs'],
    ['N1','sequence_barrier','private_pairs'],['private_pairs'],['N1','private_pairs'],
    ['fulfillment','sequence_barrier'],['fulfillment'],['fulfillment'],['fulfillment','sequence_barrier'],
    ['fulfillment'],['public_image'],['review_id'],[],['review_id','changed_fields'],
    ['review_id','changed_fields'],[],['public_image_read']];
  if (perStep[index-1].length === 0) demand(Array.isArray(v) && v.length === 0);
  else keys(v,perStep[index-1]);
  if (index <= 3) {
    keys(v.login,['application','subject_id','native_session_verified','signed_claims_verified','browser_token_join']);
    demand(v.login.application === ['api','merchantapi','adminapi'][index-1]
      && v.login.subject_id === pending.wire_public.browser_event.identity.subject_id && integer(v.login.subject_id,1)
      && v.login.native_session_verified === true && v.login.signed_claims_verified === true
      && pending.private_facts.login_observed === true && v.login.browser_token_join === true);
  }
  if (index === 5) demand(nativeId(v.private_file_id) && v.private_file_id === pending.wire_public.wire.response_public.id);
  if ([5,6,7,8].includes(index)) {
    demand(Array.isArray(v.private_pairs) && v.private_pairs.length > 0
      && v.private_pairs.length === pending.private_facts.ticket_pairs.length);
    v.private_pairs.forEach((r,i) => {
      keys(r,['issued_sequence','read_sequence','file_id','full_response_bytes_verified','net_ticket_absent','native_intermediate_observed']);
      demand(r.issued_sequence === pending.private_facts.ticket_pairs[i].issued_sequence
        && r.read_sequence === pending.private_facts.ticket_pairs[i].read_sequence
        && r.file_id === (index === 5 ? v.private_file_id : state.fileId)
        && r.full_response_bytes_verified === true && r.net_ticket_absent === true
        && r.native_intermediate_observed === false);
    });
  }
  if (index === 6 || index === 8) {
    const answer = pending.wire_public.wire.response_public.refund;
    const expected = {business_state:index === 6 ? 'REQUESTED' : 'APPROVED',physical_state:'NONE',
      finance_state:index === 6 ? 'NOT_SUBMITTED' : 'UNSUPPORTED',held_amount:'0.01',confirmed_amount:'0.00'};
    demand(equal(v.N1,expected) && Object.keys(expected).every(k => answer[k] === expected[k]));
  }
  if ([6,9,12].includes(index)) sequenceFact(v.sequence_barrier,index,pending);
  if (index >= 9 && index <= 13) fulfillmentFact(v.fulfillment,index,pending,state);
  if (index === 14) { descriptor(v.public_image); demand(equal(v.public_image,b.source_closure.artifacts['ms4-order.png'])); }
  if ([15,17,18].includes(index)) {
    demand(integer(v.review_id,1));
    if (index !== 15) demand(v.review_id === state.reviewId);
  }
  if (index === 17) demand(equal(v.changed_fields,['status']));
  if (index === 18) demand(Array.isArray(v.changed_fields) && v.changed_fields.length === 3
    && equal([...v.changed_fields].sort(),['is_reply','merchant_reply_content','merchant_reply_time']));
  if (index === 20) {
    const r = v.public_image_read;
    keys(r,['mode','original_step','original_name','original_sequence','original_wire_sha256','image_sha256']);
    demand(r.mode === pending.image_mode && r.image_sha256 === state.image.image.sha256
      && r.original_wire_sha256 === wireHash(pending.wire_public.wire));
    if (r.mode === 'fresh-normal-response') demand(r.original_step === 20 && r.original_name === steps[19]
      && r.original_sequence === pending.primary_sequence && integer(r.original_sequence));
    else {
      demand(r.mode === 'historical-normal-response-reused' && Array.isArray(pending.historical)
        && integer(r.original_step,1) && r.original_step < 20 && integer(r.original_sequence)
        && r.original_name === steps[r.original_step-1]);
      const h = pending.historical.find(v => r.original_step === v.step_index && r.original_name === v.step_name
        && r.original_sequence === v.request_sequence && r.original_wire_sha256 === wireHash(v.wire)
        && r.image_sha256 === v.private_response_sha256);
      demand(h);
    }
  }
}
function acceptNormal(frame,pending,b,state) {
  if (frame.command !== 'STEP_VERIFY') return;
  const index = frame.step, facts = frame.receipt.expected_delta.independent, e = pending.wire_public.browser_event;
  if (index <= 3) {
    state.actors[roles[index-1]] = facts.login.subject_id;
    if (index === 2) state.merchant = e.identity.merchant_id;
  }
  if (index === 5) state.fileId = facts.private_file_id;
  if (index === 8) {
    const action = pending.wire_public.wire.response_public.action_id; demand(nativeId(action)); state.approval = action;
  }
  if (index === 9) state.available = facts.fulfillment.aftersale.available_amount;
  if (index === 14) state.image = copy(pending.image);
  if (index === 15) state.reviewId = facts.review_id;
  if (state.image !== null) for (const request of e.requests) {
    if (request.method !== 'GET' || request.path !== state.image.reference) continue;
    const body = pending.private_facts.content_responses.find(r => r.sequence === request.sequence);
    demand(body && equal({bytes:body.bytes,sha256:body.sha256},state.image.image)
      && equal(request.authentication,{present:false,kind:'none',subject_id:null}));
    const wire = publicImageWire(request); wireShape(wire);
    state.witnesses.push({step_index:index,step_name:steps[index-1],request_sequence:request.sequence,
      wire,request:copy(request),image:copy(state.image.image),reference:state.image.reference,origin:b.origin,
      private_response_sha256:body.sha256});
  }
}
function preservation(value,b) {
  keys(value,['source','predecessors','private_inputs','public_materials']);
  const scopes = {source:'MS4_UI_WINDOW_SOURCE_PRESERVED',predecessors:'MS4_UI_WINDOW_PREDECESSORS_PRESERVED',
    private_inputs:'MS4_UI_WINDOW_PRIVATE_INPUTS_PRESERVED',public_materials:'MS4_UI_WINDOW_PUBLIC_MATERIALS_PRESERVED'};
  for (const [name,scope] of Object.entries(scopes)) {
    const r = value[name]; keys(r,['schema','scope','binding','files']);
    demand(r.schema === 1 && r.scope === scope && equal(r.binding,b));
    if (name === 'source' || name === 'predecessors') {
      demand(Array.isArray(r.files));
      for (const d of r.files) { descriptor(d,true); demand(d.path.startsWith(name === 'source' ? '/workspace/' : '/run/audit/evidence/')); }
      if (name === 'source') {
        demand(new Set(r.files.map(d => d.path)).size === r.files.length);
        for (const [relative,p] of Object.entries(phpPins)) {
          const actual = r.files.filter(d => d.path === '/workspace/'+relative);
          demand(actual.length === 1 && equal(actual[0],{path:'/workspace/'+relative,...p}));
        }
      }
    } else if (name === 'private_inputs') {
      keys(r.files,['/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence']); Object.values(r.files).forEach(tree);
    } else { descriptor(r.files,true); demand(r.files.path === '/run/audit/evidence/ui-public-materials.json'); }
  }
}
function receipt(frame,pending,b,state) {
  keys(frame,['schema','scope','binding','sequence','command','step','receipt']);
  demand(frame.schema === 1 && frame.scope === 'MS4_UI_OBSERVER_SAFE_FRAME' && equal(frame.binding,b)
    && frame.sequence === pending.sequence && frame.command === pending.command && frame.step === pending.step);
  const r = frame.receipt, i = frame.step;
  if (frame.command === 'BEGIN') { keys(r,['baseline']); ledger(r.baseline,b,0,'baseline'); }
  else if (frame.command === 'STEP_BEGIN') { keys(r,['before']); ledger(r.before,b,i,'before'); }
  else if (frame.command === 'STEP_VERIFY') {
    keys(r,['after','verified_after','expected_delta','oracle']);
    ledger(r.after,b,i,'after'); ledger(r.verified_after,b,i,'verified'); expectedNormal(r.expected_delta,i,pending,b,state);
    keys(r.oracle,['source','case_name','expected','actual','readback']); descriptor(r.oracle.source,true);
    demand(equal(r.oracle.source,{path:'/workspace/help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
      ...phpPins['help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php']}) && r.oracle.case_name === steps[i-1]
      && equal(r.oracle.expected,r.expected_delta) && equal(r.oracle.actual,r.after) && equal(r.oracle.readback,r.verified_after));
  } else { keys(r,['final','preservation']); ledger(r.final,b,steps.length+1,'final'); preservation(r.preservation,b); }
}
/** Root must finish genuine same-Run caller/current/source/native ownership gates before start(). */
export class UiWindowObserverBridge {
  #config; #binding; #directory; #sourceBinding = null; #child = null; #pid = null; #created = null; #phase = 'NEW';
  #fault = null; #busy = false; #writeBusy = false; #pending = null; #verified = 0; #responses = [];
  #stdout = []; #stdoutBytes = 0; #stdoutHash = createHash('sha256'); #stdoutEnd = false; #stdoutValid = true;
  #line = Buffer.alloc(0); #offset = 0; #stderr = []; #stderrBytes = 0; #stderrHash = createHash('sha256'); #stderrEnd = false;
  #closed = false; #code; #signal; #digests = null; #published = null; #publishAttempted = false;
  #normal = {actors:{},merchant:null,fileId:null,approval:null,available:null,reviewId:null,image:null,witnesses:[]};
  constructor(config) {
    keys(config,['context_path','binding']); demand(typeof config.context_path === 'string' && path.isAbsolute(config.context_path));
    this.#config = copy(config); binding(this.#config.binding); this.#binding = this.#config.binding;
  }
  #bad(code) { if (this.#fault === null) this.#fault = code; this.#phase = 'UNPROVED'; }
  async #source() {
    const raw = await ordinary(this.#config.context_path,33554432), c = metadata(raw), b = this.#binding;
    demand(c.run_id === b.run_id && c.source_sha === b.source_sha && c.freeze_sha256 === b.freeze_sha256
      && path.isAbsolute(c.run_dir) && path.basename(path.resolve(c.run_dir)) === b.run_id
      && path.resolve(this.#config.context_path) === path.resolve(c.run_dir,'actual-run-context.json')
      && equal(pin(raw),b.source_closure.context));
    const frozenRaw = await ordinary(path.join(c.run_dir,'preparation-source-binding.json'),33554432), f = metadata(frozenRaw);
    demand(equal(pin(frozenRaw),b.source_closure.freeze) && f.source_sha === b.source_sha
      && f.status === 'CLEAN_SOURCE_FROZEN_ONLY' && f.ui_web_origin === b.origin
      && Array.isArray(f.artifact_files) && equal(f.artifact_files.map(r => r.path),artifacts));
    const map = {};
    for (const r of f.artifact_files) { keys(r,['path','bytes','sha256']); map[r.path] = {bytes:r.bytes,sha256:r.sha256}; }
    demand(equal(map,b.source_closure.artifacts) && Array.isArray(f.ui_backend_source_files));
    const currentPhp = [];
    for (const [name,p] of Object.entries(phpPins)) {
      const rows = f.ui_backend_source_files.filter(r => r.path === name);
      demand(rows.length === 1); keys(rows[0],['path','bytes','sha256']);
      demand(equal({bytes:rows[0].bytes,sha256:rows[0].sha256},p));
      currentPhp.push({path:'/workspace/'+name,bytes:rows[0].bytes,sha256:rows[0].sha256});
    }
    this.#directory = path.join(c.run_dir,'evidence');
    const d = await fs.lstat(this.#directory); demand(d.isDirectory() && !d.isSymbolicLink());
    for (const name of Object.values(names)) {
      let absent = false; try { await fs.lstat(path.join(this.#directory,name)); } catch (e) { if (e.code === 'ENOENT') absent = true; else throw e; }
      demand(absent);
    }
    this.#sourceBinding = {schema:1,scope:'MS4_UI_FROZEN_PHP_SOURCE_BINDING',binding:copy(b),files:currentPhp};
  }
  sourceBinding() {
    demand(this.#sourceBinding !== null); return copy(this.#sourceBinding);
  }
  async start() {
    demand(PHP_SOURCE_ADOPTION === 'FINAL_REVIEWED_R4_SOURCE_SELECTED');
    demand(this.#phase === 'NEW' && !this.#busy); this.#busy = true; this.#phase = 'SOURCE_CHECK';
    try {
      await this.#source();
      const argv = ['exec','-i',this.#binding.backend_cid,'php','/workspace/help/audit-regressions/isolated/ms4-ui-backend.php','observe'];
      const child = spawn('docker',argv,{shell:false,windowsHide:true,stdio:['pipe','pipe','pipe']});
      this.#child = child; this.#pid = Number.isSafeInteger(child.pid) && child.pid > 0 ? child.pid : null;
      child.once('error',() => this.#bad('SPAWN_ERROR'));
      child.stdin.on('error',() => this.#bad('STDIN_ERROR'));
      child.stdout.on('error',() => { this.#stdoutValid = false; this.#bad('STDOUT_ERROR'); });
      child.stderr.on('error',() => this.#bad('STDERR_ERROR'));
      child.stdout.on('data',part => this.#onStdout(part));
      child.stderr.on('data',part => {
        this.#stderrBytes += part.length; this.#stderrHash.update(part);
        const stored = this.#stderr.reduce((n,p) => n+p.length,0);
        if (stored+part.length <= ERROR_RAM_MAX) this.#stderr.push(Buffer.from(part));
        this.#bad('NONEMPTY_STDERR_WITHHELD');
      });
      child.stdout.once('end',() => { this.#stdoutEnd = true; });
      child.stderr.once('end',() => { this.#stderrEnd = true; });
      child.once('close',(code,signal) => {
        this.#code = code; this.#signal = signal; this.#closed = true;
        this.#digests = {stdout:this.#stdoutHash.digest('hex'),stderr:this.#stderrHash.digest('hex')};
        this.#finalizeClose();
      });
      demand(this.#pid !== null);
      this.#created = {label:'ui-window-observer',executable:'docker',argv,pid:this.#pid};
      await create(path.join(this.#directory,names.created),Buffer.from(JSON.stringify(this.#created)+'\n'));
      if (this.#fault === null && !this.#closed) this.#phase = 'STARTED';
      return this.poll();
    } catch { this.#bad('START_UNPROVED'); throw new Error('MS4_UI_OBSERVER_BRIDGE_START_UNPROVED'); }
    finally { this.#busy = false; }
  }
  #finalizeClose() {
    if (!this.#closed || this.#writeBusy) return;
    if (!this.#stdoutEnd || !this.#stderrEnd || this.#line.length !== 0 || this.#pending
      || this.#responses.length !== 2*steps.length+2 || this.#phase !== 'FINISH_ACK'
      || this.#code !== 0 || this.#signal !== null) this.#bad('NATIVE_OR_PROTOCOL_INCOMPLETE');
    else if (this.#fault === null) this.#phase = 'NATURAL_ZERO_COMPLETE_PROTOCOL';
  }
  #onStdout(part) {
    this.#stdoutBytes += part.length; this.#stdoutHash.update(part);
    if (this.#stdoutBytes > OUT_MAX) { this.#stdoutValid = false; this.#bad('STDOUT_LIMIT'); return; }
    this.#stdout.push(Buffer.from(part)); if (!this.#stdoutValid) return;
    this.#line = Buffer.concat([this.#line,part]);
    try {
      while (true) {
        const end = this.#line.indexOf(10); if (end < 0) break;
        const raw = this.#line.subarray(0,end+1);
        demand(raw.length <= LINE_MAX && !raw.includes(0) && this.#pending);
        const frame = parse(raw); demand(Buffer.from(canonical(frame)+'\n').equals(raw));
        receipt(frame,this.#pending,this.#binding,this.#normal);
        const current = this.#pending;
        acceptNormal(frame,current,this.#binding,this.#normal);
        this.#responses.push({sequence:frame.sequence,command:frame.command,step:frame.step,
          request_sha256:current.public_sha256,response_sha256:sha(raw),stdout_start:this.#offset,stdout_end:this.#offset+raw.length,
          receipt:frame.receipt});
        this.#offset += raw.length; this.#line = Buffer.from(this.#line.subarray(end+1)); this.#pending = null;
        if (frame.command === 'BEGIN') this.#phase = 'BEGIN_ACK';
        else if (frame.command === 'STEP_BEGIN') this.#phase = 'STEP_READY';
        else if (frame.command === 'STEP_VERIFY') { this.#verified = frame.step; this.#phase = 'STEP_VERIFIED'; }
        else this.#phase = 'FINISH_ACK';
      }
      demand(this.#line.length < LINE_MAX);
    } catch { this.#stdoutValid = false; this.#bad('UNPROVED_PUBLIC_FRAME'); }
  }
  #send(command,step,sequence,wire_public = null,privateValue = null,validationData = null) {
    demand(!this.#busy && !this.#writeBusy && !this.#pending && !this.#closed && this.#fault === null && this.#child && this.#created);
    const publicFrame = {schema:1,binding:this.#binding,sequence,command,step,wire_public};
    const frame = {...publicFrame,private:privateValue}; ownJson(frame);
    const raw = Buffer.from(JSON.stringify(frame)+'\n');
    if (raw.length > IN_MAX || raw.includes(0)) { raw.fill(0); this.#bad('STDIN_LIMIT'); fail(); }
    this.#pending = {command,step,sequence,public_sha256:sha(Buffer.from(canonical(publicFrame))),
      wire_public:wire_public === null ? null : copy(wire_public),
      ...(validationData === null ? {} : copy(validationData))};
    this.#writeBusy = true; this.#phase = 'AWAIT_RESPONSE';
    try {
      const written = error => {
        raw.fill(0); this.#writeBusy = false; if (error) this.#bad('STDIN_WRITE_UNPROVED');
        this.#finalizeClose();
      };
      if (command === 'FINISH') this.#child.stdin.end(raw,written);
      else this.#child.stdin.write(raw,written);
    } catch { raw.fill(0); this.#writeBusy = false; this.#bad('STDIN_WRITE_UNPROVED'); fail(); }
    return this.poll();
  }
  begin() { demand(this.#phase === 'STARTED'); return this.#send('BEGIN',0,0); }
  stepBegin() {
    demand(['BEGIN_ACK','STEP_VERIFIED'].includes(this.#phase) && this.#verified < steps.length);
    const i = this.#verified+1; return this.#send('STEP_BEGIN',i,2*i-1);
  }
  stepVerify(input) {
    demand(this.#phase === 'STEP_READY'); const i = this.#verified+1;
    const validationData = verifyInput(input,i,this.#binding,this.#normal);
    demand(equal(input.wire_public.browser_event.binding,this.#binding));
    return this.#send('STEP_VERIFY',i,2*i,copy(input.wire_public),input.private === null ? null : copy(input.private),validationData);
  }
  finish() { demand(this.#phase === 'STEP_VERIFIED' && this.#verified === steps.length);
    return this.#send('FINISH',steps.length+1,2*steps.length+1); }
  poll() {
    return {phase:this.#phase,actual_pid:this.#pid,closed:this.#closed,close_code:this.#closed ? this.#code : null,
      close_signal:this.#closed ? this.#signal : null,verified_steps:this.#verified,required_steps:steps.length,
      pending:this.#pending ? {command:this.#pending.command,sequence:this.#pending.sequence,step:this.#pending.step} : null,
      ready_for_UI_action:this.#phase === 'STEP_READY' && this.#fault === null && !this.#writeBusy,
      stdout_bytes:this.#stdoutBytes,stderr_bytes:this.#stderrBytes,diagnostic:this.#fault,
      native_complete:this.#phase === 'NATURAL_ZERO_COMPLETE_PROTOCOL',full_acceptance:'INCOMPLETE',
      PHP_checkpoint:'FINAL_REVIEWED_R4_SOURCE_SELECTED_PROFILE_HOST_RUNTIME_GATES_REQUIRED',published:this.#published ? copy(this.#published) : null};
  }
  response(sequence) {
    demand(integer(sequence)); const row = this.#responses.find(r => r.sequence === sequence); demand(row); return copy(row);
  }
  async publish() {
    demand(this.#closed && this.#digests && this.#stdoutEnd && this.#stderrEnd && this.#created
      && !this.#busy && !this.#writeBusy && !this.#publishAttempted);
    this.#publishAttempted = true; this.#busy = true;
    try {
      let stdout = null, stderr = null;
      const safe = this.#stdoutValid && this.#line.length === 0 && this.#offset === this.#stdoutBytes;
      if (safe) {
        const raw = Buffer.concat(this.#stdout); demand(raw.length === this.#stdoutBytes && sha(raw) === this.#digests.stdout);
        stdout = await create(path.join(this.#directory,names.stdout),raw);
      }
      if (this.#stderrBytes === 0) stderr = await create(path.join(this.#directory,names.stderr),Buffer.alloc(0));
      const stored = (bytes,hash,d) => ({bytes,sha256:hash,stored_bytes:d?.bytes ?? 0,
        stored_sha256:d?.sha256 ?? sha(Buffer.alloc(0)),complete:d !== null && d.bytes === bytes && d.sha256 === hash});
      const terminal = {schema:1,scope:'MS4_UI_OBSERVER_NODE_NATIVE',executable:this.#created.executable,
        argv:this.#created.argv,pid:this.#created.pid,close_code:this.#code,close_signal:this.#signal,
        stdout:stored(this.#stdoutBytes,this.#digests.stdout,stdout),stderr:stored(this.#stderrBytes,this.#digests.stderr,stderr)};
      const terminalDescriptor = await create(path.join(this.#directory,names.terminal),Buffer.from(JSON.stringify(terminal)+'\n'));
      const completed = this.#phase === 'NATURAL_ZERO_COMPLETE_PROTOCOL' && terminal.stdout.complete && terminal.stderr.complete;
      this.#published = {created:{path:'/run/audit/evidence/'+names.created,
        ...pin(Buffer.from(JSON.stringify(this.#created)+'\n'))},terminal:terminalDescriptor,stdout,stderr,
        native_complete:completed,diagnostic:this.#fault,full_acceptance:'INCOMPLETE'};
      return copy(this.#published);
    } catch { this.#bad('PUBLICATION_UNPROVED'); throw new Error('MS4_UI_OBSERVER_BRIDGE_PUBLICATION_UNPROVED'); }
    finally {
      this.#stdout.forEach(p => p.fill(0)); this.#stdout = [];
      this.#stderr.forEach(p => p.fill(0)); this.#stderr = []; this.#line.fill(0); this.#line = Buffer.alloc(0);
      this.#busy = false;
    }
  }
}


// Task2 is a finite additive branch. The original UI20/42 implementation above is unchanged.
import { fileURLToPath as task2FileURLToPath } from 'node:url';
const task2AuxNames=['reader/ms4-ui-private-reader.mjs','ms4-ui-private-caller.mjs','ms4-ui-browser-close.mjs','ms4-ui-business-producer.mjs','ms4-ui-window-native-bridge.mjs','ms4-ui-network-capture.mjs','ms4-ui-dom-producer.mjs','ui-business-consumer-contract.json'];
export const TASK2_READ_MODES=Object.freeze({
 'observe-paid-read':Object.freeze({scenario:'MS5_TASK2_PAID_READ_V1',state:'test_paid',prefix:'ui-task2',scope:'MS5_TASK2_PAID_READ_BROWSER_STEP',terminal:'MS5_TASK2_OBSERVER_NODE_NATIVE'}),
 'observe-unpaid-read':Object.freeze({scenario:'MS5_TASK2_UNPAID_READ_V1',state:'unpaid',prefix:'ui-task2-unpaid',scope:'MS5_TASK2_UNPAID_READ_BROWSER_STEP',terminal:'MS5_TASK2_UNPAID_OBSERVER_NODE_NATIVE'}),
 'observe-cancelled-read':Object.freeze({scenario:'MS5_TASK2_CANCELLED_READ_V1',state:'cancelled',prefix:'ui-task2-cancelled',scope:'MS5_TASK2_CANCELLED_READ_BROWSER_STEP',terminal:'MS5_TASK2_CANCELLED_OBSERVER_NODE_NATIVE'})});
export const TASK2_READ_STEPS=Object.freeze(['login-customer','login-merchant','login-platform','customer-list','customer-root-detail',
 'merchant-list','merchant-root-packages','platform-list','platform-root-detail']);
export const TASK2_READ_ROLES=Object.freeze(['customer','merchant','platform','customer','customer','merchant','merchant','platform','platform']);
export const TASK2_READ_VIEWS=Object.freeze(['login','login','login','customer_list','customer_detail','merchant_list','merchant_detail','platform_list','platform_detail']);
export const TASK2_ACTION_TARGETS=Object.freeze(['login-route','login-account','login-password','login-submit','order-list-entry','order-detail-entry','order-row-detail',
 'order-page-next','order-page-previous','order-filter-number','order-filter-paid','order-filter-search','order-filter-reset','order-tabs-normal','document-scroll',
 'viewport-width','dom-order-read','logout-entry','logout-confirm']);
const task2Modes=Object.keys(TASK2_READ_MODES), task2Apps={customer:'api',merchant:'merchantapi',platform:'adminapi'};
export function task2Source(value,b) {
 keys(value,['schema','scope','core_source_sha','host_source_root','container_root','members']);
 demand(value.schema===1&&value.scope==='MS5_TASK2_COMPOSITE_OBSERVER_SOURCE_ONLY'&&value.core_source_sha===b.source_sha
  &&typeof value.host_source_root==='string'&&path.isAbsolute(value.host_source_root)&&value.container_root==='/run/audit/task2-observer'
  &&Array.isArray(value.members)&&value.members.length===3);
 value.members.forEach((r,i)=>{keys(r,['path','bytes','sha256']);demand(r.path===['entry.php','expected.php','gate.php'][i]&&integer(r.bytes,1)&&hex(r.sha256));});
}
export function task2Route(request,b) {
 demand(typeof request.path==='string'&&request.path.startsWith('/')&&!request.path.includes('\\')&&!request.path.includes('\0')
  &&request.path.length<=2048&&!/%(?:2f|5c|00|2e)/i.test(request.path));
 const u=new URL(request.path,b.origin);demand(u.origin===b.origin&&u.hash===''&&u.username===''&&u.password===''&&u.pathname+u.search===request.path);
 const query={};for(const[k,v]of u.searchParams){demand(!Object.hasOwn(query,k)&&!/[\0\r\n]/.test(k+v));query[k]=v;}
 demand(equal(query,request.query));return u.pathname;
}
export function task2View(request,b) {
 const route=task2Route(request,b);if(request.method==='POST')return {'/api/login':'login','/merchantapi/login':'login','/adminapi/login':'login'}[route]??null;
 if(request.method!=='GET')return null;
 if(route==='/api/order/list')return'customer_list';if(/^\/api\/order\/detail\/cp[1-9][0-9]*$/.test(route))return'customer_detail';
 if(route==='/merchantapi/orders')return'merchant_list';if(/^\/merchantapi\/orders\/[1-9][0-9]*\/packages$/.test(route))return'merchant_detail';
 if(route==='/adminapi/order/list')return'platform_list';if(/^\/adminapi\/order\/info\/[1-9][0-9]*$/.test(route))return'platform_detail';
 if(route==='/api/order/data')return'customer_order_data';if(route==='/adminapi/order/chart')return'platform_order_chart';return null;
}
export function task2Primary(request,index,b) {
 const view=task2View(request,b);if(index<=3)return view==='login'&&request.path===['/api/login','/merchantapi/login','/adminapi/login'][index-1];
 return view===TASK2_READ_VIEWS[index-1];
}
export function task2SetDigests(requests,indices) {
 const selected=indices.map(i=>requests[i]);
 return {request_set_sha256:wireHash(selected.map(r=>({sequence:r.sequence,method:r.method,path:r.path,query:r.query,capture_ref:r.capture_ref,
  request_public:r.request_public,request_public_sha256:r.request_public_sha256,authentication:r.authentication,language_headers:r.language_headers}))),
 response_set_sha256:wireHash(selected.map(r=>({sequence:r.sequence,capture_ref:r.capture_ref,http_status:r.http_status,app_status:r.app_status,
  response_public:r.response_public,response_body:r.response_body,response_public_sha256:r.response_public_sha256})))};
}
function task2QueryContract(r,view,b,mode,state) {
 const q=r.query,positive=v=>typeof v==='string'&&/^[1-9][0-9]*$/.test(v)&&BigInt(v)<=2147483647n;
 const target=state.target;demand(target&&integer(target.id,1)&&typeof target.order_no==='string');
 if(view==='customer_list'){keys(q,['type','page','limit']);demand(q.type==='9'&&q.limit==='20'&&positive(q.page));}
 else if(view==='merchant_list'){const allowed=['page','limit','order_id','paid'];demand(Object.keys(q).every(k=>allowed.includes(k))&&Object.hasOwn(q,'page')&&Object.hasOwn(q,'limit'));
  demand(positive(q.page)&&q.limit==='20'&&(!Object.hasOwn(q,'order_id')||q.order_id===target.order_no)&&(!Object.hasOwn(q,'paid')||mode.state==='test_paid'&&q.paid==='1'));}
 else if(view==='platform_list'){keys(q,['page','limit','status','pay_type','data','real_name','field_key','type']);demand(positive(q.page)&&q.limit==='15'&&['status','pay_type','data','real_name','field_key','type'].every(k=>q[k]===''));}
 else if(['customer_detail','platform_detail'].includes(view)){keys(q,['fulfillment_page','fulfillment_limit']);demand(positive(q.fulfillment_page)&&q.fulfillment_limit==='20'
  &&task2Route(r,b)===(view==='customer_detail'?'/api/order/detail/'+target.order_no:'/adminapi/order/info/'+target.id));}
 else if(view==='merchant_detail'){keys(q,['page','limit']);demand(positive(q.page)&&q.limit==='20'&&task2Route(r,b)==='/merchantapi/orders/'+target.id+'/packages');}
 else if(view==='customer_order_data')keys(q,[]);
 else if(view==='platform_order_chart'){keys(q,['data']);demand(q.data==='');}
}
function task2FullDto(value,actual) {
 keys(value,['encoding','expected_bytes','expected_sha256','actual_bytes','actual_sha256','strict_method','complete']);
 demand(value.encoding==='utf8-canonical-json-map-keys-sorted-list-order-preserved-v1'&&value.strict_method==='recursive-map-list-scalar-type-and-value'
  &&integer(value.expected_bytes)&&integer(value.actual_bytes)&&hex(value.expected_sha256)&&hex(value.actual_sha256)
  &&value.expected_bytes===value.actual_bytes&&value.expected_sha256===value.actual_sha256&&value.complete===true
  &&value.actual_bytes===actual.bytes&&value.actual_sha256===actual.sha256);
}
function task2SafeProjection(value,view) {
 keys(value,['view','selection','target','rows','private_display_digests']);demand(value.view===view);
 const statistics=['customer_order_data','platform_order_chart'].includes(view);
 keys(value.selection,['page','limit','count']);if(statistics)demand(equal(value.selection,{page:null,limit:null,count:1}));
 else Object.values(value.selection).forEach(n=>demand(integer(n)));
 keys(value.target,['root_id','order_no_sha256','in_page']);demand(integer(value.target.root_id,1)&&hex(value.target.order_no_sha256)&&typeof value.target.in_page==='boolean');
 demand(Array.isArray(value.rows)&&value.rows.length<=100&&Array.isArray(value.private_display_digests));
 if(statistics){demand(value.rows.length===1&&value.private_display_digests.length===0&&value.target.in_page===false);const r=value.rows[0];
  if(view==='platform_order_chart'){keys(r,['un_paid','un_send']);demand(integer(r.un_paid)&&integer(r.un_send));}
  else{const strings=['order_count','sum_price','unpaid_count','unshipped_count','received_count','evaluated_count','complete_count','refunding_count','no_refund_count','refunded_count','refund_count'];
   keys(r,[...strings,'yue_pay_status','pc_order_count','pay_weixin_open','ali_pay_status','friend_pay_status']);demand(strings.every(k=>typeof r[k]==='string')
    &&[1,2].includes(r.yue_pay_status)&&Number.isFinite(r.pc_order_count)&&typeof r.pc_order_count==='number'
    &&typeof r.pay_weixin_open==='boolean'&&typeof r.ali_pay_status==='boolean'&&Number.isSafeInteger(r.friend_pay_status));}return;}
 const monies=new Set(['total_price','pay_price','pay_postage','total_postage','refund_price','refund_pay_price','coupon_price','deduction_price','integral_price','gift_price','vip_true_price','levelPrice','memberPrice','postage_price','member_price']);
 for(const row of value.rows){const base=['root_id','order_no_sha256','marketplace_trade_version','paid','pay_type','status','is_cancel','money','items'];
  keys(row,Object.hasOwn(row,'pay_time')?[...base,'pay_time']:base);demand(integer(row.root_id,1)&&hex(row.order_no_sha256)&&[null,0,1].includes(row.marketplace_trade_version)
   &&[0,1].includes(row.paid)&&typeof row.pay_type==='string'&&Number.isSafeInteger(row.status)&&[0,1].includes(row.is_cancel)&&record(row.money)&&Array.isArray(row.items));
  if(Object.hasOwn(row,'pay_time'))demand(!view.startsWith('merchant')&&integer(row.pay_time));
  for(const[k,m]of Object.entries(row.money))demand(monies.has(k)&&decimal(m));
  for(const item of row.items){keys(item,['id','product_id','product_name','sku_name','image','quantity','unit_price','line_price','postage_price']);
   demand(integer(item.id,1)&&integer(item.product_id,1)&&typeof item.product_name==='string'&&typeof item.sku_name==='string'&&typeof item.image==='string'
    &&integer(item.quantity,1)&&[item.unit_price,item.line_price,item.postage_price].every(decimal));}
 }
 const invoice=['id','invoice_id','category','is_invoice','is_pay','header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number','remark','invoice_number','invoice_time','invoice_image','add_time'];
 value.private_display_digests.forEach((d,index)=>{keys(d,['kind','index','value_sha256','visible_required']);const visible=/^recipient\.(?:real_name|user_phone|user_address)$/.test(d.kind)||/^custom_form\.[1-9][0-9]*\.(?:0|[1-9][0-9]*)$/.test(d.kind);
  const stateKind=/^(?:order_state|fulfillment_notice|payment_status|cancel_status|pay_type|pay_time)\.[1-9][0-9]*$/.test(d.kind),metadata=['status.message','status.title','payment.time'].includes(d.kind);
  demand((visible||stateKind||metadata||['mark','remark','gift_mark','merchant_after_sale_contact_snapshot','nickname','avatar','trade_no','code','verify_code'].includes(d.kind)||invoice.some(k=>d.kind==='invoice.'+k))
   &&d.index===index&&hex(d.value_sha256)&&typeof d.visible_required==='boolean'&&(!visible||d.visible_required===view.endsWith('_detail'))&&(!metadata||d.visible_required===false));});
}
function task2Preservation(value,b,source,state){
 keys(value,['source','predecessors','private_inputs','public_materials','observer_tools']);
 const stat=v=>{keys(v,['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);Object.values(v).forEach(n=>demand(integer(n)));};
 const file=v=>{keys(v,['descriptor','stat']);descriptor(v.descriptor,true);stat(v.stat);demand(v.stat.nlink===1&&v.stat.size===v.descriptor.bytes);};
 keys(value.source,['core_files','artifact_files','proofs']);keys(value.source.core_files,['count','sha256']);
 demand(equal(value.source.core_files,{count:state.sourceRows.length,sha256:wireHash(state.sourceRows)})&&equal(value.source.artifact_files,state.artifactRows));
 keys(value.source.proofs,['context','freeze','binding','current','network','gateway_created','gateway_current','handoff','ready']);Object.values(value.source.proofs).forEach(file);
 demand(value.source.proofs.context.descriptor.sha256===b.context_sha256&&value.source.proofs.freeze.descriptor.sha256===b.freeze_sha256
  &&value.source.proofs.ready.descriptor.sha256===b.ready_sha256);
 demand(record(value.predecessors)&&Object.keys(value.predecessors).length>0);
 for(const[name,v]of Object.entries(value.predecessors)){demand(/^[A-Za-z0-9_.-]+$/.test(name));file(v);demand(v.descriptor.path==='/run/audit/evidence/'+name);}
 keys(value.private_inputs,['writer']);const writer=value.private_inputs.writer;
 keys(writer,['path','bytes','sha256','mode','uid','gid','dev','ino','nlink','directory_mode']);descriptor({path:writer.path,bytes:writer.bytes,sha256:writer.sha256},true);
 demand(writer.nlink===1&&writer.mode==='0600'&&writer.directory_mode==='0700'&&['uid','gid','dev','ino'].every(k=>integer(writer[k])));
 keys(value.public_materials,['materials','builds']);file(value.public_materials.materials);demand(value.public_materials.materials.descriptor.path==='/run/audit/evidence/ui-public-materials.json');
 keys(value.public_materials.builds,['h5','merchant','admin']);for(const[name,v]of Object.entries(value.public_materials.builds)){file(v);demand(v.descriptor.path==='/run/ms4-prep/manifests/ui-'+name+'-dist.json');}
 const tools=value.observer_tools;keys(tools,['observer_source','tool_tree','copy','browser_source']);demand(equal(tools.observer_source,source.observer_source));
 keys(tools.tool_tree,['root','files']);keys(tools.tool_tree.root,['path','stat']);demand(tools.tool_tree.root.path==='/run/audit/task2-observer');stat(tools.tool_tree.root.stat);
 demand(Array.isArray(tools.tool_tree.files)&&tools.tool_tree.files.length===3);
 tools.tool_tree.files.forEach((v,i)=>{file(v);const member=source.observer_source.members[i];demand(equal(v.descriptor,{path:'/run/audit/task2-observer/'+member.path,bytes:member.bytes,sha256:member.sha256}));});
 const browser=tools.browser_source;keys(browser,['source','manifest','members']);demand(equal(browser.source,state.browserSource));file(browser.manifest);demand(browser.manifest.descriptor.path==='/run/ms4-browser-source/PINS.json'&&equal({bytes:browser.manifest.descriptor.bytes,sha256:browser.manifest.descriptor.sha256},state.browserManifest));
 keys(browser.members,task2AuxNames);for(const member of state.browserSource.members){const v=browser.members[member.path];file(v);demand(equal(v.descriptor,{path:'/run/ms4-browser-source/'+member.path,bytes:member.bytes,sha256:member.sha256}));}
 keys(tools.copy,['descriptor','stat','receipt']);file({descriptor:tools.copy.descriptor,stat:tools.copy.stat});demand(tools.copy.descriptor.path==='/run/audit/evidence/task2-observer-copy.json'
  &&equal(tools.copy.receipt,state.copyReceipt)&&equal({bytes:tools.copy.descriptor.bytes,sha256:tools.copy.descriptor.sha256},state.copyPin));
}
function task2Input(input,index,b,mode,state) {
 ownJson(input);keys(input,['wire_public','private']);keys(input.wire_public,['wire','browser_event']);const w=input.wire_public.wire,e=input.wire_public.browser_event;
 keys(w,['actor_role','view','primary_sequences','request_set_sha256','response_set_sha256']);
 keys(e,['schema','scope','binding','index','name','role','tab_id_sha256','started_at_ms','finished_at_ms','actions','requests','identity','DOM','diagnostic']);
 demand(e.schema===1&&e.scope===mode.scope&&equal(e.binding,b)&&e.index===index&&e.name===TASK2_READ_STEPS[index-1]
  &&e.role===TASK2_READ_ROLES[index-1]&&w.actor_role===e.role&&w.view===TASK2_READ_VIEWS[index-1]&&hex(e.tab_id_sha256)
  &&integer(e.started_at_ms,1)&&integer(e.finished_at_ms,e.started_at_ms)&&Array.isArray(e.actions)&&e.actions.length>0
  &&Array.isArray(e.requests)&&e.requests.length>0&&e.DOM===null&&e.diagnostic===null);
 if(index<=3){demand(!Object.hasOwn(state.tabs,e.role)&&!Object.values(state.tabs).includes(e.tab_id_sha256));state.tabs[e.role]=e.tab_id_sha256;}
 demand(state.tabs[e.role]===e.tab_id_sha256);
 keys(e.identity,['application','subject_id','merchant_id','verified_by']);demand(e.identity.application===task2Apps[e.role]&&integer(e.identity.subject_id,1)
  &&(e.role==='merchant'?integer(e.identity.merchant_id,1):e.identity.merchant_id===null)&&e.identity.verified_by==='native-session-and-normal-UI');
 for(const action of e.actions){keys(action,['kind','target_public','tab_id_sha256','outcome']);demand(['navigate','click','fill','scroll','viewport','DOM-observe'].includes(action.kind)
  &&TASK2_ACTION_TARGETS.includes(action.target_public)&&action.outcome==='API_RETURNED'&&Object.values(state.tabs).includes(action.tab_id_sha256)
  &&(index===9||action.tab_id_sha256===e.tab_id_sha256));}
 const contents=new Map(),p=input.private;keys(p,['login_token','ticket_pairs','content_responses']);
 demand(Array.isArray(p.ticket_pairs)&&p.ticket_pairs.length===0&&Array.isArray(p.content_responses)&&p.content_responses.length<=16
  &&(index<=3?typeof p.login_token==='string'&&p.login_token.length>=32&&Buffer.byteLength(p.login_token)<=8192:p.login_token===null));
 for(const r of p.content_responses){keys(r,['sequence','headers','body_base64']);demand(integer(r.sequence)&&!contents.has(r.sequence)&&record(r.headers)
  &&Object.values(r.headers).every(v=>typeof v==='string')&&typeof r.body_base64==='string'&&Buffer.byteLength(r.body_base64)<=IN_MAX);
  const raw=Buffer.from(r.body_base64,'base64');try{demand(raw.toString('base64')===r.body_base64);const envelope=parse(raw);keys(envelope,['status','msg','data']);
   demand(envelope.status===200&&typeof envelope.msg==='string');contents.set(r.sequence,{body:pin(raw),data:pin(Buffer.from(canonical(envelope.data)))});
  }finally{raw.fill(0);}
 }
 const primary=[],dto=[];
 for(const[rIndex,r]of e.requests.entries()){
  keys(r,['sequence','method','path','query','capture_ref','started_at_ms','finished_at_ms','http_status','app_status','request_public','response_public','response_body','request_public_sha256','response_public_sha256','authentication','language_headers']);
  demand(r.sequence===rIndex&&['GET','POST'].includes(r.method)&&record(r.query)&&integer(r.started_at_ms,e.started_at_ms)&&integer(r.finished_at_ms,r.started_at_ms)
   &&r.finished_at_ms<=e.finished_at_ms&&r.http_status===200&&[200,null].includes(r.app_status)&&r.request_public_sha256===wireHash(r.request_public)&&r.response_public_sha256===wireHash(r.response_public));
  const route=task2Route(r,b);keys(r.language_headers,['cb-lang','accept-language']);
  for(const v of Object.values(r.language_headers))demand(v===null||(typeof v==='string'&&Buffer.byteLength(v)<=8192&&!/[\0\r\n]/.test(v)));
  keys(r.capture_ref,['tab_id_sha256','request_id_sha256','first_event_sequence','last_event_sequence']);
  const role=route.startsWith('/merchantapi/')?'merchant':route.startsWith('/adminapi/')?'platform':'customer';
  demand(r.capture_ref.tab_id_sha256===state.tabs[role]&&hex(r.capture_ref.request_id_sha256)&&integer(r.capture_ref.first_event_sequence)
   &&integer(r.capture_ref.last_event_sequence,r.capture_ref.first_event_sequence)&&(index===9||role===e.role));
  keys(r.response_body,['bytes','sha256','mime_type']);demand(integer(r.response_body.bytes)&&hex(r.response_body.sha256)&&typeof r.response_body.mime_type==='string');
  keys(r.authentication,['present','kind','subject_id']);demand(typeof r.authentication.present==='boolean'
   &&(r.authentication.present?r.authentication.kind==='Bearer'&&integer(r.authentication.subject_id,1):r.authentication.kind==='none'&&r.authentication.subject_id===null));
  if(task2Primary(r,index,b))primary.push(rIndex);
  const view=task2View(r,b);if(view!==null&&view!=='login'){task2QueryContract(r,view,b,mode,state);
   demand(r.response_public===null&&contents.has(rIndex)&&equal(contents.get(rIndex).body,{bytes:r.response_body.bytes,sha256:r.response_body.sha256}));dto.push(rIndex);
  }else demand(!contents.has(rIndex));
 }
 if(['customer_detail','merchant_detail','platform_detail'].includes(TASK2_READ_VIEWS[index-1])){const first=e.requests[primary[0]];
  demand(first&&first.query[TASK2_READ_VIEWS[index-1]==='merchant_detail'?'page':'fulfillment_page']==='1');}
 demand(contents.size===dto.length&&primary.length>0&&(index>3||primary.length===1)&&equal(w.primary_sequences,primary)&&equal(task2SetDigests(e.requests,primary),
  {request_set_sha256:w.request_set_sha256,response_set_sha256:w.response_set_sha256}));
 return {wire_public:copy(input.wire_public),dto:dto.map(sequence=>({sequence,data:contents.get(sequence).data})),login_observed:p.login_token!==null};
}
function task2Receipt(frame,pending,b,mode,source,state) {
 keys(frame,['schema','scope','binding','sequence','command','step','receipt','observer_source']);
 demand(frame.schema===1&&frame.scope==='MS5_TASK2_OBSERVER_SAFE_FRAME'&&equal(frame.binding,b)&&equal(frame.observer_source,source.observer_source)
  &&frame.sequence===pending.sequence&&frame.command===pending.command&&frame.step===pending.step);const r=frame.receipt,i=frame.step;
 if(frame.command==='BEGIN'){keys(r,['baseline']);ledger(r.baseline,b,0,'baseline');return;}
 if(frame.command==='STEP_BEGIN'){keys(r,['before']);ledger(r.before,b,i,'before');return;}
 if(frame.command==='FINISH'){keys(r,['final','preservation']);ledger(r.final,b,10,'final');task2Preservation(r.preservation,b,source,state);return;}
 keys(r,['after','verified_after','expected_delta','oracle']);ledger(r.after,b,i,'after');ledger(r.verified_after,b,i,'verified');const v=r.expected_delta;
 keys(v,['schema','scope','name','tables','independent','all_other_database_redis_files_verified','full_scope_acceptance']);
 demand(v.schema===1&&v.scope==='MS5_TASK2_INDEPENDENT_READ_CASE_EXPECTATION'&&v.name===TASK2_READ_STEPS[i-1]&&record(v.tables)
  &&v.all_other_database_redis_files_verified===true&&v.full_scope_acceptance==='NATIVE_ONLY');
 for(const[t,x]of Object.entries(v.tables)){demand(/^[A-Za-z0-9_]+$/.test(t));keys(x,['projection','natural_insert_count']);projection(x.projection);demand(integer(x.natural_insert_count));}
 const f=v.independent;keys(f,['login','reads','auxiliary_reads','state_coverage']);demand(Array.isArray(f.reads)&&Array.isArray(f.auxiliary_reads));
 if(i<=3){keys(f.login,['application','subject_id','native_session_verified','signed_claims_verified','browser_token_join']);demand(f.login.application===task2Apps[TASK2_READ_ROLES[i-1]]
  &&f.login.subject_id===pending.wire_public.browser_event.identity.subject_id&&f.login.native_session_verified===true&&f.login.signed_claims_verified===true&&f.login.browser_token_join===true&&pending.login_observed);state.actors[TASK2_READ_ROLES[i-1]]=f.login.subject_id;}
 else demand(f.login===null);
 const reads=[...f.reads,...f.auxiliary_reads.filter(x=>x.kind!=='normal-logout')],seen=new Set();
 for(const x of reads){keys(x,['sequence','view','query','capture_ref','response_body','full_dto','safe_projection','identity']);const q=pending.wire_public.browser_event.requests[x.sequence];
  demand(q&&!seen.has(x.sequence)&&x.view===task2View(q,b)&&equal(x.query,q.query)&&equal(x.capture_ref,q.capture_ref)&&equal(x.response_body,q.response_body)
   &&equal(x.identity,q.authentication));seen.add(x.sequence);const observed=pending.dto.find(z=>z.sequence===x.sequence);demand(observed);task2FullDto(x.full_dto,observed.data);
  task2SafeProjection(x.safe_projection,x.view);
 }
 demand(equal([...seen].sort((a,b)=>a-b),pending.dto.map(z=>z.sequence).sort((a,b)=>a-b)));
 const c=f.state_coverage;keys(c,['state','scenario','target','original_paid_target','views_verified']);
 demand(c.state===mode.state&&c.scenario===mode.scenario&&Array.isArray(c.views_verified)&&new Set(c.views_verified).size===c.views_verified.length
  &&c.views_verified.every(x=>TASK2_READ_VIEWS.slice(3).includes(x)));
 demand(equal(c.target,{root_id:state.target.id,order_no_sha256:sha(state.target.order_no),uid:state.target.uid,mer_id:state.target.mer_id})
  &&equal(c.original_paid_target,{root_id:state.original.id,order_no_sha256:sha(state.original.order_no),uid:state.original.uid,mer_id:state.original.mer_id}));
 for(const target of [c.target,c.original_paid_target]){keys(target,['root_id','order_no_sha256','uid','mer_id']);demand(integer(target.root_id,1)&&hex(target.order_no_sha256)&&integer(target.uid,1)&&integer(target.mer_id,1));}
 const out=f.auxiliary_reads.filter(x=>x.kind==='normal-logout');demand(i===9?out.length===3:out.length===0);
 for(const x of out){keys(x,['kind','sequence','application','subject_id','native_session_removed','issued_login_token_join','subsequent_requests_anonymous']);
  const requests=pending.wire_public.browser_event.requests,q=requests[x.sequence],role={api:'customer',merchantapi:'merchant',adminapi:'platform'}[x.application];
  const endpoints={api:['GET','/api/logout'],merchantapi:['POST','/merchantapi/logout'],adminapi:['GET','/adminapi/setting/admin/logout']};
  demand(q&&role&&q.method===endpoints[x.application][0]&&task2Route(q,b)===endpoints[x.application][1]&&integer(x.subject_id,1)
   &&x.subject_id===state.actors[role]&&q.authentication.present===true&&q.authentication.subject_id===x.subject_id
   &&q.capture_ref.tab_id_sha256===state.tabs[role]&&x.native_session_removed===true&&x.issued_login_token_join===true&&x.subsequent_requests_anonymous===true);
  for(const later of requests.slice(x.sequence+1))if(later.capture_ref.tab_id_sha256===state.tabs[role])demand(later.authentication.present===false&&task2View(later,b)===null);}
 if(i===9)demand(equal(c.views_verified,TASK2_READ_VIEWS.slice(3))&&new Set(out.map(x=>x.application)).size===3);
 if(i===9){const requests=pending.wire_public.browser_event.requests,first=Math.min(...out.map(x=>requests[x.sequence].started_at_ms));
  demand(pending.dto.every(x=>requests[x.sequence].finished_at_ms<=first));}
 keys(r.oracle,['source','case_name','expected','actual','readback','observer_source_sha256']);descriptor(r.oracle.source,true);
 const expected=source.files.find(x=>x.path==='/run/audit/task2-observer/expected.php');demand(expected&&equal(r.oracle.source,expected)&&r.oracle.case_name===TASK2_READ_STEPS[i-1]
  &&equal(r.oracle.expected,v)&&equal(r.oracle.actual,r.after)&&equal(r.oracle.readback,r.verified_after)&&r.oracle.observer_source_sha256===wireHash(source.observer_source));
}
function task2LedgerJoin(previous,current) {
 demand(equal(previous.binding,current.binding)&&equal(previous.database,current.database)&&equal(previous.redis.keys,current.redis.keys)&&equal(previous.files,current.files));
}

function task2Names(mode){return {created:mode.prefix+'-observer-created.json',terminal:mode.prefix+'-observer-terminal.json',stdout:mode.prefix+'-observer.stdout',stderr:mode.prefix+'-observer.stderr'};}
export class UiTask2ObserverBridge {
  #config; #binding; #directory; #sourceBinding = null; #child = null; #pid = null; #created = null; #phase = 'NEW';
  #fault = null; #busy = false; #writeBusy = false; #pending = null; #verified = 0; #responses = [];
  #stdout = []; #stdoutBytes = 0; #stdoutHash = createHash('sha256'); #stdoutEnd = false; #stdoutValid = true;
  #line = Buffer.alloc(0); #offset = 0; #stderr = []; #stderrBytes = 0; #stderrHash = createHash('sha256'); #stderrEnd = false;
  #closed = false; #code; #signal; #digests = null; #published = null; #publishAttempted = false;
  #eofSent=false; #normal = {tabs:{},actors:{},last:null}; #mode; #observer; #predecessor;
  constructor(config) {
    keys(config,['context_path','binding','observer_source','argv_mode','predecessor']);
    demand(typeof config.context_path==='string'&&path.isAbsolute(config.context_path)&&Object.hasOwn(TASK2_READ_MODES,config.argv_mode));
    binding(config.binding);task2Source(config.observer_source,config.binding);this.#config=copy({...config,predecessor:null});
    this.#binding=this.#config.binding;this.#mode=TASK2_READ_MODES[config.argv_mode];this.#observer=copy(config.observer_source);this.#predecessor=config.predecessor;
    const order=task2Modes.indexOf(config.argv_mode);demand(order===0?this.#predecessor===null:Object.getPrototypeOf(this.#predecessor)===UiTask2ObserverBridge.prototype);
    if(order>0)demand(this.#predecessor.#config.argv_mode===task2Modes[order-1]&&equal(this.#predecessor.#binding,this.#binding)&&equal(this.#predecessor.#observer,this.#observer));
  }
  #bad(code) { if (this.#fault === null) this.#fault = code; this.#phase = 'UNPROVED'; }
  async #source() {
    const raw=await ordinary(this.#config.context_path,33554432),c=metadata(raw),b=this.#binding;
    demand(c.run_id===b.run_id&&c.source_sha===b.source_sha&&c.freeze_sha256===b.freeze_sha256&&path.isAbsolute(c.run_dir)
      &&path.basename(path.resolve(c.run_dir))===b.run_id&&path.resolve(this.#config.context_path)===path.resolve(c.run_dir,'actual-run-context.json')&&equal(pin(raw),b.source_closure.context));
    const frozenRaw=await ordinary(path.join(c.run_dir,'preparation-source-binding.json'),67108864),f=metadata(frozenRaw);
    demand(equal(pin(frozenRaw),b.source_closure.freeze)&&f.source_sha===b.source_sha&&f.status==='CLEAN_SOURCE_FROZEN_ONLY'
      &&f.ui_web_origin===b.origin&&equal(f.task2_observer_source,this.#observer)&&Array.isArray(f.artifact_files)&&equal(f.artifact_files.map(r=>r.path),artifacts));
    const map={};for(const r of f.artifact_files){keys(r,['path','bytes','sha256']);map[r.path]={bytes:r.bytes,sha256:r.sha256};}
    demand(equal(map,b.source_closure.artifacts)&&Array.isArray(f.ui_backend_source_files));
    const files=[];
    for(const name of Object.keys(phpPins)){
      const rows=f.ui_backend_source_files.filter(r=>r.path===name);demand(rows.length===1);keys(rows[0],['path','bytes','sha256']);
      demand(integer(rows[0].bytes,1)&&hex(rows[0].sha256)&&equal(pin(await ordinary(path.join(c.repo_dir,name))),{bytes:rows[0].bytes,sha256:rows[0].sha256}));
      files.push({path:'/workspace/'+name,bytes:rows[0].bytes,sha256:rows[0].sha256});
    }
    const copyDescriptor=c.task2_observer_copy;keys(copyDescriptor,['path','bytes','sha256']);demand(copyDescriptor.path==='evidence/task2-observer-copy.json');
    const copyRaw=await ordinary(path.join(c.run_dir,copyDescriptor.path)),receipt=metadata(copyRaw);
    demand(equal(pin(copyRaw),{bytes:copyDescriptor.bytes,sha256:copyDescriptor.sha256}));
    keys(receipt,['schema','scope','run_id','source_sha','freeze_sha256','observer_source_sha256','originals','copies']);
    demand(receipt.schema===1&&receipt.scope==='MS5_TASK2_OBSERVER_SOURCE_COPY'&&receipt.run_id===b.run_id&&receipt.source_sha===b.source_sha
      &&receipt.freeze_sha256===b.freeze_sha256&&receipt.observer_source_sha256===wireHash(this.#observer)&&Array.isArray(receipt.originals)&&receipt.originals.length===3&&Array.isArray(receipt.copies)&&receipt.copies.length===3);
    const directory=path.join(c.run_dir,'task2-observer'),actual=(await fs.readdir(directory)).sort();demand(equal(actual,['entry.php','expected.php','gate.php']));
    for(const[rIndex,member]of this.#observer.members.entries()){
      const original=receipt.originals[rIndex],copied=receipt.copies[rIndex];keys(original,['path','bytes','sha256']);keys(copied,['path','bytes','sha256']);
      demand(path.resolve(original.path)===path.resolve(this.#observer.host_source_root,member.path)&&copied.path==='task2-observer/'+member.path
        &&equal({bytes:original.bytes,sha256:original.sha256},{bytes:member.bytes,sha256:member.sha256})&&equal({bytes:copied.bytes,sha256:copied.sha256},{bytes:member.bytes,sha256:member.sha256}));
      for(const name of [original.path,path.join(c.run_dir,copied.path)])demand(equal(pin(await ordinary(name)),{bytes:member.bytes,sha256:member.sha256}));
      files.push({path:this.#observer.container_root+'/'+member.path,bytes:member.bytes,sha256:member.sha256});
    }
    const readyRaw=await ordinary(path.join(c.run_dir,'evidence/ui-web-control-ui-backend-ready.json'));demand(sha(readyRaw)===b.ready_sha256);
    const prepared=metadata(await ordinary(path.join(c.run_dir,'evidence/ms5-task2-target-preparation.json')));
    keys(prepared,['schema','scope','status','binding','observer_source','actors','paid','unpaid','cancelled','window','preservation','diagnostic']);
    demand(prepared.schema===1&&prepared.scope==='MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY'&&prepared.status==='VERIFIED_MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY'
      &&prepared.binding.run_id===b.run_id&&prepared.binding.source_sha===b.source_sha&&prepared.binding.freeze_sha256===b.freeze_sha256&&equal(prepared.observer_source,this.#observer)&&prepared.diagnostic===null);
    const stateName={test_paid:'paid',unpaid:'unpaid',cancelled:'cancelled'}[this.#mode.state],target=prepared[stateName].target;
    keys(target,['id','order_no','uid','mer_id']);demand(integer(target.id,1)&&/^cp[1-9][0-9]*$/.test(target.order_no)&&integer(target.uid,1)&&integer(target.mer_id,1));
    this.#normal.target=copy(target);this.#normal.original=copy(prepared.paid.target);
    const browser=f.task2_browser_source;keys(browser,['schema','scope','members']);demand(browser.schema===1&&browser.scope==='MS5_TASK2_BROWSER_SOURCE_ONLY'&&Array.isArray(browser.members)&&equal(browser.members.map(r=>r.path),task2AuxNames));
    const ownFolder=path.dirname(task2FileURLToPath(import.meta.url)),roots=[ownFolder,path.join(path.dirname(c.prep_dir),'browser-v1')];let manifestPin=null;
    for(const root of roots){demand(equal((await fs.readdir(root)).sort(),['PINS.json','reader',...task2AuxNames.filter(n=>!n.includes('/'))].sort())&&equal(await fs.readdir(path.join(root,'reader')),['ms4-ui-private-reader.mjs']));
      const manifestRaw=await ordinary(path.join(root,'PINS.json')),manifest=metadata(manifestRaw);keys(manifest,['members']);keys(manifest.members,task2AuxNames);
      if(manifestPin===null)manifestPin=pin(manifestRaw);else demand(equal(manifestPin,pin(manifestRaw)));
      for(const member of browser.members){keys(member,['path','bytes','sha256']);demand(integer(member.bytes,1)&&hex(member.sha256)&&equal(manifest.members[member.path],{bytes:member.bytes,sha256:member.sha256})&&equal(pin(await ordinary(path.join(root,...member.path.split('/')))),{bytes:member.bytes,sha256:member.sha256}));}
    }
    this.#normal.browserSource=copy(browser);this.#normal.browserManifest=manifestPin;

    this.#normal.sourceRows=copy(f.ui_backend_source_files);this.#normal.artifactRows=copy(f.artifact_files);
    this.#normal.copyReceipt=copy(receipt);this.#normal.copyPin=pin(copyRaw);

    this.#directory=path.join(c.run_dir,'evidence');const d=await fs.lstat(this.#directory);demand(d.isDirectory()&&!d.isSymbolicLink());
    for(const name of Object.values(task2Names(this.#mode))){let absent=false;try{await fs.lstat(path.join(this.#directory,name));}catch(e){if(e.code==='ENOENT')absent=true;else throw e;}demand(absent);}
    if(this.#predecessor!==null)this.#predecessor.completedLedger(this.#binding,this.#config.argv_mode);
    this.#sourceBinding={schema:1,scope:'MS5_TASK2_FROZEN_PHP_SOURCE_BINDING',binding:copy(b),files,observer_source:copy(this.#observer),scenario:this.#mode.scenario};
  }
  sourceBinding() {
    demand(this.#sourceBinding !== null); return copy(this.#sourceBinding);
  }
  async start() {
    task2Source(this.#observer,this.#binding);
    demand(this.#phase === 'NEW' && !this.#busy); this.#busy = true; this.#phase = 'SOURCE_CHECK';
    try {
      await this.#source();
      const argv=['exec','-i',this.#binding.backend_cid,'php','/run/audit/task2-observer/entry.php',this.#config.argv_mode];
      const child = spawn('docker',argv,{shell:false,windowsHide:true,stdio:['pipe','pipe','pipe']});
      this.#child = child; this.#pid = Number.isSafeInteger(child.pid) && child.pid > 0 ? child.pid : null;
      child.once('error',() => this.#bad('SPAWN_ERROR'));
      child.stdin.on('error',() => this.#bad('STDIN_ERROR'));
      child.stdout.on('error',() => { this.#stdoutValid = false; this.#bad('STDOUT_ERROR'); });
      child.stderr.on('error',() => this.#bad('STDERR_ERROR'));
      child.stdout.on('data',part => this.#onStdout(part));
      child.stderr.on('data',part => {
        this.#stderrBytes += part.length; this.#stderrHash.update(part);
        const stored = this.#stderr.reduce((n,p) => n+p.length,0);
        if (stored+part.length <= ERROR_RAM_MAX) this.#stderr.push(Buffer.from(part));
        this.#bad('NONEMPTY_STDERR_WITHHELD');
      });
      child.stdout.once('end',() => { this.#stdoutEnd = true; });
      child.stderr.once('end',() => { this.#stderrEnd = true; });
      child.once('close',(code,signal) => {
        this.#code = code; this.#signal = signal; this.#closed = true;
        this.#digests = {stdout:this.#stdoutHash.digest('hex'),stderr:this.#stderrHash.digest('hex')};
        this.#finalizeClose();
      });
      demand(this.#pid !== null);
      this.#created = {label:this.#mode.prefix+'-observer',executable:'docker',argv,pid:this.#pid};
      await create(path.join(this.#directory,task2Names(this.#mode).created),Buffer.from(JSON.stringify(this.#created)+'\n'));
      if (this.#fault === null && !this.#closed) this.#phase = 'STARTED';
      return this.poll();
    } catch { this.#bad('START_UNPROVED'); throw new Error('MS4_UI_OBSERVER_BRIDGE_START_UNPROVED'); }
    finally { this.#busy = false; }
  }
  #finalizeClose() {
    if (!this.#closed || this.#writeBusy) return;
    if (!this.#stdoutEnd || !this.#stderrEnd || this.#line.length !== 0 || this.#pending
      || this.#responses.length !== 2*TASK2_READ_STEPS.length+2 || this.#phase !== 'FINISH_ACK'
      || !this.#eofSent || this.#code !== 0 || this.#signal !== null) this.#bad('NATIVE_OR_PROTOCOL_INCOMPLETE');
    else if (this.#fault === null) this.#phase = 'NATURAL_ZERO_COMPLETE_PROTOCOL';
  }
  #onStdout(part) {
    this.#stdoutBytes += part.length; this.#stdoutHash.update(part);
    if (this.#stdoutBytes > OUT_MAX) { this.#stdoutValid = false; this.#bad('STDOUT_LIMIT'); return; }
    this.#stdout.push(Buffer.from(part)); if (!this.#stdoutValid) return;
    this.#line = Buffer.concat([this.#line,part]);
    try {
      while (true) {
        const end = this.#line.indexOf(10); if (end < 0) break;
        const raw = this.#line.subarray(0,end+1);
        demand(raw.length <= LINE_MAX && !raw.includes(0) && this.#pending);
        const frame = parse(raw); demand(Buffer.from(canonical(frame)+'\n').equals(raw));
        task2Receipt(frame,this.#pending,this.#binding,this.#mode,this.#sourceBinding,this.#normal);
        const current = this.#pending;
        if(frame.command==='BEGIN'){
          if(this.#predecessor!==null)task2LedgerJoin(this.#predecessor.completedLedger(this.#binding,this.#config.argv_mode),frame.receipt.baseline);
          this.#normal.last=copy(frame.receipt.baseline);
        }else if(frame.command==='STEP_BEGIN'){task2LedgerJoin(this.#normal.last,frame.receipt.before);this.#normal.last=copy(frame.receipt.before);}
        else if(frame.command==='STEP_VERIFY'){task2LedgerJoin(frame.receipt.after,frame.receipt.verified_after);this.#normal.last=copy(frame.receipt.verified_after);}
        else{task2LedgerJoin(this.#normal.last,frame.receipt.final);this.#normal.last=copy(frame.receipt.final);}
        this.#responses.push({sequence:frame.sequence,command:frame.command,step:frame.step,
          request_sha256:current.public_sha256,response_sha256:sha(raw),stdout_start:this.#offset,stdout_end:this.#offset+raw.length,
          receipt:frame.receipt});
        this.#offset += raw.length; this.#line = Buffer.from(this.#line.subarray(end+1)); this.#pending = null;
        if (frame.command === 'BEGIN') this.#phase = 'BEGIN_ACK';
        else if (frame.command === 'STEP_BEGIN') this.#phase = 'STEP_READY';
        else if (frame.command === 'STEP_VERIFY') { this.#verified = frame.step; this.#phase = 'STEP_VERIFIED'; }
        else this.#phase = 'FINISH_ACK';
        this.#finishEof();
      }
      demand(this.#line.length < LINE_MAX);
    } catch { this.#stdoutValid = false; this.#bad('UNPROVED_PUBLIC_FRAME'); }
  }
  #send(command,step,sequence,wire_public = null,privateValue = null,validationData = null) {
    demand(!this.#busy && !this.#writeBusy && !this.#pending && !this.#closed && this.#fault === null && this.#child && this.#created);
    const publicFrame={schema:1,binding:this.#binding,sequence,command,step,wire_public,observer_source:this.#observer};
    const frame = {...publicFrame,private:privateValue}; ownJson(frame);
    const raw = Buffer.from(JSON.stringify(frame)+'\n');
    if (raw.length > IN_MAX || raw.includes(0)) { raw.fill(0); this.#bad('STDIN_LIMIT'); fail(); }
    this.#pending = {command,step,sequence,public_sha256:sha(Buffer.from(canonical(publicFrame))),
      wire_public:wire_public === null ? null : copy(wire_public),
      ...(validationData === null ? {} : copy(validationData))};
    this.#writeBusy = true; this.#phase = 'AWAIT_RESPONSE';
    try {
      const written = error => {
        raw.fill(0); this.#writeBusy = false; if (error) this.#bad('STDIN_WRITE_UNPROVED');
        this.#finishEof(); this.#finalizeClose();
      };
      this.#child.stdin.write(raw,written);
    } catch { raw.fill(0); this.#writeBusy = false; this.#bad('STDIN_WRITE_UNPROVED'); fail(); }
    return this.poll();
  }
  #finishEof() {
    if(this.#phase!=='FINISH_ACK'||this.#writeBusy||this.#eofSent)return;
    demand(this.#responses.length===20&&!this.#pending&&this.#fault===null&&!this.#closed);this.#eofSent=true;
    try{this.#child.stdin.end(error=>{if(error)this.#bad('STDIN_EOF_UNPROVED');});}catch{this.#bad('STDIN_EOF_UNPROVED');}
  }
  begin() { demand(this.#phase === 'STARTED'); return this.#send('BEGIN',0,0); }
  stepBegin() {
    demand(['BEGIN_ACK','STEP_VERIFIED'].includes(this.#phase) && this.#verified < TASK2_READ_STEPS.length);
    const i = this.#verified+1; return this.#send('STEP_BEGIN',i,2*i-1);
  }
  stepVerify(input) {
    demand(this.#phase === 'STEP_READY'); const i = this.#verified+1;
    const validationData = task2Input(input,i,this.#binding,this.#mode,this.#normal);
    demand(equal(input.wire_public.browser_event.binding,this.#binding));
    return this.#send('STEP_VERIFY',i,2*i,copy(input.wire_public),input.private === null ? null : copy(input.private),validationData);
  }
  finish() { demand(this.#phase === 'STEP_VERIFIED' && this.#verified === TASK2_READ_STEPS.length);
    return this.#send('FINISH',TASK2_READ_STEPS.length+1,2*TASK2_READ_STEPS.length+1); }
  poll() {
    return {phase:this.#phase,actual_pid:this.#pid,closed:this.#closed,close_code:this.#closed ? this.#code : null,
      close_signal:this.#closed ? this.#signal : null,verified_steps:this.#verified,required_steps:TASK2_READ_STEPS.length,
      pending:this.#pending ? {command:this.#pending.command,sequence:this.#pending.sequence,step:this.#pending.step} : null,
      ready_for_UI_action:this.#phase === 'STEP_READY' && this.#fault === null && !this.#writeBusy,
      stdout_bytes:this.#stdoutBytes,stderr_bytes:this.#stderrBytes,diagnostic:this.#fault,
      native_complete:this.#phase === 'NATURAL_ZERO_COMPLETE_PROTOCOL',full_acceptance:'INCOMPLETE',
      PHP_checkpoint:'INITIAL_FROZEN_CORE_AND_THREE_TOOLS_NATIVE_GATES_REQUIRED',scenario:this.#mode.scenario,observer_source:copy(this.#observer),published:this.#published ? copy(this.#published) : null};
  }
  response(sequence) {
    demand(integer(sequence)); const row = this.#responses.find(r => r.sequence === sequence); demand(row); return copy(row);
  }
  completedLedger(b,nextMode) {
    demand(equal(b,this.#binding)&&this.#phase==='NATURAL_ZERO_COMPLETE_PROTOCOL'&&this.#closed&&this.#code===0&&this.#signal===null
      &&this.#published?.native_complete===true&&this.#responses.length===20&&this.#normal.last!==null);
    const expected=task2Modes.indexOf(this.#config.argv_mode)+1;
    demand(expected===3?nextMode==='ORIGINAL_MS4_UI20':nextMode===task2Modes[expected]);return copy(this.#normal.last);
  }
  async publish() {
    demand(this.#closed && this.#digests && this.#stdoutEnd && this.#stderrEnd && this.#created
      && !this.#busy && !this.#writeBusy && !this.#publishAttempted);
    this.#publishAttempted = true; this.#busy = true;
    try {
      let stdout = null, stderr = null;
      const safe = this.#stdoutValid && this.#line.length === 0 && this.#offset === this.#stdoutBytes;
      if (safe) {
        const raw = Buffer.concat(this.#stdout); demand(raw.length === this.#stdoutBytes && sha(raw) === this.#digests.stdout);
        stdout = await create(path.join(this.#directory,task2Names(this.#mode).stdout),raw);
      }
      if (this.#stderrBytes === 0) stderr = await create(path.join(this.#directory,task2Names(this.#mode).stderr),Buffer.alloc(0));
      const stored = (bytes,hash,d) => ({bytes,sha256:hash,stored_bytes:d?.bytes ?? 0,
        stored_sha256:d?.sha256 ?? sha(Buffer.alloc(0)),complete:d !== null && d.bytes === bytes && d.sha256 === hash});
      const terminal = {schema:1,scope:this.#mode.terminal,executable:this.#created.executable,
        argv:this.#created.argv,pid:this.#created.pid,close_code:this.#code,close_signal:this.#signal,
        stdout:stored(this.#stdoutBytes,this.#digests.stdout,stdout),stderr:stored(this.#stderrBytes,this.#digests.stderr,stderr)};
      const terminalDescriptor = await create(path.join(this.#directory,task2Names(this.#mode).terminal),Buffer.from(JSON.stringify(terminal)+'\n'));
      const completed = this.#phase === 'NATURAL_ZERO_COMPLETE_PROTOCOL' && terminal.stdout.complete && terminal.stderr.complete;
      this.#published = {created:{path:'/run/audit/evidence/'+task2Names(this.#mode).created,
        ...pin(Buffer.from(JSON.stringify(this.#created)+'\n'))},terminal:terminalDescriptor,stdout,stderr,
        native_complete:completed,diagnostic:this.#fault,full_acceptance:'INCOMPLETE'};
      return copy(this.#published);
    } catch { this.#bad('PUBLICATION_UNPROVED'); throw new Error('MS4_UI_OBSERVER_BRIDGE_PUBLICATION_UNPROVED'); }
    finally {
      this.#stdout.forEach(p => p.fill(0)); this.#stdout = [];
      this.#stderr.forEach(p => p.fill(0)); this.#stderr = []; this.#line.fill(0); this.#line = Buffer.alloc(0);
      this.#busy = false;
    }
  }
}
