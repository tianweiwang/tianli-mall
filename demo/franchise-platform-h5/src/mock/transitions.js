import { accountForCustomer, calculateGoodsPricing } from './memberSelectors';
import { productForStoreById } from './selectors';
import {
  creditAsset,
  debitAsset,
  recordEligiblePrincipalConsumption,
} from './memberTransitions';

function findRequired(list, predicate, message) {
  const value = list.find(predicate);
  if (!value) {
    throw new Error(message);
  }
  return value;
}

function nextId(state, field, prefix) {
  state.sequence[field] += 1;
  return `${prefix}${state.sequence[field]}`;
}

function appendActivity(state, event) {
  state.activityLog.unshift({ ...event, at: state.demoNow });
}

export function createPaidOrder(state, input) {
  if (input.paymentAttemptId) {
    const existing = state.orders.find(
      (item) => item.paymentAttemptId === input.paymentAttemptId,
    );
    if (existing) {
      return existing;
    }
  }
  if (!state.cart.length) {
    throw new Error('购物车为空');
  }

  const store = findRequired(
    state.stores,
    (item) => item.id === state.activeStoreId && item.status === 'active',
    '当前门店不可用',
  );
  const items = state.cart.map((cartItem) => {
    const product = productForStoreById(state, store.id, cartItem.productId);
    if (!product) {
      throw new Error('商品已下架或当前门店不可售');
    }
    if (product.stock <= 0) {
      throw new Error('商品库存不足');
    }
    return {
      productId: product.id,
      name: product.name,
      image: product.image,
      priceCents: product.priceCents,
      quantity: cartItem.quantity,
      spec: cartItem.spec,
    };
  });
  const originalGoodsCents = items.reduce(
    (sum, item) => sum + item.priceCents * item.quantity,
    0,
  );
  const paymentSource = input.paymentSource || 'wechat';
  const pricing = calculateGoodsPricing(state, {
    customerId: state.activeCustomerId,
    originalCents: originalGoodsCents,
  });
  const paidCents = pricing.payableCents;
  if (paymentSource === 'principal') {
    const account = accountForCustomer(state, state.activeCustomerId);
    if (!account || account.principalBalanceCents < paidCents) {
      throw new Error('储值本金余额不足');
    }
  }
  const brandCommissionCents = Math.round(
    (paidCents * store.commissionRate) / 100,
  );
  const binding = (state.distributionBindings || []).find(
    (item) => item.id === state.activeDistributionBindingId && item.status === 'active',
  );
  const share = binding
    ? (state.shareRecords || []).find((item) => item.id === binding.shareId)
    : null;
  const distributor = binding && state.distributor?.id === binding.distributorId
    ? state.distributor
    : null;
  const commissionRate = distributor ? Number(distributor.commissionRate || 0) : 0;
  const distributionCostCents = distributor
    ? Math.round((paidCents * commissionRate) / 100)
    : 0;
  const order = {
    id: nextId(state, 'order', 'ORD20260730'),
    customerId: state.activeCustomerId,
    storeId: store.id,
    status: 'paid',
    createdAt: state.demoNow,
    paidAt: state.demoNow,
    delivery: input.delivery,
    addressId: input.addressId,
    paymentAttemptId: input.paymentAttemptId,
    items,
    originalGoodsCents,
    discountRate: pricing.discountRate,
    paidCents,
    paymentSource,
    assetDeduction: paymentSource === 'principal' ? paidCents : 0,
    subsidyEligiblePrincipalCents: paymentSource === 'principal' ? paidCents : 0,
    brandCommissionCents,
    storeIncomeCents: paidCents - brandCommissionCents,
    distributionAttribution: binding && share && distributor ? {
      shareId: share.id,
      bindingId: binding.id,
      distributorId: distributor.id,
      referralId: binding.referralId,
      commissionRate,
    } : null,
    distributionCostCents,
  };
  const sharingRecord = {
    id: nextId(state, 'sharing', 'PS20260730'),
    orderId: order.id,
    storeId: store.id,
    status: 'pending',
    paidCents,
    brandCommissionCents,
    storeIncomeCents: order.storeIncomeCents,
    distributionCostCents: order.distributionCostCents,
    timeline: [{ status: 'pending', at: state.demoNow }],
  };

  if (paymentSource === 'principal') {
    debitAsset(state, {
      customerId: state.activeCustomerId,
      assetType: 'principal',
      amount: paidCents,
      businessType: 'goods-order',
      businessId: order.id,
      title: `商品订单 ${order.id}`,
    });
    recordEligiblePrincipalConsumption(state, {
      customerId: state.activeCustomerId,
      amountCents: paidCents,
    });
  }
  state.memberConsumptions = state.memberConsumptions || [];
  state.memberConsumptions.unshift({
    id: nextId(state, 'memberConsumption', 'MCO20260730'),
    orderId: order.id,
    customerId: order.customerId,
    storeId: order.storeId,
    paymentSource,
    originalCents: originalGoodsCents,
    discountRate: pricing.discountRate,
    principalCents: paymentSource === 'principal' ? paidCents : 0,
    points: 0,
    subsidyEligiblePrincipalCents: order.subsidyEligiblePrincipalCents,
    settledAt: state.demoNow,
  });
  state.orders.unshift(order);
  state.profitSharingRecords.unshift(sharingRecord);
  if (binding && share && distributor) {
    const commission = {
      id: nextId(state, 'commission', 'CM20260730'),
      orderId: order.id,
      shareId: share.id,
      bindingId: binding.id,
      distributorId: distributor.id,
      customerName: binding.customerName,
      commissionRate,
      amountCents: distributionCostCents,
      status: 'pending',
      at: state.demoNow,
      settledAt: null,
    };
    state.commissionRecords.unshift(commission);
    distributor.totalCommissionCents += commission.amountCents;
    distributor.pendingCents += commission.amountCents;
    share.orders += 1;

    if (!binding.firstOrderId) {
      binding.firstOrderId = order.id;
      const referral = state.referrals.find((item) => item.id === binding.referralId);
      referral.status = 'rewarded';
      referral.rewardCents = Number(state.referral.rewardCents || 0);
      referral.firstOrderId = order.id;
      referral.rewardedAt = state.demoNow;
    }
  }
  state.cart = [];
  awardPoints(state, { title: `下单奖励 ${order.id}`, points: Math.floor(paidCents / 100) });
  appendActivity(state, {
    type: 'order-paid',
    entityId: order.id,
    storeId: store.id,
  });
  return order;
}

