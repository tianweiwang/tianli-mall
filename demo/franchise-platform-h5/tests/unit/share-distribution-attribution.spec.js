import { createSeed, DEMO_VERSION } from '../../src/mock/seed';
import { buildShareEntryPath, buildShareEntryUrl } from '../../src/mock/shareLinks';
import {
  acceptShareAttribution,
  advanceSharing,
  createPaidOrder,
  recordShare,
  recordShareVisit,
} from '../../src/mock/transitions';
import {
  activeDistributionBinding,
  commissionForOrder,
  customerIdentity,
  distributorOverview,
  shareRecordByToken,
} from '../../src/mock/selectors';

function bindFriend(state) {
  const share = recordShare(state, {
    channel: '微信好友',
    content: '门店首页',
    contentType: 'store',
    contentId: 'TL1001',
    storeId: 'TL1001',
  });
  return acceptShareAttribution(state, {
    token: share.token,
    customerName: '赵女士',
    phone: '138****6008',
  });
}

describe('share distribution attribution seed', () => {
  it('uses version 11 with attribution collections and sequences', () => {
    const state = createSeed();

    expect(DEMO_VERSION).toBe(11);
    expect(state.distributionBindings).toEqual([]);
    expect(state.visitedShareTokens).toEqual([]);
    expect(state.activeDistributionBindingId).toBeNull();
    expect(state.orders.every((item) => item.customerId === 'CUS1001')).toBe(true);
    expect(state.sequence).toMatchObject({
      binding: 1000,
      commission: 1003,
      customer: 1002,
    });
  });

  it('builds deployment-safe hash URLs', () => {
    expect(buildShareEntryPath('TL1001', 'TL-SH-1004'))
      .toBe('/c/store/TL1001/share-entry/TL-SH-1004');
    expect(buildShareEntryUrl(
      { origin: 'https://demo.example.com', pathname: '/franchise-demo/' },
      'TL1001',
      'TL-SH-1004',
    )).toBe('https://demo.example.com/franchise-demo/#/c/store/TL1001/share-entry/TL-SH-1004');
  });
});

describe('share campaign and binding transitions', () => {
  it('creates a tokenized share campaign and records one browser visit', () => {
    const state = createSeed();
    const share = recordShare(state, {
      channel: '微信好友',
      content: '门店首页',
      contentType: 'store',
      contentId: 'TL1001',
      storeId: 'TL1001',
    });

    expect(share).toMatchObject({
      distributorId: 'DST1001',
      storeId: 'TL1001',
      visits: 0,
      bindings: 0,
      orders: 0,
    });
    expect(share.token).toMatch(/^TL-SH-/);
    expect(recordShareVisit(state, share.token).visits).toBe(1);
    expect(recordShareVisit(state, share.token).visits).toBe(1);
    expect(shareRecordByToken(state, share.token).id).toBe(share.id);
  });

  it('accepts a share once and keeps the first active binding', () => {
    const state = createSeed();
    const share = recordShare(state, {
      channel: '二维码',
      content: '精选商品',
      contentType: 'product',
      contentId: '3',
      storeId: 'TL1001',
    });
    const first = acceptShareAttribution(state, {
      token: share.token,
      customerName: '赵女士',
      phone: '138****6008',
    });
    const second = acceptShareAttribution(state, {
      token: share.token,
      customerName: '赵女士',
      phone: '138****6008',
    });

    expect(second.id).toBe(first.id);
    expect(state.distributionBindings).toHaveLength(1);
    expect(state.referrals.filter((item) => item.id === first.referralId)).toHaveLength(1);
    expect(share.bindings).toBe(1);
    expect(activeDistributionBinding(state).id).toBe(first.id);
    expect(customerIdentity(state)).toEqual({ id: first.customerId, name: '赵女士' });
    expect(state.customers.find((item) => item.id === first.customerId)).toMatchObject({
      name: '赵女士',
      phone: '138****6008',
      type: 'member',
    });
  });

  it('does not replace the first active binding with another token', () => {
    const state = createSeed();
    const firstShare = recordShare(state, {
      channel: '二维码', content: '门店首页', contentType: 'store', contentId: 'TL1001', storeId: 'TL1001',
    });
    const secondShare = recordShare(state, {
      channel: '微信好友', content: '精选商品', contentType: 'product', contentId: '3', storeId: 'TL1001',
    });
    const first = acceptShareAttribution(state, {
      token: firstShare.token, customerName: '赵女士', phone: '138****6008',
    });
    const second = acceptShareAttribution(state, {
      token: secondShare.token, customerName: '孙女士', phone: '139****7009',
    });

    expect(second.id).toBe(first.id);
    expect(state.distributionBindings).toHaveLength(1);
    expect(secondShare.bindings).toBe(0);
    expect(customerIdentity(state)).toEqual({ id: first.customerId, name: '赵女士' });
  });

  it('rejects an unknown share token without modifying attribution state', () => {
    const state = createSeed();

    expect(() => acceptShareAttribution(state, {
      token: 'missing',
      customerName: '赵女士',
      phone: '138****6008',
    })).toThrow('分享链接无效');
    expect(state.distributionBindings).toEqual([]);
    expect(state.referrals).toHaveLength(3);
  });
});

