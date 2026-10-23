// Source-only companion. Import has no filesystem, process or browser action.
// Root uses this exported UiPrivateCaller, so both objects share the genuine class/private fields.
import { UiPrivateCaller } from './ms4-ui-private-caller.mjs';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { isDeepStrictEqual as equal, TextDecoder } from 'node:util';
export { UiPrivateCaller };
const demand = ok => { if (!ok) throw new Error('MS4_UI_BROWSER_CLOSE_UNPROVED'); };
const sha = b => createHash('sha256').update(b).digest('hex');
const pin = b => ({ bytes: b.length, sha256: sha(b) });
const record = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const keys = (v, names) => demand(record(v) && equal(Object.keys(v).sort(), [...names].sort()));
const hex = (v, n = 64) => typeof v === 'string' && new RegExp('^[a-f0-9]{' + n + '}$').test(v);
const roles = ['customer', 'merchant', 'platform'];
const artifacts = ['compose.yml','interface-v1.json','ms4-host.py','ms4-initialize.php',
  'normal-close-ms4-fulfillment.py','README.md','seed-source-inverse.json','source-derivation.json',
  'verify-zero-ms4-fulfillment.py','ms4-ui-nginx.conf','ms4-ui-public-copy.php','ms4-ui-copy.php',
  'ms4-order.png','manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json',
  'manifests/ui-admin-dist.json','manifests/ui-public-copy-profile.json'];
const callerPin = { bytes: 21351, sha256: '0fc9c608b667292fa43a161b5af81f0d09d0361ac855777ef175f19700a41288' };
const readerPin = { bytes: 14107, sha256: '940d2a03103b26eab8766a1c50b38928f02d2eece2be1bd19fd1a6fac449fd3f' };
const stable = (a,b) => ['dev','ino','mode','nlink','size','mtimeMs','ctimeMs'].every(k => a[k] === b[k]);
const json = raw => { try { return JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(raw)); }
  catch { throw new Error('MS4_UI_BROWSER_CLOSE_UNPROVED'); } };
