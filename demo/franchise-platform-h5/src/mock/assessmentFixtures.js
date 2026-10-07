export function createAssessmentFixtures() {
  const imageSet = {
    front: './assessment/posture-front.png',
    side: './assessment/posture-side.png',
    back: './assessment/posture-back.png',
  };

  return {
    activeCustomerId: 'CUS1001',
    customers: [
      { id: 'CUS1001', type: 'member', name: '陈女士', phone: '138****2046', joinedAt: '2026-05-30T09:00:00+08:00' },
      { id: 'TMP1001', type: 'temporary', name: '唐女士', phone: '139****8137', joinedAt: '2026-07-30T08:50:00+08:00' },
      { id: 'CUS1002', type: 'member', name: '林女士', phone: '136****5728', joinedAt: '2026-07-15T13:30:00+08:00' },
    ],
    consentRecords: [
      { id: 'CON1001', customerId: 'CUS1001', status: 'active', scopes: ['assessment', 'image', 'operations'], signedAt: '2026-05-30T09:05:00+08:00' },
      { id: 'CON1002', customerId: 'TMP1001', status: 'active', scopes: ['assessment', 'image'], signedAt: '2026-07-30T08:55:00+08:00' },
      { id: 'CON1003', customerId: 'CUS1002', status: 'active', scopes: ['assessment', 'image', 'operations'], signedAt: '2026-07-15T13:35:00+08:00' },
    ],
    devices: [
      { id: 'DEV-TL-001', name: '体态检测仪 A01', model: 'TL-PA100', storeId: 'TL1001', status: 'online', softwareVersion: '2.3.1', calibratedAt: '2026-07-20', todayCount: 6 },
      { id: 'DEV-TL-002', name: '体态检测仪 B01', model: 'TL-PA100', storeId: 'TL1002', status: 'offline', softwareVersion: '2.3.1', calibratedAt: '2026-07-18', todayCount: 3 },
      { id: 'DEV-TL-003', name: '体态检测仪备用机', model: 'TL-PA100', storeId: null, status: 'maintenance', softwareVersion: '2.2.8', calibratedAt: '2026-06-10', todayCount: 0 },
    ],
    assessmentTasks: [
      { id: 'AST202607301001', customerId: 'CUS1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1001', deviceId: 'DEV-TL-001', status: 'completed', createdAt: '2026-05-30T09:10:00+08:00', completedAt: '2026-05-30T09:35:00+08:00', attempts: [] },
      { id: 'AST202607301002', customerId: 'CUS1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1001', deviceId: 'DEV-TL-001', status: 'completed', createdAt: '2026-06-30T10:00:00+08:00', completedAt: '2026-06-30T10:25:00+08:00', attempts: [] },
      { id: 'AST202607301003', customerId: 'TMP1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1002', deviceId: 'DEV-TL-001', status: 'ready', createdAt: '2026-07-30T09:30:00+08:00', attempts: [{ deviceId: 'DEV-TL-001', status: 'ready', boundAt: '2026-07-30T09:30:00+08:00' }] },
      { id: 'AST202607301004', customerId: 'CUS1002', bookingId: null, storeId: 'TL1002', consentId: 'CON1003', deviceId: 'DEV-TL-002', status: 'completed', createdAt: '2026-07-15T13:40:00+08:00', completedAt: '2026-07-15T14:05:00+08:00', attempts: [] },
    ],
    assessmentRecords: [
      { id: 'ASR202607301001', taskId: 'AST202607301001', customerId: 'CUS1001', storeId: 'TL1001', deviceId: 'DEV-TL-001', measuredAt: '2026-05-30T09:30:00+08:00', version: 1, metrics: { shoulderHeightDiffMm: 12, pelvicTiltDeg: 3.8, neckForwardAngleDeg: 18, discomfortScore: 6 }, images: imageSet, reportStatus: 'published', recommendation: '建议保持规律使用并按计划复评。' },
      { id: 'ASR202607301002', taskId: 'AST202607301002', customerId: 'CUS1001', storeId: 'TL1001', deviceId: 'DEV-TL-001', measuredAt: '2026-06-30T10:20:00+08:00', version: 1, metrics: { shoulderHeightDiffMm: 8, pelvicTiltDeg: 2.9, neckForwardAngleDeg: 15, discomfortScore: 4 }, images: imageSet, reportStatus: 'published', recommendation: '本期记录较首次更稳定，建议继续执行当前计划并按期复评。' },
      { id: 'ASR202607301003', taskId: 'AST202607301004', customerId: 'CUS1002', storeId: 'TL1002', deviceId: 'DEV-TL-002', measuredAt: '2026-07-15T14:00:00+08:00', softwareVersion: '2.3.1', version: 1, metrics: { shoulderHeightDiffMm: 10, pelvicTiltDeg: 3.2, neckForwardAngleDeg: 16, discomfortScore: 5 }, images: imageSet, reportStatus: 'published', recommendation: '建议保持规律记录，并按计划完成下一次体态复评。' },
    ],
    reassessmentPlans: [
      { id: 'RAP202607301001', customerId: 'CUS1001', storeId: 'TL1001', sourceAssessmentId: 'ASR202607301002', dueDate: '2026-07-30', status: 'due' },
    ],
    assessmentPayloadFixtures: {
      success: { taskId: null, resultId: 'DEVRES-20260730-001', deviceId: 'DEV-TL-001', storeId: 'TL1001', operatorName: '李技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6, pelvicTiltDeg: 2.4, neckForwardAngleDeg: 13, discomfortScore: 3 }, images: imageSet },
      incomplete: { taskId: null, resultId: 'DEVRES-20260730-002', deviceId: 'DEV-TL-001', storeId: 'TL1001', operatorName: '李技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6 }, images: { front: imageSet.front } },
      mismatch: { taskId: null, resultId: 'DEVRES-20260730-003', deviceId: 'DEV-TL-002', storeId: 'TL1002', operatorName: '陈技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6, pelvicTiltDeg: 2.4, neckForwardAngleDeg: 13, discomfortScore: 3 }, images: imageSet },
    },
    rawAssessmentSubmissions: [],
  };
}
