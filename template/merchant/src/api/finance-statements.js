import { captureContext, currentContext, closeContext } from './aftersale';
import { request } from './request';
export { captureContext, currentContext, closeContext };

export const permission = 'finance.statement.read';
export const amountLabels = {
  sales_amount: '销售额',
  refund_amount: '已确认退款',
  channel_fee_amount: '渠道费',
  platform_fee_amount: '平台费',
  platform_fee_reversal: '平台费冲回',
  platform_fee_net: '平台费净额',
  merchant_receivable: '预计本店应收',
};
export const reasonLabels = {
  SOURCE_CHANGED: '本地资金记录有变化，待平台重新对账',
  PERIOD_OPEN: '账期尚未结束',
  NO_MOVEMENTS: '期间没有资金流水',
  RECONCILIATION_DIFFERENCES: '仍有账单差异',
  SOURCE_REVIEW_REQUIRED: '仍有本地流水待核查',
  RULE_INCOMPLETE: '所用费用规则尚未填全',
  CHANNEL_FEE_UNKNOWN: '渠道费用待确认',
  REFUND_BASIS_UNKNOWN: '退款对应的计费基数待确认',
  ORIGINAL_SALE_RULE_UNKNOWN: '原销售使用的费用规则待确认',
};
function id(value) {
  return typeof value === 'string' && /^[1-9]\d{0,19}$/.test(value)
    && (value.length < 20 || value <= '18446744073709551615');
}
function summary(row, context) {
  return row && id(row.id) && id(row.bill_id) && id(row.result_id) && Number.isInteger(row.rule_revision)
    && row.rule_revision >= 0 && Number.isInteger(row.mer_id) && String(row.mer_id) === context.merchant
    && Number.isInteger(row.created_at) && typeof row.date_from === 'string' && typeof row.date_to === 'string'
    && ['PENDING_REVIEW', 'ESTIMATED'].includes(row.status);
}
function amounts(value) {
  return value && value.settled_amount === null && Object.keys(amountLabels).every(key =>
    value[key] === null || (typeof value[key] === 'string' && /^-?\d+\.\d{2}$/.test(value[key])));
}
function reasons(value) { return Array.isArray(value) && value.every(code => typeof code === 'string'); }
export function feeRuleItems(rule) {
  const payer = { merchant: '商家承担', platform: '平台承担' };
  const basis = { goods_paid: '商品实付额', order_paid: '订单实付额（含运费）' };
  const refund = { proportional: '按比例冲回', none: '不冲回' };
  return [
    { label: '平台费', value: rule.platform_fee_enabled === null ? '待确认' : rule.platform_fee_enabled ? '启用' : '不收取' },
    { label: '计费基数', value: basis[rule.platform_fee_basis] || '待确认' },
    { label: '平台费率', value: rule.platform_fee_rate === null ? '待确认' : rule.platform_fee_rate + '%' },
    { label: '平台费承担方', value: payer[rule.platform_fee_payer] || '待确认' },
    { label: '渠道费承担方', value: payer[rule.channel_fee_payer] || '待确认' },
    { label: '退款冲回规则', value: refund[rule.refund_platform_fee] || '待确认' },
  ];
}
function feeRule(rule) {
  return rule && [true, false, null].includes(rule.platform_fee_enabled)
    && ['goods_paid', 'order_paid', null].includes(rule.platform_fee_basis)
    && ['merchant', 'platform', null].includes(rule.platform_fee_payer)
    && ['merchant', 'platform', null].includes(rule.channel_fee_payer)
    && ['proportional', 'none', null].includes(rule.refund_platform_fee)
    && (rule.platform_fee_rate === null || (typeof rule.platform_fee_rate === 'string' && /^\d+\.\d{2}$/.test(rule.platform_fee_rate)));
}
function requireCurrent(context) {
  if (!currentContext(context) || !context.store.getters.can(permission)) throw new Error('登录身份或查看权限已变化，请重新进入试算单。');
}
async function read(context, query) {
  requireCurrent(context);
  const value = await request('GET', 'finance-statements', query);
  requireCurrent(context);
  if (!value || value.schema !== 1 || value.scope !== 'testing_settlement_statement_v1' || value.real_funds_enabled !== false)
    throw new Error('试算单响应范围未确认');
  return value;
}
export async function list(context, before) {
  const value = await read(context, { view: 'list', ...(before ? { before_id: before } : {}) });
  if (!Array.isArray(value.list) || !value.list.every(row => summary(row, context) && amounts(row.totals))
    || !(value.next_before_id === null || id(value.next_before_id))) throw new Error('本店试算单列表未确认');
  return value;
}
export async function detail(context, statementId) {
  if (!id(statementId)) throw new Error('试算单编号不正确');
  const { record } = await read(context, { view: 'read', id: statementId });
  if (!summary(record, context) || record.id !== statementId || !amounts(record.totals) || !reasons(record.reasons) || !feeRule(record.fee_rule)
    || !Array.isArray(record.orders) || !record.orders.every(order => order && id(String(order.root_order_id))
      && typeof order.sale_in_period === 'boolean' && amounts(order.amounts) && reasons(order.reasons)))
    throw new Error('本店试算单详情未确认');
  return record;
}
