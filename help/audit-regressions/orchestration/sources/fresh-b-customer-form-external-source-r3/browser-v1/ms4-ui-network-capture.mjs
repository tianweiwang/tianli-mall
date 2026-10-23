/** Passive normal traffic only. All returned data is PRIVATE RAM, including credentials. */
const METHODS = Object.freeze([
  'Network.requestWillBeSent', 'Network.requestWillBeSentExtraInfo',
  'Network.responseReceived', 'Network.responseReceivedExtraInfo',
  'Network.loadingFinished', 'Network.loadingFailed',
  'Network.requestServedFromCache'
]);
const MAX_JSON_BYTES = 268435456;
const MAX_EVENTS = 100000;
const encoder = new TextEncoder();

function requireValue(ok, code) { if (!ok) throw new Error(code); }
function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
function finite(value) { return typeof value === 'number' && Number.isFinite(value); }
function jsonCopy(value, depth = 0) {
  requireValue(depth <= 64, 'NETWORK_CAPTURE_JSON_DEPTH');
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return value;
  if (typeof value === 'number') { requireValue(Number.isFinite(value), 'NETWORK_CAPTURE_JSON_NUMBER'); return value; }
  requireValue(value !== null && typeof value === 'object', 'NETWORK_CAPTURE_JSON_ONLY');
  const descriptors = Object.getOwnPropertyDescriptors(value);
  if (Array.isArray(value)) {
    requireValue(Reflect.ownKeys(descriptors).length === value.length + 1, 'NETWORK_CAPTURE_DENSE_JSON_ARRAY');
    return Array.from({ length: value.length }, (_, i) => {
      const descriptor = descriptors[String(i)];
      requireValue(descriptor && 'value' in descriptor && descriptor.enumerable, 'NETWORK_CAPTURE_JSON_ARRAY_DATA');
      return jsonCopy(descriptor.value, depth + 1);
    });
  }
  requireValue(Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null,
    'NETWORK_CAPTURE_PLAIN_JSON_OBJECT');
  const result = Object.create(null);
  for (const key of Reflect.ownKeys(descriptors)) {
    const descriptor = descriptors[key];
    requireValue(typeof key === 'string' && descriptor.enumerable && 'value' in descriptor,
      'NETWORK_CAPTURE_JSON_OBJECT_DATA');
    result[key] = jsonCopy(descriptor.value, depth + 1);
  }
  return result;
}
function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (!object(value)) return value;
  const result = Object.create(null);
  for (const key of Object.keys(value).sort()) result[key] = canonical(value[key]);
  return result;
}
function sameOrigin(url, origin) {
  if (typeof url !== 'string') return false;
  try {
    const parsed = new URL(url);
    return ['http:', 'https:'].includes(parsed.protocol) && parsed.origin === origin;
  } catch { return false; }
}
function resourceKind(url, origin, documentURL) {
  if (sameOrigin(url, origin)) return 'http';
  if (typeof url !== 'string') return 'unsupported';
  try {
    const parsed = new URL(url);
    if (parsed.protocol === 'blob:' && parsed.origin === origin) return 'local_non_HTTP';
    if (parsed.protocol === 'data:' && sameOrigin(documentURL, origin)) return 'local_non_HTTP';
  } catch { return 'unsupported'; }
  return 'unsupported';
}
function targetCopy(target) {
  if (target === undefined) return undefined;
  requireValue(object(target), 'NETWORK_CAPTURE_TARGET_OBJECT');
  const keys = Object.keys(target);
  requireValue(keys.length === 1 && ['sessionId', 'targetId'].includes(keys[0])
    && typeof target[keys[0]] === 'string' && target[keys[0]].length > 0
    && target[keys[0]].length <= 1024, 'NETWORK_CAPTURE_ACTUAL_TARGET_SELECTOR');
  return Object.freeze({ [keys[0]]: target[keys[0]] });
}

