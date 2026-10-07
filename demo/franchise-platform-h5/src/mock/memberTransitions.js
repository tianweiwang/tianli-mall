import {
  calculateServiceSettlement,
  ensureAccountForCustomer,
} from './memberSelectors';
import { buildRegionalSettlementSnapshot } from './regionalAgent';

function requiredCustomer(state, customerId) {
  const customer = (state.customers || []).find((item) => item.id === customerId);
  if (!customer) throw new Error('客户不存在');
  return customer;
}

function nextId(state, field, prefix) {
  state.sequence[field] = Number(state.sequence[field] || 0) + 1;
  return `${prefix}${state.sequence[field]}`;
}

function assetFields(assetType) {
  const fields = {
    principal: ['principalBalanceCents', 'principalRechargedCents', 'principalSpentCents'],
    points: ['pointsBalance', 'pointsEarned', 'pointsSpent'],
    subsidy: ['subsidyBalanceCents', 'subsidyReceivedCents', null],
  };
  if (!fields[assetType]) throw new Error('不支持的资产类型');
  return fields[assetType];
}

export function creditAsset(state, input) {
  const existing = (state.memberTransactions || []).find(
    (item) => item.businessId === input.businessId && item.assetType === input.assetType,
  );
  if (existing) return existing;
  const amount = Math.max(1, Math.round(Number(input.amount) || 0));
  const account = ensureAccountForCustomer(state, input.customerId);
  const [balanceField, earnedField] = assetFields(input.assetType);
  account[balanceField] += amount;
  if (earnedField) account[earnedField] += amount;
  const transaction = {
    id: nextId(state, 'memberTransaction', 'MAT20260730'),
    customerId: input.customerId,
    assetType: input.assetType,
    direction: 'credit',
    amount,
    balanceAfter: account[balanceField],
    businessType: input.businessType,
    businessId: input.businessId,
    title: input.title,
    at: state.demoNow,
  };
  state.memberTransactions.unshift(transaction);
  return transaction;
}

export function debitAsset(state, input) {
  const existing = (state.memberTransactions || []).find(
    (item) => item.businessId === input.businessId && item.assetType === input.assetType,
  );
  if (existing) return existing;
  const amount = Math.max(1, Math.round(Number(input.amount) || 0));
  const account = ensureAccountForCustomer(state, input.customerId);
  const [balanceField, , spentField] = assetFields(input.assetType);
  if (amount > account[balanceField]) {
    throw new Error(input.assetType === 'points' ? '积分余额不足' : '储值本金余额不足');
  }
  account[balanceField] -= amount;
  if (spentField) account[spentField] += amount;
  const transaction = {
    id: nextId(state, 'memberTransaction', 'MAT20260730'),
    customerId: input.customerId,
    assetType: input.assetType,
    direction: 'debit',
    amount: -amount,
    balanceAfter: account[balanceField],
    businessType: input.businessType,
    businessId: input.businessId,
    title: input.title,
    at: state.demoNow,
  };
  state.memberTransactions.unshift(transaction);
  return transaction;
}

export function purchaseMembership(state, { customerId, paymentAttemptId }) {
  requiredCustomer(state, customerId);
  const repeated = (state.memberships || []).find(
    (item) => item.paymentAttemptId === paymentAttemptId,
  );
  if (repeated) return repeated;
  const active = (state.memberships || []).find(
    (item) => item.customerId === customerId && item.planType === 'membership-510' && item.status === 'active',
  );
  if (active) return active;
  const membership = {
    id: nextId(state, 'membership', 'MEM20260730'),
    customerId,
    planType: 'membership-510',
    planName: '510元会员卡',
    paidCents: 51000,
    paymentAttemptId,
    serviceDiscountRate: 66,
    goodsDiscountRate: 85,
    status: 'active',
    activatedAt: state.demoNow,
  };
  state.memberships.unshift(membership);
  const benefits = [
    ['member-gift', '入会礼品', 39800],
    ['store-service-benefit', '店内项目权益', 60000],
    ['service-voucher', '100元服务代金券', 10000],
    ['service-voucher', '100元服务代金券', 10000],
    ['goods-voucher', '50元商品代金券', 5000],
    ['goods-voucher', '50元商品代金券', 5000],
    ['goods-voucher', '50元商品代金券', 5000],
  ];
  benefits.forEach(([type, title, faceValueCents]) => {
    state.benefitGrants.push({
      id: nextId(state, 'benefit', 'BEN20260730'),
      customerId,
      membershipId: membership.id,
      type,
      title,
      faceValueCents,
      status: 'available',
      grantedAt: state.demoNow,
    });
  });
  return membership;
}