async function ordinary(name, maximum = 33554432) {
  demand(typeof name === 'string' && path.isAbsolute(name));
  for (let parent = path.dirname(name);;) {
    const s = await fs.lstat(parent); demand(s.isDirectory() && !s.isSymbolicLink());
    const next = path.dirname(parent); if (next === parent) break; parent = next;
  }
  const before = await fs.lstat(name);
  demand(before.isFile() && !before.isSymbolicLink() && before.nlink === 1 && before.size > 0 && before.size <= maximum);
  const f = await fs.open(name,'r'); let raw;
  try { demand(stable(before,await f.stat())); raw = await f.readFile();
    const extra = Buffer.alloc(1); demand((await f.read(extra,0,1,null)).bytesRead === 0
      && raw.length === before.size && stable(before,await f.stat())); }
  finally { await f.close(); }
  demand(stable(before,await fs.lstat(name))); return raw;
}
async function create(name, value) {
  const raw = Buffer.from(JSON.stringify(value) + '\n');
  const f = await fs.open(name,'wx',0o600);
  try { await f.writeFile(raw); await f.sync(); } finally { await f.close(); }
  demand((await ordinary(name)).equals(raw));
  return { path: '/run/audit/evidence/' + path.basename(name), ...pin(raw) };
}
async function sourceProof(config) {
  const contextRaw = await ordinary(config.context_path), context = json(contextRaw);
  demand(/^\d{14}[a-f0-9]{6}$/.test(context.run_id) && hex(context.source_sha,40) && hex(context.freeze_sha256)
    && path.isAbsolute(context.run_dir) && path.isAbsolute(context.prep_dir)
    && path.basename(path.resolve(context.run_dir)) === context.run_id
    && path.resolve(config.context_path) === path.resolve(context.run_dir,'actual-run-context.json'));
  const freezeRaw = await ordinary(path.join(context.run_dir,'preparation-source-binding.json')), freeze = json(freezeRaw);
  demand(sha(freezeRaw) === context.freeze_sha256 && freeze.status === 'CLEAN_SOURCE_FROZEN_ONLY'
    && freeze.source_sha === context.source_sha && Array.isArray(freeze.artifact_files)
    && equal(freeze.artifact_files.map(r => r.path),artifacts)
    && Number.isSafeInteger(freeze.ui_web_port) && freeze.ui_web_port > 1023 && freeze.ui_web_port <= 65535
    && freeze.ui_web_origin === 'http://127.0.0.1:' + freeze.ui_web_port);
  const actual = {};
  for (const row of freeze.artifact_files) {
    keys(row,['path','bytes','sha256']); const b = await ordinary(path.join(context.prep_dir,...row.path.split('/')));
    demand(equal(pin(b),{bytes:row.bytes,sha256:row.sha256})); actual[row.path] = pin(b);
  }
  const reader = pin(await ordinary(config.reader_path)); demand(equal(reader,readerPin));
  const caller = pin(await ordinary(fileURLToPath(new URL('./ms4-ui-private-caller.mjs',import.meta.url))));
  demand(equal(caller,callerPin)); const self = pin(await ordinary(fileURLToPath(import.meta.url)));
  const source_closure = { context:pin(contextRaw),freeze:pin(freezeRaw),artifacts:actual,reader,caller };
  const e = path.join(context.run_dir,'evidence'), handoffRaw = await ordinary(path.join(e,'ui-private-caller-handoff.json'));
  const handoff = json(handoffRaw);
  keys(handoff,['schema','scope','run_id','source_sha','freeze_sha256','container_id','source_closure',
    'reader_inputs','ready','host_gates','private_reader_returned_three_roles','form_login_business_DOM','full_acceptance']);
  demand(handoff.schema === 1 && handoff.scope === 'MS4_UI_PRIVATE_CALLER_HANDOFF_ONLY'
    && handoff.run_id === context.run_id && handoff.source_sha === context.source_sha
    && handoff.freeze_sha256 === context.freeze_sha256 && hex(handoff.container_id)
    && equal(handoff.source_closure,source_closure) && handoff.private_reader_returned_three_roles === true
    && handoff.form_login_business_DOM === 'NOT RUN' && handoff.full_acceptance === 'INCOMPLETE');
  for (const [field,name] of [['reader_inputs','ui-web-private-inputs.json'],['ready','ui-web-control-ui-backend-ready.json']]) {
    const d = handoff[field]; keys(d,['path','bytes','sha256']);
    demand(d.path === '/run/audit/evidence/' + name && equal(pin(await ordinary(path.join(e,name))),{bytes:d.bytes,sha256:d.sha256}));
  }
  const gateway = json(await ordinary(path.join(e,'ui-gateway-created.json')));
  demand(hex(gateway.id) && gateway.id !== handoff.container_id && gateway.run_id === context.run_id
    && gateway.source_sha === context.source_sha && gateway.proof_name === 'ui-gateway'
    && gateway.labels?.['com.docker.compose.service'] === 'audit-ms4-ui-gateway');
  source_closure.browser_close = self;
  const binding = { run_id:context.run_id,source_sha:context.source_sha,freeze_sha256:context.freeze_sha256,
    context_sha256:sha(contextRaw),backend_cid:handoff.container_id,gateway_cid:gateway.id,
    ready_sha256:handoff.ready.sha256,origin:freeze.ui_web_origin,source_closure };
  return { binding,evidence:e,handoff:pin(handoffRaw),producer:{path:'browser-v1/ms4-ui-browser-close.mjs',...self} };
}
function proveCallerBinding(caller, binding) {
  const c = binding.source_closure;
  const source_closure = { context:c.context,freeze:c.freeze,artifacts:c.artifacts,reader:c.reader,caller:c.caller };
  const expected = { run_id:binding.run_id,source_sha:binding.source_sha,freeze_sha256:binding.freeze_sha256,
    context_sha256:binding.context_sha256,backend_cid:binding.backend_cid,ready_sha256:binding.ready_sha256,
    origin:binding.origin,source_closure };
  demand(equal(UiPrivateCaller.prototype.publicBinding.call(caller),expected));
}
const publicRows = rows => [...rows].map(([id,url]) => ({ id_sha256:sha(id),
  url_kind:url === undefined ? 'undefined' : 'string',url_sha256:url === undefined ? null : sha(url) }))
  .sort((a,b) => a.id_sha256.localeCompare(b.id_sha256));

