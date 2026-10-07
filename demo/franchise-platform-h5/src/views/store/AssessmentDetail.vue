<template>
  <div class="admin-page assessment-detail">
    <template v-if="task">
      <section class="admin-panel assessment-summary">
        <header class="admin-panel__header">
          <div><h2>{{ task.customerName }}的检测任务</h2><p>{{ task.id }} · {{ taskStatusLabel(task.status) }}</p></div>
          <router-link to="/store/assessments" class="admin-link-button"><ArrowLeft :size="15" />返回检测中心</router-link>
        </header>
        <dl class="assessment-summary__grid">
          <div><dt>顾客</dt><dd>{{ task.customerName }}<small>{{ task.customerPhone }} · {{ task.customerType === 'member' ? '会员' : '临时顾客' }}</small></dd></div>
          <div><dt>预约</dt><dd>{{ booking ? booking.id : '现场检测' }}<small>{{ booking ? `${booking.date} ${booking.start}` : '现场建档任务' }}</small></dd></div>
          <div><dt>授权</dt><dd>{{ consent.scopes.includes('assessment') ? '检测已授权' : '缺少检测授权' }}<small>{{ consent.scopes.includes('image') ? '含影像记录' : '不含影像记录' }}</small></dd></div>
          <div><dt>任务状态</dt><dd><AdminStatusTag :status="task.status" :text="taskStatusLabel(task.status)" /><small>创建于 {{ formatDateTime(task.createdAt) }}</small></dd></div>
        </dl>
      </section>

      <section class="admin-panel assessment-timeline-panel">
        <header class="admin-panel__header"><div><h2>状态时间线</h2><p>任务可见状态的本地演示记录</p></div></header>
        <ol class="assessment-timeline">
          <li><span /><div><strong>检测任务已创建并绑定本店设备</strong><small>{{ formatDateTime(task.createdAt) }}</small></div></li>
          <li v-for="event in taskTimeline" :key="`${event.type}-${event.at}`"><span /><div><strong>{{ activityLabel(event.type) }}</strong><small>{{ formatDateTime(event.at) }}</small></div></li>
        </ol>
      </section>

      <section class="admin-panel fixed-device-panel">
        <div class="fixed-device-panel__info">
          <span class="assessment-kicker">固定门店设备</span>
          <h2>{{ assignedDevice ? assignedDevice.name : '未绑定设备' }}</h2>
          <p>{{ assignedDevice ? `${assignedDevice.model} · ${assignedDevice.softwareVersion} · ${deviceStatusLabel(assignedDevice.status)}` : '本任务没有可用的固定设备' }}</p>
          <dl>
            <div><dt>任务号</dt><dd>{{ task.id }}</dd></div>
            <div><dt>归属门店</dt><dd>{{ store.name }}</dd></div>
            <div><dt>有效期</dt><dd>{{ formatDateTime(task.expiresAt) }}</dd></div>
          </dl>
        </div>
      </section>

      <section class="admin-panel simulation-panel">
        <header class="admin-panel__header"><div><h2>设备回传模拟</h2><p>选择固定场景后执行当前可用动作</p></div></header>
        <div class="scenario-switch" role="radiogroup" aria-label="设备回传场景">
          <button
            v-for="scenario in scenarios"
            :key="scenario.key"
            :class="{ 'is-active': selectedScenario === scenario.key }"
            type="button"
            @click="selectedScenario = scenario.key"
          >{{ scenario.label }}</button>
        </div>
        <div class="assessment-actions">
          <el-button v-if="task.status === 'ready'" type="primary" @click="startTask">开始检测</el-button>
          <el-button v-if="['measuring', 'review-required'].includes(task.status)" type="primary" @click="simulateResult">模拟回传</el-button>
          <el-button v-if="task.status === 'retrying'" type="primary" @click="retryTask">恢复并重试</el-button>
          <el-button v-if="task.status === 'awaiting-review'" type="success" @click="confirmResult">确认并生成报告</el-button>
          <span>当前状态：{{ taskStatusLabel(task.status) }}</span>
        </div>
      </section>

      <section v-if="previewMetrics.length" class="admin-panel result-preview-panel">
        <header class="admin-panel__header">
          <div><h2>已接收结果预览</h2><p>固定演示数据，门店确认前不生成消费者报告</p></div>
        </header>
        <div class="result-preview-panel__body">
          <AssessmentMetricGrid :metrics="previewMetrics" compact />
          <AssessmentImageStrip
            :images="acceptedPayload.images"
            :captured-at="formatDateTime(acceptedPayload.measuredAt)"
            :store-name="store.name"
          />
        </div>
      </section>

      <section v-if="resultState" :class="['assessment-result', `is-${resultState.code}`]">
        <div>
          <strong>{{ resultState.title }}</strong>
          <p>{{ resultState.message }}</p>
        </div>
        <span>{{ recoveryGuidance }}</span>
      </section>
      <div v-if="generatedReportId" class="assessment-generated" data-testid="generated-assessment-id">{{ generatedReportId }}</div>
    </template>

    <section v-else class="admin-panel assessment-not-found">
      <h2>未找到本店检测任务</h2>
      <p>任务不存在，或不属于当前操作门店。</p>
      <router-link to="/store/assessments" class="admin-link-button">返回检测中心</router-link>
    </section>
  </div>
