<template>
  <section class="fulfillment">
    <p v-if="loading" role="status">正在读取包裹资料…</p>
    <div v-if="error" class="read-error" role="alert"><p>{{ error }}</p><el-button class="fulfillment-control" :disabled="loading" @click="$emit('retry')">重新加载</el-button></div>
    <template v-if="view && view.kind === 'root'">
      <h3>履约情况</h3><p role="status">{{ rootText(view.summary, view.aftersale) }}</p>
      <p v-if="view.summary">共 {{ view.summary.original }} 件 · 已发 {{ view.summary.shipped }} 件 · 已收 {{ view.summary.received }} 件 · {{ view.summary.child_count }} 个包裹</p>
      <p v-if="view.aftersale">未发取消 {{ view.summary.cancelled_unshipped }} 件 · 已验收退件 {{ view.summary.returned_accepted }} 件 · 暂停发货 {{ view.aftersale.pending_cancel_quantity }} 件 · 待处理退件 {{ view.aftersale.pending_return_quantity }} 件</p>
      <p v-if="view.aftersale">履约结束时间（含取消、退件）：{{ time(view.aftersale.fulfillment_completed_at) }}</p>
      <template v-if="view.children !== undefined">
        <p v-if="view.children === null">包裹资料暂不可用</p>
        <template v-else>
          <h3>履约包裹</h3><p v-if="view.children.count === 0">暂无履约包裹</p><p v-else-if="!view.children.list.length">本页暂无包裹</p>
          <article v-for="child in view.children.list" :key="child.id" class="package-card">
            <h4>包裹 {{ child.order_id }}</h4><p>{{ child.merchant_name_snapshot }}</p><p>{{ childText(child) }} · 已发 {{ child.shipped }} 件 / 已收 {{ child.received }} 件</p>
            <p v-if="child.schema === 2">已验收退件 {{ child.returned_accepted }} 件 · 待处理退件 {{ child.aftersale.pending_return_quantity }} 件</p>
            <p>承运：{{ child.carrier_name || '—' }}</p><p>物流单号：{{ child.tracking_no || '—' }}</p><p>对应付款订单：{{ child.root_order_no }}</p>
            <p>{{ child.pay_type === 'marketplace_test' ? '测试支付' : child.pay_type_name }}</p>
            <div v-for="item in child.items" :key="item.id" class="saved-item"><img v-if="item.image" :src="item.image" alt="" /><div class="item-copy"><p>{{ item.product_name }}</p><p>{{ item.sku_name }}</p><p>￥{{ item.unit_price }} × {{ item.quantity }}</p></div></div>
            <div class="controls"><el-button class="fulfillment-control" :disabled="loading || !!error" @click="$emit('view-package', child)">查看包裹</el-button></div>
          </article>
          <div v-if="view.children.count > 0" class="pagination">
            <p>第 {{ view.children.page }} 页 · 共 {{ view.children.count }} 个包裹</p>
            <div class="controls"><el-button v-if="!view.children.list.length && view.children.page > 1" class="fulfillment-control" :disabled="loading" @click="$emit('page-change', 1)">回到第一页</el-button>
              <el-button class="fulfillment-control" :disabled="loading || view.children.page <= 1" @click="$emit('page-change', view.children.page - 1)">上一页</el-button>
              <el-button class="fulfillment-control" :disabled="loading || view.children.page * view.children.limit >= view.children.count" @click="$emit('page-change', view.children.page + 1)">下一页</el-button>
            </div>
          </div>
        </template>
      </template>
    </template>
    <template v-else-if="view && view.kind === 'child'">
      <h3>履约包裹 {{ view.order.order_id }}</h3><p role="status">{{ childText(view.order) }}</p><p>{{ view.order.merchant_name_snapshot }}</p>
      <p>对应付款订单：{{ view.order.root_order_no }}</p><p>付款方式：{{ view.order.pay_type === 'marketplace_test' ? '测试支付' : view.order.pay_type_name }}</p><p>付款时间：{{ time(view.order.pay_time) }}</p>
      <p>已发 {{ view.order.shipped }} 件 / 已收 {{ view.order.received }} 件 / 待收 {{ view.order.to_receive }} 件</p><p>承运：{{ view.order.carrier_name || '—' }}</p><p>物流单号：{{ view.order.tracking_no || '—' }}</p>
      <p>发货时间：{{ time(view.order.shipped_at) }}</p><p>预计自动收货时间：{{ time(view.order.receipt_due_at) }}</p><p>最近收货时间：{{ time(view.order.last_received_at) }}</p><p>全部收货时间：{{ time(view.order.received_at) }}</p>
      <p v-if="view.order.schema === 2">已验收退件 {{ view.order.returned_accepted }} 件 · 待处理退件 {{ view.order.aftersale.pending_return_quantity }} 件</p>
      <p v-if="view.order.schema === 2">履约结束时间（含退件）：{{ time(view.order.aftersale.fulfillment_completed_at) }}</p>
      <div v-for="item in view.order.items" :key="item.id" class="saved-item"><img v-if="item.image" :src="item.image" alt="" /><div class="item-copy"><p>{{ item.product_name }}</p><p>{{ item.sku_name }}</p><p>￥{{ item.unit_price }} × {{ item.quantity }}</p><p>商品金额 ￥{{ item.line_price }} · 运费 ￥{{ item.postage_price }}</p></div></div>
      <template v-if="view.order.recipient"><h3>收件资料</h3><p>{{ view.order.recipient.real_name }} {{ view.order.recipient.user_phone }}</p><p>{{ view.order.recipient.user_address }}</p><p v-if="view.order.mark">备注：{{ view.order.mark }}</p></template>
      <section v-for="group in view.order.custom_form" :key="group.product_id"><h4>{{ group.product_name }} · 保存表单</h4><div v-for="field in group.fields" :key="field.field_index"><p>{{ field.title }}</p><div v-if="field.label === 'img'" class="form-images"><img v-for="(src, index) in field.value" :key="index" :src="src" alt="保存表单图片" /></div><p v-else>{{ field.value || '—' }}</p></div></section>
    </template>
  </section>
