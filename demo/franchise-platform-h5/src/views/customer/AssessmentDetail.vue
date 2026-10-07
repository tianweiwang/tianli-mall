<template>
  <div v-if="record && comparison" class="customer-assessment-report">
    <section class="report-heading">
      <router-link :to="`${basePath}/assessments`" aria-label="返回检测记录"><ArrowLeft :size="18" /></router-link>
      <div><span>体态记录报告</span><h1>{{ formatDate(record.measuredAt) }}</h1><p>{{ record.storeName }} · 报告 v{{ record.version }}</p></div>
      <span class="report-heading__badge">已发布</span>
    </section>

    <section class="report-comparison-summary">
      <div><span>较上次</span><strong>{{ comparisonSummary('previousDelta') }}</strong></div>
      <div><span>较首次</span><strong>{{ comparisonSummary('firstDelta') }}</strong></div>
    </section>

    <AssessmentMetricGrid :metrics="comparison.metricList" />
    <AssessmentImageStrip
      :images="record.images"
      :captured-at="formatDateTime(record.measuredAt)"
      :store-name="record.storeName"
    />

    <section class="report-recommendation">
      <header><ClipboardCheck :size="19" aria-hidden="true" /><h2>品牌建议</h2></header>
      <p>{{ record.recommendation }}</p>
      <dl>
        <div><dt>记录门店</dt><dd>{{ record.storeName }}</dd></div>
        <div><dt>设备型号</dt><dd>{{ record.deviceModel }}</dd></div>
        <div><dt>采集时间</dt><dd>{{ formatDateTime(record.measuredAt) }}</dd></div>
      </dl>
    </section>

    <p class="report-notice">本报告用于记录体态变化和日常健康管理，不作为医疗诊断或治疗依据。</p>

    <router-link
      :to="reassessmentPath"
      class="report-reassessment"
      data-testid="reassessment-booking"
    ><RefreshCw :size="17" aria-hidden="true" />预约下一次复评</router-link>
  </div>
  <section v-else class="report-not-found">
    <strong>未找到检测报告</strong>
    <span>该报告不存在或不属于当前顾客。</span>
    <router-link :to="`${basePath}/assessments`">返回我的检测</router-link>
  </section>
</template>

<script>
import { ArrowLeft, ClipboardCheck, RefreshCw } from 'lucide-vue';
import AssessmentImageStrip from '../../components/assessment/AssessmentImageStrip.vue';
import AssessmentMetricGrid from '../../components/assessment/AssessmentMetricGrid.vue';
import { demoStore } from '../../mock/store';
import { assessmentHistoryForCustomer, buildAssessmentComparison } from '../../mock/assessmentSelectors';

export default {
  name: 'CustomerAssessmentDetail',
  components: { ArrowLeft, AssessmentImageStrip, AssessmentMetricGrid, ClipboardCheck, RefreshCw },
  computed: {
    state() { return demoStore.state.data; },
    basePath() { return `/c/store/${this.$route.params.storeId}`; },
    record() {
      return assessmentHistoryForCustomer(this.state, this.state.activeCustomerId)
        .find((item) => item.id === this.$route.params.assessmentId);
    },
    comparison() { return this.record ? buildAssessmentComparison(this.state, this.record.id) : null; },
    reassessmentPath() {
      return `${this.basePath}/booking?serviceId=SV1001&sourceAssessmentId=${this.record.id}`;
    },
  },
  methods: {
    formatDate(value) { return value.slice(0, 10).replaceAll('-', '.'); },
    formatDateTime(value) { return value.replace('T', ' ').replace('+08:00', '').slice(0, 16); },
    comparisonSummary(field) {
      const improved = this.comparison.metricList.filter((item) => item[field] < 0).length;
      const stable = this.comparison.metricList.filter((item) => item[field] === 0).length;
      return improved ? `${improved} 项改善` : `${stable} 项持平`;
    },
  },
};
</script>

<style scoped>
.customer-assessment-report { display: grid; gap: 10px; }
.report-heading, .report-comparison-summary, .report-recommendation, .report-notice { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.report-heading { display: grid; min-height: 88px; grid-template-columns: 30px minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 14px; }
.report-heading > a { display: grid; width: 30px; height: 30px; place-items: center; color: var(--text-primary); }
.report-heading div > span { color: var(--brand-primary); font-size: 10px; font-weight: 700; }
.report-heading h1, .report-heading p { margin: 0; }
.report-heading h1 { margin-top: 2px; font-size: 18px; letter-spacing: 0; }
.report-heading p { margin-top: 2px; color: var(--text-muted); font-size: 10px; }
.report-heading__badge { padding: 4px 7px; color: var(--success); background: #edf9f2; border-radius: var(--radius-sm); font-size: 10px; }
.report-comparison-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 10px; }
.report-comparison-summary div { padding: 7px 12px; border-right: 1px solid var(--border); }
.report-comparison-summary div:last-child { border-right: 0; }
.report-comparison-summary span, .report-comparison-summary strong { display: block; }
.report-comparison-summary span { color: var(--text-muted); font-size: 10px; }
.report-comparison-summary strong { margin-top: 3px; font-size: 13px; }
.customer-assessment-report :deep(.assessment-metrics) { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.customer-assessment-report :deep(.assessment-metric) { padding: 12px; }
.customer-assessment-report :deep(.assessment-metric strong) { font-size: 20px; }
.report-recommendation { padding: 14px; }
.report-recommendation header { display: flex; align-items: center; gap: 7px; color: var(--brand-primary); }
.report-recommendation h2 { margin: 0; color: var(--text-primary); font-size: 14px; }
.report-recommendation > p { margin: 11px 0; color: var(--text-regular); font-size: 12px; line-height: 1.7; }
.report-recommendation dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin: 0; }
.report-recommendation dl div { min-width: 0; padding: 8px; background: var(--surface-subtle); border-radius: var(--radius-sm); }
.report-recommendation dt, .report-recommendation dd { margin: 0; }
.report-recommendation dt { color: var(--text-muted); font-size: 9px; }
.report-recommendation dd { margin-top: 3px; overflow: hidden; font-size: 10px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.report-notice { margin: 0; padding: 11px 13px; color: var(--text-muted); font-size: 10px; line-height: 1.6; }
.report-reassessment { position: sticky; z-index: 20; bottom: calc(68px + env(safe-area-inset-bottom)); display: flex; min-height: 44px; align-items: center; justify-content: center; gap: 6px; color: #fff; background: var(--brand-primary); border-radius: var(--radius-md); font-size: 13px; font-weight: 600; box-shadow: 0 6px 18px rgb(174 47 35 / 18%); }
.report-not-found { display: grid; min-height: 280px; align-content: center; justify-items: center; padding: 20px; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); text-align: center; }
.report-not-found strong { color: var(--text-primary); }
.report-not-found span { margin-top: 4px; font-size: 11px; }
.report-not-found a { margin-top: 16px; color: var(--brand-primary); font-size: 12px; }
</style>
