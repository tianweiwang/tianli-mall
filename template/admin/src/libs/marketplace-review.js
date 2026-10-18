// Shared Review contract. Keep these three app-local copies byte-identical.
const INT_MAX = 2147483647;
const ownKeys = ['id', 'root_order_id', 'source_item_id', 'product_id', 'product_score', 'service_score', 'comment', 'pics', 'add_time', 'merchant_reply_content', 'merchant_reply_time', 'is_reply', 'status', 'nickname', 'avatar', 'suk'];
let sequence = 0;
function fail(message) { throw new Error(message || '评价资料格式未确认，请重新读取'); }
function object(value, expected) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) fail();
  const keys = Object.keys(value).sort(); const wanted = expected.slice().sort();
  if (keys.length !== wanted.length || keys.some((key, index) => key !== wanted[index])) fail();
  return value;
}
function integer(value, min, max) { if (!Number.isSafeInteger(value) || value < min || value > max) fail(); return value; }
function text(value, min, max) {
  if (typeof value !== 'string' || value.indexOf('\0') !== -1) fail();
  let length = 0;
  for (const character of value) { const code = character.codePointAt(0); if (code >= 0xd800 && code <= 0xdfff) fail(); length += 1; }
  if (length < min || (max !== undefined && length > max)) fail(); return value;
}
function list(value, max) { if (!Array.isArray(value) || value.length > max || Object.keys(value).length !== value.length) fail(); return value; }
export function routeId(value) {
  if (typeof value === 'number') return integer(value, 1, INT_MAX);
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,9}(?![\s\S])/.test(value)) fail('评价目标编号无效');
  return integer(Number(value), 1, INT_MAX);
}
function publicReference(value, max) {
  text(value, 1, max);
  // Match canonical public references without changing the supplied bytes.
  if (/[\u0000-\u0020\u007f-\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\\]/.test(value)) fail('图片引用格式无效');
  let path;
  if (value.indexOf('/uploads/') === 0) {
    if (/[?#]/.test(value)) fail('图片引用格式无效'); path = value;
  } else {
    const match = /^(https?):\/\/([^/?#]+)(\/[^?#]*)?(?:\?[^#]*)?(?![\s\S])/.exec(value);
    if (!match || match[2].indexOf('@') !== -1) fail('图片引用格式无效');
    const authority = match[2];
    const host = authority.charAt(0) === '[' ? /^\[[0-9a-fA-F:.]+\](?::([0-9]+))?(?![\s\S])/.exec(authority) : /^[^:]+(?::([0-9]+))?(?![\s\S])/.exec(authority);
    if (!host || (host[1] && Number(host[1]) > 65535)) fail('图片引用格式无效'); path = match[3] || '';
  }
  for (let round = 0; round < 3; round += 1) {
    if (/[\u0000-\u0020\u007f-\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\\]/.test(path) || path.split('/').some(part => part === '.' || part === '..')) fail('图片引用格式无效');
    // decodeURIComponent preserves '+'; keep original reference for the actual wire.
    let decoded; try { decoded = decodeURIComponent(path); } catch (error) { fail('图片引用格式无效'); }
    if (decoded === path) break; path = decoded;
  }
  return value;
}
export function pictureRef(value) { return publicReference(value, 200); }
export function pictureURL(value) { return publicReference(value); }
function score(value) { return integer(value, 1, 5); }
function money(value) { if (typeof value !== 'string' || !/^(?:0|[1-9][0-9]{0,9})\.[0-9]{2}(?![\s\S])/.test(value)) fail(); return value; }
function item(value) {
  object(value, ['id', 'product_id', 'product_name', 'sku_name', 'image', 'quantity', 'unit_price', 'line_price', 'postage_price']);
  integer(value.id, 1, INT_MAX); integer(value.product_id, 1, INT_MAX); text(value.product_name, 0); text(value.sku_name, 0); text(value.image, 0);
  integer(value.quantity, 1, INT_MAX); money(value.unit_price); money(value.line_price); money(value.postage_price); return value;
}
export function reviewView(value) {
  object(value, ownKeys); ['id', 'root_order_id', 'source_item_id', 'product_id'].forEach(key => integer(value[key], 1, INT_MAX));
  score(value.product_score); score(value.service_score); text(value.comment, 0, 512); list(value.pics, 8).forEach(pictureURL);
  integer(value.add_time, 0, INT_MAX); text(value.merchant_reply_content, 0, 300); integer(value.merchant_reply_time, 0, INT_MAX);
  integer(value.is_reply, 0, 1); integer(value.status, 0, 2); text(value.nickname, 0, 64); text(value.avatar, 0); text(value.suk, 0, 255); return value;
}
export function reviewQuote(value, rootId, itemId) {
  object(value, ['schema', 'scope', 'root_order_id', 'source_item_id', 'item', 'review', 'allowed_actions']);
  if (value.schema !== 1 || value.scope !== 'marketplace_order_review_v1' || value.root_order_id !== routeId(rootId) || value.source_item_id !== routeId(itemId)) fail();
  item(value.item); if (value.item.id !== value.source_item_id) fail(); object(value.allowed_actions, ['submit']);
  if (typeof value.allowed_actions.submit !== 'boolean') fail();
  if (value.review !== null) {
    const saved = reviewView(value.review);
    if (saved.root_order_id !== value.root_order_id || saved.source_item_id !== value.source_item_id || saved.product_id !== value.item.product_id || value.allowed_actions.submit) fail();
  }
  return value;
}
export function reviewPage(value, page, limit) {
  object(value, ['page', 'limit', 'count', 'list']); integer(page, 1, INT_MAX); integer(limit, 1, 100);
  if (value.page !== page || value.limit !== limit) fail(); integer(value.count, 0, Number.MAX_SAFE_INTEGER); list(value.list, limit);
  const ids = new Set(); value.list.forEach(row => { reviewView(row); if (ids.has(row.id)) fail(); ids.add(row.id); });
  if (value.list.length > value.count || (value.list.length && (page - 1) * limit + value.list.length > value.count)) fail(); return value;
}
function freeze(value) { if (value && typeof value === 'object') { Object.keys(value).forEach(key => freeze(value[key])); Object.freeze(value); } return value; }
function localKey(kind, target) { if (sequence >= Number.MAX_SAFE_INTEGER) fail('本地提交代次已用完，请重新进入页面'); sequence += 1; return kind + ':' + target + ':' + Date.now().toString(36) + ':' + sequence.toString(36); }
export function createIntent(quote, draft) {
  reviewQuote(quote, quote.root_order_id, quote.source_item_id); if (!quote.allowed_actions.submit || quote.review !== null) fail('当前商品尚不可新增评价');
  object(draft, ['product_score', 'service_score', 'comment', 'pics']);
  const body = { schema: 1, product_score: score(draft.product_score), service_score: score(draft.service_score), comment: text(draft.comment, 0, 512), pics: list(draft.pics, 8).map(pictureRef) };
  return freeze({ rootId: quote.root_order_id, itemId: quote.source_item_id, body, wire: JSON.stringify(body), localKey: localKey('create', quote.root_order_id + '/' + quote.source_item_id) });
}
export function intentWire(intent) {
  if (!intent || !Object.isFrozen(intent) || !Object.isFrozen(intent.body) || typeof intent.wire !== 'string' || intent.wire !== JSON.stringify(intent.body) || typeof intent.localKey !== 'string' || !intent.localKey) fail('原提交内容已变化，请重新进入页面');
  if (intent.action === undefined) {
    object(intent, ['rootId', 'itemId', 'body', 'wire', 'localKey']); routeId(intent.rootId); routeId(intent.itemId);
    object(intent.body, ['schema', 'product_score', 'service_score', 'comment', 'pics']);
    if (intent.body.schema !== 1 || !Object.isFrozen(intent.body.pics)) fail(); score(intent.body.product_score); score(intent.body.service_score); text(intent.body.comment, 0, 512); list(intent.body.pics, 8).forEach(pictureRef);
  } else {
    object(intent, ['action', 'reviewId', 'rootId', 'itemId', 'base', 'body', 'wire', 'localKey']); routeId(intent.reviewId); routeId(intent.rootId); routeId(intent.itemId); reviewView(intent.base);
    if (intent.base.id !== intent.reviewId || intent.base.root_order_id !== intent.rootId || intent.base.source_item_id !== intent.itemId) fail();
    if (intent.action === 'reply') { object(intent.body, ['schema', 'content']); text(intent.body.content, 1, 300); }
    else if (intent.action === 'moderate') { object(intent.body, ['schema', 'status']); integer(intent.body.status, 1, 2); }
    else fail(); if (intent.body.schema !== 1) fail();
  }
  return intent.wire;
}
export function reviewWrite(value, intent) {
  intentWire(intent); if (intent.action !== undefined) fail(); reviewView(value);
  if (value.root_order_id !== intent.rootId || value.source_item_id !== intent.itemId || value.product_score !== intent.body.product_score || value.service_score !== intent.body.service_score || value.comment !== intent.body.comment || value.pics.length !== intent.body.pics.length) fail('评价写入结果尚未确认，请读取原商品评价');
  // Raw attachment refs are not expanded public URLs. Their equality cannot be inferred here.
  return value;
}
function commandIntent(view, action, body) {
  reviewView(view); const base = { ...view, pics: view.pics.slice() };
  return freeze({ action, reviewId: view.id, rootId: view.root_order_id, itemId: view.source_item_id, base, body, wire: JSON.stringify(body), localKey: localKey(action, view.id) });
}
export function replyIntent(view, content) {
  reviewView(view); if (view.status !== 1) fail('仅可回复当前已通过审核的评价');
  return commandIntent(view, 'reply', { schema: 1, content: text(content, 1, 300) });
}
export function moderateIntent(view, status) { return commandIntent(view, 'moderate', { schema: 1, status: integer(status, 1, 2) }); }
export function commandMatches(value, intent) {
  intentWire(intent); if (!intent.action) fail(); reviewView(value); const base = intent.base;
  for (const key of ['id', 'root_order_id', 'source_item_id', 'product_id', 'product_score', 'service_score', 'comment', 'add_time', 'suk']) if (value[key] !== base[key]) fail('评价原件或目标未确认');
  if (value.pics.length !== base.pics.length || value.pics.some((picture, index) => picture !== base.pics[index])) fail('评价图片原序未确认');
  return intent.action === 'reply' ? value.is_reply === 1 && value.merchant_reply_content === intent.body.content : value.status === intent.body.status;
}
export function commandWrite(value, intent) { if (!commandMatches(value, intent)) fail('本次处理结果尚未确认，请重新读取'); return value; }
export function pageQuery(value, platform = false) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) fail(); const allowed = ['page', 'limit', 'status', 'is_reply', 'product_id']; if (platform) allowed.push('mer_id');
  if (Object.keys(value).some(key => !allowed.includes(key))) fail(); const result = { page: integer(value.page, 1, INT_MAX), limit: integer(value.limit, 1, 100) };
  ['status', 'is_reply', 'product_id', 'mer_id'].forEach(key => { if (!Object.prototype.hasOwnProperty.call(value, key)) return; const range = key === 'status' ? [0, 2] : key === 'is_reply' ? [0, 1] : key === 'mer_id' ? [1, 4294967295] : [1, INT_MAX]; result[key] = integer(value[key], range[0], range[1]); }); return result;
}
export function errorText(error) { return error && typeof error.message === 'string' && error.message ? error.message : typeof error === 'string' && error ? error : '请求未完成，请重新读取评价资料'; }
export function statusText(value) { return ['待审核', '审核通过', '审核未通过'][integer(value, 0, 2)]; }
export function timeText(value) { integer(value, 0, INT_MAX); if (!value) return '—'; const date = new Date(value * 1000); return date.toLocaleString(); }
