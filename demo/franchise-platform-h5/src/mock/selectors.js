import { accountForCustomer, assetLedgerForCustomer } from './memberSelectors';

export function activeStore(state) {
  return state.stores.find((item) => item.id === state.activeStoreId);
}

export function ordersForStore(state, storeId) {
  return state.orders.filter((item) => item.storeId === storeId);
}

export function allOrders(state) {
  return state.orders;
}

export function ordersForCustomer(state, customerId) {
  return state.orders.filter((item) => item.customerId === customerId);
}

export function orderForCustomer(state, customerId, orderId) {
  return state.orders.find(
    (item) => item.customerId === customerId && item.id === orderId,
  ) || null;
}

export function bookingsForStore(state, storeId) {
  return state.bookings.filter((item) => item.storeId === storeId);
}

export function profitSharingForStore(state, storeId) {
  return state.profitSharingRecords.filter((item) => item.storeId === storeId);
}

export function formatMoney(cents) {
  return `¥${(Number(cents) / 100).toFixed(2)}`;
}

export function sumBy(items, field) {
  return items.reduce((total, item) => total + Number(item[field] || 0), 0);
}

const revenueStatuses = new Set(['paid', 'shipping', 'completed']);

function revenueOrdersForStore(state, storeId) {
  return ordersForStore(state, storeId).filter((item) => revenueStatuses.has(item.status));
}

function allRevenueOrders(state) {
  return state.orders.filter((item) => revenueStatuses.has(item.status));
}

export function storeOverview(state, storeId) {
  const orders = ordersForStore(state, storeId);
  const revenueOrders = revenueOrdersForStore(state, storeId);
  const bookings = bookingsForStore(state, storeId);
  const settlements = profitSharingForStore(state, storeId);
  const pendingBookingCount = bookings.filter((item) => item.status === 'pending').length;
  const verifiedBookingCount = bookings.filter((item) => item.status === 'verified').length;
  const activeBookingCount = pendingBookingCount + verifiedBookingCount;
  const openSettlements = settlements.filter(
    (item) => !['completed', 'returned'].includes(item.status),
  );

  return {
    orderCount: orders.length,
    revenueOrderCount: revenueOrders.length,
    bookingCount: bookings.length,
    pendingBookingCount,
    verifiedBookingCount,
    arrivalRate: activeBookingCount
      ? Math.round((verifiedBookingCount / activeBookingCount) * 100)
      : 0,
    grossSalesCents: sumBy(revenueOrders, 'paidCents'),
    expectedIncomeCents: sumBy(revenueOrders, 'storeIncomeCents'),
    brandCommissionCents: sumBy(revenueOrders, 'brandCommissionCents'),
    openSettlementCount: openSettlements.length,
    openSettlementIncomeCents: sumBy(openSettlements, 'storeIncomeCents'),
  };
}

function buildSalesTrend(state, orders, days) {
  const anchorDate = state.demoNow.slice(0, 10);
  const anchor = new Date(`${anchorDate}T00:00:00Z`);
  const salesByDate = orders.reduce((result, order) => {
    const date = (order.paidAt || order.createdAt).slice(0, 10);
    result[date] = (result[date] || 0) + order.paidCents;
    return result;
  }, {});

  return Array.from({ length: days }, (_, index) => {
    const date = new Date(anchor);
    date.setUTCDate(anchor.getUTCDate() - (days - index - 1));
    const dateKey = date.toISOString().slice(0, 10);
    return {
      date: dateKey,
      label: dateKey.slice(5),
      salesCents: salesByDate[dateKey] || 0,
    };
  });
}

export function salesTrendForStore(state, storeId, days = 7) {
  return buildSalesTrend(state, revenueOrdersForStore(state, storeId), days);
}

