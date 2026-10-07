import { createSeed } from '../../src/mock/seed';
import {
  assessmentHistoryForCustomer,
  assessmentOperationsForBrand,
  assessmentTasksForStore,
  buildAssessmentComparison,
  reassessmentQueueForStore,
} from '../../src/mock/assessmentSelectors';

describe('assessment selectors', () => {
  it('keeps store and customer records inside their ownership scope', () => {
    const state = createSeed();

    expect(assessmentTasksForStore(state, 'TL1001').every((item) => item.storeId === 'TL1001')).toBe(true);
    expect(reassessmentQueueForStore(state, 'TL1001')[0].customerName).toBe('陈女士');
    expect(assessmentHistoryForCustomer(state, 'CUS1001')).toHaveLength(2);
    expect(assessmentHistoryForCustomer(state, 'CUS1001').map((item) => item.id)).toEqual([
      'ASR202607301002',
      'ASR202607301001',
    ]);
  });

  it('builds neutral metric comparisons against previous and first records', () => {
    const comparison = buildAssessmentComparison(createSeed(), 'ASR202607301002');

    expect(comparison.metrics.shoulderHeightDiffMm).toMatchObject({
      current: 8,
      previousDelta: -4,
      firstDelta: -4,
      unit: 'mm',
      status: 'improvement',
    });
    expect(comparison.metrics.discomfortScore.label).toBe('自评不适程度');
  });

  it('returns masked brand aggregates grouped by store and device', () => {
    const operations = assessmentOperationsForBrand(createSeed());

    expect(operations).toMatchObject({
      deviceCount: 3,
      onlineDeviceCount: 1,
      completedTaskCount: 3,
      pairedCustomerCount: 2,
    });
    expect(operations.stores.find((item) => item.storeId === 'TL1001')).toMatchObject({
      taskCount: 3,
      completedTaskCount: 2,
      pairedCustomerCount: 1,
    });
    expect(operations.devices).toHaveLength(3);
    expect(operations.taskRows.every((item) => !/^1\d{10}$/.test(item.customerPhone))).toBe(true);
    expect(operations.recordRows).toHaveLength(3);
    expect(operations.recordRows.every((item) => item.reportStatus === 'published')).toBe(true);
    expect(operations.recordRows.every((item) => !/1\d{10}/.test(item.customerPhone))).toBe(true);
    expect(operations.recordRows[0]).toMatchObject({
      reportId: expect.stringMatching(/^ASR/),
      storeName: expect.any(String),
      deviceName: expect.any(String),
      deviceModel: 'TL-PA100',
      reportVersion: 1,
      metrics: {
        shoulderHeightDiffMm: expect.any(Number),
        pelvicTiltDeg: expect.any(Number),
        neckForwardAngleDeg: expect.any(Number),
        discomfortScore: expect.any(Number),
      },
    });
  });

  it('excludes unpublished reports and identifies available comparison history', () => {
    const state = createSeed();
    state.assessmentRecords.push({
      ...state.assessmentRecords[0],
      id: 'ASR-PENDING',
      reportStatus: 'pending',
    });

    const operations = assessmentOperationsForBrand(state);
    const rows = operations.recordRows;
    expect(rows.map((item) => item.reportId)).not.toContain('ASR-PENDING');
    expect(operations.completionTrend.reduce((sum, item) => sum + item.count, 0)).toBe(3);
    expect(operations.metricAverages.find((item) => item.key === 'shoulderHeightDiffMm'))
      .toMatchObject({ sampleCount: 3, average: 10 });

    const first = rows.find((item) => item.reportId === 'ASR202607301001');
    expect(first).toMatchObject({
      hasPreviousComparison: false,
      hasFirstComparison: false,
    });

    const second = rows.find((item) => item.reportId === 'ASR202607301002');
    expect(second).toMatchObject({
      hasPreviousComparison: true,
      hasFirstComparison: true,
    });
    expect(second.metricList.find((item) => item.key === 'shoulderHeightDiffMm'))
      .toMatchObject({ previousDelta: -4, firstDelta: -4 });
  });
});
