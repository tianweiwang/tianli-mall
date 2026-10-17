const INT_MAX = 2147483647;
const UINT_MAX = 4294967295;
const labels = ['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'];

function unavailable() { throw new Error('订单保存信息不完整，请重新查询'); }
function row(value) { if (!value || typeof value !== 'object' || Array.isArray(value)) unavailable(); return value; }
function text(value) { if (typeof value !== 'string' || value.includes('\u0000')) unavailable(); return value; }
function integer(value, min = 0, max = INT_MAX) { if (!Number.isInteger(value) || value < min || value > max) unavailable(); return value; }
function money(value) { if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/.test(value)) unavailable(); return value; }

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
  return { id: integer(order.id, 1), order_id: text(order.order_id), marketplace_trade_version: 1,
    pay_type: text(order.pay_type),
    add_time: integer(order.add_time, 0, UINT_MAX), paid: integer(order.paid, 0, 1), status: integer(order.status, -INT_MAX - 1),
    is_cancel: integer(order.is_cancel, 0, 1), refund_status: integer(order.refund_status), shipping_type: integer(order.shipping_type),
    total_num: integer(order.total_num, 1, UINT_MAX), total_price: money(order.total_price), pay_price: money(order.pay_price),
    pay_postage: money(order.pay_postage), items: order.items.map(savedItem) };
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
  const names = { 0: order.shipping_type === 2 ? '待提货' : '待发货', 1: order.shipping_type === 2 ? '已提货' : '待收货', 2: '已收货', 3: '已完成' };
  return names[order.status] || '订单状态：' + order.status;
}
