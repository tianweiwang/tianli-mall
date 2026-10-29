const choices = (rows) => rows.map(([value, label]) => ({ value, label }));
const payerOptions = choices([['merchant', '商家承担'], ['platform', '平台承担']]);
export const financeFields = [
  { key: 'contracting_entity', label: '经营签约主体', section: 'collection', type: 'text' },
  { key: 'payment_provider', label: '拟选支付服务商', section: 'collection', type: 'text' },
  { key: 'payment_product', label: '拟选支付产品', section: 'collection', type: 'text' },
  { key: 'account_model', label: '拟选收款账户模式', section: 'collection', options: choices([['merchant_direct', '商家直接收款'], ['provider_submerchant', '服务商子商户']]) },
  { key: 'platform_fee_enabled', label: '是否收取平台费', section: 'fee', options: choices([[true, '收取'], [false, '不收取']]) },
  { key: 'platform_fee_payer', label: '平台费承担方', section: 'fee', options: payerOptions },
  { key: 'platform_fee_basis', label: '平台费计费基数', section: 'fee', options: choices([['goods_paid', '商品实付（不含运费）'], ['order_paid', '订单实付（含运费）']]) },
  { key: 'platform_fee_rate', label: '平台费率（%）', section: 'fee', type: 'rate' },
  { key: 'channel_fee_payer', label: '支付通道费拟承担方', section: 'fee', options: payerOptions },
  { key: 'settlement_trigger', label: '拟定结算触发条件', section: 'settlement', options: choices([['receipt', '确认收货'], ['aftersale_end', '售后期结束']]) },
  { key: 'settlement_cycle', label: '拟定结算周期', section: 'settlement', options: choices([['daily', '每日'], ['weekly', '每周'], ['monthly', '每月']]) },
  { key: 'settlement_delay_days', label: '拟定结算延后天数', section: 'settlement', type: 'days' },
  { key: 'aftersale_hold', label: '售后期间是否暂缓结算', section: 'settlement', options: choices([[true, '暂缓结算'], [false, '不暂缓']]) },
  { key: 'refund_platform_fee', label: '退款时退还平台费', section: 'settlement', options: choices([['proportional', '按退款比例退还'], ['none', '不退还']]) },
];
const names = financeFields.map(field => field.key);
function exact(value, keys, message) {
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join('|') !== keys.slice().sort().join('|')) throw new Error(message);
}
function integer(value, minimum, maximum) { return Number.isSafeInteger(value) && value >= minimum && value <= maximum; }
export function emptyDraft() { return Object.fromEntries(names.map(key => [key, null])); }
export function normalizeDraft(value) {
  exact(value, names, '资金草稿字段不完整，请重新读取'); const out = {};
  financeFields.forEach(field => {
    const input = value[field.key]; let checked = input;
    if (field.type === 'text') {
      if (input !== null && typeof input !== 'string') throw new Error(field.label + '格式不正确');
      checked = input === null ? null : input.trim() || null;
      if (checked !== null && (Array.from(checked).length > 120 || /[\u0000-\u001f\u007f]/.test(checked))) throw new Error(field.label + '请填写不超过120字的单行内容');
    } else if (field.type === 'rate') {
      if (input === '' || input === null) checked = null;
      else {
        if (typeof input !== 'string' || !/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?(?![\s\S])/.test(input)) throw new Error('平台费率须为0.00至100.00，最多两位小数');
        const parts = input.split('.'); checked = parts[0] + '.' + (parts[1] || '').padEnd(2, '0');
        if (Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0')) > 10000) throw new Error('平台费率不能超过100.00%');
      }
    } else if (field.type === 'days') {
      if (input === '' || input === null) checked = null;
      else {
        if (typeof input === 'string' && /^(?:0|[1-9][0-9]{0,2})(?![\s\S])/.test(input)) checked = Number(input);
        if (!integer(checked, 0, 365)) throw new Error('结算延后天数须为0至365的整数');
      }
    } else if (input !== null && !field.options.some(option => option.value === input)) throw new Error(field.label + '选项不正确');
    out[field.key] = checked;
  });
  return out;
}
function savedDraft(value) {
  const checked = normalizeDraft(value);
  if (names.some(key => checked[key] !== value[key])) throw new Error('资金草稿返回值不规范，请重新读取');
  return checked;
}
export function sameDraft(left, right) { return names.every(key => left[key] === right[key]); }
export function financeView(value) {
  exact(value, ['schema', 'status', 'real_funds_enabled', 'revision', 'draft', 'missing_fields', 'activation_blockers', 'updated_at', 'updated_by', 'history', 'history_total'], '资金设置响应不完整');
  if (value.schema !== 1 || value.status !== 'DRAFT' || value.real_funds_enabled !== false || !integer(value.revision, 0, 4294967295)) throw new Error('资金草稿状态未确认，请重新读取');
  const draft = savedDraft(value.draft);
  if (!Array.isArray(value.missing_fields) || !Array.isArray(value.activation_blockers) || !value.activation_blockers.length || value.activation_blockers.some(item => typeof item !== 'string' || !item)) throw new Error('资金配置完整性提示不完整');
  const missing = new Set(); value.missing_fields.forEach(item => {
    exact(item, ['field', 'label'], '资金配置缺项提示不完整');
    if (!names.includes(item.field) || missing.has(item.field) || typeof item.label !== 'string' || !item.label) throw new Error('资金配置缺项提示不完整'); missing.add(item.field);
  });
  const initial = value.revision === 0;
  if (initial ? value.updated_at !== null || value.updated_by !== null : !integer(value.updated_at, 1, 4294967295) || !integer(value.updated_by, 1, 4294967295)) throw new Error('资金草稿修改身份或时间未确认');
  if (!Array.isArray(value.history) || !integer(value.history_total, 0, 4294967295) || value.history_total !== value.revision || value.history.length !== Math.min(20, value.history_total)) throw new Error('资金草稿版本记录不完整');
  const history = value.history.map((row, index) => {
    exact(row, ['revision', 'payload', 'changed_fields', 'actor_id', 'created_at'], '资金草稿历史字段不完整');
    if (row.revision !== value.revision - index || !integer(row.actor_id, 1, 4294967295) || !integer(row.created_at, 1, 4294967295) || !Array.isArray(row.changed_fields) || !row.changed_fields.length || row.changed_fields.some(key => !names.includes(key)) || new Set(row.changed_fields).size !== row.changed_fields.length) throw new Error('资金草稿历史版本未确认');
    return { ...row, payload: savedDraft(row.payload), changed_fields: row.changed_fields.slice() };
  });
  if (history.length && (!sameDraft(history[0].payload, draft) || history[0].actor_id !== value.updated_by || history[0].created_at !== value.updated_at)) throw new Error('当前草稿与版本记录不一致');
  return { ...value, draft, history, missing_fields: value.missing_fields.map(item => ({ ...item })), activation_blockers: value.activation_blockers.slice() };
}
export function saveIntent(revision, draft) {
  if (!integer(revision, 0, 4294967295)) throw new Error('草稿版本未确认，请重新读取');
  return { expected_revision: revision, draft: normalizeDraft(draft) };
}
export function fieldLabel(key) { const field = financeFields.find(item => item.key === key); return field ? field.label : key; }
export function fieldText(key, value) {
  if (value === null) return '未配置'; const field = financeFields.find(item => item.key === key);
  if (field && field.options) { const option = field.options.find(item => item.value === value); return option ? option.label : '未配置'; }
  return String(value) + (key === 'platform_fee_rate' ? '%' : key === 'settlement_delay_days' ? '天' : '');
}
export function amountCents(value) {
  if (typeof value !== 'string' || !/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?(?![\s\S])/.test(value)) throw new Error('金额须为0至99999999.99元，最多两位小数');
  const parts = value.split('.'); return Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
}
export function yuan(cents) {
  if (!integer(cents, 0, Number.MAX_SAFE_INTEGER)) throw new Error('测算金额超出范围');
  return Math.floor(cents / 100) + '.' + String(cents % 100).padStart(2, '0');
}
export function estimateFee(draft, goods, shipping) {
  const value = normalizeDraft(draft);
  if (value.platform_fee_enabled === null) return { ready: false, message: '平台费是否收取：未配置' };
  if (!value.platform_fee_enabled) return { ready: true, fee_cents: 0, basis_cents: null, message: '草稿设为不收取平台费' };
  if (value.platform_fee_basis === null || value.platform_fee_rate === null) return { ready: false, message: '平台费计费基数或费率：未配置' };
  if (goods === '' || shipping === '') return { ready: false, message: '请填写商品实付与运费金额' };
  const goodsCents = amountCents(goods); const shippingCents = amountCents(shipping);
  const basis = goodsCents + (value.platform_fee_basis === 'order_paid' ? shippingCents : 0);
  const rate = amountCents(value.platform_fee_rate); const fee = Math.floor((basis * rate + 5000) / 10000);
  return { ready: true, fee_cents: fee, basis_cents: basis, message: '按当前表单草稿测算，四舍五入到分' };
}