export function createBooking(state, input) {
  const store = findRequired(
    state.stores,
    (item) => item.id === state.activeStoreId && item.status === 'active',
    '当前门店不可用',
  );
  const slot = findRequired(
    state.slotInventory,
    (item) => item.id === input.slotId,
    '预约时段不存在',
  );
  if (slot.storeId !== store.id) {
    throw new Error('预约时段不属于当前门店');
  }
  if (slot.booked >= slot.capacity) {
    throw new Error('当前时段已满');
  }

  const bookingSequence = state.sequence.booking + 1;
  const booking = {
    id: nextId(state, 'booking', 'BK20260730'),
    customerId: state.activeCustomerId,
    storeId: slot.storeId,
    serviceId: slot.serviceId,
    slotId: slot.id,
    date: slot.date,
    start: slot.start,
    end: slot.end,
    contactName: input.contactName,
    phone: input.phone,
    sourceAssessmentId: input.sourceAssessmentId || null,
    bookingType: input.sourceAssessmentId ? 'reassessment' : 'service',
    status: 'pending',
    verifyCode: String(824100 + bookingSequence),
    createdAt: state.demoNow,
    released: false,
  };

  slot.booked += 1;
  state.bookings.unshift(booking);
  awardPoints(state, { title: `预约奖励 ${booking.id}`, points: 100 });
  appendActivity(state, {
    type: 'booking-created',
    entityId: booking.id,
    storeId: booking.storeId,
  });
  return booking;
}

function releaseBooking(state, bookingId, nextStatus, timestampField) {
  const booking = findRequired(
    state.bookings,
    (item) => item.id === bookingId,
    '预约不存在',
  );
  if (booking.status !== 'pending') {
    return booking;
  }
  const slot = findRequired(
    state.slotInventory,
    (item) => item.id === booking.slotId,
    '预约时段不存在',
  );

  booking.status = nextStatus;
  booking[timestampField] = state.demoNow;
  if (!booking.released) {
    slot.booked = Math.max(0, slot.booked - 1);
    booking.released = true;
  }
  appendActivity(state, {
    type: `booking-${nextStatus}`,
    entityId: booking.id,
    storeId: booking.storeId,
  });
  return booking;
}

export function cancelBooking(state, bookingId) {
  return releaseBooking(state, bookingId, 'cancelled', 'cancelledAt');
}

export function expireBooking(state, bookingId) {
  return releaseBooking(state, bookingId, 'expired', 'expiredAt');
}