export function productRankingForStore(state, storeId) {
  const ranking = new Map();
  revenueOrdersForStore(state, storeId).forEach((order) => {
    order.items.forEach((item) => {
      const current = ranking.get(item.productId) || {
        productId: item.productId,
        name: item.name,
        quantity: 0,
        salesCents: 0,
      };
      current.quantity += item.quantity;
      current.salesCents += item.priceCents * item.quantity;
      ranking.set(item.productId, current);
    });
  });

  return [...ranking.values()].sort(
    (left, right) => right.salesCents - left.salesCents || right.quantity - left.quantity,
  );
}

export function brandOverview(state) {
  const revenueOrders = allRevenueOrders(state);
  const pendingBookingCount = state.bookings.filter((item) => item.status === 'pending').length;
  const verifiedBookingCount = state.bookings.filter((item) => item.status === 'verified').length;
  const activeBookingCount = pendingBookingCount + verifiedBookingCount;
  const openSettlements = state.profitSharingRecords.filter(
    (item) => !['completed', 'returned'].includes(item.status),
  );

  return {
    activeStoreCount: state.stores.filter((item) => item.status === 'active').length,
    reviewingStoreCount: state.stores.filter(
      (item) => item.onboardingStatus === 'reviewing',
    ).length,
    orderCount: state.orders.length,
    revenueOrderCount: revenueOrders.length,
    bookingCount: state.bookings.length,
    pendingBookingCount,
    verifiedBookingCount,
    arrivalRate: activeBookingCount
      ? Math.round((verifiedBookingCount / activeBookingCount) * 100)
      : 0,
    grossSalesCents: sumBy(revenueOrders, 'paidCents'),
    brandCommissionCents: sumBy(revenueOrders, 'brandCommissionCents'),
    storeIncomeCents: sumBy(revenueOrders, 'storeIncomeCents'),
    openSettlementCount: openSettlements.length,
    openBrandCommissionCents: sumBy(openSettlements, 'brandCommissionCents'),
  };
}

export function storeRanking(state) {
  return state.stores.map((store) => ({
    storeId: store.id,
    storeName: store.name,
    storeStatus: store.status,
    onboardingStatus: store.onboardingStatus,
    ...storeOverview(state, store.id),
  })).sort(
    (left, right) => right.grossSalesCents - left.grossSalesCents,
  );
}

export function salesTrendForBrand(state, days = 7) {
  return buildSalesTrend(state, allRevenueOrders(state), days);
}

export function categorySalesForBrand(state) {
  const categories = new Map();
  allRevenueOrders(state).forEach((order) => {
    order.items.forEach((item) => {
      const product = state.products.find((candidate) => candidate.id === item.productId);
      const category = product?.category || '其他';
      const current = categories.get(category) || { category, quantity: 0, salesCents: 0 };
      current.quantity += item.quantity;
      current.salesCents += item.priceCents * item.quantity;
      categories.set(category, current);
    });
  });

  return [...categories.values()].sort((left, right) => right.salesCents - left.salesCents);
}

