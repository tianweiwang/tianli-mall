import { createSeed } from '../../src/mock/seed';
import {
  buildRegionalSettlementSnapshot,
  isRegionalAgentSeries,
  regionalAgentForStore,
  regionalAgentOverview,
} from '../../src/mock/regionalAgent';

function createPolicyState() {
  const state = createSeed();
  state.regionalAgents = [
    { id: 'RA1001', name: '川西区域运营中心', status: 'active', commissionRate: 15 },
  ];
  state.stores.find((store) => store.id === 'TL1001').regionalAgentId = 'RA1001';
  state.stores.find((store) => store.id === 'TL1002').regionalAgentId = null;
  return state;
}

describe('regional agent settlement policy', () => {
  it('applies only to featured and classic service series', () => {
    expect(isRegionalAgentSeries('特色系列')).toBe(true);
    expect(isRegionalAgentSeries('经典系列')).toBe(true);
    expect(isRegionalAgentSeries('评估系列')).toBe(false);
    expect(isRegionalAgentSeries('传统系列')).toBe(false);
    expect(isRegionalAgentSeries('精品系列')).toBe(false);
    expect(isRegionalAgentSeries('定制系列')).toBe(false);
  });

  it('splits an agent-store principal settlement 50/35/15 without losing cents', () => {
    const state = createPolicyState();

    const snapshot = buildRegionalSettlementSnapshot(state, {
      storeId: 'TL1001',
      service: { id: 'SV1007', name: '颈椎矫正', series: '特色系列' },
      paymentSource: 'principal',
      settlementCents: 10001,
    });

    expect(snapshot).toMatchObject({
      applicable: true,
      cashSettlement: true,
      serviceId: 'SV1007',
      serviceName: '颈椎矫正',
      serviceSeries: '特色系列',
      storeId: 'TL1001',
      regionalAgentId: 'RA1001',
      regionalAgentName: '川西区域运营中心',
      storeRate: 50,
      groupRate: 35,
      regionalAgentRate: 15,
      settlementCents: 10001,
      storeIncomeCents: 5001,
      groupIncomeCents: 3500,
      regionalAgentIncomeCents: 1500,
    });
    expect(snapshot.storeIncomeCents + snapshot.groupIncomeCents + snapshot.regionalAgentIncomeCents)
      .toBe(snapshot.settlementCents);
  });

  it('splits a direct-store principal settlement 50/50/0', () => {
    const snapshot = buildRegionalSettlementSnapshot(createPolicyState(), {
      storeId: 'TL1002',
      service: { id: 'SV1014', name: '肩背经筋调理', series: '经典系列' },
      paymentSource: 'principal',
      settlementCents: 10001,
    });

    expect(snapshot).toMatchObject({
      cashSettlement: true,
      regionalAgentId: null,
      regionalAgentName: '集团直营',
      storeRate: 50,
      groupRate: 50,
      regionalAgentRate: 0,
      settlementCents: 10001,
      storeIncomeCents: 5001,
      groupIncomeCents: 5000,
      regionalAgentIncomeCents: 0,
    });
  });

  it('stores zero cash amounts for points settlement', () => {
    const snapshot = buildRegionalSettlementSnapshot(createPolicyState(), {
      storeId: 'TL1001',
      service: { id: 'SV1007', name: '颈椎矫正', series: '特色系列' },
      paymentSource: 'points',
      settlementCents: 10001,
    });

    expect(snapshot).toMatchObject({
      applicable: true,
      cashSettlement: false,
      settlementCents: 0,
      storeIncomeCents: 0,
      groupIncomeCents: 0,
      regionalAgentIncomeCents: 0,
    });
  });

  it('does not resolve a regional agent for a direct store', () => {
    expect(regionalAgentForStore(createPolicyState(), 'TL1002')).toBeNull();
  });

  it('returns no settlement snapshot for an ineligible service series', () => {
    expect(buildRegionalSettlementSnapshot(createPolicyState(), {
      storeId: 'TL1001',
      service: { id: 'SV1004', name: '古法刮痧', series: '传统系列' },
      paymentSource: 'principal',
      settlementCents: 6468,
    })).toBeNull();
  });

  it('restores deterministic agents and store attribution', () => {
    const first = createSeed();
    const second = createSeed();

    expect(first.regionalAgents).toEqual(second.regionalAgents);
    expect(first.regionalAgents.map((item) => item.id)).toEqual(['RA1001', 'RA1002']);
    expect(first.stores.find((item) => item.id === 'TL1001').regionalAgentId).toBe('RA1001');
    expect(first.stores.find((item) => item.id === 'TL1002').regionalAgentId).toBeNull();
    expect(first.stores.find((item) => item.id === 'TL1003').regionalAgentId).toBe('RA1002');
  });

  it('derives pending and settled income from original sharing status', () => {
    const state = createSeed();
    state.profitSharingRecords = state.profitSharingRecords.filter((item) => !item.regionalAgentId);
    state.profitSharingRecords.unshift(
      { id: 'PS-RA-PENDING', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 1500, status: 'pending' },
      { id: 'PS-RA-RETURNING', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 600, status: 'returning' },
      { id: 'PS-RA-COMPLETED', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 900, status: 'completed' },
      { id: 'PS-RA-RETURNED', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 400, status: 'returned' },
    );

    expect(regionalAgentOverview(state, 'RA1001')).toMatchObject({
      pendingCents: 2100,
      settledCents: 900,
    });
  });

  it('includes deterministic pending and completed agent examples after reset', () => {
    const state = createSeed();
    const records = state.profitSharingRecords.filter((item) => item.regionalAgentId === 'RA1001');

    expect(records.map((item) => item.status).sort()).toEqual(['completed', 'pending']);
    expect(records.every((item) => item.businessType === 'service-settlement')).toBe(true);
    expect(records.every((item) => state.memberConsumptions.some((entry) => entry.id === item.businessId))).toBe(true);
    expect(state.memberConsumptions.reduce((sum, item) => sum + Number(item.subsidyEligiblePrincipalCents || 0), 0)).toBe(510000);
  });
});
