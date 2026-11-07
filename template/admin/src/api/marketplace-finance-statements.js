import axios from 'axios';
import setting from '@/setting';
import { currentContext } from '@/api/marketplace-finance-bills';
import { platformCan } from '@/libs/marketplace-navigation';

const client = axios.create({ baseURL: setting.apiBaseURL, timeout: 60000 });
const statuses = ['PENDING_REVIEW', 'ESTIMATED'];

export const amountLabels = {
  sales_amount: '销售额',
  refund_amount: '已确认退款',
  channel_fee_amount: '渠道费',
  platform_fee_amount: '平台费',
  platform_fee_reversal: '平台费冲回',
  platform_fee_net: '平台费净额',
  merchant_receivable: '预计商家应收',
};
export const reasons = {
  SOURCE_CHANGED: '本地资金记录有变化，请重新对账',
  PERIOD_OPEN: '账期尚未结束',
  NO_MOVEMENTS: '期间没有资金流水',
  RECONCILIATION_DIFFERENCES: '仍有账单差异',
  SOURCE_REVIEW_REQUIRED: '仍有本地流水待核查',
  RULE_INCOMPLETE: '所选费用规则尚未填全',
  CHANNEL_FEE_UNKNOWN: '渠道费用待确认',
  REFUND_BASIS_UNKNOWN: '退款对应的计费基数待确认',
  ORIGINAL_SALE_RULE_UNKNOWN: '原销售使用的费用规则待确认',
};

export function can(store, action = 'read') {
  const read = platformCan(store, 'marketplace-finance-statements-read');
  return read && (action === 'read' || (action === 'create'
    && platformCan(store, 'marketplace-finance-statements-create')
    && platformCan(store, 'marketplace-finance-bills-read')));
}

function id(value) {
  return typeof value === 'string' && /^[1-9]\d{0,19}$/.test(value)
    && (value.length < 20 || value <= '18446744073709551615');
}
function revision(value) { return Number.isSafeInteger(value) && value >= 0 && value <= 4294967295; }
function summary(row) {
  return row && id(row.id) && id(row.bill_id) && id(row.result_id) && revision(row.rule_revision)
    && Number.isInteger(row.mer_id) && row.mer_id > 0 && Number.isInteger(row.created_at)
    && typeof row.date_from === 'string' && typeof row.date_to === 'string' && statuses.includes(row.status);
}
function amounts(value) {
  return value && value.settled_amount === null && Object.keys(amountLabels).every(key =>
    value[key] === null || (typeof value[key] === 'string' && /^-?\d+\.\d{2}$/.test(value[key])));
}
function reasonList(value) { return Array.isArray(value) && value.every(code => typeof code === 'string'); }
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
function detail(row) {
  return summary(row) && reasonList(row.reasons) && amounts(row.totals) && feeRule(row.fee_rule) && Array.isArray(row.orders)
    && row.orders.every(order => order && id(String(order.root_order_id))
      && typeof order.sale_in_period === 'boolean' && amounts(order.amounts) && reasonList(order.reasons));
}
function current(context, action) {
  if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入试算单');
  if (!can(context.store, action)) throw new Error('当前账号缺少试算单权限');
}

async function request(context, action, method, input) {
  current(context, action);
  const source = axios.CancelToken.source();
  context.life.cancels.add(source.cancel);
  const config = {
    method, url: 'marketplace/finance-statements', cancelToken: source.token,
    headers: { 'Authori-zation': 'Bearer ' + context.token },
  };
  if (method === 'GET') config.params = input;
  else {
    config.headers['Content-Type'] = 'application/json';
    config.data = JSON.stringify(input);
    config.transformRequest = [value => value];
  }
  try {
    const response = await client(config);
    current(context, action);
    const envelope = response.data;
    if (response.status !== 200 || !envelope || envelope.status !== 200) {
      const error = new Error('试算单响应未确认');
      error.statementStatus = envelope && envelope.status;
      error.statementMessage = envelope && envelope.msg;
      throw error;
    }
    const data = envelope.data;
    if (!data || data.schema !== 1 || data.scope !== 'testing_settlement_statement_v1' || data.real_funds_enabled !== false)
      throw new Error('试算单响应范围未确认');
    return data;
  } catch (error) {
    current(context, action);
    const response = error.response;
    const payload = response && response.data;
    const status = error.statementStatus || (response && response.status);
    const message = error.statementMessage || (payload && payload.msg);
    if (status === 401) throw new Error('登录已失效，请重新登录');
    if (status === 403) throw new Error('当前账号缺少试算单权限');
    if ([404, 409, 422].includes(status) && typeof message === 'string') throw new Error(message);
    throw new Error(method === 'POST' ? '试算结果尚未确认，请先刷新已保存试算单。' : '试算单读取失败，请重试。');
  } finally {
    context.life.cancels.delete(source.cancel);
  }
}

export async function list(context, billId, before) {
  const value = await request(context, 'read', 'GET', { view: 'list', bill_id: billId, ...(before ? { before_id: before } : {}) });
  if (!Array.isArray(value.list) || !value.list.every(row => summary(row) && amounts(row.totals) && row.bill_id === billId)
    || !(value.next_before_id === null || id(value.next_before_id))) throw new Error('已保存试算单列表未确认');
  return value;
}
export async function rules(context) {
  const value = await request(context, 'read', 'GET', { view: 'rules' });
  if (!Array.isArray(value.versions) || !value.versions.every(row => row && revision(row.revision)
    && (row.created_at === null || Number.isInteger(row.created_at)))) throw new Error('可用费用规则版本未确认');
  return value.versions;
}
export async function read(context, billId, statementId) {
  const value = await request(context, 'read', 'GET', { view: 'read', id: statementId });
  if (!detail(value.record) || value.record.bill_id !== billId || value.record.id !== statementId) throw new Error('试算单详情未确认');
  return value.record;
}
export async function create(context, billId, resultId, ruleRevision) {
  if (!id(billId) || !id(resultId) || !revision(ruleRevision)) throw new Error('请选择有效的账单结果和规则版本');
  const value = await request(context, 'create', 'POST', { bill_id: billId, result_id: resultId, rule_revision: ruleRevision });
  const record = value.record;
  if (!detail(record) || record.bill_id !== billId || record.result_id !== resultId
    || record.rule_revision !== ruleRevision || typeof value.reused_statement !== 'boolean')
    throw new Error('试算结果尚未确认，请先刷新已保存试算单。');
  return value;
}
