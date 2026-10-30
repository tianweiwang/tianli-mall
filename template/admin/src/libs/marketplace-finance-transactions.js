const attemptLabels = {
  applied: '已应用付款',
  succeeded_observed: '已观察到成功，尚未应用',
  closed: '已关闭',
  pending: '处理中',
  unresolved: '尚未确认',
};
const estimateLabels = {
  NO_REVISION: '未选择保存版本',
  NOT_APPLIED: '尚未应用付款',
  PAYMENT_HOLD: '付款存在暂缓标记',
  RULE_INCOMPLETE: '平台费规则未配置完整',
  ESTIMATED: '平台费估算',
};
const ruleKeys = ['platform_fee_enabled', 'platform_fee_basis', 'platform_fee_rate', 'platform_fee_payer'];
function exact(value, keys) {
  if (
    !value ||
    typeof value !== 'object' ||
    Array.isArray(value) ||
    Object.keys(value).sort().join('|') !== keys.slice().sort().join('|')
  )
    throw new Error('测试交易响应字段未确认，请重新读取');
}
function integer(value, maximum = 4294967295) {
  return Number.isSafeInteger(value) && value > 0 && value <= maximum;
}
function amount(value) {
  return typeof value === 'string' && /^(?:0|[1-9][0-9]{0,9})\.[0-9]{2}(?![\s\S])/.test(value);
}
function identifier(value) {
  return (
    typeof value === 'string' &&
    /^[1-9][0-9]{0,19}(?![\s\S])/.test(value) &&
    (value.length < 20 || value <= '18446744073709551615')
  );
}
function payer(value) {
  return value === null || value === 'merchant' || value === 'platform';
}
export function transactionQuery(query = {}) {
  if (
    !query ||
    typeof query !== 'object' ||
    Array.isArray(query) ||
    Object.keys(query).some((key) => !['before_id', 'revision', 'limit'].includes(key))
  )
    throw new Error('测试交易查询条件未确认');
  const limit = query.limit === undefined ? 20 : query.limit;
  const out = { limit };
  if (!integer(limit, 50)) throw new Error('每页可读取1至50笔测试根单');
  if (query.before_id !== null && query.before_id !== undefined) {
    if (!integer(query.before_id, 2147483647)) throw new Error('测试交易分页位置未确认');
    out.before_id = query.before_id;
  }
  if (query.revision !== null && query.revision !== undefined) {
    if (!integer(query.revision)) throw new Error('请选择已保存的资金规则版本');
    out.revision = query.revision;
  }
  return out;
}
export function transactionsView(value, query = {}) {
  const requested = transactionQuery(query);
  exact(value, [
    'schema',
    'scope',
    'real_funds_enabled',
    'available_revisions',
    'selected_revision',
    'rule',
    'list',
    'next_before_id',
    'channel_fee_amount',
    'merchant_receivable',
    'settled_amount',
  ]);
  if (
    value.schema !== 1 ||
    value.scope !== 'testing_persisted_payment_v1' ||
    value.real_funds_enabled !== false ||
    value.selected_revision !== (requested.revision || null) ||
    value.channel_fee_amount !== null ||
    value.merchant_receivable !== null ||
    value.settled_amount !== null
  )
    throw new Error('测试交易或保存版本状态未确认，请重新读取');
  if (!Array.isArray(value.available_revisions) || value.available_revisions.length > 20)
    throw new Error('保存版本列表未确认');
  let previousRevision = Infinity;
  const revisions = value.available_revisions.map((row) => {
    exact(row, ['revision', 'created_at']);
    if (!integer(row.revision) || !integer(row.created_at) || row.revision >= previousRevision)
      throw new Error('保存版本顺序未确认');
    previousRevision = row.revision;
    return { ...row };
  });
  let rule = null;
  if (value.rule !== null) {
    exact(value.rule, ruleKeys);
    rule = { ...value.rule };
    if (
      ![null, true, false].includes(rule.platform_fee_enabled) ||
      ![null, 'goods_paid', 'order_paid'].includes(rule.platform_fee_basis) ||
      !payer(rule.platform_fee_payer) ||
      (rule.platform_fee_rate !== null &&
        (typeof rule.platform_fee_rate !== 'string' ||
          !/^(?:(?:0|[1-9][0-9]?)\.[0-9]{2}|100\.00)(?![\s\S])/.test(rule.platform_fee_rate)))
    )
      throw new Error('所选保存版本的平台费规则未确认');
  }
  if ((value.selected_revision === null) !== (rule === null)) throw new Error('保存版本与平台费规则不一致');
  if (!Array.isArray(value.list) || value.list.length > requested.limit) throw new Error('测试根单列表未确认');
  let previousRoot = requested.before_id || Infinity;
  const list = value.list.map((row) => {
    exact(row, [
      'root_order_id',
      'order_no',
      'mer_id',
      'currency',
      'order_amount',
      'goods_paid',
      'shipping_paid',
      'fulfillment_hold',
      'attempts',
      'estimate_status',
      'fee_estimate',
    ]);
    if (
      !integer(row.root_order_id, 2147483647) ||
      row.root_order_id >= previousRoot ||
      !integer(row.mer_id) ||
      typeof row.order_no !== 'string' ||
      !row.order_no ||
      row.currency !== 'CNY' ||
      !amount(row.order_amount) ||
      !amount(row.goods_paid) ||
      !amount(row.shipping_paid) ||
      typeof row.fulfillment_hold !== 'boolean' ||
      !Object.prototype.hasOwnProperty.call(estimateLabels, row.estimate_status)
    )
      throw new Error('测试根单金额或状态未确认');
    previousRoot = row.root_order_id;
    if (!Array.isArray(row.attempts) || !row.attempts.length) throw new Error('测试付款尝试列表未确认');
    const attempts = row.attempts.map((attempt) => {
      exact(attempt, ['attempt_id', 'admitted_generation', 'created_at', 'state', 'applied_at']);
      if (
        !identifier(attempt.attempt_id) ||
        !identifier(attempt.admitted_generation) ||
        !integer(attempt.created_at) ||
        !Object.prototype.hasOwnProperty.call(attemptLabels, attempt.state) ||
        (attempt.applied_at !== null && !integer(attempt.applied_at))
      )
        throw new Error('测试付款尝试状态未确认');
      return { ...attempt };
    });
    let estimate = null;
    if (row.fee_estimate !== null) {
      exact(row.fee_estimate, ['basis_amount', 'platform_fee_amount', 'payer']);
      estimate = { ...row.fee_estimate };
      if (
        (estimate.basis_amount !== null && !amount(estimate.basis_amount)) ||
        !amount(estimate.platform_fee_amount) ||
        !payer(estimate.payer)
      )
        throw new Error('平台费估算金额未确认');
    }
    if ((row.estimate_status === 'ESTIMATED') !== (estimate !== null)) throw new Error('平台费估算状态与金额不一致');
    return { ...row, attempts, fee_estimate: estimate };
  });
  if (
    value.next_before_id !== null &&
    (!integer(value.next_before_id, 2147483647) ||
      !list.length ||
      value.next_before_id !== list[list.length - 1].root_order_id)
  )
    throw new Error('下一页位置未确认');
  return { ...value, available_revisions: revisions, rule, list };
}
export function moneyText(value) {
  return value === null ? '尚未确认' : value + ' 元';
}
export function attemptStateText(state) {
  return attemptLabels[state];
}
export function estimateText(status) {
  return estimateLabels[status];
}
export function payerText(value) {
  return value === 'merchant' ? '商家承担' : value === 'platform' ? '平台承担' : '未配置';
}
