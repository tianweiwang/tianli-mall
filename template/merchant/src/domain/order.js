const INT_MAX = 2147483647;
const UINT_MAX = 4294967295;
const labels = ['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'];

function unavailable() { throw new Error('订单保存信息不完整，请重新查询'); }
function row(value) { if (!value || typeof value !== 'object' || Array.isArray(value)) unavailable(); return value; }
function text(value) { if (typeof value !== 'string' || value.includes('\u0000')) unavailable(); return value; }
function integer(value, min = 0, max = INT_MAX) { if (!Number.isInteger(value) || value < min || value > max) unavailable(); return value; }
function money(value) { if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/.test(value)) unavailable(); return value; }

function exactKeys(value, names) {
  const data = row(value);
  if (Object.keys(data).length !== names.length || names.some((key) => !Object.prototype.hasOwnProperty.call(data, key))) unavailable();
  return data;
}

function fulfillmentSummary(value, totalNum) {
  if (value === null) return null;
  const summary = exactKeys(value, ['shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received',
    'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'child_count']);
  if (!['unshipped', 'partial', 'shipped'].includes(summary.shipping_state)
    || !['not_shipped', 'pending', 'partial', 'received'].includes(summary.receipt_state)
    || typeof summary.closed !== 'boolean' || summary.original !== integer(totalNum, 1, UINT_MAX)) unavailable();
  const result = { shipping_state: summary.shipping_state, receipt_state: summary.receipt_state, closed: summary.closed,
    original: integer(summary.original, 1, UINT_MAX), shipped: integer(summary.shipped, 0, UINT_MAX),
    received: integer(summary.received, 0, UINT_MAX), cancelled_unshipped: integer(summary.cancelled_unshipped, 0, UINT_MAX),
    returned_accepted: integer(summary.returned_accepted, 0, UINT_MAX), to_ship: integer(summary.to_ship, 0, UINT_MAX),
    to_receive: integer(summary.to_receive, 0, UINT_MAX), child_count: integer(summary.child_count, 0, UINT_MAX) };
  if (result.received > result.shipped || result.shipped > result.original
    || result.cancelled_unshipped !== 0 || result.returned_accepted !== 0
    || result.to_ship !== result.original - result.shipped || result.to_receive !== result.shipped - result.received
    || ((result.child_count === 0) !== (result.shipped === 0)) || result.child_count > result.shipped
    || result.shipping_state !== (result.shipped === 0 ? 'unshipped' : result.to_ship > 0 ? 'partial' : 'shipped')
    || result.receipt_state !== (result.shipped === 0 ? 'not_shipped' : result.received === result.shipped ? 'received' : result.received === 0 ? 'pending' : 'partial')
    || result.closed !== (result.to_ship === 0 && result.to_receive === 0)) unavailable();
  return result;
}

function fulfillmentActions(value, summary) {
  const actions = exactKeys(value, ['ship', 'receive']);
  if (typeof actions.ship !== 'boolean' || actions.receive !== false || (summary === null && actions.ship !== false)) unavailable();
  return { ship: actions.ship, receive: actions.receive };
}

// Native order PKs fit signed INT; route strings remain strings without numeric conversion.
export function orderRecordId(value) {
  if (typeof value === 'number') { integer(value, 1); return String(value); }
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,9}$/.test(value)
    || (value.length === 10 && value > '2147483647')) throw new Error('订单编号不正确');
  return value;
}

export function orderQuery(filters, page, limit) {
  const query = { page: integer(page, 1), limit: integer(limit, 1, 100) };
  const orderNumber = text(filters.order_id).trim();
  if (orderNumber.length > 32) throw new Error('请填写完整订单号，最多32个字符');
  if (orderNumber !== '') query.order_id = orderNumber;
  ['paid', 'is_cancel'].forEach((key) => { if (filters[key] !== '') query[key] = integer(filters[key], 0, 1); });
  return query;
}

function savedItem(value) {
  const item = row(value);
  return { id: integer(item.id, 1), product_id: integer(item.product_id, 1), product_name: text(item.product_name),
    sku_name: text(item.sku_name), image: text(item.image), quantity: integer(item.quantity, 1),
    unit_price: money(item.unit_price), line_price: money(item.line_price), postage_price: money(item.postage_price) };
}