</template>

<script>
import { ArrowLeft } from 'lucide-vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import AssessmentImageStrip from '../../components/assessment/AssessmentImageStrip.vue';
import AssessmentMetricGrid from '../../components/assessment/AssessmentMetricGrid.vue';
import { demoStore } from '../../mock/store';
import {
  confirmAssessmentResult,
  markAssessmentOffline,
  receiveAssessmentResult,
  retryAssessmentTask,
  startAssessment,
} from '../../mock/assessmentTransitions';
import { assessmentMetricDefinitions, assessmentTasksForStore, buildAssessmentComparison } from '../../mock/assessmentSelectors';

export default {
  name: 'StoreAssessmentDetail',
  components: { AdminStatusTag, ArrowLeft, AssessmentImageStrip, AssessmentMetricGrid },
  data() {
    return {
      selectedScenario: 'success',
      generatedReportId: '',
      resultState: null,
      scenarios: [
        { key: 'success', label: '正常回传' },
        { key: 'offline', label: '设备离线' },
        { key: 'duplicate', label: '重复回传' },
        { key: 'incomplete', label: '数据不完整' },
        { key: 'mismatch', label: '门店不匹配' },
      ],
    };
  },
  computed: {
    state() { return demoStore.state.data; },
    task() {
      return assessmentTasksForStore(this.state, this.state.activeStoreId)
        .find((item) => item.id === this.$route.params.taskId);
    },
    store() { return this.state.stores.find((item) => item.id === this.state.activeStoreId) || {}; },
    booking() { return this.state.bookings.find((item) => item.id === this.task.bookingId); },
    consent() { return this.state.consentRecords.find((item) => item.id === this.task.consentId) || { scopes: [] }; },
    assignedDevice() { return this.state.devices.find((item) => item.id === this.task.deviceId); },
    taskTimeline() {
      const creationEvents = new Set(['assessment-task-created', 'assessment-walk-in-created']);
      return [...this.state.activityLog]
        .filter((item) => item.entityId === this.task.id && !creationEvents.has(item.type))
        .reverse();
    },
    acceptedPayload() { return this.task.attempts.at(-1)?.acceptedPayload || null; },
    previewMetrics() {
      if (!this.acceptedPayload) return [];
      const comparison = this.generatedReportId
        ? buildAssessmentComparison(this.state, this.generatedReportId)
        : null;
      if (comparison) return comparison.metricList;
      return Object.entries(assessmentMetricDefinitions).map(([key, definition]) => ({
        key, ...definition, current: this.acceptedPayload.metrics[key], previousDelta: 0, firstDelta: 0, status: 'stable',
      }));
    },
    recoveryGuidance() {
      return {
        offline: '检查设备网络后点击“恢复并重试”。',
        duplicate: '结果已去重，可继续门店确认，不会生成重复报告。',
        'review-required': `缺少 ${this.missingItemLabels.join('、')}，补充后重新回传。`,
        quarantined: '核对设备归属与任务门店后，使用本店设备重新回传。',
        'awaiting-review': '核对指标和影像后生成消费者报告。',
        completed: '报告已发布，可在消费者端查看并发起复评。',
      }[this.resultState?.code] || '';
    },
    missingItemLabels() {
      const imageLabels = { front: '正面影像', side: '侧面影像', back: '背面影像' };
      return [
        ...(this.task.missingMetrics || []).map((key) => assessmentMetricDefinitions[key]?.label || key),
        ...(this.task.missingImages || []).map((key) => imageLabels[key] || key),
      ];
    },
  },
  methods: {
    startTask() {
      try { demoStore.commit((draft) => startAssessment(draft, this.task.id)); } catch (error) { this.showError(error); }
    },
    retryTask() {
      try {
        demoStore.commit((draft) => retryAssessmentTask(draft, this.task.id));
        this.resultState = null;
      } catch (error) { this.showError(error); }
    },
    simulateResult() {
      if (this.selectedScenario === 'offline') {
        try {
          demoStore.commit((draft) => markAssessmentOffline(draft, this.task.id));
          this.resultState = { code: 'offline', title: '设备连接中断', message: '任务已保留并进入等待重试状态。' };
        } catch (error) { this.showError(error); }
        return;
      }
      const fixtureKey = ['incomplete', 'mismatch'].includes(this.selectedScenario) ? this.selectedScenario : 'success';
      const payload = structuredClone(this.state.assessmentPayloadFixtures[fixtureKey]);
      payload.taskId = this.task.id;
      let outcome;
      try {
        demoStore.commit((draft) => {
          outcome = receiveAssessmentResult(draft, { taskId: this.task.id, payload });
          if (this.selectedScenario === 'duplicate') {
            outcome = receiveAssessmentResult(draft, { taskId: this.task.id, payload });
          }
        });
        this.resultState = {
          code: outcome.code,
          title: this.resultTitle(outcome.code),
          message: outcome.message,
        };
      } catch (error) { this.showError(error); }
    },
    confirmResult() {
      let report;
      try {
        demoStore.commit((draft) => { report = confirmAssessmentResult(draft, this.task.id); });
        this.generatedReportId = report.id;
        this.resultState = { code: 'completed', title: '报告已生成', message: '消费者报告与复评计划已同步到演示状态。' };
      } catch (error) { this.showError(error); }
    },
    showError(error) { this.resultState = { code: 'error', title: '操作未完成', message: error.message }; },
    resultTitle(code) {
      return { 'awaiting-review': '结果待门店确认', duplicate: '重复结果已忽略', 'review-required': '回传数据不完整', quarantined: '跨店结果已隔离' }[code] || '设备结果';
    },
    deviceStatusLabel(status) {
      return { online: '在线', offline: '离线', maintenance: '维护中' }[status] || status;
    },
    formatDateTime(value) { return value ? value.replace('T', ' ').replace('+08:00', '').replace('Z', '').slice(0, 16) : '-'; },
    activityLabel(type) {
      return {
        'assessment-task-created': '检测任务已创建', 'assessment-walk-in-created': '现场建档任务已创建',
        'assessment-started': '检测已开始', 'assessment-offline': '设备离线，等待重试',
        'assessment-retried': '设备已恢复', 'assessment-result-received': '完整结果已回传',
        'assessment-result-incomplete': '结果缺少必要数据', 'assessment-result-quarantined': '跨店结果已隔离',
        'assessment-report-published': '报告已确认发布',
      }[type] || type;
    },
    taskStatusLabel(status) {
      return { ready: '待检测', measuring: '检测中', retrying: '等待重试', 'review-required': '数据待补充', 'awaiting-review': '待门店确认', completed: '已完成' }[status] || status;
    },
  },
};
</script>