export function rechargeStoredValue(state, { customerId, paymentAttemptId }) {
  requiredCustomer(state, customerId);
  const repeated = (state.memberships || []).find(
    (item) => item.paymentAttemptId === paymentAttemptId,
  );
  if (repeated) return repeated;
  const membership = {
    id: nextId(state, 'membership', 'MEM20260730'),
    customerId,
    planType: 'stored-value-5100',
    planName: '5100元储值方案',
    paidCents: 510000,
    paymentAttemptId,
    serviceDiscountRate: 66,
    goodsDiscountRate: 100,
    status: 'active',
    activatedAt: state.demoNow,
  };
  state.memberships.unshift(membership);
  creditAsset(state, {
    customerId,
    assetType: 'principal',
    amount: 510000,
    businessType: 'stored-value-recharge',
    businessId: `${membership.id}-principal`,
    title: '储值本金到账',
  });
  creditAsset(state, {
    customerId,
    assetType: 'points',
    amount: 10200,
    businessType: 'stored-value-gift',
    businessId: `${membership.id}-points`,
    title: '储值赠送积分',
  });
  return membership;
}

export function recordEligiblePrincipalConsumption(state, { customerId, amountCents }) {
  const account = ensureAccountForCustomer(state, customerId);
  account.eligiblePrincipalSpentCents += amountCents;
  let plan = (state.subsidyPlans || []).find((item) => item.customerId === customerId);
  if (!plan && account.eligiblePrincipalSpentCents >= 510000) {
    plan = {
      id: nextId(state, 'subsidyPlan', 'SUB20260730'),
      customerId,
      targetPrincipalCents: 510000,
      periodAmountCents: 5100,
      totalPeriods: 100,
      paidPeriods: 0,
      paidCents: 0,
      status: 'active',
      createdAt: state.demoNow,
      lastPeriodKey: null,
      nextPeriodKey: null,
    };
    state.subsidyPlans.unshift(plan);
  }
  return plan;
}