</template>

<script>
const SUMMARY = ['shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'child_count'];
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
function fail() { throw new Error('订单保存信息不可用，请重新加载'); }
function object(value) { if (!value || typeof value !== 'object' || Array.isArray(value)) fail(); return value; }
function exact(value, keys) { object(value); if (Object.keys(value).length !== keys.length || Object.keys(value).some(key => !keys.includes(key))) fail(); }
function uint(value, minimum = 0, maximum = 4294967295) { if (!Number.isSafeInteger(value) || value < minimum || value > maximum) fail(); return value; }
function nativeQuantity(value) { if (typeof value === 'string') { if (!/^(0|[1-9][0-9]{0,9})$/.test(value)) fail(); value = Number(value); } return uint(value, 1); }
function nativeId(value) { if (!/^[1-9][0-9]{0,9}$/.test(String(value)) || Number(value) > 2147483647) fail(); return Number(value); }
function text(value) { if (typeof value !== 'string' || value.includes('\u0000')) fail(); }
function money(value) { if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/.test(value)) fail(); }
function timeValue(value, nullable = false, zero = false) { if (nullable && value === null || zero && value === 0) return; uint(value, 1); }
const AFTERSALE = ['schema', 'revision', 'confirmed_amount', 'held_amount', 'available_amount', 'pending_cancel_quantity', 'pending_return_quantity', 'returned_after_receipt', 'fulfillment_completed_at'];
function aftersaleData(value, summary = null, paid = null) {
  exact(value, AFTERSALE);
  if (value.schema !== 1 || typeof value.revision !== 'string' || !/^[1-9][0-9]{0,19}$/.test(value.revision)
    || (value.revision.length === 20 && value.revision > '18446744073709551615')) fail();
  ['confirmed_amount', 'held_amount', 'available_amount'].forEach(key => money(value[key]));
  const cents = ['confirmed_amount', 'held_amount', 'available_amount'].reduce((sum, key) => sum + Number(value[key].replace('.', '')), 0);
  if (!Number.isSafeInteger(cents) || cents > 999999999999) fail();
  if (paid !== null) { money(paid); if (cents !== Number(paid.replace('.', ''))) fail(); }
  ['pending_cancel_quantity', 'pending_return_quantity', 'returned_after_receipt'].forEach(key => uint(value[key], 0, 4294967295));
  if (value.fulfillment_completed_at !== null) uint(value.fulfillment_completed_at, 1, 4294967295);
  if (summary !== null && (value.pending_cancel_quantity > summary.to_ship
    || value.pending_return_quantity > summary.shipped - summary.returned_accepted
    || value.returned_after_receipt > Math.min(summary.received, summary.returned_accepted)
    || (value.fulfillment_completed_at !== null) !== summary.closed)) fail();
  return { ...value };
}
function summaryV2(value, original, aftersale) {
  exact(value, SUMMARY);
  ['original', 'shipped', 'received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'child_count'].forEach(key => uint(value[key], key === 'original' ? 1 : 0, 4294967295));
  const s = value; const a = aftersaleData(aftersale);
  if (s.original !== original || s.shipped > s.original || s.received > s.shipped || s.returned_accepted > s.shipped
    || s.cancelled_unshipped > s.original - s.shipped || a.returned_after_receipt > Math.min(s.received, s.returned_accepted)
    || s.to_ship !== s.original - s.shipped - s.cancelled_unshipped
    || s.to_receive !== s.shipped - s.received - s.returned_accepted + a.returned_after_receipt
    || ((s.child_count === 0) !== (s.shipped === 0)) || s.child_count > s.shipped
    || s.shipping_state !== (s.shipped === 0 ? 'unshipped' : s.to_ship > 0 ? 'partial' : 'shipped')
    || s.receipt_state !== (s.shipped === 0 ? 'not_shipped' : s.received === s.shipped ? 'received' : s.received === 0 ? 'pending' : 'partial')
    || s.closed !== (s.to_ship === 0 && s.to_receive === 0)) fail();
  aftersaleData(a, s); return { ...s };
}
function childSummaryV2(child) {
  const summary = {}; SUMMARY.forEach(key => { summary[key] = key === 'child_count' ? 1 : child[key]; });
  if (child.cancelled_unshipped !== 0 || child.to_ship !== 0 || child.original !== child.shipped) fail();
  summaryV2(summary, child.original, child.aftersale);
  if ((child.received === 0) !== (child.last_received_at === null)
    || child.received_at !== (child.received === child.shipped ? child.last_received_at : null)) fail();
}
function aftersaleState(summary, aftersale) {
  if (!aftersale || !summary) return '';
  if (summary.closed) {
    if (summary.shipped > 0 && summary.received === summary.shipped && summary.cancelled_unshipped === 0) return '已收货';
    return summary.shipped === 0 ? '已取消履约（未发货）' : '履约已结束（含退件或取消）';
  }
  if (aftersale.pending_cancel_quantity) return '售后处理中，部分发货暂停';
  if (aftersale.pending_return_quantity) return '退件申请处理中';
  if (summary.returned_accepted) return '含已退件，仍有待履约商品';
  return '';
}

function savedForms(groups) {
  if (!Array.isArray(groups) || groups.length > 500) fail();
  groups.forEach(group => {
    object(group); uint(group.product_id, 1, 2147483647); text(group.product_name);
    if (!Array.isArray(group.fields) || group.fields.length > 10) fail();
    group.fields.forEach((field, index) => {
      object(field); if (field.field_index !== index || typeof field.status !== 'boolean' || !['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'].includes(field.label)) fail();
      text(field.title);
      if (field.label === 'img') { if (!Array.isArray(field.value) || field.value.length > 8) fail(); field.value.forEach(text); } else text(field.value);
    });
  });
}
function packageRow(value, detail) {
  object(value);
  const s2 = value.schema === 2 && value.scope === 'marketplace_fulfillment_child_aftersale_v1';
  if ((!s2 && (value.scope !== 'marketplace_fulfillment_child_v1' || value.schema !== 1)) || value.marketplace_trade_version !== 1 || value.paid !== 1 || own(value, 'allowed_actions')) fail();
  ['id', 'root_order_id', 'payment_owner_order_id'].forEach(key => uint(value[key], 1, 2147483647));
  if (value.id === value.root_order_id || value.payment_owner_order_id !== value.root_order_id || value.shipping_state !== 'shipped' || !['pending', 'partial', 'received'].includes(value.receipt_state) || typeof value.closed !== 'boolean') fail();
  ['order_id', 'root_order_no', 'merchant_name_snapshot', 'carrier_name', 'tracking_no', 'pay_type', 'pay_type_name'].forEach(key => text(value[key]));
  ['shipped', 'received', 'to_receive'].forEach(key => uint(value[key]));
  if (s2) {
    const childKeys = ['schema', 'scope', 'id', 'order_id', 'root_order_id', 'root_order_no', 'marketplace_trade_version', 'merchant_name_snapshot', 'shipping_type', 'carrier_code', 'carrier_name', 'tracking_no', 'shipped_at', 'receipt_due_at', 'last_received_at', 'received_at', 'shipping_state', 'receipt_state', 'closed', 'original', 'shipped', 'received', 'cancelled_unshipped', 'returned_accepted', 'to_ship', 'to_receive', 'items', 'payment_owner_order_id', 'paid', 'pay_type', 'pay_time', 'pay_type_name', 'aftersale'];
    exact(value, detail ? childKeys.concat(['recipient', 'mark', 'custom_form']) : childKeys);
    uint(value.shipping_type, 1, 2); text(value.carrier_code); childSummaryV2(value);
  }
  ['shipped_at', 'pay_time'].forEach(key => timeValue(value[key])); timeValue(value.receipt_due_at, false, true);
  ['last_received_at', 'received_at'].forEach(key => timeValue(value[key], true));
  if (!Array.isArray(value.items) || !value.items.length || value.items.length > 500) fail(); const seen = new Set();
  value.items.forEach(item => { object(item); uint(item.id, 1, 2147483647); if (seen.has(item.id)) fail(); seen.add(item.id); uint(item.quantity, 1, 2147483647); ['product_name', 'sku_name', 'image'].forEach(key => text(item[key])); ['unit_price', 'line_price', 'postage_price'].forEach(key => money(item[key])); });
  if (s2) {
    let quantity = 0; value.items.forEach(item => {
      exact(item, ['id', 'product_id', 'product_name', 'sku_name', 'image', 'quantity', 'unit_price', 'line_price', 'postage_price']);
      uint(item.product_id, 1, 2147483647); quantity += item.quantity;
    });
    if (quantity !== value.original) fail();
  }
  if (detail) {
    exact(value.recipient, ['real_name', 'user_phone', 'user_address']); Object.values(value.recipient).forEach(text); text(value.mark);
    savedForms(value.custom_form);
  }
  return value;
}
export function readPlatformFulfillment(order, detail = false) {
  object(order); if (!own(order, 'marketplace_trade_version')) fail(); const version = order.marketplace_trade_version;
  if (order.scope === 'marketplace_fulfillment_child_v1' || order.scope === 'marketplace_fulfillment_child_aftersale_v1') return { kind: 'child', order: packageRow(order, detail) };
  if (version === null || version === 0 || version === '0') { if (own(order, 'scope') || own(order, 'schema')) fail(); return { kind: 'legacy', order }; }
  const s2 = order.schema === 2 && order.scope === 'marketplace_order_aftersale_v1';
  if (version !== 1 && version !== '1' || !s2 && (own(order, 'scope') || own(order, 'schema') || own(order, 'aftersale')) || order.pid !== 0 && order.pid !== '0') fail();
  const id = nativeId(order.id); text(order.order_id); const summary = order.fulfillment_summary;
  if (s2) { summaryV2(summary, nativeQuantity(order.total_num), order.aftersale); aftersaleData(order.aftersale, summary, order.pay_price); }
  exact(order.allowed_actions, ['ship', 'receive']); if (order.allowed_actions.ship !== false || order.allowed_actions.receive !== false) fail();
  if (summary !== null) { exact(summary, SUMMARY); if (!['unshipped', 'partial', 'shipped'].includes(summary.shipping_state) || !['not_shipped', 'pending', 'partial', 'received'].includes(summary.receipt_state) || typeof summary.closed !== 'boolean') fail(); SUMMARY.slice(3).forEach(key => uint(summary[key])); if (summary.original !== nativeQuantity(order.total_num) || !s2 && (summary.cancelled_unshipped !== 0 || summary.returned_accepted !== 0)) fail(); }
  ['total_price', 'pay_price', 'total_postage', 'pay_postage', 'refund_price', 'refunded_price', 'coupon_price', 'deduction_price', 'levelPrice', 'memberPrice'].forEach(key => { if (own(order, key)) money(order[key]); });
  money(order.total_price); money(order.pay_price);
  let children;
  if (detail) {
    object(order._status); text(order._status._title); savedForms(order.custom_form);
    if (!Array.isArray(order.cartInfo)) fail();
    order.cartInfo.forEach(cart => { object(cart); money(cart.truePrice); object(cart.productInfo); if (cart.productInfo.attrInfo) object(cart.productInfo.attrInfo); });
    if (!own(order, 'fulfillment_children')) fail(); children = order.fulfillment_children;
    if (children !== null) { if (summary === null) fail(); exact(children, ['page', 'limit', 'count', 'list']); uint(children.page, 1); uint(children.limit, 1, 100); uint(children.count); if (!Array.isArray(children.list) || children.count !== summary.child_count || children.list.length !== Math.max(0, Math.min(children.limit, children.count - (children.page - 1) * children.limit))) fail(); const seen = new Set(); children.list.forEach(child => { packageRow(child, false); if ((child.schema === 2) !== s2 || seen.has(child.id) || child.root_order_id !== id || child.root_order_no !== order.order_id) fail(); seen.add(child.id); if (s2 && ['revision', 'confirmed_amount', 'held_amount', 'available_amount'].some(key => child.aftersale[key] !== order.aftersale[key])) fail(); }); }
  }
  return { kind: 'root', order, summary, aftersale: s2 ? { ...order.aftersale } : undefined, children };
}
export function rootFulfillmentText(summary, aftersale) { const state = aftersaleState(summary, aftersale); if (state) return state; return summary === null ? '履约资料暂不可用' : summary.shipping_state === 'unshipped' ? '待发货' : summary.shipping_state === 'partial' ? '部分发货' : summary.receipt_state === 'received' ? '已收货' : summary.receipt_state === 'partial' ? '部分收货' : '待收货'; }
function childText(child) { const state = child.schema === 2 ? aftersaleState(child, child.aftersale) : ''; if (state) return state; return child.receipt_state === 'received' ? '已收货' : child.receipt_state === 'partial' ? '部分收货' : '待收货'; }
function displayTime(value) { if (value === null || value === 0) return '—'; timeValue(value); const date = new Date(value * 1000); const pad = number => String(number).padStart(2, '0'); return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); }
export default { name: 'FulfillmentPackages', props: { view: { type: Object, default: null }, loading: { type: Boolean, default: false }, error: { type: String, default: '' } }, methods: { rootText: rootFulfillmentText, childText, time: displayTime } };
</script>