export function importBookingFromPayload(state, payload) {
  if (payload.type !== 'booking') {
    throw new Error('当前只支持预约核销');
  }
  const existing = state.bookings.find((item) => item.id === payload.id);
  if (existing) {
    return existing;
  }
  const store = state.stores.find((item) => item.id === payload.storeId);
  if (!store) {
    throw new Error('二维码门店不存在');
  }

  const booking = {
    id: payload.id,
    storeId: payload.storeId,
    serviceId: state.services[0]?.id || 'SV1001',
    slotId: null,
    date: state.demoNow.slice(0, 10),
    start: '--:--',
    end: '--:--',
    contactName: '跨设备演示顾客',
    phone: '未同步',
    status: 'pending',
    verifyCode: payload.id.slice(-6),
    createdAt: state.demoNow,
    issuedAt: payload.issuedAt,
    imported: true,
    importedAt: state.demoNow,
    released: false,
  };

  state.bookings.unshift(booking);
  appendActivity(state, {
    type: 'booking-imported',
    entityId: booking.id,
    storeId: booking.storeId,
  });
  return booking;
}

export function verifyBooking(state, { bookingId, operatingStoreId }) {
  const booking = state.bookings.find((item) => item.id === bookingId);
  if (!booking) {
    return {
      ok: false,
      code: 'not-found',
      message: '未找到预约记录',
    };
  }
  if (booking.storeId !== operatingStoreId) {
    return {
      ok: false,
      code: 'cross-store',
      message: '该预约属于其他门店',
    };
  }
  if (booking.status === 'verified') {
    return {
      ok: false,
      code: 'duplicate',
      message: '该预约已核销',
    };
  }
  if (booking.status === 'expired') {
    return {
      ok: false,
      code: 'expired',
      message: '该预约已过期',
    };
  }
  if (booking.status !== 'pending') {
    return {
      ok: false,
      code: booking.status,
      message: '当前状态不可核销',
    };
  }

  booking.status = 'verified';
  booking.verifiedAt = state.demoNow;
  appendActivity(state, {
    type: 'booking-verified',
    entityId: booking.id,
    storeId: operatingStoreId,
  });
  return {
    ok: true,
    code: 'verified',
    booking,
  };
}

const sharingTransitions = {
  pending: ['processing'],
  processing: ['completed', 'failed', 'returning'],
  failed: ['processing'],
  completed: ['returning'],
  returning: ['returned'],
  returned: [],
};

export function advanceSharing(state, recordId, nextStatus) {
  const record = findRequired(
    state.profitSharingRecords,
    (item) => item.id === recordId,
    '分账记录不存在',
  );
  if (!sharingTransitions[record.status]?.includes(nextStatus)) {
    throw new Error('分账状态不可直接跳转');
  }

  record.status = nextStatus;
  record.timeline.push({ status: nextStatus, at: state.demoNow });
  if (nextStatus === 'completed') {
    const commission = (state.commissionRecords || []).find(
      (item) => item.orderId === record.orderId && item.status === 'pending',
    );
    if (commission) {
      commission.status = 'settled';
      commission.settledAt = state.demoNow;
      const distributor = state.distributor?.id === commission.distributorId
        ? state.distributor
        : null;
      if (distributor) {
        distributor.pendingCents -= commission.amountCents;
        distributor.settledCents += commission.amountCents;
      }
    }
  }
  appendActivity(state, {
    type: 'sharing-status-changed',
    entityId: record.id,
    storeId: record.storeId,
    status: nextStatus,
  });
  return record;
}

export function awardPoints(state, { title, points }) {
  const amount = Math.max(1, Math.floor(Number(points) || 0));
  return creditAsset(state, {
    customerId: state.activeCustomerId,
    assetType: 'points',
    amount,
    businessType: title.startsWith('下单奖励') ? 'order-reward' : 'booking-reward',
    businessId: nextId(state, 'points', 'PT20260730'),
    title,
  });
}

export function redeemPoints(state, { title, points }) {
  const amount = Math.max(1, Math.floor(Number(points) || 0));
  return debitAsset(state, {
    customerId: state.activeCustomerId,
    assetType: 'points',
    amount,
    businessType: 'points-redemption',
    businessId: nextId(state, 'points', 'PT20260730'),
    title,
  });
}

export function addReferral(state, { name, phone }) {
  const referral = {
    id: nextId(state, 'referral', 'RF20260730'),
    name,
    phone,
    status: 'pending',
    rewardCents: 0,
    invitedAt: state.demoNow,
  };
  state.referrals = state.referrals || [];
  state.referrals.unshift(referral);
  appendActivity(state, {
    type: 'referral-added',
    entityId: referral.id,
  });
  return referral;
}

