// Import-only network observation. No navigation, DOM/action, storage, token or App writes.
const methods = ['Network.requestWillBeSent', 'Network.responseReceived', 'Network.loadingFinished', 'Network.loadingFailed'];
const states = new WeakMap();
const projections = new WeakMap();
const encoder = new TextEncoder();
const demand = (ok, code) => { if (!ok) throw new Error(code); };
const MAX_BODY = 2097152;
const MAX_REQUESTS = 4096;
const GETS = new Set(['basic_config', 'theme_info/theme', 'theme_info/detail', 'lang_version', 'get_lang_json',
  'copyright', 'wechat/get_logo', 'theme/navigation', 'v2/diy/get_version/0', 'user', 'cart/count', 'cart/list',
  'product/hot', 'v2/invoice', 'address/default', 'address/list', 'get_customer_type', 'get_script']);
const POSTS = new Set(['login', 'image_base64', 'cart/add', 'cart/num', 'order/check_shipping', 'order/confirm']);
const freeze = value => {
  if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); }
  return value;
};
const id = value => {
  demand((typeof value === 'string' || Number.isSafeInteger(value)) && /^[1-9][0-9]*$/.test(String(value)),
    'CUSTOMER_ACTUAL_PUBLIC_ID');
  return String(value);
};
const ids = values => {
  demand(Array.isArray(values) && values.length > 0 && values.length <= 500, 'CUSTOMER_FINITE_PUBLIC_IDS');
  const set = new Set(values.map(id)); demand(set.size === values.length, 'CUSTOMER_UNIQUE_PUBLIC_IDS'); return set;
};
const state = observer => {
  const value = states.get(observer); demand(value, 'CUSTOMER_PRIVATE_OBSERVER_HANDLE'); return value;
};
const json = (raw, code) => { try { return JSON.parse(raw); } catch { throw new Error(code); } };
const object = value => value && typeof value === 'object' && !Array.isArray(value);
function base64(bytes) {
  const chunks = [];
  for (let offset = 0; offset < bytes.length; offset += 8192)
    chunks.push(String.fromCharCode(...bytes.subarray(offset, offset + 8192)));
  return btoa(chunks.join(''));
}
async function bounded(promise, deadline, code) {
  const remaining = deadline - Date.now(); demand(remaining > 0, code); let timer;
  try { return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(code)), remaining); })]); }
  finally { clearTimeout(timer); }
}
async function digest(s, bytes) {
  let result; try { result = await s.sha256(bytes); } catch { throw new Error('CUSTOMER_PRIVATE_HASH_FAILED'); }
  demand(typeof result === 'string' && /^[a-f0-9]{64}$/.test(result), 'CUSTOMER_ACTUAL_FULL_SHA256'); return result;
}