/** Only this object's newly created tabs are owned. Existing IDs and URLs remain private RAM. */
export class UiBrowserNormalClose {
  #browser; #caller; #config; #proof; #baseline; #owned = new Map(); #observations = [];
  #viewport = { sets:[],reset:null }; #viewportTouched = false; #resetStarted = false;
  #busy = false; #phase = 'NEW'; #released = false; #last = null;
  constructor({browser,caller,context_path,reader_path}) {
    demand(browser && typeof browser.browserId === 'string' && browser.tabs
      && Object.getPrototypeOf(caller) === UiPrivateCaller.prototype
      && typeof context_path === 'string' && path.isAbsolute(context_path)
      && typeof reader_path === 'string' && path.isAbsolute(reader_path));
    this.#browser = browser; this.#caller = caller; this.#config = {context_path,reader_path};
  }
  #enter(phase) { demand(!this.#busy && this.#phase === phase); this.#busy = true; }
  async #observe(event) {
    const first = await this.#browser.tabs.list();
    demand(Array.isArray(first) && first.every(t => typeof t.id === 'string' && t.id.length > 0));
    const ids = first.map(t => t.id); demand(new Set(ids).size === ids.length); const rows = new Map();
    for (const id of ids) { const tab = await this.#browser.tabs.get(id); demand(tab.id === id);
      const url = await tab.url(); demand(url === undefined || typeof url === 'string'); rows.set(id,url); }
    const second = await this.#browser.tabs.list();
    demand(Array.isArray(second) && second.length === ids.length
      && equal(second.map(t => t.id).sort(),[...ids].sort()));
    if (this.#baseline) {
      for (const [id,url] of this.#baseline) demand(rows.has(id) && rows.get(id) === url);
      const expected = new Set([...this.#baseline.keys(),...[...this.#owned.values()].filter(t => !t.closed).map(t => t.id)]);
      demand(equal([...rows.keys()].sort(),[...expected].sort()));
    }
    this.#observations.push({index:this.#observations.length,event,tabs:publicRows(rows)}); return rows;
  }
  async begin() {
    this.#enter('NEW');
    try { const state = UiPrivateCaller.prototype.status.call(this.#caller);
      demand(state.phase === 'PRIVATE_READY' && state.private_read === 'COMPLETED_BOTH_HOST_GATES' && !state.private_references_released);
      this.#proof = await sourceProof(this.#config); proveCallerBinding(this.#caller,this.#proof.binding);
      this.#baseline = await this.#observe('baseline');
      this.#phase = 'OPEN'; return this.status(); }
    catch { this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_BROWSER_CLOSE_UNPROVED'); }
    finally { this.#busy = false; }
  }
  async openOwned(role) {
    this.#enter('OPEN');
    try { demand(roles.includes(role) && !this.#owned.has(role)); await this.#observe('before_new_' + role);
      const tab = await this.#browser.tabs.new();
      demand(typeof tab.id === 'string' && tab.id.length > 0 && !this.#baseline.has(tab.id)
        && ![...this.#owned.values()].some(t => t.id === tab.id));
      this.#owned.set(role,{id:tab.id,closed:false}); await this.#observe('after_new_' + role); return tab; }
    catch { this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_BROWSER_CREATE_OUTCOME_UNKNOWN'); }
    finally { this.#busy = false; }
  }
  async fillRoleForm(role,selectors) {
    this.#enter('OPEN');
    try { const owned = this.#owned.get(role); demand(owned && !owned.closed);
      const tab = await this.#browser.tabs.get(owned.id); demand(tab.id === owned.id);
      return await UiPrivateCaller.prototype.fillRoleForm.call(this.#caller,tab,role,selectors); }
    catch { this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_PRIVATE_FORM_OUTCOME_UNKNOWN'); }
    finally { this.#busy = false; }
  }
  async setViewport(size) {
    this.#enter('OPEN');
    try { keys(size,['width','height']); demand([320,390,1440].includes(size.width)
      && Number.isSafeInteger(size.height) && size.height > 0 && size.height <= 16384);
      this.#viewportTouched = true;
      await (await this.#browser.capabilities.get('viewport')).set({width:size.width,height:size.height});
      this.#viewport.sets.push({width:size.width,height:size.height,api_returned:true});
      return {viewport_set_api_returned:true,DOM_size_and_layout:'NOT_PROVEN'}; }
    catch { this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_VIEWPORT_OUTCOME_UNKNOWN'); }
    finally { this.#busy = false; }
  }
  #release() {
    let result = null;
    try { if (this.#caller) result = UiPrivateCaller.prototype.forget.call(this.#caller);
      demand(result?.private_references_released === true && result.immutable_JS_strings_securely_erased === false);
      this.#released = true; return {caller_forget:result,companion_references_released:true,
        immutable_JS_strings_securely_erased:false,external_references:'NOT_ATTESTED'}; }
    finally { this.#caller = null; this.#browser = null; this.#config = null; this.#baseline = null;
      this.#owned.clear(); this.#proof = null; }
  }
  async close() {
    this.#enter('OPEN'); let result = null, failure = false, proof = null, references = null, actualAttempt = null;
    try {
      demand(equal([...this.#owned.keys()].sort(),[...roles].sort()));
      demand(equal([...new Set(this.#viewport.sets.map(s => s.width))].sort((a,b) => a-b),[320,390,1440]));
      proof = await sourceProof(this.#config); demand(equal(proof,this.#proof));
      proveCallerBinding(this.#caller,proof.binding);
      const baseline = publicRows(this.#baseline);
      const owned = [...this.#owned].map(([role,t]) => ({role,id_sha256:sha(t.id)})).sort((a,b) => a.role.localeCompare(b.role));
      await this.#observe('before_close');
      const attemptValue = {schema:1,scope:'MS4_UI_BROWSER_SINGLE_NORMAL_CLOSE_ATTEMPT',binding:proof.binding,baseline,owned,
        before_close:this.#observations[this.#observations.length-1],producer_source:proof.producer};
      const attempt = await create(path.join(proof.evidence,'ui-browser-normal-close-attempt.json'),attemptValue); actualAttempt = attemptValue;
      for (const role of [...roles].reverse()) {
        const t = this.#owned.get(role), tab = await this.#browser.tabs.get(t.id); demand(tab.id === t.id);
        await tab.close(); t.closed = true; // The following real list must prove absence; await alone is insufficient.
        await this.#observe('after_close_' + role);
      }
      if (this.#viewportTouched) {
        this.#resetStarted = true; await (await this.#browser.capabilities.get('viewport')).reset();
        this.#viewport.reset = {attempted:true,api_returned:true,restoration:'DEFAULT_ONLY_PREVIOUS_OVERRIDE_UNKNOWN'};
      } else this.#viewport.reset = {attempted:false,api_returned:false,restoration:'NO_OVERRIDE_SET_BY_COMPANION'};
      await this.#observe('after_viewport_reset');
      demand(equal(await sourceProof(this.#config),proof));
      result = {schema:1,scope:'MS4_UI_BROWSER_NORMAL_CLOSE_ONLY',
        status:'VERIFIED_MS4_UI_OWNED_TABS_CLOSED_DEFAULT_VIEWPORT',binding:proof.binding,attempt,baseline,owned,
        observations:this.#observations,viewport:this.#viewport,references:null,producer_source:proof.producer,
        authentication:'NOT_PROVEN',business_DOM:'NOT_VERIFIED_BY_BROWSER_CLOSE',full_acceptance:'INCOMPLETE'};
    } catch { failure = true; }
    finally {
      // A failed normal close is not retried. If a temporary override might remain, attempt reset once, then release.
      if (this.#viewportTouched && !this.#resetStarted && this.#browser) {
        this.#resetStarted = true;
        try { await (await this.#browser.capabilities.get('viewport')).reset(); }
        catch { failure = true; }
      }
      try { references = this.#release(); } catch { failure = true; }
    }
    if (failure || !result || !references) { this.#busy = false; this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_BROWSER_CLOSE_OUTCOME_UNKNOWN'); }
    result.references = references;
    try {
      verifyUiBrowserNormalCloseReceipt(result,actualAttempt); // Raw attempt must be joined by external consumer too.
      const descriptor = await create(path.join(proof.evidence,'ui-browser-normal-close.json'),result);
      this.#phase = 'CLOSED'; this.#last = {descriptor,binding:proof.binding};
      return {...this.#last,authentication:'NOT_PROVEN',business_DOM:'NOT_VERIFIED_BY_BROWSER_CLOSE'};
    } catch { this.#phase = 'INCOMPLETE'; throw new Error('MS4_UI_BROWSER_CLOSE_PUBLICATION_UNPROVED'); }
    finally { this.#busy = false; }
  }
  releasePrivateReferences() {
    demand(!this.#busy && this.#caller); const references = this.#release(); this.#phase = 'RELEASED_WITHOUT_CLOSE';
    return {references,browser_close:'NOT_PROVEN',authentication:'NOT_PROVEN',business_DOM:'NOT RUN'};
  }
  ownedTabIdentity(role) {
    demand(roles.includes(role)&&this.#phase==='OPEN'&&!this.#busy&&!this.#released);
    const owned=this.#owned.get(role);demand(owned&&!owned.closed&&typeof owned.id==='string'&&owned.id.length>0);
    return {role,tab_id_sha256:sha(owned.id)};
  }
  status() { return {phase:this.#phase,owned_roles:[...this.#owned.keys()].sort(),
    private_references_released:this.#released,authentication:'NOT_PROVEN',business_DOM:'NOT RUN',closed:this.#last}; }
}

/** Pure consumer. The caller first reads attempt and receipt as ordinary stable pinned raw files. */
export function verifyUiBrowserNormalCloseReceipt(value,attemptValue) {
  keys(value,['schema','scope','status','binding','attempt','baseline','owned','observations','viewport','references',
    'producer_source','authentication','business_DOM','full_acceptance']);
  demand(value.schema === 1 && value.scope === 'MS4_UI_BROWSER_NORMAL_CLOSE_ONLY'
    && value.status === 'VERIFIED_MS4_UI_OWNED_TABS_CLOSED_DEFAULT_VIEWPORT'
    && value.authentication === 'NOT_PROVEN' && value.business_DOM === 'NOT_VERIFIED_BY_BROWSER_CLOSE'
    && value.full_acceptance === 'INCOMPLETE');
  const b = value.binding;
  keys(b,['run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin','source_closure']);
  demand(typeof b.run_id === 'string' && /^\d{14}[a-f0-9]{6}$/.test(b.run_id) && hex(b.source_sha,40)
    && [b.freeze_sha256,b.context_sha256,b.backend_cid,b.gateway_cid,b.ready_sha256].every(v => hex(v))
    && b.backend_cid !== b.gateway_cid && /^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(b.origin));
  const port = Number(new URL(b.origin).port); demand(port > 1023 && port <= 65535);
  const c = b.source_closure; keys(c,['context','freeze','artifacts','reader','caller','browser_close']);
  for (const p of [c.context,c.freeze,c.reader,c.caller,c.browser_close]) { keys(p,['bytes','sha256']);
    demand(Number.isSafeInteger(p.bytes) && p.bytes > 0 && hex(p.sha256)); }
  demand(c.context.sha256 === b.context_sha256 && c.freeze.sha256 === b.freeze_sha256
    && equal(c.reader,readerPin) && equal(c.caller,callerPin));
  demand(record(c.artifacts) && equal(Object.keys(c.artifacts),artifacts));
  for (const p of Object.values(c.artifacts)) { keys(p,['bytes','sha256']); demand(Number.isSafeInteger(p.bytes) && p.bytes >= 0 && hex(p.sha256)); }
  demand(Array.isArray(value.owned) && value.owned.length === 3
    && equal(value.owned.map(t => t.role).sort(),[...roles].sort()));
  const owned = new Map();
  for (const t of value.owned) { keys(t,['role','id_sha256']); demand(hex(t.id_sha256)); owned.set(t.role,t.id_sha256); }
  demand(new Set(owned.values()).size === 3);
  const tabMap = rows => { demand(Array.isArray(rows)); const m = new Map();
    for (const r of rows) { keys(r,['id_sha256','url_kind','url_sha256']); demand(hex(r.id_sha256)
      && (r.url_kind === 'undefined' ? r.url_sha256 === null : r.url_kind === 'string' && hex(r.url_sha256)) && !m.has(r.id_sha256)); m.set(r.id_sha256,r); }
    demand(equal([...m.keys()],[...m.keys()].sort())); return m; };
  const baseline = tabMap(value.baseline); demand([...owned.values()].every(id => !baseline.has(id)));
  demand(Array.isArray(value.observations) && value.observations.length === 12); const byEvent = new Map();
  const events = new Set(['baseline','before_close','after_viewport_reset',...roles.flatMap(r => ['before_new_' + r,'after_new_' + r,'after_close_' + r])]);
  for (const [i,o] of value.observations.entries()) { keys(o,['index','event','tabs']); demand(o.index === i && events.has(o.event) && !byEvent.has(o.event));
    const m = tabMap(o.tabs); for (const [id,r] of baseline) demand(equal(m.get(id),r)); byEvent.set(o.event,{i,m}); }
  demand(equal(value.observations[0],{index:0,event:'baseline',tabs:value.baseline}));
  let available = new Set(baseline.keys());
  for (let i=1;i<=5;i+=2) {
    const before = value.observations[i], after = value.observations[i+1];
    demand(before.event.startsWith('before_new_')); const role = before.event.slice('before_new_'.length);
    demand(roles.includes(role) && after.event === 'after_new_' + role);
    demand(equal([...tabMap(before.tabs).keys()].sort(),[...available].sort()) && !available.has(owned.get(role)));
    available.add(owned.get(role)); demand(equal([...tabMap(after.tabs).keys()].sort(),[...available].sort()));
  }
  const before = byEvent.get('before_close'); demand(before?.i === 7 && before.m.size === baseline.size + 3);
  for (const id of owned.values()) demand(before.m.has(id));
  let last = before.i; const absent = new Set();
  for (const role of [...roles].reverse()) { const o = byEvent.get('after_close_' + role); demand(o && o.i === last+1); last = o.i;
    absent.add(owned.get(role)); for (const id of absent) demand(!o.m.has(id));
    const remaining = [...owned.values()].filter(id => !absent.has(id));
    demand(o.m.size === baseline.size + remaining.length && remaining.every(id => o.m.has(id))); }
  const end = byEvent.get('after_viewport_reset'); demand(end?.i === 11 && end.m.size === baseline.size);
  keys(value.viewport,['sets','reset']); demand(Array.isArray(value.viewport.sets));
  for (const s of value.viewport.sets) { keys(s,['width','height','api_returned']); demand([320,390,1440].includes(s.width)
    && Number.isSafeInteger(s.height) && s.height > 0 && s.height <= 16384 && s.api_returned === true); }
  demand(equal([...new Set(value.viewport.sets.map(s => s.width))].sort((a,b) => a-b),[320,390,1440]));
  const reset = value.viewport.reset; keys(reset,['attempted','api_returned','restoration']);
  demand(reset.attempted === true && reset.api_returned === true && reset.restoration === 'DEFAULT_ONLY_PREVIOUS_OVERRIDE_UNKNOWN');
  keys(value.references,['caller_forget','companion_references_released','immutable_JS_strings_securely_erased','external_references']);
  demand(equal(value.references.caller_forget,{private_references_released:true,immutable_JS_strings_securely_erased:false,browser_close:'NOT PROVEN',business_DOM:'NOT RUN'})
    && value.references.companion_references_released === true && value.references.immutable_JS_strings_securely_erased === false
    && value.references.external_references === 'NOT_ATTESTED');
  for (const d of [value.attempt,value.producer_source]) { keys(d,['path','bytes','sha256']);
    demand(typeof d.path === 'string' && Number.isSafeInteger(d.bytes) && d.bytes > 0 && hex(d.sha256)); }
  demand(value.attempt.path === '/run/audit/evidence/ui-browser-normal-close-attempt.json'
    && value.producer_source.path === 'browser-v1/ms4-ui-browser-close.mjs'
    && equal({bytes:value.producer_source.bytes,sha256:value.producer_source.sha256},c.browser_close));
  keys(attemptValue,['schema','scope','binding','baseline','owned','before_close','producer_source']);
  demand(attemptValue.schema === 1 && attemptValue.scope === 'MS4_UI_BROWSER_SINGLE_NORMAL_CLOSE_ATTEMPT'
    && equal(attemptValue.binding,b) && equal(attemptValue.baseline,value.baseline) && equal(attemptValue.owned,value.owned)
    && equal(attemptValue.before_close,value.observations[7]) && equal(attemptValue.producer_source,value.producer_source));
  return {scope:'PUBLIC_BROWSER_CLOSE_JOINS_ONLY',authentication:'NOT_PROVEN',business_DOM:'NOT_VERIFIED_BY_BROWSER_CLOSE',actual_runtime_provenance:'ROOT_STILL_REQUIRED'};
}