export function brandBookingOperations(state, filters = {}) {
  const date = filters.date || state.demoNow.slice(0, 10);
  const storeId = filters.storeId || 'all';
  const serviceId = filters.serviceId || 'all';
  const matchesFilters = (item) => item.date === date
    && (storeId === 'all' || item.storeId === storeId)
    && (serviceId === 'all' || item.serviceId === serviceId);
  const inventory = state.slotInventory.filter(matchesFilters);
  const bookings = state.bookings.filter(matchesFilters);
  const services = new Map(state.services.map((service) => [service.id, service.name]));
  const columns = [...new Map(inventory.map((slot) => {
    const key = `${slot.start}-${slot.end}`;
    return [key, { key, label: key, start: slot.start, end: slot.end }];
  })).values()].sort((left, right) => left.start.localeCompare(right.start)
    || left.end.localeCompare(right.end));
  const inventoryByCell = new Map();

  inventory.forEach((slot) => {
    const key = `${slot.storeId}:${slot.start}-${slot.end}`;
    const cell = inventoryByCell.get(key) || {
      booked: 0,
      capacity: 0,
      serviceIds: new Set(),
    };
    cell.booked += Number(slot.booked || 0);
    cell.capacity += Number(slot.capacity || 0);
    cell.serviceIds.add(slot.serviceId);
    inventoryByCell.set(key, cell);
  });

  const stores = state.stores.filter((store) => storeId === 'all' || store.id === storeId);
  const buildCell = (store, column) => {
    const key = `${store.id}:${column.key}`;
    const source = inventoryByCell.get(key);
    const serviceNames = source ? [...source.serviceIds].map((id) => services.get(id) || id) : [];
    const capacity = source?.capacity || 0;
    const booked = source?.booked || 0;
    const selectable = store.status === 'active' && capacity > 0;
    const tone = store.status !== 'active' || capacity <= 0
      ? 'off'
      : (booked >= capacity ? 'full' : (booked / capacity >= 0.6 ? 'busy' : 'available'));

    return {
      key,
      storeId: store.id,
      storeName: store.name,
      start: column.start,
      end: column.end,
      label: column.label,
      booked,
      capacity,
      serviceLabel: serviceNames.length > 1 ? `${serviceNames.length} 个服务` : (serviceNames[0] || ''),
      tone,
      selectable,
      testId: `booking-heat-cell-${store.id}-${column.start}-${column.end}`,
    };
  };
  const rows = stores.map((store) => {
    const storeBookings = bookings.filter((booking) => booking.storeId === store.id);
    const pendingCount = storeBookings.filter((booking) => booking.status === 'pending').length;
    const verifiedCount = storeBookings.filter((booking) => booking.status === 'verified').length;

    return {
      storeId: store.id,
      storeName: store.name,
      storeStatus: store.status,
      openHours: store.openHours,
      bookingCount: storeBookings.length,
      arrivalRate: pendingCount + verifiedCount
        ? Math.round((verifiedCount / (pendingCount + verifiedCount)) * 100)
        : 0,
      cells: columns.map((column) => buildCell(store, column)),
    };
  });
  const cells = rows.flatMap((row) => row.cells);
  const pendingBookings = bookings.filter((booking) => booking.status === 'pending');
  const expiredCount = bookings.filter((booking) => booking.status === 'expired').length;
  const fullCells = cells.filter((cell) => cell.tone === 'full');
  const capacity = sumBy(inventory, 'capacity');
  const booked = sumBy(inventory, 'booked');
  const metrics = {
    bookingCount: bookings.length,
    activeStoreCount: new Set(bookings
      .filter((booking) => state.stores.find((store) => store.id === booking.storeId)?.status === 'active')
      .map((booking) => booking.storeId)).size,
    booked,
    capacity,
    occupancyRate: capacity ? Math.round((booked / capacity) * 100) : 0,
    pendingCount: pendingBookings.length,
    nextPendingTime: pendingBookings.length
      ? pendingBookings.map((booking) => booking.start).sort()[0]
      : null,
    fullSlotCount: fullCells.length,
    expiredCount,
    attentionCount: fullCells.length + expiredCount,
  };
  const attention = fullCells.map((cell) => ({
    type: 'full-slot',
    key: cell.key,
    label: `${cell.storeName} ${cell.label} 已满`,
    cell,
  }));
  if (expiredCount) {
    attention.push({
      type: 'expired',
      key: 'expired',
      label: `${expiredCount} 条预约已过期`,
      count: expiredCount,
    });
  }

  return { date, columns, rows, metrics, attention };
}

export function customerIdentity(state) {
  return {
    id: state.activeCustomerId || 'CUS1001',
    name: state.activeCustomerName || '陈女士',
  };
}

export function switchableCustomerAccounts(state) {
  const customerIds = new Set([
    state.distributor?.customerId || 'CUS1001',
    ...(state.memberAccounts || []).map((item) => item.customerId),
  ]);
  (state.distributionBindings || [])
    .filter((item) => item.status === 'active')
    .forEach((item) => customerIds.add(item.customerId));
  return [...customerIds]
    .map((customerId) => (state.customers || []).find((item) => item.id === customerId))
    .filter(Boolean);
}