// Root establishes the actual PNG response first. This function verifies its current URL, not its pixels/HTTP receipt.
export async function initializeCustomerNetworkObserver(tab, runtime, publicIds, { sha256, onPrivateCapture } = {}) {
  const origin = new URL(runtime.origin);
  demand(origin.protocol === 'http:' && origin.hostname === '127.0.0.1' && origin.origin === runtime.origin
    && /^[1-9][0-9]{0,4}$/.test(origin.port) && Number(origin.port) <= 65535, 'CUSTOMER_ACTUAL_LOOPBACK_ORIGIN');
  demand(await tab.url() === runtime.origin + '/__ms4_fixture__/product.png', 'CUSTOMER_ALREADY_OPEN_REAL_ORIGIN_PNG');
  demand(typeof sha256 === 'function' && (onPrivateCapture === undefined || typeof onPrivateCapture === 'function'),
    'CUSTOMER_EXPLICIT_PRIVATE_CONSUMER');
  const binding = { merchants: ids(publicIds.merchantIds), products: ids(publicIds.productIds),
    addresses: ids(publicIds.addressIds), invoices: ids(publicIds.invoiceIds), skus: new Set() };
  demand(Array.isArray(publicIds.skuPairs) && publicIds.skuPairs.length > 0 && publicIds.skuPairs.length <= 500,
    'CUSTOMER_FINITE_ACTUAL_SKU_PAIRS');
  for (const pair of publicIds.skuPairs) {
    const product = id(pair.productId);
    demand(binding.products.has(product) && typeof pair.unique === 'string' && /^[A-Za-z0-9]{8}$/.test(pair.unique),
      'CUSTOMER_SKU_BOUND_TO_ACTUAL_PRODUCT');
    const key = product + ':' + pair.unique; demand(!binding.skus.has(key), 'CUSTOMER_UNIQUE_SKU_PAIR'); binding.skus.add(key);
  }
  const cdp = await tab.capabilities.get('cdp');
  await cdp.send('Network.enable', {});
  await cdp.send('Network.setBlockedURLs', {
    urlPatterns: [{ urlPattern: runtime.origin + '/*', block: false }, { urlPattern: '*://*/*', block: true }], urls: [],
  });
  const start = await cdp.readEvents({ methods, limit: 1000 });
  demand(!start.truncated && !start.hasMore, 'CUSTOMER_FRESH_ACTUAL_EVENT_CURSOR');
  const observer = Object.freeze({ origin: runtime.origin });
  states.set(observer, { cdp, origin: runtime.origin, cursor: start.cursor, requests: new Map(), binding,
    cartRefs: new Set(), confirmations: new Set(), orders: new Set(), sha256, onPrivateCapture });
  return observer;
}

async function events(s, deadline) {
  let batch; let pages = 0;
  do {
    demand(++pages <= 8, 'CUSTOMER_BOUNDED_EVENT_DRAIN');
    try { batch = await bounded(s.cdp.readEvents({ afterSequence: s.cursor, methods, limit: 1000,
      timeoutMs: Math.min(1000, Math.max(1, deadline - Date.now())) }), deadline, 'CUSTOMER_EVENT_WAIT_DEADLINE'); }
    catch { throw new Error('CUSTOMER_ACTUAL_EVENT_READ_FAILED'); }
    demand(!batch.truncated && Array.isArray(batch.events), 'CUSTOMER_ACTUAL_EVENTS_NOT_TRUNCATED'); s.cursor = batch.cursor;
    for (const event of batch.events) {
      const p = event.params || {};
      if (event.method === 'Network.requestWillBeSent' && p.request && /^https?:/.test(p.request.url)) {
        demand(typeof p.requestId === 'string' && p.requestId, 'CUSTOMER_ACTUAL_REQUEST_ID');
        const previous = s.requests.get(p.requestId);
        demand(!previous || p.redirectResponse, 'CUSTOMER_UNIQUE_REQUEST_ID_OR_REAL_REDIRECT');
        demand(s.requests.size < MAX_REQUESTS || previous, 'CUSTOMER_BOUNDED_ACTUAL_REQUESTS');
        const url = new URL(p.request.url);
        // Headers/cookies/auth headers are deliberately never retained. URL/postData remain private.
        s.requests.set(p.requestId, { request_id: p.requestId,
          url: url.href, origin: url.origin, path: url.pathname, method: p.request.method,
          post_data: typeof p.request.postData === 'string' ? p.request.postData : null,
          has_post_data: p.request.hasPostData === true || typeof p.request.postData === 'string',
          redirected: !!previous || !!p.redirectResponse, response: null, finished: false, failed: null, capture: null });
      }
      const row = s.requests.get(p.requestId);
      if (!row) continue;
      if (event.method === 'Network.responseReceived') {
        demand(row.origin === s.origin, 'CUSTOMER_NO_EXTERNAL_RESPONSE');
        row.response = { http_status: p.response.status, mime_type: p.response.mimeType };
      }
      if (event.method === 'Network.loadingFinished') row.finished = true;
      if (event.method === 'Network.loadingFailed') {
        row.failed = { blocked_reason: p.blockedReason || null, error_text: p.errorText || null, canceled: p.canceled === true };
        if (row.origin !== s.origin) demand(p.blockedReason === 'inspector', 'CUSTOMER_EXTERNAL_ACTUALLY_BLOCKED');
      }
    }
  } while (batch.hasMore);
}