export function orderRow(value) {
  const order = row(value);
  if (order.marketplace_trade_version !== 1 || !Array.isArray(order.items) || !order.items.length) unavailable();
  const summary = fulfillmentSummary(order.fulfillment_summary, order.total_num);
  const actions = fulfillmentActions(order.allowed_actions, summary);
  return { id: integer(order.id, 1), order_id: text(order.order_id), marketplace_trade_version: 1,
    pay_type: text(order.pay_type),
    add_time: integer(order.add_time, 0, UINT_MAX), paid: integer(order.paid, 0, 1), status: integer(order.status, -INT_MAX - 1),
    is_cancel: integer(order.is_cancel, 0, 1), refund_status: integer(order.refund_status), shipping_type: integer(order.shipping_type),
    total_num: integer(order.total_num, 1, UINT_MAX), total_price: money(order.total_price), pay_price: money(order.pay_price),
    pay_postage: money(order.pay_postage), items: order.items.map(savedItem), fulfillment_summary: summary, allowed_actions: actions };
}

export function orderList(value) {
  const data = row(value);
  if (!Array.isArray(data.list)) unavailable();
  return { list: data.list.map(orderRow), count: integer(data.count, 0, UINT_MAX) };
}

function savedForms(value) {
  if (!Array.isArray(value)) unavailable();
  const seen = new Set();
  return value.map((value) => {
    const group = row(value); const productId = integer(group.product_id, 1);
    if (seen.has(productId) || typeof group.definition_sha256 !== 'string' || !/^[0-9a-f]{64}$/.test(group.definition_sha256)
      || !Array.isArray(group.fields) || !group.fields.length || group.fields.length > 10) unavailable();
    seen.add(productId);
    const fields = group.fields.map((value, index) => {
      const field = row(value);
      if (field.field_index !== index || typeof field.status !== 'boolean' || !labels.includes(field.label)) unavailable();
      let answer;
      if (field.label === 'img') {
        if (!Array.isArray(field.value) || field.value.length > 8) unavailable();
        answer = field.value.map(text);
      } else answer = text(field.value);
      return { field_index: field.field_index, title: text(field.title), label: field.label, status: field.status, value: answer };
    });
    return { product_id: productId, product_name: text(group.product_name), definition_sha256: group.definition_sha256, fields };
  });
}

export function orderDetail(value) {
  const order = row(value); const recipient = row(order.recipient);
  return { ...orderRow(order), recipient: { real_name: text(recipient.real_name), user_phone: text(recipient.user_phone),
    user_address: text(recipient.user_address) }, mark: text(order.mark), custom_form: savedForms(order.custom_form) };
}

export function orderStatus(order) {
  if (order.is_cancel === 1) return '已取消';
  if (order.paid === 0) return '待支付';
  if (order.refund_status !== 0) return '退款状态：' + order.refund_status;
  const summary = order.fulfillment_summary;
  if (summary !== null) {
    if (summary.shipping_state === 'partial') return '部分发货';
    if (summary.shipping_state === 'shipped') {
      if (summary.receipt_state === 'received') return '已收货';
      return order.shipping_type === 2 ? '已提货' : '待收货';
    }
    return order.shipping_type === 2 ? '待提货' : '待发货';
  }
  return '履约资料暂不可用';
}


// Dedicated public fulfillment envelopes. Saved money/items reuse the existing validators.
const PACKAGE_KEYS = ['schema', 'scope', 'id', 'order_id', 'root_order_id', 'root_order_no', 'marketplace_trade_version',
  'merchant_name_snapshot', 'shipping_type', 'carrier_code', 'carrier_name', 'tracking_no', 'shipped_at', 'receipt_due_at',
  'last_received_at', 'received_at', 'shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received',
  'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'items', 'payment_owner_order_id', 'paid',
  'pay_type', 'pay_time', 'pay_type_name'];
const OPERATION_KEYS = ['schema', 'operation_id', 'action', 'root_order_id', 'root_order_no', 'child_order_id',
  'child_order_no', 'quantity', 'created_at', 'committed_at', 'shipped_at', 'receipt_due_at', 'received_at', 'fulfillment_summary'];

