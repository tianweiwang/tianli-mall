import { createSeed, DEMO_VERSION } from '../../src/mock/seed';

describe('assessment demo seed', () => {
  it('contains repeatable customers, devices, tasks, records and scenarios', () => {
    const state = createSeed();

    expect(DEMO_VERSION).toBe(11);
    expect(state.activeCustomerId).toBe('CUS1001');
    expect(state.customers.map((item) => item.id)).toEqual(['CUS1001', 'TMP1001', 'CUS1002', 'CUS1004']);
    expect(state.devices).toHaveLength(3);
    expect(state.assessmentTasks.some((item) => ['waiting-device', 'claimed'].includes(item.status))).toBe(false);
    expect(state.assessmentTasks.find((item) => item.id === 'AST202607301003')).toMatchObject({
      status: 'ready',
      deviceId: 'DEV-TL-001',
    });
    expect(state.assessmentRecords.filter((item) => item.customerId === 'CUS1001')).toHaveLength(2);
    expect(state.assessmentRecords).toHaveLength(3);
    expect(new Set(state.assessmentRecords.map((item) => item.storeId))).toEqual(new Set(['TL1001', 'TL1002']));
    expect(state.assessmentPayloadFixtures.success.metrics).toMatchObject({
      shoulderHeightDiffMm: 6,
      pelvicTiltDeg: 2.4,
      neckForwardAngleDeg: 13,
      discomfortScore: 3,
    });
    expect(state.sequence).toMatchObject({
      customer: 1002,
      consent: 1003,
      assessmentTask: 1004,
      assessmentRecord: 1003,
      reassessmentPlan: 1001,
    });
  });
});