// Private list: actual URL, postData and error text may identify the actor. Never print or publish this return value.
export async function readCustomerNetworkPrivate(observer, { timeoutMs = 2000 } = {}) {
  demand(Number.isInteger(timeoutMs) && timeoutMs >= 1000 && timeoutMs <= 30000, 'CUSTOMER_FINITE_READ_DEADLINE');
  const s = state(observer); await events(s, Date.now() + timeoutMs);
  return [...s.requests.values()].map(row => ({ request_id: row.request_id, method: row.method, url: row.url,
    post_data_available: row.post_data !== null, redirected: row.redirected,
    response: row.response ? { ...row.response } : null, finished: row.finished,
    failed: row.failed ? { ...row.failed } : null, captured: row.capture !== null }));
}

function template(s, row, body) {
  const path = row.path; const url = new URL(row.url); let match;
  if (row.method === 'GET' && GETS.has(path.slice(5)) && path.startsWith('/api/')) return path;
  if (row.method === 'POST' && POSTS.has(path.slice(5)) && path.startsWith('/api/')) {
    demand(object(body), 'CUSTOMER_ACTUAL_JSON_REQUEST_BODY');
    if (path === '/api/cart/add') demand(s.binding.products.has(id(body.productId))
      && s.binding.skus.has(id(body.productId) + ':' + body.uniqueId) && (body.new === 0 || body.new === '0'),
      'CUSTOMER_PERSISTENT_CART_ACTUAL_PRODUCT_SKU');
    if (path === '/api/cart/num') demand(s.cartRefs.has(String(body.id)), 'CUSTOMER_CART_REF_FROM_REAL_RESPONSE');
    if (['/api/order/check_shipping', '/api/order/confirm'].includes(path)) {
      demand(typeof body.cartId === 'string' && body.cartId.split(',').every(ref => s.cartRefs.has(ref)),
        'CUSTOMER_CONFIRM_REFS_FROM_REAL_RESPONSE');
      if (path === '/api/order/confirm') demand(s.binding.merchants.has(id(body.mer_id)), 'CUSTOMER_REAL_CHECKOUT_MERCHANT');
    }
    return path;
  }
  if (row.method === 'GET' && path === '/api/products') {
    demand(s.binding.merchants.has(id(url.searchParams.get('mer_id'))), 'CUSTOMER_REAL_STORE_PRODUCT_QUERY'); return path;
  }
  if (row.method === 'GET' && (match = path.match(/^\/api\/marketplace\/stores\/([1-9][0-9]*)$/))) {
    demand(s.binding.merchants.has(match[1]), 'CUSTOMER_BOUND_STORE_ID'); return '/api/marketplace/stores/:merchant';
  }
  if (row.method === 'GET' && (match = path.match(/^\/api\/product\/detail\/([1-9][0-9]*)$/))) {
    demand(s.binding.products.has(match[1]), 'CUSTOMER_BOUND_PRODUCT_ID'); return '/api/product/detail/:product';
  }
  if (row.method === 'GET' && (match = path.match(/^\/api\/product\/real_price\/([1-9][0-9]*)\/([A-Za-z0-9]{8})$/))) {
    demand(s.binding.skus.has(match[1] + ':' + match[2]), 'CUSTOMER_BOUND_REAL_PRICE_SKU'); return '/api/product/real_price/:product/:sku';
  }
  if (row.method === 'GET' && (match = path.match(/^\/api\/address\/detail\/([1-9][0-9]*)$/))) {
    demand(s.binding.addresses.has(match[1]), 'CUSTOMER_BOUND_OWNED_ADDRESS'); return '/api/address/detail/:address';
  }
  if (row.method === 'POST' && (match = path.match(/^\/api\/order\/confirmation\/([^/]+)(\/restore)?$/))) {
    let key; try { key = decodeURIComponent(match[1]); } catch { throw new Error('CUSTOMER_ACTUAL_CONFIRMATION_ENCODING'); }
    demand(/^[\x21-\x7e]{1,32}$/.test(key) && encodeURIComponent(key) === match[1]
      && s.confirmations.has(key) && url.search === '' && url.hash === '', 'CUSTOMER_RESTORE_SAME_FRESH_CONFIRMATION_KEY');
    demand(object(body), 'CUSTOMER_ACTUAL_RESTORE_JSON_OBJECT');
    if (match[2]) {
      demand(JSON.stringify(Object.keys(body).sort()) === JSON.stringify(['addressId', 'invoice_id', 'payType'])
        && typeof body.payType === 'string' && /^[\x20-\x7e]{0,32}$/.test(body.payType), 'CUSTOMER_EXACT_THREE_RESTORE_HINTS');
      for (const [field, owned] of [['addressId', s.binding.addresses], ['invoice_id', s.binding.invoices]]) {
        const value = body[field]; demand((typeof value === 'string' || Number.isSafeInteger(value))
          && /^(0|[1-9][0-9]*)$/.test(String(value)) && (String(value) === '0' || owned.has(String(value))),
          'CUSTOMER_RESTORE_ACTUAL_OWNED_ID_OR_EXPLICIT_NONE');
      }
      return '/api/order/confirmation/:confirmation/restore';
    }
    demand(Object.keys(body).length === 0 && /^[\x20\t\r\n]*\{[\x20\t\r\n]*\}[\x20\t\r\n]*$/.test(row.post_data),
      'CUSTOMER_LOOKUP_ORIGINAL_EMPTY_JSON_OBJECT');
    return '/api/order/confirmation/:confirmation';
  }
  if (row.method === 'POST' && (match = path.match(/^\/api\/order\/(computed|create)\/([^/]+)$/))) {
    let key; try { key = decodeURIComponent(match[2]); } catch { throw new Error('CUSTOMER_ACTUAL_CONFIRMATION_ENCODING'); }
    demand(s.confirmations.has(key), 'CUSTOMER_KEY_FROM_ACTUAL_CONFIRM_RESPONSE');
    demand(object(body), 'CUSTOMER_FULL_ACTUAL_ORDER_REQUEST_BODY');
    if (match[1] === 'create') {
      demand(typeof body.cartId === 'string' && body.cartId.split(',').every(ref => s.cartRefs.has(ref))
        && s.binding.merchants.has(id(body.mer_id)) && s.binding.addresses.has(id(body.addressId))
        && s.binding.invoices.has(id(body.invoice_id)), 'CUSTOMER_CREATE_BOUND_ACTUAL_REFS_AND_OWNED_IDS');
    }
    return '/api/order/' + match[1] + '/:confirmation';
  }
  if (row.method === 'GET' && (match = path.match(/^\/api\/order\/detail\/([^/]+)$/))) {
    let number; try { number = decodeURIComponent(match[1]); } catch { throw new Error('CUSTOMER_ACTUAL_ORDER_ENCODING'); }
    demand(s.orders.has(number), 'CUSTOMER_ORDER_FROM_ACTUAL_CREATE_RESPONSE'); return '/api/order/detail/:order';
  }
  throw new Error('CUSTOMER_OUTSIDE_FINITE_NORMAL_API');
}

