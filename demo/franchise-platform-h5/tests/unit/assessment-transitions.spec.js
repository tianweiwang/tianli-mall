import { createSeed } from '../../src/mock/seed';
import {
  confirmAssessmentResult,
  createAssessmentTask,
  createWalkInAssessmentTask,
  markAssessmentOffline,
  receiveAssessmentResult,
  retryAssessmentTask,
  startAssessment,
} from '../../src/mock/assessmentTransitions';

describe('assessment transitions', () => {
  it('automatically binds the store online device and creates a ready task', () => {
    const state = createSeed();
    const task = createAssessmentTask(state, {
      bookingId: 'BK202607302001',
      customerId: 'CUS1001',
      storeId: 'TL1001',
    });

    expect(task).toMatchObject({
      storeId: 'TL1001',
      deviceId: 'DEV-TL-001',
      status: 'ready',
    });
    expect(task.attempts).toEqual([{
      deviceId: 'DEV-TL-001',
      status: 'ready',
      boundAt: state.demoNow,
    }]);
  });

  it('completes one result and creates one versioned report and reassessment plan', () => {
    const state = createSeed();
    const task = createAssessmentTask(state, {
      bookingId: 'BK202607302001',
      customerId: 'CUS1001',
      storeId: 'TL1001',
    });
    startAssessment(state, task.id);

    expect(receiveAssessmentResult(state, {
      taskId: task.id,
      payload: { ...state.assessmentPayloadFixtures.success, taskId: task.id },
    })).toMatchObject({ ok: true, code: 'awaiting-review' });
    const report = confirmAssessmentResult(state, task.id);

    expect(report).toMatchObject({
      customerId: 'CUS1001',
      version: 1,
      reportStatus: 'published',
    });
    expect(state.assessmentRecords.filter((item) => item.taskId === task.id)).toHaveLength(1);
    expect(state.reassessmentPlans.find((item) => item.sourceAssessmentId === report.id)).toMatchObject({
      customerId: 'CUS1001',
      status: 'scheduled',
      dueDate: '2026-08-27',
    });
  });

  it('keeps incomplete and offline outcomes non-destructive', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    const task = state.assessmentTasks.find((item) => item.id === taskId);
    expect(task).toMatchObject({ deviceId: 'DEV-TL-001', status: 'ready' });
    startAssessment(state, taskId);
    markAssessmentOffline(state, taskId);
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('retrying');
    retryAssessmentTask(state, taskId);
    expect(task).toMatchObject({ deviceId: 'DEV-TL-001', status: 'measuring' });

    expect(receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.incomplete, taskId },
    }).code).toBe('review-required');
    expect(state.assessmentRecords).toHaveLength(3);
    expect(() => confirmAssessmentResult(state, taskId)).toThrow('检测结果尚不可确认');
  });

  it('quarantines a device or store mismatch without creating a report', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);

    const result = receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.mismatch, taskId },
    });

    expect(result).toMatchObject({ ok: false, code: 'quarantined' });
    expect(state.rawAssessmentSubmissions.at(-1).status).toBe('quarantined');
    expect(state.assessmentRecords).toHaveLength(3);
  });

  it('rejects a repeated result id before appending another raw submission', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);
    const payload = { ...state.assessmentPayloadFixtures.success, taskId };

    expect(receiveAssessmentResult(state, { taskId, payload }).code).toBe('awaiting-review');
    const submissionCount = state.rawAssessmentSubmissions.length;
    expect(receiveAssessmentResult(state, { taskId, payload })).toMatchObject({ ok: false, code: 'duplicate' });
    expect(state.rawAssessmentSubmissions).toHaveLength(submissionCount);
  });

  it('rejects a new result before the task starts', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    const before = state.rawAssessmentSubmissions.length;

    expect(() => receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-READY' },
    })).toThrow('检测任务当前不可接收结果');
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('ready');
    expect(state.rawAssessmentSubmissions).toHaveLength(before);
  });

  it('rejects a new result while the task waits for an offline retry', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);
    markAssessmentOffline(state, taskId);
    const before = state.rawAssessmentSubmissions.length;

    expect(() => receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-RETRYING' },
    })).toThrow('检测任务当前不可接收结果');
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('retrying');
    expect(state.rawAssessmentSubmissions).toHaveLength(before);
  });

  it('rejects another new result while the first result awaits review', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);
    receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-ACCEPTED' },
    });
    const before = state.rawAssessmentSubmissions.length;

    expect(() => receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-SECOND' },
    })).toThrow('检测任务当前不可接收结果');
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('awaiting-review');
    expect(state.rawAssessmentSubmissions).toHaveLength(before);
  });

  it('rejects a new result after the task is completed', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);
    receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-COMPLETE' },
    });
    confirmAssessmentResult(state, taskId);
    const before = {
      records: state.assessmentRecords.length,
      submissions: state.rawAssessmentSubmissions.length,
    };

    expect(() => receiveAssessmentResult(state, {
      taskId,
      payload: { ...state.assessmentPayloadFixtures.success, taskId, resultId: 'DEVRES-STATE-AFTER-COMPLETE' },
    })).toThrow('检测任务当前不可接收结果');
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('completed');
    expect(state.assessmentRecords).toHaveLength(before.records);
    expect(state.rawAssessmentSubmissions).toHaveLength(before.submissions);
  });

  it('quarantines a same-store result from a device other than the bound device', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    state.devices.push({
      ...state.devices.find((item) => item.id === 'DEV-TL-001'),
      id: 'DEV-TL-004',
      name: '体态检测仪 A02',
    });
    startAssessment(state, taskId);

    const outcome = receiveAssessmentResult(state, {
      taskId,
      payload: {
        ...state.assessmentPayloadFixtures.success,
        taskId,
        resultId: 'DEVRES-WRONG-BOUND-DEVICE',
        deviceId: 'DEV-TL-004',
      },
    });

    expect(outcome).toMatchObject({ ok: false, code: 'quarantined' });
    expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('measuring');
    expect(state.assessmentRecords).toHaveLength(3);
  });

  it('stores a detached raw payload clone', () => {
    const state = createSeed();
    const taskId = 'AST202607301003';
    startAssessment(state, taskId);
    const payload = structuredClone({ ...state.assessmentPayloadFixtures.success, taskId });

    receiveAssessmentResult(state, { taskId, payload });
    payload.metrics.shoulderHeightDiffMm = 99;

    expect(state.rawAssessmentSubmissions[0].payload.metrics.shoulderHeightDiffMm).toBe(6);
  });

  it('rejects unavailable store devices and duplicate booking tasks without appending tasks', () => {
    const state = createSeed();
    const before = state.assessmentTasks.length;

    expect(() => createAssessmentTask(state, {
      customerId: 'TMP1001',
      storeId: 'TL1002',
    })).toThrow('本店检测设备当前不可用');
    expect(state.assessmentTasks).toHaveLength(before);

    createAssessmentTask(state, {
      bookingId: 'BK202607302001',
      customerId: 'CUS1001',
      storeId: 'TL1001',
    });
    expect(() => createAssessmentTask(state, {
      bookingId: 'BK202607302001',
      customerId: 'CUS1001',
      storeId: 'TL1001',
    })).toThrow('预约已创建检测任务');
  });

  it('creates exactly one temporary customer, consent and task for a walk-in', () => {
    const state = createSeed();
    const before = {
      customers: state.customers.length,
      consents: state.consentRecords.length,
      tasks: state.assessmentTasks.length,
    };

    const task = createWalkInAssessmentTask(state, {
      storeId: 'TL1001',
      name: '许女士',
      phone: '137****4826',
      scopes: ['assessment', 'image'],
    });

    const customer = state.customers.find((item) => item.id === task.customerId);
    expect(customer).toMatchObject({ type: 'temporary', name: '许女士' });
    expect(task).toMatchObject({ deviceId: 'DEV-TL-001', status: 'ready' });
    expect(state.customers).toHaveLength(before.customers + 1);
    expect(state.consentRecords).toHaveLength(before.consents + 1);
    expect(state.assessmentTasks).toHaveLength(before.tasks + 1);
    expect(() => createWalkInAssessmentTask(state, {
      storeId: 'TL1001',
      name: '',
      phone: '137****4826',
      scopes: ['assessment'],
    })).toThrow('请输入顾客姓名');
    expect(() => createWalkInAssessmentTask(state, {
      storeId: 'TL1001',
      name: '许女士',
      phone: '137****4826',
      scopes: ['image'],
    })).toThrow('需要检测授权');
  });

  it('keeps walk-in state unchanged when the store device is unavailable', () => {
    const state = createSeed();
    const before = {
      customers: state.customers.length,
      consents: state.consentRecords.length,
      tasks: state.assessmentTasks.length,
    };

    expect(() => createWalkInAssessmentTask(state, {
      storeId: 'TL1002',
      name: '周女士',
      phone: '135****6249',
      scopes: ['assessment', 'image'],
    })).toThrow('本店检测设备当前不可用');
    expect(state.customers).toHaveLength(before.customers);
    expect(state.consentRecords).toHaveLength(before.consents);
    expect(state.assessmentTasks).toHaveLength(before.tasks);
  });
});