<style scoped>
.fulfillment { box-sizing: border-box; width: 100%; min-width: 0; padding: 20px; color: #303133; font-size: 14px; line-height: 1.6; overflow-wrap: anywhere; word-break: break-word; }
.fulfillment p { margin: 8px 0; }
.package-card { min-width: 0; margin-top: 16px; padding: 16px; border: 1px solid #ebeef5; border-radius: 6px; }
.read-error { padding: 12px; background: #fef0f0; border-left: 3px solid #c45656; color: #9b3232; }
.saved-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; }
.saved-item > img { width: 64px; height: 64px; flex: 0 0 64px; object-fit: cover; }
.item-copy { min-width: 0; flex: 1; }
.pagination { margin-top: 16px; text-align: center; }
.controls { display: flex; flex-wrap: wrap; justify-content: center; align-items: stretch; gap: 12px; min-width: 0; }
.fulfillment-control { display: flex; align-items: center; justify-content: center; box-sizing: border-box; max-width: 100%; min-width: 0; min-height: 44px; height: auto; margin: 0 !important; padding: 10px 16px; line-height: 1.4; white-space: normal; text-align: center; }
.fulfillment-control /deep/ > span { display: flex; align-items: center; justify-content: center; min-width: 0; white-space: normal; text-align: center; }
.form-images { display: flex; flex-wrap: wrap; gap: 12px; }
.form-images img { width: 80px; height: 80px; max-width: 100%; object-fit: contain; }
</style>