/** No import-time action. Root must provide its actual origin-scoped tab capability. */
export class UiNormalNetworkCapture {
  #cap; #origin; #target; #attempted = false; #enabled = false; #busy = false;
  #halted = false; #cursor = null; #bytes = 0; #sourceKey = null;
  #pages = []; #events = []; #records = new Map(); #issues = new Set(); #boundaries = new WeakMap();
  #initialBoundary = null;

  constructor(capability, { origin, target } = {}) {
    requireValue(capability && typeof capability.send === 'function'
      && typeof capability.readEvents === 'function', 'NETWORK_CAPTURE_CAPABILITY_REQUIRED');
    requireValue(typeof origin === 'string', 'NETWORK_CAPTURE_ORIGIN_REQUIRED');
    let parsed;
    try { parsed = new URL(origin); } catch { throw new Error('NETWORK_CAPTURE_CANONICAL_ORIGIN'); }
    requireValue(['http:', 'https:'].includes(parsed.protocol) && parsed.origin === origin
      && parsed.username === '' && parsed.password === '' && parsed.search === '' && parsed.hash === '',
      'NETWORK_CAPTURE_CANONICAL_ORIGIN');
    this.#cap = capability; this.#origin = origin; this.#target = targetCopy(target);
  }

  async #exclusive(action) {
    requireValue(!this.#busy, 'NETWORK_CAPTURE_SERIAL_CALLS_ONLY');
    this.#busy = true;
    try { return await action(); } finally { this.#busy = false; }
  }
  #mark(code, halt = false) { this.#issues.add(code); if (halt) this.#halted = true; }
  #remember(value) {
    let copy;
    try { copy = jsonCopy(value); } catch { this.#mark('NON_JSON_OBSERVATION_INCOMPLETE', true); return null; }
    const bytes = encoder.encode(JSON.stringify(copy)).byteLength;
    if (this.#bytes + bytes > MAX_JSON_BYTES) { this.#mark('RAM_LIMIT_INCOMPLETE', true); return null; }
    this.#bytes += bytes;
    return copy;
  }
  #options(afterSequence) {
    const options = { methods: [...METHODS], limit: 1000 };
    if (afterSequence !== undefined) options.afterSequence = afterSequence;
    if (this.#target !== undefined) options.target = { ...this.#target };
    return options;
  }
  #sendOptions() { return this.#target === undefined ? undefined : { target: { ...this.#target } }; }

  async enable() {
    return this.#exclusive(async () => {
      requireValue(!this.#attempted, 'NETWORK_CAPTURE_ENABLE_ONCE');
      this.#attempted = true;
      const initial = await this.#page(true);
      if (initial === null || this.#halted) return this.#status();
      if (initial.events.length !== 0 || initial.hasMore) {
        this.#mark('INITIAL_CURSOR_NOT_CURRENT_INCOMPLETE', true);
        return this.#status();
      }
      const boundary = Object.freeze({ after_sequence: this.#cursor });
      this.#boundaries.set(boundary, this.#cursor);
      this.#initialBoundary = boundary;
      try { await this.#cap.send('Network.enable', {}, this.#sendOptions()); this.#enabled = true; }
      catch { this.#mark('CDP_ENABLE_FAILED_INCOMPLETE', true); return this.#status(); }
      await this.#drain();
      return this.#status();
    });
  }

  async #page(initial = false) {
    const after = initial ? undefined : this.#cursor;
    let received;
    try { received = await this.#cap.readEvents(this.#options(after)); }
    catch { this.#mark('CDP_READ_EVENTS_FAILED_INCOMPLETE', true); return null; }
    const result = this.#remember(received);
    if (result === null) return null;
    this.#pages.push({ options: this.#options(after), result });
    if (!object(result) || !Number.isSafeInteger(result.cursor) || result.cursor < 0
      || !Array.isArray(result.events) || typeof result.hasMore !== 'boolean'
      || typeof result.truncated !== 'boolean') {
      this.#mark('INVALID_EVENT_PAGE_INCOMPLETE', true); return null;
    }
    if (result.truncated) { this.#mark('EVICTED_EVENTS_INCOMPLETE', true); return null; }
    if (after !== undefined && (result.cursor < after || (result.hasMore && result.cursor === after))) {
      this.#mark('NON_ADVANCING_CURSOR_INCOMPLETE', true); return null;
    }
    let previous = after;
    for (const event of result.events) {
      if (!object(event) || !METHODS.includes(event.method) || !object(event.params) || !object(event.source)
        || !Number.isSafeInteger(event.sequence) || event.sequence < 0
        || (previous !== undefined && event.sequence <= previous) || event.sequence > result.cursor) {
        this.#mark('INVALID_OR_UNORDERED_EVENT_INCOMPLETE', true); return null;
      }
      previous = event.sequence;
      if (this.#events.length >= MAX_EVENTS) { this.#mark('EVENT_LIMIT_INCOMPLETE', true); return null; }
      this.#events.push(event);
      this.#event(event);
    }
    this.#cursor = result.cursor;
    return result;
  }
  async #drain() {
    if (this.#halted) return;
    let page;
    do { page = await this.#page(); } while (page !== null && page.hasMore && !this.#halted);
  }

  #event(event) {
    const params = event.params;
    if (typeof params.requestId !== 'string' || params.requestId.length === 0) {
      this.#mark('MISSING_ACTUAL_REQUEST_ID_INCOMPLETE', true); return;
    }
    const sourceKey = JSON.stringify(canonical(event.source));
    if (this.#sourceKey === null) this.#sourceKey = sourceKey;
    else if (sourceKey !== this.#sourceKey) this.#mark('MULTIPLE_SOURCE_SCOPES_INCOMPLETE');
    const key = JSON.stringify([sourceKey, params.requestId]);
    let record = this.#records.get(key);
    if (!record) {
      record = { source: event.source, request_id: params.requestId, events: [],
        request_will_be_sent: [], request_extra_info: [], response_received: [], response_extra_info: [],
        loading_finished: [], loading_failed: [], request_served_from_cache: [],
        get_response_body: null, body_attempted: false,
        resource_kind: 'unknown', unsupported: false };
      this.#records.set(key, record);
    }
    record.events.push(event);
    switch (event.method) {
      case 'Network.requestWillBeSent':
        record.request_will_be_sent.push(event);
        if (record.request_will_be_sent.length !== 1 || params.redirectResponse !== undefined) {
          record.unsupported = true; this.#mark('REDIRECT_OR_REUSED_ID_INCOMPLETE');
        }
        record.resource_kind = object(params.request)
          ? resourceKind(params.request.url, this.#origin, params.documentURL) : 'unsupported';
        if (record.resource_kind === 'unsupported' || (record.resource_kind === 'http'
          && (typeof params.request.method !== 'string' || !finite(params.timestamp) || params.timestamp < 0
            || !finite(params.wallTime) || params.wallTime <= 0))) {
          record.unsupported = true; this.#mark('REQUEST_SCOPE_OR_TIMING_INCOMPLETE');
        }
        break;
      case 'Network.requestWillBeSentExtraInfo':
        record.request_extra_info.push(event);
        if (record.request_extra_info.length !== 1) {
          record.unsupported = true; this.#mark('REPEATED_REQUEST_EXTRA_INFO_INCOMPLETE');
        }
        break;
      case 'Network.responseReceived':
        record.response_received.push(event);
        if (record.response_received.length !== 1 || !object(params.response)
          || resourceKind(params.response.url, this.#origin,
            record.request_will_be_sent[0]?.params.documentURL) !== record.resource_kind
          || (record.resource_kind === 'http' && !finite(params.timestamp))) {
          record.unsupported = true; this.#mark('RESPONSE_SCOPE_OR_TIMING_INCOMPLETE');
        }
        break;
      case 'Network.responseReceivedExtraInfo':
        record.response_extra_info.push(event);
        if (record.response_extra_info.length !== 1) {
          record.unsupported = true; this.#mark('REPEATED_RESPONSE_EXTRA_INFO_INCOMPLETE');
        }
        break;
      case 'Network.loadingFinished':
        record.loading_finished.push(event);
        if (record.loading_finished.length !== 1 || record.loading_failed.length !== 0
          || (record.resource_kind === 'http' && !finite(params.timestamp))) {
          record.unsupported = true; this.#mark('INVALID_NATURAL_FINISH_INCOMPLETE');
        }
        break;
      case 'Network.loadingFailed':
        record.loading_failed.push(event);
        record.unsupported = true; this.#mark('ACTUAL_LOADING_FAILED_INCOMPLETE');
        break;
      case 'Network.requestServedFromCache':
        record.request_served_from_cache.push(event);
        if (record.request_served_from_cache.length !== 1) {
          record.unsupported = true; this.#mark('REPEATED_CACHE_EVENT_INCOMPLETE');
        }
        break;
    }
  }

  #timing(record) {
    if (record.request_will_be_sent.length !== 1) return null;
    const request = record.request_will_be_sent[0].params;
    if (!finite(request.timestamp) || !finite(request.wallTime)) return null;
    const result = { request_wall_time: request.wallTime, request_monotonic_time: request.timestamp,
      derived_request_wall_time_ms: request.wallTime * 1000 };
    for (const [field, events] of [['response', record.response_received], ['finished', record.loading_finished]]) {
      if (events.length !== 1 || !finite(events[0].params.timestamp)) continue;
      const timestamp = events[0].params.timestamp;
      if (timestamp < request.timestamp) {
        this.#mark('MONOTONIC_TIME_PRECEDES_REQUEST_INCOMPLETE');
        continue;
      }
      result[field + '_monotonic_time'] = timestamp;
      result['derived_' + field + '_wall_time_ms'] = request.wallTime * 1000
        + (timestamp - request.timestamp) * 1000;
    }
    return result;
  }

  async #bodies() {
    if (this.#halted || this.#issues.has('MULTIPLE_SOURCE_SCOPES_INCOMPLETE')) return 0;
    let attempted = 0;
    for (const record of this.#records.values()) {
      if (this.#halted) break;
      if (record.resource_kind !== 'http' || record.unsupported || record.body_attempted || record.request_will_be_sent.length !== 1
        || record.response_received.length !== 1 || record.loading_finished.length !== 1
        || record.loading_failed.length !== 0) continue;
      record.body_attempted = true;
      attempted++;
      const params = { requestId: record.request_id };
      let result;
      try { result = await this.#cap.send('Network.getResponseBody', params, this.#sendOptions()); }
      catch {
        record.get_response_body = { after_sequence: this.#cursor, params, result: null,
          command_error: 'CDP_GET_RESPONSE_BODY_FAILED' };
        this.#mark('CDP_GET_RESPONSE_BODY_FAILED_INCOMPLETE');
        continue;
      }
      const copy = this.#remember(result);
      record.get_response_body = { after_sequence: this.#cursor, params, result: copy, command_error: null };
      if (copy === null) continue;
      if (!object(copy) || Object.keys(copy).sort().join(',') !== 'base64Encoded,body'
        || typeof copy.body !== 'string' || typeof copy.base64Encoded !== 'boolean') {
        this.#mark('RESPONSE_BODY_SHAPE_INCOMPLETE');
      }
    }
    return attempted;
  }

  async #poll() {
    requireValue(this.#enabled, 'NETWORK_CAPTURE_NOT_ENABLED');
    await this.#drain();
    const attempted = await this.#bodies();
    if (attempted > 0) await this.#drain();
    return this.#status();
  }
  async poll() { return this.#exclusive(() => this.#poll()); }

  initialCheckpoint() {
    requireValue(!this.#busy && this.#enabled && this.#initialBoundary !== null,
      'NETWORK_CAPTURE_OWN_INITIAL_CHECKPOINT');
    return { boundary: this.#initialBoundary, status: this.#status() };
  }

  async checkpoint() {
    return this.#exclusive(async () => {
      const status = await this.#poll();
      const boundary = Object.freeze({ after_sequence: this.#cursor });
      this.#boundaries.set(boundary, this.#cursor);
      return { boundary, status };
    });
  }

  #unresolved(record) {
    const result = [];
    if (record.request_will_be_sent.length !== 1) result.push('request');
    if (record.resource_kind === 'local_non_HTTP') {
      if (record.loading_finished.length !== 1) result.push('local_natural_finish_unknown');
      if (record.unsupported) result.push('unsupported_lifecycle');
      return result;
    }
    if (record.response_received.length !== 1) result.push('response');
    const response = record.response_received[0]?.params;
    if (response?.hasExtraInfo === true) {
      if (record.request_extra_info.length !== 1) result.push('request_extra_info');
      if (record.response_extra_info.length !== 1) result.push('response_extra_info');
    } else if (response && response.hasExtraInfo !== false) result.push('extra_info_declaration_unavailable');
    if (record.loading_finished.length !== 1) result.push('natural_loading_finished');
    if (!record.get_response_body || record.get_response_body.command_error !== null
      || !object(record.get_response_body.result) || typeof record.get_response_body.result.body !== 'string'
      || typeof record.get_response_body.result.base64Encoded !== 'boolean') result.push('response_body');
    if (record.unsupported) result.push('unsupported_lifecycle');
    return result;
  }
  #status() {
    const pending = []; const unresolved = [];
    for (const record of this.#records.values()) {
      this.#timing(record);
      if (record.loading_finished.length === 0 && record.loading_failed.length === 0) {
        pending.push({ source: record.source, request_id: record.request_id, resource_kind: record.resource_kind });
      }
      const fields = this.#unresolved(record);
      if (fields.length !== 0) unresolved.push({ source: record.source, request_id: record.request_id,
        resource_kind: record.resource_kind, fields });
    }
    return jsonCopy({ scope: 'MS4_UI_PASSIVE_NETWORK_RAM_ONLY', enabled: this.#enabled, halted: this.#halted,
      covered_through_sequence: this.#cursor, pending, unresolved, incomplete: [...this.#issues],
      quiet_identity_server_state: 'NOT_CLAIMED' });
  }

  readWindow(boundary) {
    requireValue(!this.#busy && this.#boundaries.has(boundary), 'NETWORK_CAPTURE_OWN_ISSUED_CHECKPOINT');
    const after = this.#boundaries.get(boundary);
    const records = [];
    for (const record of this.#records.values()) {
      if (!record.events.some(event => event.sequence > after)) continue;
      records.push({ ...record, timing: this.#timing(record),
        relation: record.request_will_be_sent[0]?.sequence > after ? 'started_after_checkpoint' : 'preexisting_or_unresolved' });
    }
    return jsonCopy({ schema: 1, scope: 'MS4_UI_PASSIVE_NETWORK_RAM_ONLY', origin: this.#origin,
      after_sequence: after, covered_through_sequence: this.#cursor, status: this.#status(),
      pages: this.#pages.filter(page => page.result.cursor > after),
      events: this.#events.filter(event => event.sequence > after), requests: records,
      http_requests: records.filter(record => record.resource_kind === 'http'),
      local_non_HTTP: records.filter(record => record.resource_kind === 'local_non_HTTP'),
      unknown: records.filter(record => !['http', 'local_non_HTTP'].includes(record.resource_kind)),
      no_quiet_identity_server_or_business_acceptance: true });
  }
}

// Task2 alone obtains CDP from the same actual owned Tab, after the existing CSS bootstrap.
// No capability adapter, synthetic source, CDP cursor, or import-time action is introduced.
import { createHash as task2CreateHash } from 'node:crypto';
import { UiBrowserNormalClose as Task2OwnedBrowser } from './ms4-ui-browser-close.mjs';
const task2OwnedCaptures=new WeakMap();
const task2Sha=value=>task2CreateHash('sha256').update(value).digest('hex');
export async function createTask2OwnedNetworkCapture({tab,companion,role,origin}) {
  requireValue(Object.getPrototypeOf(companion)===Task2OwnedBrowser.prototype
    &&['customer','merchant','platform'].includes(role)&&tab&&typeof tab.id==='string'
    &&typeof tab.url==='function'&&tab.capabilities&&typeof tab.capabilities.get==='function',
    'TASK2_SAME_GENUINE_OWNED_TAB_CAPABILITY');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.role===role&&owner.tab_id_sha256===task2Sha(tab.id),'TASK2_REGISTERED_OWNED_TAB');
  const cssURL=await tab.url();let parsed;
  try{parsed=new URL(cssURL);}catch{throw new Error('TASK2_EXISTING_HTTP_CSS_BOOTSTRAP_REQUIRED');}
  requireValue(sameOrigin(cssURL,origin)&&parsed.pathname.endsWith('.css')&&parsed.hash===''
    &&parsed.username===''&&parsed.password==='','TASK2_EXISTING_HTTP_CSS_BOOTSTRAP_REQUIRED');
  const capability=await tab.capabilities.get('cdp');
  requireValue(capability&&typeof capability.send==='function'&&typeof capability.readEvents==='function',
    'TASK2_REAL_TAB_CDP_CAPABILITY');
  const observation=jsonCopy(await capability.send('Target.getTargetInfo',{}));
  requireValue(object(observation)&&object(observation.targetInfo),'TASK2_ACTUAL_TARGET_INFO_RESULT');
  const target=observation.targetInfo;
  requireValue(typeof target.targetId==='string'&&target.targetId.length>0&&target.targetId.length<=1024
    &&target.type==='page'&&target.url===cssURL&&await tab.url()===cssURL,
    'TASK2_CSS_OWNED_TARGET_INFO_JOIN');
  const capture=new UiNormalNetworkCapture(capability,{origin});
  task2OwnedCaptures.set(capture,{tab,companion,role,origin,target:jsonCopy(target),
    owner:jsonCopy(owner),cssURL,sourceKey:null,mapping:null});
  return capture;
}
export function task2OwnedCaptureSource(capture,{tab,companion,role,origin,source}) {
  const original=task2OwnedCaptures.get(capture);
  requireValue(original&&original.tab===tab&&original.companion===companion&&original.role===role
    &&original.origin===origin&&object(source),'TASK2_ORIGINAL_OWNED_CAPTURE_REQUIRED');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.tab_id_sha256===original.owner.tab_id_sha256&&owner.tab_id_sha256===task2Sha(tab.id),
    'TASK2_OWNED_CAPTURE_STILL_SAME_TAB');
  const raw=jsonCopy(source),sourceKey=JSON.stringify(canonical(raw));
  let kind;
  if(typeof raw.targetId==='string'){
    requireValue(raw.targetId===original.target.targetId,'TASK2_REAL_EVENT_TARGET_ID_JOIN');kind='targetId';
  }else{
    requireValue(Number.isSafeInteger(raw.tabId)&&raw.tabId>=0,'TASK2_REAL_EVENT_TAB_MAPPING_REQUIRED');
    const value=String(raw.tabId);
    requireValue(tab.id===value,'TASK2_REAL_PUBLIC_TAB_ID_JOIN');kind='tabId';
  }
  if(original.sourceKey===null){original.sourceKey=sourceKey;original.mapping={kind,
    tab_id_sha256:owner.tab_id_sha256,target_id_sha256:task2Sha(original.target.targetId),
    source_sha256:task2Sha(sourceKey),css_url_sha256:task2Sha(original.cssURL)};}
  else requireValue(original.sourceKey===sourceKey,'TASK2_ONE_REAL_OWNED_CAPTURE_SOURCE_SCOPE');
  return jsonCopy(original.mapping);
}