function learn(s, label, envelope) {
  const data = envelope.data;
  const addRef = value => { demand((typeof value === 'string' || Number.isSafeInteger(value))
    && String(value) !== '' && !String(value).includes(','), 'CUSTOMER_REAL_RESPONSE_CART_REF'); s.cartRefs.add(String(value)); };
  if (label === '/api/cart/add') { demand(object(data), 'CUSTOMER_ACTUAL_CART_ADD_RESULT'); addRef(data.cartId); }
  if (label === '/api/cart/count' && object(data) && Array.isArray(data.ids)) data.ids.forEach(addRef);
  if (label === '/api/cart/list' && object(data) && Array.isArray(data.valid)) data.valid.forEach(item => addRef(item.id));
  if (label === '/api/order/confirm') {
    demand(object(data) && typeof data.orderKey === 'string' && /^[\x21-\x7e]{1,32}$/.test(data.orderKey),
      'CUSTOMER_REAL_CONFIRMATION_KEY'); s.confirmations.add(data.orderKey);
  }
  if (label === '/api/order/create/:confirmation' ||
      (label === '/api/order/computed/:confirmation' && data?.status === 'SUCCESS')) {
    demand(typeof data.result.orderId === 'string' && data.result.orderId && typeof data.result.key === 'string'
      && s.confirmations.has(data.result.key), 'CUSTOMER_REAL_RETURNED_ORDER_AND_CONFIRMATION'); s.orders.add(data.result.orderId);
  }
}

