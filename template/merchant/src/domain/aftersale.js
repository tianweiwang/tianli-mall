// Public saved aftersale DTOs and immutable submissions. This module grants no permission.
const INT_MAX = 2147483647;
const UINT_MAX = 4294967295;
const KINDS = ['money_only', 'cancel_unshipped', 'return_goods'];
export const businessLabels = { REQUESTED: '待处理', APPROVED: '已批准', WITHDRAWN: '已撤回', REJECTED: '已拒绝', COMPLETED: '业务已完成' };
export const physicalLabels = { NONE: '无需退件', CANCEL_PENDING: '待取消未发商品', CANCELLED: '未发商品已取消', RETURN_PENDING: '待退件验收', RETURN_PARTIAL: '部分退件已验收', RETURN_ACCEPTED: '退件已验收' };
export const financeLabels = { NOT_SUBMITTED: '尚未提交资金退款', UNSUPPORTED: '资金退款暂不支持', PROCESSING: '资金处理中', UNKNOWN: '资金结果待确认', PARTIAL: '部分资金已确认', SUCCEEDED: '资金已确认', FAILED: '资金处理失败' };
export const kindLabels = { money_only: '仅申请金额', cancel_unshipped: '取消未发商品', return_goods: '退回已发商品' };
export function fail(message = '售后资料返回不完整，请重新读取') { throw new Error(message); }
export function exact(value, names) {
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length !== names.length || names.some(name => !Object.prototype.hasOwnProperty.call(value, name))) fail();
  return value;
}
export function integer(value, min = 0, max = INT_MAX) { if (!Number.isSafeInteger(value) || value < min || value > max) fail(); return value; }
export function recordId(value) { return integer(value, 1); }
export function routeId(value) { if (typeof value === 'string' && /^[1-9][0-9]*(?![\s\S])/.test(value)) value = Number(value); return recordId(value); }
export function u64(value) {
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,19}(?![\s\S])/.test(value) || (value.length === 20 && value > '18446744073709551615')) fail(); return value;
}
export function text(value, max, nonempty = false) {
  if (typeof value !== 'string' || value.includes('\0') || (nonempty && !value)) fail();
  let chars; try { encodeURIComponent(value); chars = Array.from(value).length; } catch (error) { fail(); }
  if (chars > max) fail(); return value;
}
export function money(value, positive = false) {
  if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})\.[0-9]{2}(?![\s\S])/.test(value) || (positive && value === '0.00')) fail('请填写两位小数的有效申请金额'); return value;
}
export function cents(value) { money(value); const parts = value.split('.'); return Number(parts[0]) * 100 + Number(parts[1]); }
function list(value, maximum = Number.MAX_SAFE_INTEGER) { if (!Array.isArray(value) || value.length > maximum) fail(); return value; }
function distinct(values) { if (new Set(values).size !== values.length) fail(); }
function flags(value, names) { exact(value, names); names.forEach(name => { if (typeof value[name] !== 'boolean') fail(); }); return value; }
function enumValue(value, labels) { if (!Object.prototype.hasOwnProperty.call(labels, value)) fail(); return value; }
function freeze(value) { if (value && typeof value === 'object') { Object.keys(value).forEach(key => freeze(value[key])); Object.freeze(value); } return value; }
export function returnDestination(value) {
  exact(value, ['recipient_name', 'phone', 'address']);
  const destination = { recipient_name: text(value.recipient_name, 64), phone: text(value.phone, 32), address: text(value.address, 500) };
  if (Object.values(destination).some(part => /^[\s\u0085]*$/u.test(part))) fail('请填写回寄收件人、联系电话和完整地址');
  return destination;
}
function sameReturnDestination(actual, expected) {
  if (actual === null || expected === null) return actual === expected;
  return ['recipient_name', 'phone', 'address'].every(key => actual[key] === expected[key]);
}
export function savedItem(value) {
  exact(value, ['id', 'product_id', 'product_name', 'sku_name', 'image', 'quantity', 'unit_price', 'line_price', 'postage_price']);
  recordId(value.id); integer(value.product_id, 1); text(value.product_name, 65535); text(value.sku_name, 65535); text(value.image, 65535);
  integer(value.quantity, 1); ['unit_price', 'line_price', 'postage_price'].forEach(key => money(value[key])); return value;
}
function segments(value, quantity) {
  let previous = 0; list(value).forEach(part => { exact(part, ['first', 'last']); integer(part.first, 1, quantity); integer(part.last, part.first, quantity); if (part.first <= previous) fail(); previous = part.last; }); return value;
}
export function segmentQuantity(parts) { return parts.reduce((sum, part) => sum + part.last - part.first + 1, 0); }
function returnSelection(value, quote = false) {
  exact(value, quote ? ['schema', 'coordinate', 'items'] : ['schema', 'coordinate', 'child_order_no', 'items']);
  if (value.schema !== 1 || value.coordinate !== 'child_item_ordinal') fail(); if (!quote) text(value.child_order_no, 32, true);
  list(value.items).forEach(row => {
    exact(row, quote ? ['item_id', 'quantity', 'eligible_quantity', 'eligible_segments'] : ['item_id', 'quantity', 'selected_quantity', 'selected_segments', 'accepted_quantity', 'accepted_segments', 'eligible_quantity', 'eligible_segments']);
    recordId(row.item_id); integer(row.quantity, 1); integer(row.eligible_quantity, 0, row.quantity); segments(row.eligible_segments, row.quantity);
    if (segmentQuantity(row.eligible_segments) !== row.eligible_quantity) fail();
    if (!quote) {
      integer(row.selected_quantity, 1, row.quantity); integer(row.accepted_quantity, 0, row.selected_quantity);
      segments(row.selected_segments, row.quantity); segments(row.accepted_segments, row.quantity);
      if (segmentQuantity(row.selected_segments) !== row.selected_quantity || segmentQuantity(row.accepted_segments) !== row.accepted_quantity) fail();
      requireSubset(row.accepted_segments, row.selected_segments); requireSubset(row.eligible_segments, row.selected_segments);
    }
  }); distinct(value.items.map(row => row.item_id)); return value;
}
export function refundView(value) {
  const fields = ['schema', 'scope', 'id', 'refund_no', 'root_order_id', 'root_order_no', 'merchant_name_snapshot', 'operation_kind', 'mode', 'business_state', 'physical_state', 'finance_state', 'requested_amount', 'confirmed_amount', 'held_amount', 'created_at', 'updated_at', 'reason', 'images', 'return_delivery', 'items', 'allowed_actions', 'return_selection'];
  const hasDestination = Object.prototype.hasOwnProperty.call(value || {}, 'return_destination');
  const hasRejectionReason = Object.prototype.hasOwnProperty.call(value || {}, 'rejection_reason');
  exact(value, fields.concat(hasDestination ? ['return_destination'] : [], hasRejectionReason ? ['rejection_reason'] : []));
  if (value.schema !== 2 || value.scope !== 'marketplace_aftersale_v1' || !KINDS.includes(value.operation_kind) || value.mode !== (value.operation_kind === 'return_goods' ? 'return_goods' : 'money_only')) fail();
  recordId(value.id); recordId(value.root_order_id); text(value.refund_no, 50, true); text(value.root_order_no, 32, true); text(value.merchant_name_snapshot, 65535);
  enumValue(value.business_state, businessLabels); enumValue(value.physical_state, physicalLabels); enumValue(value.finance_state, financeLabels);
  ['requested_amount', 'confirmed_amount', 'held_amount'].forEach(key => money(value[key])); integer(value.created_at, 0, UINT_MAX); integer(value.updated_at, 0, UINT_MAX); text(value.reason, 255, true);
  list(value.images, 9).forEach(u64); distinct(value.images);
  if (value.return_delivery !== null) { exact(value.return_delivery, ['carrier_name', 'tracking_no']); text(value.return_delivery.carrier_name, 255, true); text(value.return_delivery.tracking_no, 100, true); }
  list(value.items).forEach(row => { exact(row, ['item', 'selected_quantity', 'accepted_quantity', 'confirmed_amount']); savedItem(row.item); integer(row.selected_quantity, 1, row.item.quantity); integer(row.accepted_quantity, 0, row.selected_quantity); money(row.confirmed_amount); });
  distinct(value.items.map(row => row.item.id)); flags(value.allowed_actions, ['withdraw', 'submit_return', 'approve', 'reject', 'accept_return']);
  if (value.operation_kind === 'return_goods') returnSelection(value.return_selection); else if (value.return_selection !== null) fail();
  if (hasDestination) {
    if (value.operation_kind !== 'return_goods' || !['APPROVED', 'COMPLETED'].includes(value.business_state)) fail();
    returnDestination(value.return_destination);
  }
  if (hasRejectionReason) {
    if (value.business_state !== 'REJECTED') fail();
    text(value.rejection_reason, 255, true);
  }
  return value;
}
export function refundPage(value, page, limit) {
  exact(value, ['schema', 'page', 'limit', 'count', 'list']); if (value.schema !== 2 || value.page !== integer(page, 1) || value.limit !== integer(limit, 1, 100)) fail();
  integer(value.count, 0, UINT_MAX); list(value.list, limit).forEach(refundView); distinct(value.list.map(row => row.id));
  if (value.list.length !== Math.max(0, Math.min(limit, value.count - (page - 1) * limit))) fail(); return value;
}
export function quoteView(value, rootId, page, limit) {
  exact(value, ['schema', 'scope', 'root', 'capacity', 'root_items', 'packages', 'allowed_actions']);
  if (value.schema !== 2 || value.scope !== 'marketplace_aftersale_quote_v1') fail(); exact(value.root, ['id', 'order_no', 'merchant_name_snapshot']);
  if (recordId(value.root.id) !== recordId(rootId)) fail(); text(value.root.order_no, 32, true); text(value.root.merchant_name_snapshot, 65535);
  exact(value.capacity, ['paid', 'confirmed', 'held', 'available', 'currency']); ['paid', 'confirmed', 'held', 'available'].forEach(key => money(value.capacity[key]));
  if (value.capacity.currency !== 'CNY' || cents(value.capacity.confirmed) + cents(value.capacity.held) + cents(value.capacity.available) !== cents(value.capacity.paid)) fail();
  list(value.root_items).forEach(row => { exact(row, ['item', 'available_cancel_quantity', 'available_money_amount']); savedItem(row.item); integer(row.available_cancel_quantity, 0, row.item.quantity); money(row.available_money_amount); }); distinct(value.root_items.map(row => row.item.id));
  exact(value.packages, ['page', 'limit', 'count', 'list']); if (value.packages.page !== page || value.packages.limit !== limit) fail(); integer(value.packages.count, 0, UINT_MAX);
  list(value.packages.list, limit).forEach(row => {
    exact(row, ['package', 'return_selection']); const child = row.package;
    if (!child || typeof child !== 'object' || Array.isArray(child) || !((child.schema === 1 && child.scope === 'marketplace_fulfillment_child_v1') || (child.schema === 2 && child.scope === 'marketplace_fulfillment_child_aftersale_v1')) || child.root_order_id !== rootId || child.root_order_no !== value.root.order_no) fail();
    recordId(child.id); text(child.order_id, 32, true); list(child.items).forEach(savedItem); distinct(child.items.map(item => item.id));
    returnSelection(row.return_selection, true); const saved = new Map(child.items.map(item => [item.id, item]));
    row.return_selection.items.forEach(item => { if (!saved.has(item.item_id) || saved.get(item.item_id).quantity !== item.quantity) fail(); });
  }); distinct(value.packages.list.map(row => row.package.id));
  if (value.packages.list.length !== Math.max(0, Math.min(limit, value.packages.count - (page - 1) * limit))) fail();
  flags(value.allowed_actions, ['apply']); return value;
}
// Interval operations never expand a quantity into individual units.
export function requireSubset(selected, eligible) {
  selected.forEach(part => { let cursor = part.first; for (const free of eligible) { if (free.last < cursor) continue; if (free.first > cursor) break; cursor = Math.max(cursor, free.last + 1); if (cursor > part.last) return; } if (cursor <= part.last) fail('所选序号超出当前允许区间，请重新读取'); });
}
export function segmentText(parts) { return parts.map(part => part.first === part.last ? String(part.first) : part.first + '-' + part.last).join(', '); }
export function parseSegments(value, eligible, quantity) {
  text(value, 16000); if (!value.trim()) return [];
  const parts = value.split(',').map(token => { const match = /^\s*([1-9][0-9]*)(?:\s*-\s*([1-9][0-9]*))?\s*(?![\s\S])/.exec(token); if (!match) fail('请按 1-2, 4 填写本次实际序号'); return { first: integer(Number(match[1]), 1, quantity), last: integer(Number(match[2] || match[1]), 1, quantity) }; }).sort((a, b) => a.first - b.first);
  segments(parts, quantity); requireSubset(parts, eligible); return parts;
}
export function ordinalItems(rows, inputs) {
  const items = []; rows.forEach(row => { const parts = parseSegments(String(inputs[row.item_id] || ''), row.eligible_segments, row.quantity); if (parts.length) items.push({ item_id: row.item_id, segments: parts }); });
  if (!items.length) fail('请选择本次实际退件序号'); return items.sort((a, b) => a.item_id - b.item_id);
}
let sequence = 0;
export function newKey(kind, id) { sequence += 1; if (!Number.isSafeInteger(sequence)) fail('请重新进入页面'); return 'aftersale:' + kind + ':' + id + ':' + Date.now().toString(36) + ':' + sequence.toString(36) + ':' + Math.random().toString(36).slice(2, 14); }
function bodyLimit(body) { const wire = JSON.stringify(body); let bytes; try { bytes = encodeURIComponent(wire).replace(/%[A-Fa-f0-9]{2}|./g, 'x').length; } catch (error) { fail(); } if (bytes > 65536) fail('本次提交内容过长'); return wire; }
export function applyIntent(quote, draft) {
  if (!quote || quote.allowed_actions.apply !== true || !KINDS.includes(draft.operation_kind)) fail('当前暂不能新建申请，请重新读取');
  const kind = draft.operation_kind; money(draft.amount, true); if (cents(draft.amount) > cents(quote.capacity.available)) fail('申请金额超出当前可用金额'); text(draft.reason, 255, true);
  const images = list(draft.images, 9).map(u64); if (!images.length) fail('请先成功上传至少一张私有凭证'); distinct(images);
  let selection;
  if (kind === 'return_goods') {
    const pack = quote.packages.list.find(row => row.package.id === draft.child_id); if (!pack) fail('请选择当前页实际包裹');
    selection = { mode: 'child_items', child_order_no: pack.package.order_id, items: ordinalItems(pack.return_selection.items, draft.segments) };
  } else {
    const items = [];
    quote.root_items.forEach(row => { const raw = String(draft.quantities[row.item.id] || ''); if (!raw || raw === '0') return; if (!/^[1-9][0-9]*(?![\s\S])/.test(raw)) fail('请填写整数商品数量'); const maximum = kind === 'cancel_unshipped' ? row.available_cancel_quantity : row.item.quantity; items.push({ item_id: row.item.id, quantity: integer(Number(raw), 1, maximum) }); });
    if (!items.length) fail('请选择实际保存商品与申请数量'); selection = { mode: 'root_quantities', items: items.sort((a, b) => a.item_id - b.item_id) };
  }
  const body = { schema: 2, idem_key: newKey('apply', quote.root.id), operation_kind: kind, amount: draft.amount, reason: draft.reason, images, selection };
  const wire = bodyLimit(body); return freeze({ action: 'apply', rootId: quote.root.id, rootNo: quote.root.order_no, refundId: null, refundNo: null, body, wire });
}
export function commandIntent(view, action, draft = {}) {
  const flagsByAction = { withdraw: 'withdraw', approve: 'approve', reject: 'reject', express: 'submit_return', accept_return: 'accept_return' };
  if (!flagsByAction[action] || view.allowed_actions[flagsByAction[action]] !== true) fail('当前暂不能执行此操作，请重新读取');
  const body = { schema: 2, idem_key: newKey(action, view.id) };
  let expectedReturnDestination = Object.prototype.hasOwnProperty.call(view, 'return_destination') ? returnDestination(view.return_destination) : null;
  if (action === 'approve' && view.operation_kind === 'return_goods') {
    body.return_destination = returnDestination(draft.return_destination);
    expectedReturnDestination = returnDestination(body.return_destination);
  }
  if (action === 'reject') body.reason = text(draft.reason, 255, true);
  if (action === 'express') { body.refund_no = view.refund_no; body.return_delivery = { carrier_name: text(draft.carrier_name, 255, true), tracking_no: text(draft.tracking_no, 100, true) }; }
  if (action === 'accept_return') { if (!view.return_selection) fail(); body.items = ordinalItems(view.return_selection.items, draft.segments || {}); }
  const wire = bodyLimit(body); return freeze({ action, rootId: view.root_order_id, rootNo: view.root_order_no, refundId: view.id, refundNo: view.refund_no, expectedReturnDestination, body, wire });
}
export function intentWire(intent) { if (!intent || bodyLimit(intent.body) !== intent.wire) fail('原提交已变化，请重新进入页面'); return intent.wire; }
export function actionResult(value, intent) {
  exact(value, ['schema', 'scope', 'action_id', 'action', 'refund', 'committed_at', 'acceptance_ids']); intentWire(intent);
  if (value.schema !== 2 || value.scope !== 'marketplace_aftersale_action_v1' || value.action !== intent.action) fail('本次提交结果尚未确认，请重试原提交');
  if (Object.prototype.hasOwnProperty.call(value.refund || {}, 'rejection_reason')) fail();
  u64(value.action_id); integer(value.committed_at, 0, UINT_MAX); const view = refundView(value.refund);
  if (view.root_order_id !== intent.rootId || view.root_order_no !== intent.rootNo || (intent.refundId !== null && (view.id !== intent.refundId || view.refund_no !== intent.refundNo))) fail('本次提交目标尚未确认，请重试原提交');
  list(value.acceptance_ids).forEach(u64); distinct(value.acceptance_ids); if ((intent.action === 'accept_return') !== (value.acceptance_ids.length > 0)) fail();
  if (intent.action === 'apply' && (view.operation_kind !== intent.body.operation_kind || view.requested_amount !== intent.body.amount || view.reason !== intent.body.reason || JSON.stringify(view.images) !== JSON.stringify(intent.body.images))) fail('申请结果尚未完整确认，请重试原提交');
  if (intent.action === 'express' && (!view.return_delivery || ['carrier_name', 'tracking_no'].some(key => view.return_delivery[key] !== intent.body.return_delivery[key]))) fail();
  const submittedDestination = intent.action === 'approve' && Object.prototype.hasOwnProperty.call(intent.body, 'return_destination');
  if (submittedDestination || Object.prototype.hasOwnProperty.call(intent, 'expectedReturnDestination')) {
    const actual = Object.prototype.hasOwnProperty.call(view, 'return_destination') ? returnDestination(view.return_destination) : null;
    const expected = submittedDestination ? returnDestination(intent.body.return_destination) :
      intent.expectedReturnDestination === null ? null : returnDestination(intent.expectedReturnDestination);
    if (!sameReturnDestination(actual, expected)) fail('回寄地址结果尚未完整确认，请重试原提交');
  }
  return value;
}
export function fileView(value, rootId) {
  exact(value, ['schema', 'scope', 'id', 'root_order_id', 'media_type', 'byte_size', 'width', 'height', 'sha256', 'created_at', 'claim_expires_at']);
  if (value.schema !== 1 || value.scope !== 'marketplace_aftersale_file_v1' || recordId(value.root_order_id) !== recordId(rootId) || !['image/jpeg', 'image/png', 'image/webp'].includes(value.media_type) || !/^[a-f0-9]{64}(?![\s\S])/.test(value.sha256)) fail('私有文件资料未确认');
  u64(value.id); integer(value.byte_size, 1, 5242880); integer(value.width, 1, 4096); integer(value.height, 1, 4096); integer(value.created_at, 0, UINT_MAX); integer(value.claim_expires_at, 0, UINT_MAX); return value;
}
export function readTicket(value, rootId, fileId, refundId) {
  exact(value, ['schema', 'scope', 'file', 'refund_id', 'read_ticket', 'issued_at', 'expires_at']);
  if (value.schema !== 1 || value.scope !== 'marketplace_aftersale_read_ticket_v1') fail(); const file = fileView(value.file, rootId);
  if (file.id !== u64(fileId) || value.refund_id !== refundId || typeof value.read_ticket !== 'string' || value.read_ticket.length !== 43 || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(value.read_ticket)) fail();
  integer(value.issued_at, 1, UINT_MAX - 300); integer(value.expires_at, 1, UINT_MAX); if (value.expires_at !== value.issued_at + 300) fail(); return value;
}
export function errorText(error) { return error && typeof error.msg === 'string' ? error.msg : error && error.message ? error.message : typeof error === 'string' ? error : '请求未完成，请重试'; }
export function timeText(value) { return value ? new Date(value * 1000).toLocaleString() : '—'; }
