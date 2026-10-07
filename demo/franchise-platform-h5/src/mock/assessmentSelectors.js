export const assessmentMetricDefinitions = {
  shoulderHeightDiffMm: { label: '双肩高度差', unit: 'mm', lowerIsBetter: true },
  pelvicTiltDeg: { label: '骨盆倾斜角', unit: '°', lowerIsBetter: true },
  neckForwardAngleDeg: { label: '颈部前倾角', unit: '°', lowerIsBetter: true },
  discomfortScore: { label: '自评不适程度', unit: '/10', lowerIsBetter: true },
};

function maskPhone(phone = '') {
  if (phone.includes('*')) return phone;
  return phone.replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2');
}

function round(value) {
  return Math.round(value * 10) / 10;
}

function statusForDelta(delta, lowerIsBetter) {
  if (delta === 0) return 'stable';
  const improved = lowerIsBetter ? delta < 0 : delta > 0;
  return improved ? 'improvement' : 'fluctuation';
}

function customerFor(state, customerId) {
  return state.customers.find((item) => item.id === customerId);
}

export function assessmentTasksForStore(state, storeId) {
  return state.assessmentTasks
    .filter((item) => item.storeId === storeId)
    .map((task) => {
      const customer = customerFor(state, task.customerId);
      const booking = state.bookings.find((item) => item.id === task.bookingId);
      return {
        ...task,
        customerName: customer?.name || '未知顾客',
        customerPhone: maskPhone(customer?.phone),
        customerType: customer?.type || 'unknown',
        booking,
      };
    })
    .sort((left, right) => right.createdAt.localeCompare(left.createdAt));
}

export function reassessmentQueueForStore(state, storeId) {
  return state.reassessmentPlans
    .filter((item) => item.storeId === storeId && item.status !== 'completed')
    .map((plan) => {
      const customer = customerFor(state, plan.customerId);
      return {
        ...plan,
        customerName: customer?.name || '未知顾客',
        customerPhone: maskPhone(customer?.phone),
        sourceRecord: state.assessmentRecords.find(
          (item) => item.id === plan.sourceAssessmentId,
        ),
      };
    })
    .sort((left, right) => left.dueDate.localeCompare(right.dueDate));
}

export function assessmentHistoryForCustomer(state, customerId) {
  return state.assessmentRecords
    .filter((item) => item.customerId === customerId && item.reportStatus === 'published')
    .map((record) => ({
      ...record,
      storeName: state.stores.find((item) => item.id === record.storeId)?.name || '',
      deviceModel: state.devices.find((item) => item.id === record.deviceId)?.model || '',
    }))
    .sort((left, right) => right.measuredAt.localeCompare(left.measuredAt));
}

export function buildAssessmentComparison(state, assessmentId) {
  const current = state.assessmentRecords.find((item) => item.id === assessmentId);
  if (!current) return null;
  const history = state.assessmentRecords
    .filter((item) => item.customerId === current.customerId && item.reportStatus === 'published')
    .sort((left, right) => left.measuredAt.localeCompare(right.measuredAt));
  const currentIndex = history.findIndex((item) => item.id === assessmentId);
  const previous = history[Math.max(0, currentIndex - 1)] || current;
  const first = history[0] || current;
  const metrics = Object.fromEntries(
    Object.entries(assessmentMetricDefinitions).map(([key, definition]) => {
      const currentValue = Number(current.metrics[key]);
      const previousDelta = round(currentValue - Number(previous.metrics[key]));
      const firstDelta = round(currentValue - Number(first.metrics[key]));
      return [key, {
        key,
        ...definition,
        current: currentValue,
        previousDelta,
        firstDelta,
        status: statusForDelta(previousDelta, definition.lowerIsBetter),
      }];
    }),
  );

  return {
    record: current,
    previousRecord: previous,
    firstRecord: first,
    metrics,
    metricList: Object.values(metrics),
  };
}