// Intentionally PRIVATE return. Root retains the exact create body + confirm frame/refs + returned order for its SQL oracle.
export async function awaitCustomerResponsePrivate(observer, requestId, { timeoutMs = 20000 } = {}) {
  demand(Number.isInteger(timeoutMs) && timeoutMs >= 1000 && timeoutMs <= 30000, 'CUSTOMER_FINITE_CAPTURE_DEADLINE');
  const s = state(observer); demand(typeof requestId === 'string' && s.requests.has(requestId), 'CUSTOMER_PREVIOUSLY_OBSERVED_REQUEST_ID');
  const deadline = Date.now() + timeoutMs; let row = s.requests.get(requestId); let polls = 0;
  while (!row.finished && !row.failed && Date.now() < deadline && ++polls <= 32) { await events(s, deadline); row = s.requests.get(requestId); }
  demand(!row.failed && row.finished && row.response && row.origin === s.origin && !row.redirected,
    'CUSTOMER_ACTUAL_FINISHED_SAME_ORIGIN_RESPONSE');
  demand(row.response.http_status === 200, 'CUSTOMER_ACTUAL_HTTP_200');
  if (row.capture) return row.capture;
  demand(row.post_data === null || encoder.encode(row.post_data).byteLength <= MAX_BODY, 'CUSTOMER_BOUNDED_PRIVATE_REQUEST_BODY');
  if (row.method === 'POST') demand(row.post_data !== null, 'CUSTOMER_REQUEST_BODY_NOT_AVAILABLE_NO_FABRICATION');
  const requestBody = row.post_data === null ? null : json(row.post_data, 'CUSTOMER_ACTUAL_REQUEST_JSON');
  const label = template(s, row, requestBody); let wire;
  try { wire = await bounded(s.cdp.send('Network.getResponseBody', { requestId }), deadline, 'CUSTOMER_RESPONSE_BODY_DEADLINE'); }
  catch { throw new Error('CUSTOMER_ACTUAL_RESPONSE_BODY_UNAVAILABLE'); }
  demand(wire && typeof wire.body === 'string' && typeof wire.base64Encoded === 'boolean', 'CUSTOMER_ACTUAL_CDP_BODY');
  let raw; try { raw = wire.base64Encoded ? Uint8Array.from(atob(wire.body), c => c.charCodeAt(0)) : encoder.encode(wire.body); }
  catch { throw new Error('CUSTOMER_ACTUAL_BODY_ENCODING'); }
  demand(raw.byteLength <= MAX_BODY && (label === '/api/get_script' || raw.byteLength > 0), 'CUSTOMER_BOUNDED_ACTUAL_RESPONSE_BODY');
  const text = new TextDecoder('utf-8', { fatal: true }).decode(raw);
  const envelope = label === '/api/get_script' ? null : json(text, 'CUSTOMER_ACTUAL_RESPONSE_JSON');
  let nested = null;
  if (envelope !== null) {
    demand(object(envelope) && envelope.status === 200 && typeof envelope.msg === 'string', 'CUSTOMER_ACTUAL_BUSINESS_200');
    if (label === '/api/order/computed/:confirmation' || label === '/api/order/create/:confirmation') {
      nested = envelope.data?.status;
      demand(object(envelope.data) && object(envelope.data.result)
        && (label === '/api/order/create/:confirmation' ? nested === 'SUCCESS' : ['NONE', 'SUCCESS'].includes(nested)),
        'CUSTOMER_ACTUAL_NESTED_NONE_OR_SUCCESS');
      const actualKey = decodeURIComponent(row.path.slice(row.path.lastIndexOf('/') + 1));
      if (nested === 'SUCCESS') demand(envelope.data.result.key === actualKey
        && (label === '/api/order/create/:confirmation' || envelope.data.result.state === 'created'),
        'CUSTOMER_RESULT_BOUND_TO_ACTUAL_REQUEST_CONFIRMATION');
    }
    if (label === '/api/order/confirmation/:confirmation' || label === '/api/order/confirmation/:confirmation/restore') {
      const match = row.path.match(/^\/api\/order\/confirmation\/([^/]+)(\/restore)?$/);
      const actualKey = decodeURIComponent(match[1]), data = envelope.data;
      demand(object(data) && data.key === actualKey && s.confirmations.has(data.key)
        && ['not_found', 'processing', 'created'].includes(data.state), 'CUSTOMER_REAL_OWNER_CONFIRMATION_RESULT');
      if (data.state === 'not_found') {
        const expected = match[2] ? ['confirmation', 'key', 'selection', 'state'] : ['key', 'state'];
        demand(JSON.stringify(Object.keys(data).sort()) === JSON.stringify(expected), 'CUSTOMER_COMPLETE_ACTUAL_RESTORE_RESULT');
        if (match[2]) demand(object(data.confirmation) && data.confirmation.orderKey === actualKey
          && data.confirmation.marketplace_confirmation_version === 1 && object(data.selection)
          && object(data.selection.address) && object(data.selection.invoice), 'CUSTOMER_COMPLETE_SAME_KEY_RESTORE_DTO');
      }
    }
    learn(s, label, envelope);
  }
  const record = { scope: 'PRIVATE_ACTUAL_CUSTOMER_NETWORK_ONLY', request: { request_id: requestId,
    url: row.url, method: row.method, post_data: row.post_data, body: requestBody }, response: { ...row.response,
    body_bytes: raw.byteLength, body_sha256: await digest(s, raw), body_base64: base64(raw), text, envelope },
    path_template: label, business_status: envelope?.status ?? null, nested_status: nested };
  const fullHash = await digest(s, encoder.encode(JSON.stringify(record)));
  record.private_capture_sha256 = fullHash; freeze(record);
  const projection = Object.freeze({ scope: 'NETWORK_ONLY_NOT_DOM_ACTION_ACK_JPEG_OR_UI_ACCEPTANCE', method: row.method,
    path_template: label, http_status: row.response.http_status, response_kind: envelope === null ? 'raw_text' : 'json',
    business_status: envelope?.status ?? null, nested_status: nested, response_bytes: raw.byteLength,
    response_sha256_prefix: record.response.body_sha256.slice(0, 12), private_capture_sha256_prefix: fullHash.slice(0, 12) });
  projections.set(record, projection); row.capture = record;
  if (s.onPrivateCapture) { try { await s.onPrivateCapture(record); } catch { throw new Error('CUSTOMER_PRIVATE_CONSUMER_FAILED'); } }
  return record;
}

// Safe immutable whitelist projection. No actual URL/query/id/account/ref/order/key/headers/body/token is exposed.
export function projectCustomerCapturePublic(privateRecord) {
  const result = projections.get(privateRecord); demand(result, 'CUSTOMER_ACTUAL_CAPTURE_ONLY_PUBLIC_PROJECTION'); return result;
}

export function customerNetworkSummaryPublic(observer) {
  const rows = [...state(observer).requests.values()];
  return Object.freeze({ scope: 'NETWORK_OBSERVATIONS_ONLY_NOT_UI_ACCEPTANCE', observed_requests: rows.length,
    captured_api_responses: rows.filter(row => row.capture !== null).length,
    actual_external_inspector_failures: rows.filter(row => row.origin !== observer.origin && row.failed?.blocked_reason === 'inspector').length,
    actual_same_origin_loading_failures: rows.filter(row => row.origin === observer.origin && row.failed !== null).length });
}
