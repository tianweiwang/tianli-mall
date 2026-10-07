import { createSeed } from '../../src/mock/seed';
import * as selectors from '../../src/mock/selectors';
import {
  addReferral,
  applyWithdrawal,
  createPaidOrder,
  recordShare,
  redeemPoints,
  reviewWithdrawal,
} from '../../src/mock/transitions';

describe('growth seed consistency', () => {
  it('keeps the points account balanced with its ledger', () => {
    const state = createSeed();
    const account = selectors.pointsOverview(state);
    const ledgerSum = account.ledger.reduce((sum, item) => sum + item.amount, 0);

    expect(account.balance).toBe(1680);
    expect(account.totalEarned).toBe(11980);
    expect(account.totalSpent).toBe(10300);
    expect(ledgerSum).toBe(account.balance);
    expect(ledgerSum).toBe(account.totalEarned - account.totalSpent);
  });

  it('keeps distributor and commission totals consistent', () => {
    const state = createSeed();
    const commissionSum = state.commissionRecords.reduce((sum, item) => sum + item.amountCents, 0);

    expect(state.distributor.totalCommissionCents).toBe(commissionSum);
    expect(state.distributor.settledCents + state.distributor.pendingCents)
      .toBe(state.distributor.totalCommissionCents);
  });

  it('computes store and distributor withdrawal limits from seed data', () => {
    const state = createSeed();
    const store = selectors.storeWithdrawalOverview(state, 'TL1001');
    const distributor = selectors.distributorOverview(state);

    expect(store).toMatchObject({
      settledIncomeCents: 56274,
      availableCents: 41274,
      pendingCents: 10000,
      completedCents: 5000,
    });
    expect(distributor.account).toMatchObject({
      settledCents: 21340,
      pendingCents: 11495,
      withdrawableCents: 12740,
    });
  });
});

describe('growth selectors', () => {
  it('summarizes referral and share data', () => {
    const state = createSeed();
    const referral = selectors.referralOverview(state);
    const share = selectors.shareOverview(state);

    expect(referral).toMatchObject({
      code: 'TL88C6',
      rewardCents: 800,
      invitedCount: 3,
      rewardedCount: 1,
      rewardPaidCents: 800,
    });
    expect(share).toMatchObject({ visits: 48, orders: 3 });
    expect(share.records).toHaveLength(3);
  });

  it('aggregates brand growth metrics', () => {
    const overview = selectors.brandGrowthOverview(createSeed());

    expect(overview).toMatchObject({
      distributorCount: 1,
      totalCommissionCents: 32835,
      pendingCommissionCents: 11495,
      pendingWithdrawalCount: 2,
      pendingWithdrawalCents: 18600,
      shareVisits: 48,
      shareOrders: 3,
    });
  });
});

describe('growth transitions', () => {
  it('awards points when a paid order is created', () => {
    const state = createSeed();
    const before = selectors.pointsOverview(state).balance;
    state.cart = [{ productId: 3, quantity: 2, spec: '盒装' }];

    const order = createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express' });

    expect(order.paidCents).toBe(84660);
    expect(selectors.pointsOverview(state).balance).toBe(before + 846);
    expect(selectors.pointsOverview(state).ledger[0]).toMatchObject({
      direction: 'credit',
      title: `下单奖励 ${order.id}`,
      amount: 846,
    });
  });

  it('rejects redemption when points are insufficient', () => {
    const state = createSeed();

    expect(() => redeemPoints(state, { title: '大额兑换', points: 999999 }))
      .toThrow('积分余额不足');
    expect(selectors.pointsOverview(state).balance).toBe(1680);
  });

  it('records a referral and a share', () => {
    const state = createSeed();
    const referralCount = state.referrals.length;
    const shareCount = state.shareRecords.length;

    const referral = addReferral(state, { name: '测试顾客', phone: '138****0000' });
    const share = recordShare(state, {
      channel: '微信好友',
      content: '门店分享',
      contentType: 'store',
      contentId: 'TL1001',
      storeId: 'TL1001',
    });

    expect(referral).toMatchObject({ status: 'pending', rewardCents: 0 });
    expect(state.referrals).toHaveLength(referralCount + 1);
    expect(share).toMatchObject({
      channel: '微信好友',
      distributorId: 'DST1001',
      visits: 0,
      bindings: 0,
      orders: 0,
    });
    expect(state.shareRecords).toHaveLength(shareCount + 1);
  });

  it('rejects a store withdrawal above the available balance', () => {
    const state = createSeed();
    const before = state.withdrawalRequests.length;

    const availableCents = selectors.storeWithdrawalOverview(state, 'TL1001').availableCents;

    expect(() => applyWithdrawal(state, {
      role: 'store',
      storeId: 'TL1001',
      applicantName: '天俪锦江店',
      amountCents: availableCents + 1,
      channel: 'bank',
      accountNo: '6217****8821',
    })).toThrow('可提现余额不足');
    expect(state.withdrawalRequests).toHaveLength(before);
  });

  it('creates a store withdrawal and reduces the available balance', () => {
    const state = createSeed();
    const before = selectors.storeWithdrawalOverview(state, 'TL1001').availableCents;

    const record = applyWithdrawal(state, {
      role: 'store',
      storeId: 'TL1001',
      applicantName: '天俪锦江店',
      amountCents: 5000,
      channel: 'bank',
      accountNo: '6217****8821',
    });

    expect(record).toMatchObject({ role: 'store', status: 'pending', amountCents: 5000 });
    expect(selectors.storeWithdrawalOverview(state, 'TL1001').availableCents)
      .toBe(before - 5000);
  });

  it('advances a withdrawal through review and rejects a finished record', () => {
    const state = createSeed();
    const record = state.withdrawalRequests.find((item) => item.id === 'WD202607301001');

    reviewWithdrawal(state, { id: record.id, approved: true });
    expect(record.status).toBe('processing');

    reviewWithdrawal(state, { id: record.id, approved: true });
    expect(record.status).toBe('completed');
    expect(record.processedAt).toBe(state.demoNow);

    expect(() => reviewWithdrawal(state, { id: record.id, approved: true }))
      .toThrow('提现已结束');
  });

  it('rejects a pending withdrawal directly', () => {
    const state = createSeed();
    const record = state.withdrawalRequests.find((item) => item.id === 'WD202607301001');

    reviewWithdrawal(state, { id: record.id, approved: false });

    expect(record.status).toBe('rejected');
  });
});
