function accountTemplate(customerId) {
  return {
    customerId,
    principalBalanceCents: 0,
    principalRechargedCents: 0,
    principalSpentCents: 0,
    pointsBalance: 0,
    pointsEarned: 0,
    pointsSpent: 0,
    subsidyBalanceCents: 0,
    subsidyReceivedCents: 0,
    eligiblePrincipalSpentCents: 0,
  };
}

export function accountForCustomer(state, customerId) {
  return (state.memberAccounts || []).find((item) => item.customerId === customerId) || null;
}

export function ensureAccountForCustomer(state, customerId) {
  let account = accountForCustomer(state, customerId);
  if (!account) {
    account = accountTemplate(customerId);
    state.memberAccounts = state.memberAccounts || [];
    state.memberAccounts.push(account);
  }
  return account;
}

export function membershipsForCustomer(state, customerId) {
  return (state.memberships || []).filter(
    (item) => item.customerId === customerId && item.status === 'active',
  );
}

export function benefitsForCustomer(state, customerId) {
  return (state.benefitGrants || []).filter((item) => item.customerId === customerId);
}

export function effectiveBenefitsForCustomer(state, customerId) {
  const plans = membershipsForCustomer(state, customerId);
  const hasMembership = plans.some((item) => item.planType === 'membership-510');
  const hasStoredValue = plans.some((item) => item.planType === 'stored-value-5100');
  return {
    hasMembership,
    hasStoredValue,
    serviceDiscountRate: hasMembership || hasStoredValue ? 66 : 100,
    goodsDiscountRate: hasMembership ? 85 : 100,
  };
}

export function assetLedgerForCustomer(state, customerId, assetType = null) {
  return (state.memberTransactions || []).filter(
    (item) => item.customerId === customerId && (!assetType || item.assetType === assetType),
  );
}

export function memberProfileForCustomer(state, customerId) {
  const customer = (state.customers || []).find((item) => item.id === customerId) || null;
  if (!customer) return null;
  return {
    customer,
    account: accountForCustomer(state, customerId) || accountTemplate(customerId),
    plans: membershipsForCustomer(state, customerId),
    benefits: benefitsForCustomer(state, customerId),
    effectiveBenefits: effectiveBenefitsForCustomer(state, customerId),
    ledger: assetLedgerForCustomer(state, customerId),
    consumptions: (state.memberConsumptions || []).filter((item) => item.customerId === customerId),
    subsidyPlan: (state.subsidyPlans || []).find((item) => item.customerId === customerId) || null,
  };
}

export function memberOperationsOverview(state) {
  const customers = (state.memberAccounts || []).map((account) => (
    memberProfileForCustomer(state, account.customerId)
  )).filter(Boolean);
  return {
    customers,
    memberships: state.memberships || [],
    consumptions: state.memberConsumptions || [],
    subsidyPlans: state.subsidyPlans || [],
    subsidyRuns: state.subsidyRuns || [],
  };
}

export function calculateGoodsPricing(state, { customerId, originalCents }) {
  const discountRate = effectiveBenefitsForCustomer(state, customerId).goodsDiscountRate;
  return {
    originalCents,
    discountRate,
    payableCents: Math.round((originalCents * discountRate) / 100),
  };
}

export function calculateServiceSettlement(state, { customerId, originalCents, paymentSource }) {
  const discountRate = paymentSource === 'principal'
    ? effectiveBenefitsForCustomer(state, customerId).serviceDiscountRate
    : 100;
  return {
    originalCents,
    discountRate,
    principalCents: paymentSource === 'principal'
      ? Math.round((originalCents * discountRate) / 100)
      : 0,
    points: paymentSource === 'points' ? Math.round(originalCents / 100) : 0,
  };
}