describe('attributed order transitions', () => {
  it('creates one 8 percent commission and one first-order reward', () => {
    const state = createSeed();
    const binding = bindFriend(state);
    const commissionCount = state.commissionRecords.length;
    const totalBefore = state.distributor.totalCommissionCents;
    const pendingBefore = state.distributor.pendingCents;
    state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];

    const order = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-order-001',
    });

    expect(order.distributionAttribution).toMatchObject({
      bindingId: binding.id,
      distributorId: 'DST1001',
      commissionRate: 8,
    });
    expect(order.customerId).toBe(binding.customerId);
    expect(order.distributionCostCents).toBe(3984);
    expect(state.commissionRecords).toHaveLength(commissionCount + 1);
    expect(commissionForOrder(state, order.id)).toMatchObject({
      amountCents: 3984,
      status: 'pending',
      customerName: '赵女士',
    });
    expect(state.distributor.totalCommissionCents).toBe(totalBefore + 3984);
    expect(state.distributor.pendingCents).toBe(pendingBefore + 3984);
    expect(state.distributor.totalCommissionCents)
      .toBe(state.distributor.settledCents + state.distributor.pendingCents);
    expect(state.referrals.find((item) => item.id === binding.referralId)).toMatchObject({
      status: 'rewarded',
      rewardCents: 800,
      firstOrderId: order.id,
    });
    expect(binding.firstOrderId).toBe(order.id);
    expect(state.shareRecords.find((item) => item.id === binding.shareId).orders).toBe(1);
  });

  it('continues commission without repeating the referral reward', () => {
    const state = createSeed();
    const binding = bindFriend(state);
    state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];
    const first = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-order-002',
    });
    state.cart = [{ productId: 2, quantity: 1, spec: '单桶装' }];

    const second = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-order-003',
    });

    expect(commissionForOrder(state, first.id).amountCents).toBe(3184);
    expect(commissionForOrder(state, second.id).amountCents).toBe(3184);
    expect(state.referrals.find((item) => item.id === binding.referralId)).toMatchObject({
      status: 'rewarded',
      rewardCents: 800,
      firstOrderId: first.id,
    });
    expect(binding.firstOrderId).toBe(first.id);
    expect(state.shareRecords.find((item) => item.id === binding.shareId).orders).toBe(2);
  });

  it('keeps ordinary orders free from commission', () => {
    const state = createSeed();
    const before = state.commissionRecords.length;
    state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];

    const order = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'ordinary-order-001',
    });

    expect(order.distributionAttribution).toBeNull();
    expect(order.distributionCostCents).toBe(0);
    expect(state.commissionRecords).toHaveLength(before);
  });

  it('does not duplicate commission or referral rewards for a repeated payment attempt', () => {
    const state = createSeed();
    const binding = bindFriend(state);
    state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    const input = {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-order-repeat-001',
    };
    const first = createPaidOrder(state, input);
    const commissionCount = state.commissionRecords.length;
    const totalCommissionCents = state.distributor.totalCommissionCents;
    const referral = state.referrals.find((item) => item.id === binding.referralId);

    const second = createPaidOrder(state, input);

    expect(second.id).toBe(first.id);
    expect(state.commissionRecords).toHaveLength(commissionCount);
    expect(state.distributor.totalCommissionCents).toBe(totalCommissionCents);
    expect(referral).toMatchObject({ rewardCents: 800, firstOrderId: first.id });
  });
});

describe('attributed commission settlement', () => {
  it('settles attributed commission only when profit sharing completes', () => {
    const state = createSeed();
    bindFriend(state);
    state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    const order = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-settlement-001',
    });
    const sharing = state.profitSharingRecords.find((item) => item.orderId === order.id);
    const commission = commissionForOrder(state, order.id);
    const settledBefore = state.distributor.settledCents;
    const availableBefore = distributorOverview(state).account.withdrawableCents;

    advanceSharing(state, sharing.id, 'processing');
    expect(commission.status).toBe('pending');
    expect(state.distributor.settledCents).toBe(settledBefore);

    advanceSharing(state, sharing.id, 'completed');

    expect(commission).toMatchObject({ status: 'settled', settledAt: state.demoNow });
    expect(state.distributor.settledCents).toBe(settledBefore + commission.amountCents);
    expect(distributorOverview(state).account.withdrawableCents)
      .toBe(availableBefore + commission.amountCents);
    expect(state.distributor.pendingCents).toBeGreaterThanOrEqual(0);
    expect(state.distributor.totalCommissionCents)
      .toBe(state.distributor.settledCents + state.distributor.pendingCents);
    expect(() => advanceSharing(state, sharing.id, 'completed'))
      .toThrow('分账状态不可直接跳转');
  });
});