export function assessmentOperationsForBrand(state) {
  const completedTasks = state.assessmentTasks.filter((item) => item.status === 'completed');
  const pairedCustomers = new Set(state.assessmentRecords.map((item) => item.customerId));
  const stores = state.stores.map((store) => {
    const tasks = state.assessmentTasks.filter((item) => item.storeId === store.id);
    const completed = tasks.filter((item) => item.status === 'completed');
    const storeCustomers = new Set(completed.map((item) => item.customerId));
    const dueReassessments = state.reassessmentPlans.filter(
      (item) => item.storeId === store.id && ['due', 'scheduled'].includes(item.status),
    );
    const taskIds = new Set(tasks.map((item) => item.id));
    const hasDataIssue = state.rawAssessmentSubmissions.some(
      (item) => taskIds.has(item.taskId) && item.status !== 'accepted',
    );
    return {
      storeId: store.id,
      storeName: store.name,
      taskCount: tasks.length,
      completedTaskCount: completed.length,
      completionRate: tasks.length ? Math.round((completed.length / tasks.length) * 100) : 0,
      pairedCustomerCount: storeCustomers.size,
      dueReassessmentCount: dueReassessments.length,
      dataQualityFlag: hasDataIssue ? 'attention' : 'normal',
    };
  });
  const devices = state.devices.map((device) => ({
    ...device,
    storeName: state.stores.find((item) => item.id === device.storeId)?.name || '未分配',
    taskCount: state.assessmentTasks.filter((item) => item.deviceId === device.id).length,
  }));
  const taskRows = state.assessmentTasks.map((task) => {
    const customer = customerFor(state, task.customerId);
    return {
      taskId: task.id,
      storeId: task.storeId,
      customerName: customer?.name || '未知顾客',
      customerPhone: maskPhone(customer?.phone),
      status: task.status,
    };
  });
  const publishedRecords = state.assessmentRecords
    .filter((record) => record.reportStatus === 'published');
  const recordRows = publishedRecords
    .map((record) => {
      const customer = customerFor(state, record.customerId);
      const store = state.stores.find((item) => item.id === record.storeId);
      const device = state.devices.find((item) => item.id === record.deviceId);
      const comparison = buildAssessmentComparison(state, record.id);
      return {
        reportId: record.id,
        reportStatus: record.reportStatus,
        customerName: customer?.name || '未知顾客',
        customerPhone: maskPhone(customer?.phone),
        storeId: record.storeId,
        storeName: store?.name || record.storeId,
        deviceId: record.deviceId,
        deviceName: device?.name || record.deviceId,
        deviceModel: device?.model || '-',
        softwareVersion: record.softwareVersion || device?.softwareVersion || '-',
        measuredAt: record.measuredAt,
        reportVersion: record.version,
        recommendation: record.recommendation,
        metrics: { ...record.metrics },
        metricList: comparison.metricList,
        hasPreviousComparison: comparison.previousRecord.id !== record.id,
        hasFirstComparison: comparison.firstRecord.id !== record.id,
      };
    })
    .sort((left, right) => right.measuredAt.localeCompare(left.measuredAt));
  const trendByDate = publishedRecords.reduce((result, record) => {
    const date = record.measuredAt.slice(0, 10);
    result[date] = (result[date] || 0) + 1;
    return result;
  }, {});
  const completionTrend = Object.entries(trendByDate)
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([date, count]) => ({ date, label: date.slice(5), count }));
  const metricAverages = Object.entries(assessmentMetricDefinitions).map(([key, definition]) => {
    const values = publishedRecords
      .map((record) => Number(record.metrics[key]))
      .filter(Number.isFinite);
    return {
      key,
      ...definition,
      sampleCount: values.length,
      average: values.length ? round(values.reduce((sum, value) => sum + value, 0) / values.length) : 0,
    };
  });

  return {
    deviceCount: state.devices.length,
    onlineDeviceCount: state.devices.filter((item) => item.status === 'online').length,
    completedTaskCount: completedTasks.length,
    pairedCustomerCount: pairedCustomers.size,
    stores,
    devices,
    taskRows,
    recordRows,
    completionTrend,
    metricAverages,
  };
}