function publicStamp(value, nullable = false) { return nullable && value === null ? null : integer(value, 1, UINT_MAX); }
function packageRow(value, root, detail) {
  const child = exactKeys(value, detail ? PACKAGE_KEYS.concat(['recipient', 'mark', 'custom_form']) : PACKAGE_KEYS);
  if (child.schema !== 1 || child.scope !== 'marketplace_fulfillment_child_v1' || child.marketplace_trade_version !== 1
    || child.paid !== 1 || child.shipping_state !== 'shipped' || child.root_order_id !== root.id
    || child.root_order_no !== root.order_id || child.payment_owner_order_id !== root.id) unavailable();
  integer(child.id, 1); if (child.id === root.id) unavailable();
  integer(child.shipping_type, 1, 2);
  ['order_id', 'merchant_name_snapshot', 'carrier_code', 'carrier_name', 'tracking_no', 'pay_type', 'pay_type_name'].forEach((key) => text(child[key]));
  ['original', 'shipped'].forEach((key) => integer(child[key], 1, UINT_MAX));
  ['received', 'to_receive', 'receipt_due_at'].forEach((key) => integer(child[key], 0, UINT_MAX));
  ['cancelled_unshipped', 'returned_accepted', 'to_ship'].forEach((key) => { if (child[key] !== 0) unavailable(); });
  ['shipped_at', 'pay_time'].forEach((key) => publicStamp(child[key]));
  ['last_received_at', 'received_at'].forEach((key) => publicStamp(child[key], true));
  if (child.original !== child.shipped || child.received > child.original || child.to_receive !== child.original - child.received
    || child.closed !== (child.received === child.original)
    || child.receipt_state !== (child.received === 0 ? 'pending' : child.closed ? 'received' : 'partial')
    || (child.received === 0) !== (child.last_received_at === null)
    || child.received_at !== (child.closed ? child.last_received_at : null)) unavailable();
  if (!Array.isArray(child.items) || !child.items.length || child.items.length > 500) unavailable();
  const items = child.items.map(item => savedItem(exactKeys(item, ['id', 'product_id', 'product_name', 'sku_name', 'image', 'quantity', 'unit_price', 'line_price', 'postage_price'])));
  const seen = new Set(); let quantity = 0;
  items.forEach((item) => { if (seen.has(item.id)) unavailable(); seen.add(item.id); quantity += item.quantity; });
  if (quantity !== child.original) unavailable();
  const result = { ...child, items };
  if (detail) {
    const recipient = exactKeys(child.recipient, ['real_name', 'user_phone', 'user_address']);
    result.recipient = { real_name: text(recipient.real_name), user_phone: text(recipient.user_phone), user_address: text(recipient.user_address) };
    result.mark = text(child.mark); result.custom_form = savedForms(child.custom_form);
  }
  return result;
}

export function orderPackages(value, expectedId, requestedPage, requestedLimit) {
  const data = exactKeys(value, ['root', 'packages', 'shipping_items']); const root = orderDetail(data.root);
  if (orderRecordId(root.id) !== orderRecordId(expectedId)) unavailable();
  if (root.fulfillment_summary === null) {
    if (data.packages !== null || data.shipping_items !== null) unavailable();
    return { root, packages: null, shipping_items: null };
  }
  const page = exactKeys(data.packages, ['page', 'limit', 'count', 'list']);
  if (page.page !== integer(requestedPage, 1, UINT_MAX) || page.limit !== integer(requestedLimit, 1, 100)
    || integer(page.count, 0, UINT_MAX) !== root.fulfillment_summary.child_count || !Array.isArray(page.list)) unavailable();
  if (page.list.length !== Math.max(0, Math.min(page.limit, page.count - (page.page - 1) * page.limit))) unavailable();
  const seen = new Set(); const children = page.list.map((value) => {
    const child = packageRow(value, root, false); if (seen.has(child.id)) unavailable(); seen.add(child.id); return child;
  });
  if (!Array.isArray(data.shipping_items) || data.shipping_items.length !== root.items.length) unavailable();
  const source = new Map(root.items.map((item) => [item.id, item])); const itemIds = new Set();
  let shipped = 0; let remaining = 0; let previous = 0;
  const shippingItems = data.shipping_items.map((value) => {
    const item = exactKeys(value, ['item_id', 'original_quantity', 'shipped_quantity', 'to_ship']);
    const id = integer(item.item_id, 1); const saved = source.get(id);
    if (!saved || itemIds.has(id) || id <= previous || integer(item.original_quantity, 1) !== saved.quantity) unavailable();
    integer(item.shipped_quantity); integer(item.to_ship);
    if (item.shipped_quantity + item.to_ship !== item.original_quantity) unavailable();
    itemIds.add(id); previous = id; shipped += item.shipped_quantity; remaining += item.to_ship;
    return { ...item };
  });
  if (shipped !== root.fulfillment_summary.shipped || remaining !== root.fulfillment_summary.to_ship) unavailable();
  return { root, packages: { page: page.page, limit: page.limit, count: page.count, list: children }, shipping_items: shippingItems };
}