export function recordShare(state, input) {
  const id = nextId(state, 'share', 'SH20260730');
  const record = {
    id,
    token: `TL-SH-${id.slice(-4)}`,
    distributorId: state.distributor.id,
    storeId: input.storeId,
    contentType: input.contentType,
    contentId: input.contentId,
    channel: input.channel,
    content: input.content,
    visits: 0,
    bindings: 0,
    orders: 0,
    at: state.demoNow,
  };
  state.shareRecords = state.shareRecords || [];
  state.shareRecords.unshift(record);
  appendActivity(state, {
    type: 'share-recorded',
    entityId: record.id,
    channel: record.channel,
  });
  return record;
}

export function recordShareVisit(state, token) {
  const share = findRequired(
    state.shareRecords,
    (item) => item.token === token && item.distributorId && item.storeId,
    '分享链接无效',
  );
  state.visitedShareTokens = state.visitedShareTokens || [];
  if (!state.visitedShareTokens.includes(token)) {
    state.visitedShareTokens.push(token);
    share.visits += 1;
  }
  return share;
}

export function activateBindingCustomer(state, binding) {
  state.activeRole = 'customer';
  state.activeCustomerId = binding.customerId;
  state.activeCustomerName = binding.customerName;
  state.customers = state.customers || [];

  const customer = state.customers.find((item) => item.id === binding.customerId);
  if (customer) {
    customer.name = binding.customerName;
    customer.phone = binding.phone;
  } else {
    state.customers.unshift({
      id: binding.customerId,
      type: 'member',
      name: binding.customerName,
      phone: binding.phone,
      joinedAt: binding.acceptedAt || state.demoNow,
    });
  }
  return binding;
}

export function switchCustomer(state, customerId) {
  const distributorCustomerId = state.distributor?.customerId || 'CUS1001';
  const memberCustomer = (state.memberAccounts || []).some((item) => item.customerId === customerId);
  const binding = (state.distributionBindings || []).find(
    (item) => item.customerId === customerId && item.status === 'active',
  );
  if (customerId !== distributorCustomerId && !binding && !memberCustomer) {
    throw new Error('消费者账号不可用');
  }
  const customer = (state.customers || []).find((item) => item.id === customerId);
  if (!customer) {
    throw new Error('消费者账号不存在');
  }

  state.activeRole = 'customer';
  state.activeCustomerId = customer.id;
  state.activeCustomerName = customer.name;
  state.activeDistributionBindingId = binding?.id || null;
  return customer;
}

export function acceptShareAttribution(state, input) {
  const share = findRequired(
    state.shareRecords,
    (item) => item.token === input.token && item.distributorId && item.storeId,
    '分享链接无效',
  );
  const existing = (state.distributionBindings || []).find(
    (item) => item.status === 'active',
  );
  if (existing) {
    state.activeDistributionBindingId = existing.id;
    return activateBindingCustomer(state, existing);
  }

  const referral = addReferral(state, {
    name: input.customerName,
    phone: input.phone,
  });
  const knownCustomer = (state.customers || []).find(
    (item) => item.name === input.customerName && item.phone === input.phone,
  );
  const binding = {
    id: nextId(state, 'binding', 'DB20260730'),
    shareId: share.id,
    token: share.token,
    distributorId: share.distributorId,
    referralId: referral.id,
    customerId: knownCustomer?.id || nextId(state, 'customer', 'CUS'),
    customerName: input.customerName,
    phone: input.phone,
    storeId: share.storeId,
    status: 'active',
    acceptedAt: state.demoNow,
    firstOrderId: null,
  };
  state.distributionBindings = state.distributionBindings || [];
  state.distributionBindings.unshift(binding);
  state.activeDistributionBindingId = binding.id;
  activateBindingCustomer(state, binding);
  share.bindings += 1;
  appendActivity(state, {
    type: 'distribution-bound',
    entityId: binding.id,
    shareId: share.id,
  });
  return binding;
}

function storeSettledIncomeCents(state, storeId) {
  return (state.profitSharingRecords || [])
    .filter((item) => item.storeId === storeId && item.status === 'completed')
    .reduce((total, item) => total + Number(item.storeIncomeCents || 0), 0);
}

function frozenWithdrawalCents(state, matches) {
  return (state.withdrawalRequests || [])
    .filter((item) => item.status !== 'rejected' && matches(item))
    .reduce((total, item) => total + Number(item.amountCents || 0), 0);
}