export function pointsOverview(state) {
  const account = accountForCustomer(state, state.activeCustomerId);
  return {
    balance: Number(account?.pointsBalance || 0),
    totalEarned: Number(account?.pointsEarned || 0),
    totalSpent: Number(account?.pointsSpent || 0),
    ledger: assetLedgerForCustomer(state, state.activeCustomerId, 'points'),
  };
}

export function referralOverview(state) {
  const referrals = state.referrals || [];
  const rewarded = referrals.filter((item) => item.status === 'rewarded');
  return {
    code: state.referral?.code || 'TL88C6',
    rewardCents: Number(state.referral?.rewardCents || 0),
    inviteUrl: state.referral?.inviteUrl || '',
    invitedCount: referrals.length,
    rewardedCount: rewarded.length,
    rewardPaidCents: sumBy(rewarded, 'rewardCents'),
    referrals,
  };
}

export function shareRecordByToken(state, token) {
  return (state.shareRecords || []).find((item) => item.token === token) || null;
}

export function activeDistributionBinding(state) {
  return (state.distributionBindings || []).find(
    (item) => item.id === state.activeDistributionBindingId && item.status === 'active',
  ) || null;
}

export function customerRecommendationOverview(state) {
  const binding = activeDistributionBinding(state);
  if (!binding || binding.customerId !== state.activeCustomerId) {
    return null;
  }
  const distributor = state.distributor?.id === binding.distributorId
    ? state.distributor
    : null;
  return {
    bindingId: binding.id,
    recommenderName: distributor?.name || '推荐人',
    boundAt: binding.acceptedAt,
    status: binding.status,
    firstOrderId: binding.firstOrderId,
    firstOrderStatus: binding.firstOrderId ? '首单已完成' : '待完成首单',
  };
}

export function activeCustomerIsDistributor(state) {
  return state.activeCustomerId === (state.distributor?.customerId || 'CUS1001');
}

export function commissionForOrder(state, orderId) {
  return (state.commissionRecords || []).find((item) => item.orderId === orderId) || null;
}

export function distributorOverview(state) {
  const account = state.distributor || {};
  const records = state.commissionRecords || [];
  const frozenCents = (state.withdrawalRequests || [])
    .filter((item) => item.role === 'distributor'
      && item.distributorId === account.id
      && item.status !== 'rejected')
    .reduce((total, item) => total + Number(item.amountCents || 0), 0);
  const withdrawableCents = Math.max(0, Number(account.settledCents || 0) - frozenCents);
  return {
    account: { ...account, withdrawableCents },
    records,
    teamCount: (state.referrals || []).length,
  };
}

export function shareOverview(state) {
  const records = state.shareRecords || [];
  return {
    records,
    visits: sumBy(records, 'visits'),
    orders: sumBy(records, 'orders'),
  };
}

export function withdrawalRequestsForStore(state, storeId) {
  return (state.withdrawalRequests || []).filter(
    (item) => item.role === 'store' && item.storeId === storeId,
  );
}

export function storeWithdrawalOverview(state, storeId) {
  const settledIncomeCents = profitSharingForStore(state, storeId)
    .filter((item) => item.status === 'completed')
    .reduce((total, item) => total + Number(item.storeIncomeCents || 0), 0);
  const records = withdrawalRequestsForStore(state, storeId);
  const frozenCents = records
    .filter((item) => item.status !== 'rejected')
    .reduce((total, item) => total + Number(item.amountCents || 0), 0);
  return {
    settledIncomeCents,
    availableCents: Math.max(0, settledIncomeCents - frozenCents),
    pendingCents: sumBy(records.filter((item) => item.status === 'pending' || item.status === 'processing'), 'amountCents'),
    completedCents: sumBy(records.filter((item) => item.status === 'completed'), 'amountCents'),
    records,
  };
}