export function orderPackageDetail(value, root, childId) {
  const data = exactKeys(value, ['package']); const child = packageRow(data.package, root, true);
  if (orderRecordId(child.id) !== orderRecordId(childId)) unavailable(); return child;
}

function utf8Text(value, maximum, label) {
  if (typeof value !== 'string' || !value || value !== value.replace(/^[ \t\n\r\u0000\u000b]+|[ \t\n\r\u0000\u000b]+$/g, '') || value.includes('\u0000')) throw new Error(label + '格式不正确');
  let length;
  try { length = encodeURIComponent(value).replace(/%[A-Fa-f0-9]{2}|./g, 'x').length; }
  catch (error) { throw new Error(label + '格式不正确'); }
  if (length > maximum) throw new Error(label + '过长'); return value;
}
function frozen(value) { if (value && typeof value === 'object') { Object.values(value).forEach(frozen); Object.freeze(value); } return value; }
let fulfillmentKeySequence = 0;
function shipmentKey(rootId) {
  fulfillmentKeySequence += 1;
  return 'ship:' + rootId + ':' + Date.now().toString(36) + ':' + fulfillmentKeySequence.toString(36) + ':'
    + Math.random().toString(36).slice(2, 13) + Math.random().toString(36).slice(2, 13);
}

export function shipmentIntent(snapshot, draft) {
  if (!snapshot || !snapshot.root || !Array.isArray(snapshot.shipping_items)) unavailable();
  const root = snapshot.root; exactKeys(draft, ['items', 'delivery']);
  if (!Array.isArray(draft.items) || !draft.items.length) throw new Error('请选择本次发货商品和数量');
  const remaining = new Map(snapshot.shipping_items.map((item) => [item.item_id, item.to_ship])); const seen = new Set();
  const items = draft.items.map((value) => {
    exactKeys(value, ['item_id', 'quantity']); const id = integer(value.item_id, 1); const quantity = integer(value.quantity, 1);
    if (seen.has(id) || !remaining.has(id) || quantity > remaining.get(id)) throw new Error('本次数量超出当前待发货数量，请重新读取');
    seen.add(id); return { item_id: id, quantity };
  }).sort((left, right) => left.item_id - right.item_id);
  exactKeys(draft.delivery, ['type', 'carrier_code', 'carrier_name', 'tracking_no']);
  if (draft.delivery.type !== 'express') throw new Error('本次只支持快递发货');
  const delivery = { type: 'express', carrier_code: utf8Text(draft.delivery.carrier_code, 50, '承运编码'),
    carrier_name: utf8Text(draft.delivery.carrier_name, 64, '承运名称'), tracking_no: utf8Text(draft.delivery.tracking_no, 64, '物流单号') };
  const body = { idem_key: shipmentKey(root.id), items, delivery };
  if (encodeURIComponent(JSON.stringify(body)).replace(/%[A-Fa-f0-9]{2}|./g, 'x').length > 65536) throw new Error('本次发货内容超出限制');
  return frozen({ rootId: root.id, rootNo: root.order_id, original: root.total_num, body, wire: JSON.stringify(body) });
}

export function shipmentResult(value, intent) {
  const result = exactKeys(value, OPERATION_KEYS);
  if (result.schema !== 1 || result.action !== 'ship' || result.root_order_id !== intent.rootId || result.root_order_no !== intent.rootNo
    || typeof result.operation_id !== 'string' || !/^[1-9][0-9]{0,19}$/.test(result.operation_id)
    || (result.operation_id.length === 20 && result.operation_id > '18446744073709551615')) unavailable();
  integer(result.child_order_id, 1); utf8Text(result.child_order_no, 32, '包裹单号');
  if (result.child_order_id === result.root_order_id || result.quantity !== intent.body.items.reduce((sum, item) => sum + item.quantity, 0)) unavailable();
  publicStamp(result.created_at); publicStamp(result.committed_at); publicStamp(result.shipped_at);
  integer(result.receipt_due_at, 0, UINT_MAX);
  if (result.committed_at < result.created_at || result.shipped_at !== result.created_at || result.received_at !== null
    || (result.receipt_due_at !== 0 && result.receipt_due_at < result.shipped_at)) unavailable();
  const summary = fulfillmentSummary(result.fulfillment_summary, intent.original);
  if (summary === null || result.quantity > summary.shipped) unavailable(); return { ...result, fulfillment_summary: summary };
}
