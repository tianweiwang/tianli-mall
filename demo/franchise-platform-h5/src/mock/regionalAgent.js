const ELIGIBLE_SERIES = new Set(['特色系列', '经典系列']);

export function isRegionalAgentSeries(series) {
  return ELIGIBLE_SERIES.has(series);
}

export function regionalAgentById(state, agentId) {
  return (state.regionalAgents || []).find((item) => item.id === agentId) || null;
}

export function regionalAgentForStore(state, storeId) {
  const store = (state.stores || []).find((item) => item.id === storeId);
  return store?.regionalAgentId ? regionalAgentById(state, store.regionalAgentId) : null;
}

export function buildRegionalSettlementSnapshot(state, input) {
  if (!isRegionalAgentSeries(input.service?.series)) return null;
  const agent = regionalAgentForStore(state, input.storeId);
  const cashSettlement = input.paymentSource === 'principal';
  const settlementCents = cashSettlement ? Math.max(0, Math.round(Number(input.settlementCents) || 0)) : 0;
  const storeIncomeCents = Math.round(settlementCents * 0.5);
  const regionalAgentIncomeCents = agent ? Math.round(settlementCents * 0.15) : 0;
  const groupIncomeCents = settlementCents - storeIncomeCents - regionalAgentIncomeCents;

  return {
    applicable: true,
    cashSettlement,
    serviceId: input.service.id,
    serviceName: input.service.name,
    serviceSeries: input.service.series,
    storeId: input.storeId,
    regionalAgentId: agent?.id || null,
    regionalAgentName: agent?.name || '集团直营',
    storeRate: 50,
    groupRate: agent ? 35 : 50,
    regionalAgentRate: agent ? 15 : 0,
    settlementCents,
    storeIncomeCents,
    groupIncomeCents,
    regionalAgentIncomeCents,
  };
}

const PENDING_STATUSES = new Set(['pending', 'processing', 'failed', 'returning']);

export function regionalAgentOverview(state, agentId) {
  const agent = regionalAgentById(state, agentId);
  const stores = (state.stores || []).filter((item) => item.regionalAgentId === agentId);
  const records = (state.profitSharingRecords || []).filter(
    (item) => item.regionalAgentId === agentId,
  );
  return {
    agent,
    stores,
    records,
    pendingCents: records
      .filter((item) => PENDING_STATUSES.has(item.status))
      .reduce((sum, item) => sum + Number(item.regionalAgentIncomeCents || 0), 0),
    settledCents: records
      .filter((item) => item.status === 'completed')
      .reduce((sum, item) => sum + Number(item.regionalAgentIncomeCents || 0), 0),
  };
}

export function regionalAgentList(state) {
  return (state.regionalAgents || []).map((agent) => regionalAgentOverview(state, agent.id));
}