export function settleVerifiedBooking(state, input) {
  const booking = (state.bookings || []).find((item) => item.id === input.bookingId);
  if (!booking) throw new Error('预约不存在');
  if (booking.storeId !== input.operatingStoreId) throw new Error('该预约属于其他门店');
  if (booking.status !== 'verified') throw new Error('预约核销后才能结算');
  if (booking.memberSettlement) return booking.memberSettlement;
  const customerId = booking.customerId;
  if (!customerId) throw new Error('预约未关联客户');
  const service = (state.services || []).find((item) => item.id === booking.serviceId);
  if (!service) throw new Error('服务项目不存在');
  const pricing = calculateServiceSettlement(state, {
    customerId,
    originalCents: service.unitPriceCents,
    paymentSource: input.paymentSource,
  });
  const regionalSettlement = buildRegionalSettlementSnapshot(state, {
    storeId: booking.storeId,
    service,
    paymentSource: input.paymentSource,
    settlementCents: pricing.principalCents,
  });
  const amount = input.paymentSource === 'principal' ? pricing.principalCents : pricing.points;
  debitAsset(state, {
    customerId,
    assetType: input.paymentSource,
    amount,
    businessType: 'service-settlement',
    businessId: input.settlementAttemptId || booking.id,
    title: `${service.name}项目结算`,
  });
  const settlement = {
    id: nextId(state, 'memberConsumption', 'MCO20260730'),
    bookingId: booking.id,
    customerId,
    storeId: booking.storeId,
    serviceId: service.id,
    paymentSource: input.paymentSource,
    originalCents: service.unitPriceCents,
    discountRate: pricing.discountRate,
    principalCents: pricing.principalCents,
    points: pricing.points,
    subsidyEligiblePrincipalCents: input.paymentSource === 'principal' ? pricing.principalCents : 0,
    regionalSettlement,
    settledAt: state.demoNow,
  };
  booking.memberSettlement = settlement;
  state.memberConsumptions.unshift(settlement);
  if (regionalSettlement?.cashSettlement) {
    const sharingRecord = {
      id: nextId(state, 'sharing', 'PS20260730'),
      orderId: null,
      businessType: 'service-settlement',
      businessId: settlement.id,
      bookingId: booking.id,
      storeId: booking.storeId,
      regionalAgentId: regionalSettlement.regionalAgentId,
      regionalAgentName: regionalSettlement.regionalAgentName,
      paidCents: regionalSettlement.settlementCents,
      storeIncomeCents: regionalSettlement.storeIncomeCents,
      brandCommissionCents: regionalSettlement.groupIncomeCents,
      regionalAgentIncomeCents: regionalSettlement.regionalAgentIncomeCents,
      distributionCostCents: 0,
      settlementSnapshot: { ...regionalSettlement },
      status: 'pending',
      timeline: [{ status: 'pending', at: state.demoNow }],
    };
    state.profitSharingRecords.unshift(sharingRecord);
    settlement.profitSharingRecordId = sharingRecord.id;
  }
  if (input.paymentSource === 'principal') {
    recordEligiblePrincipalConsumption(state, {
      customerId,
      amountCents: pricing.principalCents,
    });
  }
  return settlement;
}

export function runWeeklySubsidy(state, { periodKey }) {
  if (!periodKey) throw new Error('补贴周期不能为空');
  state.subsidyBatches = state.subsidyBatches || [];
  const previousBatch = state.subsidyBatches.find((item) => item.periodKey === periodKey);
  if (previousBatch) {
    const records = (state.subsidyRuns || []).filter(
      (item) => item.batchId === previousBatch.id,
    );
    return {
      ...previousBatch,
      alreadyExecuted: true,
      records,
    };
  }

  const batch = {
    id: nextId(state, 'subsidyBatch', 'SUBBATCH20260730'),
    periodKey,
    executedAt: state.demoNow,
    totalAmountCents: 0,
    recordCount: 0,
  };
  const records = [];
  (state.subsidyPlans || [])
    .filter((plan) => plan.status === 'active' && plan.paidPeriods < plan.totalPeriods)
    .forEach((plan) => {
      const run = {
        id: nextId(state, 'subsidyRun', 'SUBRUN20260730'),
        batchId: batch.id,
        periodKey,
        planId: plan.id,
        customerId: plan.customerId,
        amountCents: plan.periodAmountCents,
        executedAt: state.demoNow,
      };
      creditAsset(state, {
        customerId: plan.customerId,
        assetType: 'subsidy',
        amount: plan.periodAmountCents,
        businessType: 'weekly-subsidy',
        businessId: run.id,
        title: `第${plan.paidPeriods + 1}期集团补贴`,
      });
      plan.paidPeriods += 1;
      plan.paidCents += plan.periodAmountCents;
      plan.lastPeriodKey = periodKey;
      plan.nextPeriodKey = null;
      if (plan.paidPeriods >= plan.totalPeriods) plan.status = 'completed';
      state.subsidyRuns.unshift(run);
      records.push(run);
    });
  batch.recordCount = records.length;
  batch.totalAmountCents = records.reduce((sum, item) => sum + item.amountCents, 0);
  state.subsidyBatches.unshift(batch);
  return {
    ...batch,
    alreadyExecuted: false,
    records,
  };
}