<style scoped>
.assessment-summary__grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 0; padding: 0 18px 18px; }
.assessment-summary__grid > div { min-width: 0; padding: 15px; background: #f7f8fa; border-right: 1px solid var(--border); }
.assessment-summary__grid > div:last-child { border-right: 0; }
.assessment-summary dt, .fixed-device-panel dt { color: var(--text-muted); font-size: 11px; }
.assessment-summary dd, .fixed-device-panel dd { margin: 5px 0 0; font-size: 14px; font-weight: 600; }
.assessment-summary dd small { display: block; margin-top: 4px; color: var(--text-muted); font-size: 11px; font-weight: 400; }

.assessment-timeline { display: flex; overflow-x: auto; margin: 0; padding: 8px 24px 22px; list-style: none; }
.assessment-timeline li { position: relative; flex: 1 0 160px; padding-right: 18px; }
.assessment-timeline li::before { position: absolute; top: 6px; right: 0; left: 8px; height: 2px; background: var(--border); content: ''; }
.assessment-timeline li > span { position: relative; z-index: 1; display: block; width: 14px; height: 14px; background: var(--admin-primary); border: 3px solid #eaf3ff; border-radius: 50%; }
.assessment-timeline li div { margin-top: 10px; }
.assessment-timeline strong, .assessment-timeline small { display: block; }
.assessment-timeline strong { font-size: 12px; }
.assessment-timeline small { margin-top: 3px; color: var(--text-muted); font-size: 10px; }

.fixed-device-panel { padding: 24px; }
.fixed-device-panel__info h2 { margin: 7px 0; font-size: 22px; letter-spacing: 0; }
.fixed-device-panel__info > p { margin: 0; color: var(--text-muted); }
.assessment-kicker { color: var(--admin-primary); font-size: 11px; font-weight: 700; }
.fixed-device-panel dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 22px 0 0; }
.fixed-device-panel dl div { min-width: 0; padding: 12px; background: #f7f8fa; border-radius: var(--radius-md); }
.fixed-device-panel dd { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.scenario-switch { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 1px; margin: 0 18px; overflow: hidden; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius-md); }
.scenario-switch button { min-height: 42px; padding: 0 10px; color: var(--text-muted); background: var(--surface); border: 0; cursor: pointer; }
.scenario-switch button.is-active { color: #fff; background: var(--admin-primary); font-weight: 600; }
.assessment-actions { display: flex; min-height: 72px; align-items: center; gap: 10px; padding: 14px 18px; }
.assessment-actions > span { margin-left: auto; color: var(--text-muted); font-size: 12px; }

.result-preview-panel__body { display: grid; gap: 16px; padding: 0 18px 18px; }
.assessment-result { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 16px 18px; background: #eef6ff; border: 1px solid #cfe4ff; border-radius: var(--radius-md); }
.assessment-result strong, .assessment-result p { display: block; margin: 0; }
.assessment-result p, .assessment-result > span { margin-top: 3px; color: var(--text-muted); font-size: 12px; }
.assessment-result.is-review-required, .assessment-result.is-offline { background: #fff7e9; border-color: #f4ddb7; }
.assessment-result.is-quarantined, .assessment-result.is-error { background: #fff0ef; border-color: #f3d0cd; }
.assessment-result.is-completed { background: #edf9f2; border-color: #ccebd9; }
.assessment-generated { color: var(--text-muted); font-size: 11px; }
.assessment-not-found { padding: 32px; text-align: center; }
.assessment-not-found p { color: var(--text-muted); }

@media (max-width: 900px) {
  .assessment-summary__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .fixed-device-panel dl { grid-template-columns: minmax(0, 1fr); }
  .scenario-switch { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
</style>
