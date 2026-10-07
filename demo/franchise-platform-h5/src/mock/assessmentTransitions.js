const REQUIRED_METRICS = [
  'shoulderHeightDiffMm',
  'pelvicTiltDeg',
  'neckForwardAngleDeg',
  'discomfortScore',
];
const REQUIRED_IMAGES = ['front', 'side', 'back'];

function findRequired(list, predicate, message) {
  const value = list.find(predicate);
  if (!value) throw new Error(message);
  return value;
}

function nextId(state, field, prefix) {
  state.sequence[field] += 1;
  return `${prefix}${state.sequence[field]}`;
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function appendActivity(state, type, task, extra = {}) {
  state.activityLog.unshift({
    type,
    entityId: task.id,
    storeId: task.storeId,
    at: state.demoNow,
    ...extra,
  });
}

function taskExpiry(demoNow) {
  const expiry = new Date(demoNow);
  expiry.setDate(expiry.getDate() + 1);
  return expiry.toISOString();
}

function onlineDeviceForStore(state, storeId) {
  return findRequired(
    state.devices,
    (item) => item.storeId === storeId && item.status === 'online',
    '本店检测设备当前不可用',
  );
}

function readyAttempt(deviceId, boundAt) {
  return { deviceId, status: 'ready', boundAt };
}

export function createAssessmentTask(
  state,
  { bookingId = null, customerId, storeId },
) {
  const customer = findRequired(
    state.customers,
    (item) => item.id === customerId,
    '顾客不存在',
  );
  findRequired(
    state.stores,
    (item) => item.id === storeId && item.status === 'active',
    '门店不可用',
  );
  const consent = findRequired(
    state.consentRecords,
    (item) => item.customerId === customer.id
      && item.status === 'active'
      && item.scopes.includes('assessment'),
    '缺少有效检测授权',
  );

  if (bookingId) {
    const booking = findRequired(
      state.bookings,
      (item) => item.id === bookingId,
      '预约不存在',
    );
    if (booking.storeId !== storeId || booking.customerId !== customerId) {
      throw new Error('预约与检测顾客或门店不一致');
    }
    if (state.assessmentTasks.some((item) => item.bookingId === bookingId)) {
      throw new Error('预约已创建检测任务');
    }
  }

  const device = onlineDeviceForStore(state, storeId);

  const task = {
    id: nextId(state, 'assessmentTask', 'AST20260730'),
    customerId,
    bookingId,
    storeId,
    consentId: consent.id,
    deviceId: device.id,
    status: 'ready',
    createdAt: state.demoNow,
    expiresAt: taskExpiry(state.demoNow),
    attempts: [readyAttempt(device.id, state.demoNow)],
  };
  state.assessmentTasks.unshift(task);
  appendActivity(state, 'assessment-task-created', task, { deviceId: device.id });
  return task;
}

export function createWalkInAssessmentTask(state, {
  storeId,
  name,
  phone,
  scopes,
}) {
  if (!name?.trim()) throw new Error('请输入顾客姓名');
  if (!scopes?.includes('assessment')) throw new Error('需要检测授权');
  findRequired(
    state.stores,
    (item) => item.id === storeId && item.status === 'active',
    '门店不可用',
  );
  const device = onlineDeviceForStore(state, storeId);

  const customer = {
    id: nextId(state, 'customer', 'TMP20260730'),
    type: 'temporary',
    name: name.trim(),
    phone,
    joinedAt: state.demoNow,
  };
  const consent = {
    id: nextId(state, 'consent', 'CON20260730'),
    customerId: customer.id,
    status: 'active',
    scopes: [...scopes],
    signedAt: state.demoNow,
  };
  const task = {
    id: nextId(state, 'assessmentTask', 'AST20260730'),
    customerId: customer.id,
    bookingId: null,
    storeId,
    consentId: consent.id,
    deviceId: device.id,
    status: 'ready',
    createdAt: state.demoNow,
    expiresAt: taskExpiry(state.demoNow),
    attempts: [readyAttempt(device.id, state.demoNow)],
  };

  state.customers.unshift(customer);
  state.consentRecords.unshift(consent);
  state.assessmentTasks.unshift(task);
  appendActivity(state, 'assessment-walk-in-created', task, {
    customerId: customer.id,
    deviceId: device.id,
  });
  return task;
}

export function startAssessment(state, taskId) {
  const task = findRequired(state.assessmentTasks, (item) => item.id === taskId, '检测任务不存在');
  if (task.status !== 'ready') throw new Error('检测任务当前不可开始');
  task.status = 'measuring';
  task.attempts.at(-1).status = 'measuring';
  task.attempts.at(-1).startedAt = state.demoNow;
  appendActivity(state, 'assessment-started', task);
  return task;
}

export function markAssessmentOffline(state, taskId) {
  const task = findRequired(state.assessmentTasks, (item) => item.id === taskId, '检测任务不存在');
  if (task.status !== 'measuring') throw new Error('检测任务当前不可标记离线');
  task.status = 'retrying';
  task.attempts.at(-1).status = 'retrying';
  appendActivity(state, 'assessment-offline', task);
  return task;
}

export function retryAssessmentTask(state, taskId) {
  const task = findRequired(state.assessmentTasks, (item) => item.id === taskId, '检测任务不存在');
  if (task.status !== 'retrying') throw new Error('检测任务当前不可重试');
  task.status = 'measuring';
  task.attempts.at(-1).status = 'measuring';
  appendActivity(state, 'assessment-retried', task);
  return task;
}

export function receiveAssessmentResult(state, { taskId, payload }) {
  const task = findRequired(state.assessmentTasks, (item) => item.id === taskId, '检测任务不存在');
  const duplicate = state.rawAssessmentSubmissions.some(
    (item) => item.resultId === payload.resultId,
  );
  if (duplicate) {
    return { ok: false, code: 'duplicate', message: '已接收过该设备结果' };
  }
  if (!['measuring', 'review-required'].includes(task.status)) {
    throw new Error('检测任务当前不可接收结果');
  }

  const submission = {
    resultId: payload.resultId,
    taskId,
    receivedAt: state.demoNow,
    status: 'received',
    payload: clone(payload),
  };
  state.rawAssessmentSubmissions.push(submission);
  const device = state.devices.find((item) => item.id === payload.deviceId);
  const mismatched = payload.taskId !== taskId
    || payload.storeId !== task.storeId
    || !device
    || device.storeId !== task.storeId
    || payload.deviceId !== task.deviceId;
  if (mismatched) {
    submission.status = 'quarantined';
    task.lastResultCode = 'quarantined';
    appendActivity(state, 'assessment-result-quarantined', task, { resultId: payload.resultId });
    return { ok: false, code: 'quarantined', message: '设备结果与任务门店不匹配' };
  }

  const missingMetrics = REQUIRED_METRICS.filter(
    (key) => payload.metrics?.[key] === undefined,
  );
  const missingImages = REQUIRED_IMAGES.filter((key) => !payload.images?.[key]);
  if (missingMetrics.length || missingImages.length) {
    submission.status = 'review-required';
    task.status = 'review-required';
    task.lastResultCode = 'review-required';
    task.missingMetrics = missingMetrics;
    task.missingImages = missingImages;
    appendActivity(state, 'assessment-result-incomplete', task, { resultId: payload.resultId });
    return {
      ok: false,
      code: 'review-required',
      message: '检测数据不完整',
      missingMetrics,
      missingImages,
    };
  }

  submission.status = 'accepted';
  task.status = 'awaiting-review';
  task.lastResultCode = 'awaiting-review';
  task.missingMetrics = [];
  task.missingImages = [];
  task.attempts.at(-1).status = 'awaiting-review';
  task.attempts.at(-1).acceptedPayload = clone(payload);
  appendActivity(state, 'assessment-result-received', task, { resultId: payload.resultId });
  return { ok: true, code: 'awaiting-review', message: '待门店确认' };
}

export function confirmAssessmentResult(state, taskId) {
  const task = findRequired(state.assessmentTasks, (item) => item.id === taskId, '检测任务不存在');
  if (task.status !== 'awaiting-review') throw new Error('检测结果尚不可确认');
  const payload = task.attempts.at(-1)?.acceptedPayload;
  if (!payload) throw new Error('检测结果尚不可确认');

  const report = {
    id: nextId(state, 'assessmentRecord', 'ASR20260730'),
    taskId: task.id,
    customerId: task.customerId,
    storeId: task.storeId,
    deviceId: payload.deviceId,
    measuredAt: payload.measuredAt,
    operatorName: payload.operatorName,
    softwareVersion: payload.softwareVersion,
    version: 1,
    metrics: clone(payload.metrics),
    images: clone(payload.images),
    reportStatus: 'published',
    recommendation: '建议按日常健康管理计划保持规律记录，并按期复评。',
  };
  const dueDate = new Date(state.demoNow);
  dueDate.setDate(dueDate.getDate() + 28);
  const reassessmentPlan = {
    id: nextId(state, 'reassessmentPlan', 'RAP20260730'),
    customerId: task.customerId,
    storeId: task.storeId,
    sourceAssessmentId: report.id,
    dueDate: dueDate.toISOString().slice(0, 10),
    status: 'scheduled',
  };

  state.assessmentRecords.unshift(report);
  state.reassessmentPlans.unshift(reassessmentPlan);
  task.status = 'completed';
  task.completedAt = state.demoNow;
  task.reportId = report.id;
  appendActivity(state, 'assessment-report-published', task, { reportId: report.id });
  return report;
}