export function applyWithdrawal(state, input) {
  const amountCents = Math.floor(Number(input.amountCents) || 0);
  if (amountCents <= 0) {
    throw new Error('提现金额无效');
  }
  let availableCents = 0;
  if (input.role === 'store') {
    if (!input.storeId) {
      throw new Error('缺少门店信息');
    }
    availableCents = storeSettledIncomeCents(state, input.storeId)
      - frozenWithdrawalCents(state, (item) => item.role === 'store' && item.storeId === input.storeId);
  } else if (input.role === 'distributor') {
    availableCents = Number(state.distributor?.settledCents || 0)
      - frozenWithdrawalCents(state, (item) => item.role === 'distributor' && item.distributorId === input.distributorId);
  } else {
    throw new Error('提现主体无效');
  }
  if (amountCents > availableCents) {
    throw new Error('可提现余额不足');
  }

  const record = {
    id: nextId(state, 'withdrawal', 'WD20260730'),
    role: input.role,
    applicantName: input.applicantName,
    amountCents,
    channel: input.channel,
    accountNo: input.accountNo,
    status: 'pending',
    appliedAt: state.demoNow,
  };
  if (input.role === 'store') record.storeId = input.storeId;
  if (input.role === 'distributor') record.distributorId = input.distributorId;
  state.withdrawalRequests = state.withdrawalRequests || [];
  state.withdrawalRequests.unshift(record);
  appendActivity(state, {
    type: 'withdrawal-applied',
    entityId: record.id,
    role: record.role,
    amountCents,
  });
  return record;
}

export function reviewWithdrawal(state, { id, approved }) {
  const record = findRequired(
    state.withdrawalRequests,
    (item) => item.id === id,
    '提现记录不存在',
  );
  if (record.status === 'completed' || record.status === 'rejected') {
    throw new Error('提现已结束');
  }
  if (!approved) {
    record.status = 'rejected';
    record.processedAt = state.demoNow;
  } else if (record.status === 'pending') {
    record.status = 'processing';
  } else if (record.status === 'processing') {
    record.status = 'completed';
    record.processedAt = state.demoNow;
  }
  appendActivity(state, {
    type: 'withdrawal-reviewed',
    entityId: record.id,
    role: record.role,
    status: record.status,
  });
  return record;
}

export function bumpLiveStats(state, { roomId, likes = 0, viewers = 0 }) {
  const room = findRequired(
    state.liveRooms,
    (item) => item.id === roomId,
    '直播间不存在',
  );
  if (room.status !== 'live') {
    throw new Error('直播间当前不在直播中');
  }
  room.likeCount = Number(room.likeCount || 0) + Math.max(0, Number(likes) || 0);
  room.viewerCount = Number(room.viewerCount || 0) + Math.max(0, Number(viewers) || 0);
  appendActivity(state, {
    type: 'live-stats-bumped',
    entityId: room.id,
    storeId: room.storeId,
    likes: Number(likes) || 0,
    viewers: Number(viewers) || 0,
  });
  return room;
}

export function transitionLiveRoom(state, { roomId, targetStatus }) {
  const room = findRequired(
    state.liveRooms,
    (item) => item.id === roomId,
    '直播场次不存在',
  );
  const nextStatus = { upcoming: 'live', live: 'ended' }[room.status];
  if (nextStatus !== targetStatus) {
    throw new Error('直播状态不可这样变更');
  }
  room.status = targetStatus;
  if (targetStatus === 'live') room.startedAt = state.demoNow;
  if (targetStatus === 'ended') room.endedAt = state.demoNow;
  appendActivity(state, {
    type: 'live-status-transitioned',
    entityId: room.id,
    storeId: room.storeId,
    status: targetStatus,
  });
  return room;
}

export function createLiveRoom(state, input) {
  const store = findRequired(
    state.stores,
    (item) => item.id === input.storeId && item.status === 'active',
    '当前门店不可用',
  );
  (input.productIds || []).forEach((productId) => {
    const product = productForStoreById(state, store.id, productId);
    if (!product || product.stock <= 0) {
      throw new Error('直播商品已下架或当前门店不可售');
    }
  });
  const room = {
    id: nextId(state, 'live', 'LR'),
    storeId: store.id,
    title: input.title,
    anchor: input.anchor,
    status: 'upcoming',
    scheduledAt: state.demoNow,
    viewerCount: 0,
    likeCount: 0,
    productIds: input.productIds || [],
  };
  state.liveRooms = state.liveRooms || [];
  state.liveRooms.unshift(room);
  appendActivity(state, {
    type: 'live-room-created',
    entityId: room.id,
    storeId: store.id,
  });
  return room;
}
