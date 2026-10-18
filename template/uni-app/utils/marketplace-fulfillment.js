// Public display contracts only. No authentication, network, storage or fulfillment eligibility is derived here.
const INT_MAX = 2147483647;
const UINT_MAX = 4294967295;
const SUMMARY = ['shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'child_count'];
const CHILD = ['schema', 'scope', 'id', 'order_id', 'root_order_id', 'root_order_no', 'marketplace_trade_version', 'merchant_name_snapshot', 'shipping_type', 'carrier_code', 'carrier_name', 'tracking_no', 'shipped_at', 'receipt_due_at', 'last_received_at', 'received_at', 'shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'items', 'payment_owner_order_id', 'paid', 'pay_type', 'pay_time', 'pay_type_name'];
const ITEM = ['id', 'product_id', 'product_name', 'sku_name', 'image', 'quantity', 'unit_price', 'line_price', 'postage_price'];

function unavailable() { throw new Error('订单保存信息不可用，请重新加载'); }
function row(value) { if (!value || typeof value !== 'object' || Array.isArray(value)) unavailable(); return value; }
function keys(value, expected) { row(value); const actual = Object.keys(value); if (actual.length !== expected.length || actual.some(key => !expected.includes(key))) unavailable(); }
function text(value) { if (typeof value !== 'string' || value.includes('\u0000')) unavailable(); return value; }
function integer(value, minimum = 0, maximum = UINT_MAX) { if (!Number.isSafeInteger(value) || value < minimum || value > maximum) unavailable(); return value; }
function nativeInteger(value, minimum = 0, maximum = UINT_MAX) {
  if (typeof value === 'string') { if (!/^(0|[1-9][0-9]*)$/.test(value) || value.length > 10) unavailable(); value = Number(value); }
  return integer(value, minimum, maximum);
}
function list(value) { if (!Array.isArray(value)) unavailable(); return value; }
function stamp(value, nullable = false) { if (nullable && value === null) return null; return integer(value, 1); }
export function savedFulfillmentMoney(value) { if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/.test(value)) unavailable(); return value; }

export function savedOrderKind(value) {
  const order = row(value);
  if (!Object.prototype.hasOwnProperty.call(order, 'marketplace_trade_version')) unavailable();
  const version = order.marketplace_trade_version;
  if (order.scope === 'marketplace_fulfillment_child_v1') {
    if (version !== 1 || order.schema !== 1) unavailable();
    return 'child';
  }
  if (version === null || version === 0 || version === '0') {
    if (Object.prototype.hasOwnProperty.call(order, 'scope') || Object.prototype.hasOwnProperty.call(order, 'schema')) unavailable();
    return 'legacy';
  }
  if ((version !== 1 && version !== '1') || Object.prototype.hasOwnProperty.call(order, 'scope') || Object.prototype.hasOwnProperty.call(order, 'schema') || nativeInteger(order.pid, 0, INT_MAX) !== 0) unavailable();
  return 'root';
}

function extension(order) {
  const summary = order.fulfillment_summary;
  const actions = row(order.allowed_actions); keys(actions, ['ship', 'receive']);
  if (actions.ship !== false || actions.receive !== false) unavailable();
  if (summary === null) { if (actions.ship !== false) unavailable(); }
  else {
    keys(summary, SUMMARY);
    if (!['unshipped', 'partial', 'shipped'].includes(summary.shipping_state) || !['not_shipped', 'pending', 'partial', 'received'].includes(summary.receipt_state) || typeof summary.closed !== 'boolean') unavailable();
    SUMMARY.slice(3).forEach(key => integer(summary[key]));
    if (summary.original !== nativeInteger(order.total_num, 1) || summary.cancelled_unshipped !== 0 || summary.returned_accepted !== 0) unavailable();
  }
  return { summary, actions: { ship: actions.ship, receive: false } };
}

function forms(value) {
  const seen = new Set();
  if (list(value).length > 500) unavailable();
  return value.map(group => {
    keys(group, ['product_id', 'product_name', 'definition_sha256', 'fields']);
    integer(group.product_id, 1, INT_MAX); text(group.product_name);
    if (seen.has(group.product_id) || !/^[0-9a-f]{64}$/.test(text(group.definition_sha256)) || !list(group.fields).length || group.fields.length > 10) unavailable();
    seen.add(group.product_id);
    const fields = group.fields.map((field, index) => {
      keys(field, ['field_index', 'title', 'label', 'status', 'value']);
      if (field.field_index !== index || typeof field.status !== 'boolean' || !['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'].includes(field.label)) unavailable();
      text(field.title);
      if (field.label === 'img') { if (list(field.value).length > 8) unavailable(); field.value.forEach(text); } else text(field.value);
      return { field_index: index, title: field.title, label: field.label, status: field.status, value: field.label === 'img' ? field.value.slice() : field.value };
    });
    return { product_id: group.product_id, product_name: group.product_name, definition_sha256: group.definition_sha256, fields };
  });
}

export function readFulfillmentChild(value, detail = false) {
  const child = row(value); keys(child, detail ? CHILD.concat(['recipient', 'mark', 'custom_form']) : CHILD);
  if (child.schema !== 1 || child.scope !== 'marketplace_fulfillment_child_v1' || child.marketplace_trade_version !== 1 || child.paid !== 1 || child.shipping_state !== 'shipped' || typeof child.closed !== 'boolean') unavailable();
  ['id', 'root_order_id', 'payment_owner_order_id'].forEach(key => integer(child[key], 1, INT_MAX));
  integer(child.shipping_type, 1, 2);
  ['original', 'shipped'].forEach(key => integer(child[key], 1));
  ['received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'receipt_due_at'].forEach(key => integer(child[key]));
  ['shipped_at', 'pay_time'].forEach(key => stamp(child[key]));
  ['last_received_at', 'received_at'].forEach(key => stamp(child[key], true));
  ['order_id', 'root_order_no', 'merchant_name_snapshot', 'carrier_code', 'carrier_name', 'tracking_no', 'pay_type', 'pay_type_name'].forEach(key => text(child[key]));
  if (child.id === child.root_order_id || child.payment_owner_order_id !== child.root_order_id || child.original !== child.shipped || child.received > child.shipped || child.to_receive !== child.shipped - child.received || child.cancelled_unshipped !== 0 || child.returned_accepted !== 0 || child.to_ship !== 0 || child.closed !== (child.received === child.shipped) || child.receipt_state !== (child.received === 0 ? 'pending' : child.closed ? 'received' : 'partial') || (child.received === 0) !== (child.last_received_at === null) || child.received_at !== (child.closed ? child.last_received_at : null)) unavailable();
  const seen = new Set(); if (!list(child.items).length || child.items.length > 500) unavailable();
  const items = child.items.map(item => {
    keys(item, ITEM); integer(item.id, 1, INT_MAX); integer(item.product_id, 1, INT_MAX); integer(item.quantity, 1, INT_MAX);
    if (seen.has(item.id)) unavailable(); seen.add(item.id);
    ['product_name', 'sku_name', 'image'].forEach(key => text(item[key]));
    ['unit_price', 'line_price', 'postage_price'].forEach(key => savedFulfillmentMoney(item[key]));
    return { ...item };
  });
  const out = { ...child, items };
  if (detail) {
    keys(child.recipient, ['real_name', 'user_phone', 'user_address']); Object.values(child.recipient).forEach(text);
    out.recipient = { ...child.recipient }; out.mark = text(child.mark); out.custom_form = forms(child.custom_form);
  }
  return out;
}

function childrenPage(order, summary) {
  const page = order.fulfillment_children;
  if (page === null) return null;
  if (summary === null) unavailable(); keys(page, ['page', 'limit', 'count', 'list']);
  integer(page.page, 1); integer(page.limit, 1, 100); integer(page.count);
  if (page.count !== summary.child_count) unavailable();
  const rows = list(page.list); const offset = (page.page - 1) * page.limit;
  if (rows.length !== Math.max(0, Math.min(page.limit, page.count - offset))) unavailable();
  const seen = new Set(); const id = nativeInteger(order.id, 1, INT_MAX); const number = text(order.order_id);
  return { page: page.page, limit: page.limit, count: page.count, list: rows.map(value => {
    const child = readFulfillmentChild(value);
    if (seen.has(child.id) || child.root_order_id !== id || child.root_order_no !== number) unavailable(); seen.add(child.id); return child;
  }) };
}

export function readOrderFulfillment(order, detail = false) {
  const kind = savedOrderKind(order);
  if (kind === 'legacy') return { kind, order };
  if (kind === 'child') { const child = readFulfillmentChild(order, detail); return { kind, order: child, package: child }; }
  nativeInteger(order.id, 1, INT_MAX); text(order.order_id);
  const checked = extension(order);
  savedFulfillmentMoney(order.total_price); savedFulfillmentMoney(order.pay_price);
  ['total_price', 'pay_price', 'pay_postage', 'total_postage', 'refund_price', 'refund_pay_price', 'coupon_price', 'deduction_price', 'integral_price', 'gift_price', 'vip_true_price', 'levelPrice', 'memberPrice', 'postage_price', 'member_price', 'refunded_price'].forEach(key => {
    if (Object.prototype.hasOwnProperty.call(order, key)) savedFulfillmentMoney(order[key]);
  });
  if (detail || Object.prototype.hasOwnProperty.call(order, 'cartInfo')) list(order.cartInfo).forEach(cart => {
    row(cart); savedFulfillmentMoney(cart.truePrice);
    const product = row(cart.productInfo);
    if (product.attrInfo) row(product.attrInfo);
  });
  if (detail) {
    forms(order.custom_form);
    savedFulfillmentMoney(order.vip_true_price);
    const status = row(order._status);
    if (!Number.isSafeInteger(status._type)) unavailable();
    ['_title', '_msg', '_payType'].forEach(key => text(status[key]));
  }
  if (detail && !Object.prototype.hasOwnProperty.call(order, 'fulfillment_children')) unavailable();
  return { kind, order, summary: checked.summary, actions: checked.actions, children: detail ? childrenPage(order, checked.summary) : undefined };
}

export function rootFulfillmentText(summary) {
  if (summary === null) return '履约资料暂不可用';
  if (summary.shipping_state === 'partial') return '部分发货';
  if (summary.shipping_state === 'unshipped') return '待发货';
  return summary.receipt_state === 'received' ? '已收货' : summary.receipt_state === 'partial' ? '部分收货' : '待收货';
}
export function childFulfillmentText(child) { return child.receipt_state === 'received' ? '已收货' : child.receipt_state === 'partial' ? '部分收货' : '待收货'; }
export function fulfillmentSummaryText(order) { const view = readOrderFulfillment(order); return view.kind === 'root' ? rootFulfillmentText(view.summary) : view.kind === 'child' ? childFulfillmentText(view.package) : ''; }
export function fulfillmentPageQuery(page = 1, limit = 20) { return { fulfillment_page: integer(page, 1), fulfillment_limit: integer(limit, 1, 100) }; }
export function fulfillmentTime(value) {
  if (value === null || value === 0) return '—'; stamp(value);
  const date = new Date(value * 1000); const pad = number => String(number).padStart(2, '0');
  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
}


/** Dedicated customer wrapper only; the flat child contract never gains actions. */
export function fulfillmentChildNumber(value) {
  text(value);
  if (!value || value !== value.replace(/^[ \t\n\r\u0000\u000b]+|[ \t\n\r\u0000\u000b]+$/g, '')) unavailable();
  let length;
  try { length = encodeURIComponent(value).replace(/%[a-fA-F0-9]{2}|./g, 'x').length; } catch (error) { unavailable(); }
  if (length > 32) unavailable(); return value;
}

export function readCustomerReceipt(value, childNo) {
  keys(value, ['package', 'allowed_actions', 'receipt_selection']);
  const child = readFulfillmentChild(value.package, true);
  if (fulfillmentChildNumber(child.order_id) !== fulfillmentChildNumber(childNo)) unavailable();
  keys(value.allowed_actions, ['receive']); if (typeof value.allowed_actions.receive !== 'boolean') unavailable();
  const selection = value.receipt_selection; keys(selection, ['schema', 'coordinate', 'items']);
  if (selection.schema !== 1 || selection.coordinate !== 'child_item_ordinal' || list(selection.items).length !== child.items.length) unavailable();
  let received = 0; let eligible = 0; let original = 0; let previousId = 0;
  const items = selection.items.map((item, index) => {
    keys(item, ['item_id', 'quantity', 'received_quantity', 'eligible_quantity', 'eligible_segments']);
    const saved = child.items[index]; integer(item.item_id, 1, INT_MAX); integer(item.quantity, 1, INT_MAX);
    integer(item.received_quantity, 0, INT_MAX); integer(item.eligible_quantity, 0, INT_MAX);
    if (item.item_id !== saved.id || item.quantity !== saved.quantity || item.item_id <= previousId
      || item.received_quantity > item.quantity || item.eligible_quantity > item.quantity - item.received_quantity) unavailable();
    previousId = item.item_id; let count = 0; let last = 0;
    const segments = list(item.eligible_segments).map(segment => {
      keys(segment, ['first', 'last']); integer(segment.first, 1, item.quantity); integer(segment.last, segment.first, item.quantity);
      if (segment.first <= last || (last > 0 && segment.first === last + 1)) unavailable();
      last = segment.last; count += segment.last - segment.first + 1; return { first: segment.first, last: segment.last };
    });
    if (count !== item.eligible_quantity) unavailable();
    received += item.received_quantity; eligible += item.eligible_quantity; original += item.quantity;
    return { item_id: item.item_id, quantity: item.quantity, received_quantity: item.received_quantity,
      eligible_quantity: item.eligible_quantity, eligible_segments: segments };
  });
  if (original !== child.original || received !== child.received || eligible > child.to_receive || value.allowed_actions.receive !== (eligible > 0)) unavailable();
  return { package: child, allowed_actions: { receive: value.allowed_actions.receive },
    receipt_selection: { schema: 1, coordinate: 'child_item_ordinal', items } };
}

const RECEIPT_OPERATION = ['schema', 'operation_id', 'action', 'root_order_id', 'root_order_no', 'child_order_id',
  'child_order_no', 'quantity', 'created_at', 'committed_at', 'shipped_at', 'receipt_due_at', 'received_at', 'fulfillment_summary'];
export function readReceiptResult(value, intent) {
  row(value);
  if (Object.prototype.hasOwnProperty.call(value, 'operation_created')) {
    keys(value, ['state', 'operation_created', 'root_order_id', 'child_order_id']);
    if (value.state !== 'already_received' || value.operation_created !== false || value.root_order_id !== intent.rootId
      || value.child_order_id !== intent.childId) unavailable();
    return { kind: 'no_operation', result: { ...value } };
  }
  keys(value, RECEIPT_OPERATION);
  if (value.schema !== 1 || value.action !== 'receive' || value.root_order_id !== intent.rootId
    || value.root_order_no !== intent.rootNo || value.child_order_id !== intent.childId || value.child_order_no !== intent.childNo
    || typeof value.operation_id !== 'string' || !/^[1-9][0-9]{0,19}$/.test(value.operation_id)
    || (value.operation_id.length === 20 && value.operation_id > '18446744073709551615')) unavailable();
  integer(value.quantity, 1); stamp(value.created_at); stamp(value.committed_at); stamp(value.received_at);
  if (value.committed_at < value.created_at || value.received_at !== value.created_at || value.shipped_at !== null || value.receipt_due_at !== null) unavailable();
  if (intent.body.selection.mode === 'items') {
    let quantity = 0;
    intent.body.selection.items.forEach(item => item.segments.forEach(segment => { quantity += segment.last - segment.first + 1; }));
    if (quantity !== value.quantity) unavailable();
  }
  const summary = value.fulfillment_summary; keys(summary, SUMMARY);
  if (!['unshipped', 'partial', 'shipped'].includes(summary.shipping_state)
    || !['not_shipped', 'pending', 'partial', 'received'].includes(summary.receipt_state) || typeof summary.closed !== 'boolean') unavailable();
  SUMMARY.slice(3).forEach(key => integer(summary[key]));
  if (summary.original < 1 || summary.shipped > summary.original || summary.received > summary.shipped
    || summary.cancelled_unshipped !== 0 || summary.returned_accepted !== 0
    || summary.to_ship !== summary.original - summary.shipped || summary.to_receive !== summary.shipped - summary.received
    || ((summary.child_count === 0) !== (summary.shipped === 0)) || summary.child_count > summary.shipped
    || summary.shipping_state !== (summary.shipped === 0 ? 'unshipped' : summary.to_ship > 0 ? 'partial' : 'shipped')
    || summary.receipt_state !== (summary.shipped === 0 ? 'not_shipped' : summary.received === summary.shipped ? 'received' : summary.received === 0 ? 'pending' : 'partial')
    || summary.closed !== (summary.to_ship === 0 && summary.to_receive === 0) || value.quantity > summary.received) unavailable();
  return { kind: 'operation', result: { ...value, fulfillment_summary: { ...summary } } };
}