export function allWithdrawalRequests(state) {
  return state.withdrawalRequests || [];
}

export function brandGrowthOverview(state) {
  const commissions = state.commissionRecords || [];
  const withdrawals = state.withdrawalRequests || [];
  const shares = state.shareRecords || [];
  const bindings = state.distributionBindings || [];
  const pendingWithdrawals = withdrawals.filter(
    (item) => item.status === 'pending' || item.status === 'processing',
  );
  return {
    distributorCount: state.distributor ? 1 : 0,
    totalCommissionCents: sumBy(commissions, 'amountCents'),
    pendingCommissionCents: sumBy(commissions.filter((item) => item.status === 'pending'), 'amountCents'),
    settledCommissionCents: sumBy(commissions.filter((item) => item.status === 'settled'), 'amountCents'),
    pendingWithdrawalCount: pendingWithdrawals.length,
    pendingWithdrawalCents: sumBy(pendingWithdrawals, 'amountCents'),
    shareVisits: sumBy(shares, 'visits'),
    shareOrders: sumBy(shares, 'orders'),
    shareBindings: bindings.filter((item) => item.status === 'active').length,
    attributedOrders: state.orders.filter((item) => item.distributionAttribution).length,
  };
}

export function liveRoomsForStore(state, storeId) {
  return (state.liveRooms || []).filter((item) => item.storeId === storeId);
}

export function liveRoomById(state, roomId) {
  const room = (state.liveRooms || []).find((item) => item.id === roomId);
  if (!room) {
    return null;
  }
  return {
    ...room,
    products: (room.productIds || [])
      .map((productId) => productForStoreById(state, room.storeId, productId))
      .filter(Boolean),
  };
}

export function liveBrandOverview(state) {
  const rooms = state.liveRooms || [];
  return {
    roomCount: rooms.length,
    liveCount: rooms.filter((item) => item.status === 'live').length,
    upcomingCount: rooms.filter((item) => item.status === 'upcoming').length,
    endedCount: rooms.filter((item) => item.status === 'ended').length,
    viewerCount: sumBy(rooms, 'viewerCount'),
    likeCount: sumBy(rooms, 'likeCount'),
  };
}

function isCatalogItemVisibleAtStore(item, storeId) {
  return item?.status === 'active' && item.storeIds?.includes(storeId);
}

export function productsForStore(state, storeId) {
  return (state.products || []).filter((item) => isCatalogItemVisibleAtStore(item, storeId));
}

export function servicesForStore(state, storeId) {
  return (state.services || []).filter((item) => isCatalogItemVisibleAtStore(item, storeId));
}

export function productForStoreById(state, storeId, productId) {
  return productsForStore(state, storeId).find((item) => item.id === productId);
}

export function serviceForStoreById(state, storeId, serviceId) {
  return servicesForStore(state, storeId).find((item) => item.id === serviceId);
}

export function serviceById(state, serviceId) {
  return (state.services || []).find((item) => item.id === serviceId) || null;
}

export function servicesForSeries(state, series) {
  return (state.services || []).filter((item) => item.series === series);
}

export function serviceSeriesList(state) {
  return [...new Set((state.services || []).map((item) => item.series))];
}

export function serviceAvailability(state, storeId, serviceId) {
  const slots = (state.slotInventory || []).filter(
    (item) => item.storeId === storeId && item.serviceId === serviceId,
  );
  const remainingTotal = slots.reduce(
    (sum, item) => sum + Math.max(0, item.capacity - item.booked),
    0,
  );
  return {
    slotCount: slots.length,
    remainingTotal,
    hasOnlineBooking: remainingTotal > 0,
  };
}

export function catalogOverview(state) {
  return {
    serviceCount: (state.services || []).length,
    productCount: (state.products || []).length,
    seriesCount: serviceSeriesList(state).length,
    activeStoreCount: (state.stores || []).filter((item) => item.status === 'active').length,
  };
}
