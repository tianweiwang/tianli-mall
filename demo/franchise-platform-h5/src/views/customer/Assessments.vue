<template>
  <div class="customer-assessments">
    <section class="assessment-history-head">
      <Activity :size="22" aria-hidden="true" />
      <div><h1>我的检测</h1><p>按时间查看体态记录与复评变化</p></div>
    </section>

    <section v-if="records.length" class="assessment-history" aria-label="检测记录时间线">
      <article v-for="record in records" :key="record.id">
        <span class="assessment-history__dot" aria-hidden="true" />
        <div class="assessment-history__card">
          <header>
            <div><strong>第 {{ sequenceNumber(record) }} 次记录</strong><span>{{ formatDate(record.measuredAt) }} · {{ record.storeName }}</span></div>
            <small>报告 v{{ record.version }}</small>
          </header>
          <dl>
            <div v-for="metric in metricValues(record)" :key="metric.key"><dt>{{ metric.label }}</dt><dd>{{ metric.value }} {{ metric.unit }}</dd></div>
          </dl>
          <router-link :to="`${basePath}/assessments/${record.id}`">查看报告<ChevronRight :size="15" aria-hidden="true" /></router-link>
        </div>
      </article>
    </section>
    <section v-else class="assessment-history-empty">
      <Activity :size="28" aria-hidden="true" />
      <strong>暂无检测记录</strong>
      <span>完成门店检测并确认后，报告会显示在这里</span>
    </section>
    <p class="assessment-history-note">页面中的指标和影像均为固定演示数据。</p>
  </div>
</template>

<script>
import { Activity, ChevronRight } from 'lucide-vue';
import { demoStore } from '../../mock/store';
import { assessmentHistoryForCustomer, assessmentMetricDefinitions } from '../../mock/assessmentSelectors';

export default {
  name: 'CustomerAssessments',
  components: { Activity, ChevronRight },
  computed: {
    state() { return demoStore.state.data; },
    basePath() { return `/c/store/${this.$route.params.storeId}`; },
    records() { return assessmentHistoryForCustomer(this.state, this.state.activeCustomerId); },
    chronologicalRecords() { return [...this.records].reverse(); },
  },
  methods: {
    sequenceNumber(record) { return this.chronologicalRecords.findIndex((item) => item.id === record.id) + 1; },
    formatDate(value) { return value.slice(0, 10).replaceAll('-', '.'); },
    metricValues(record) {
      return Object.entries(assessmentMetricDefinitions).map(([key, definition]) => ({
        key, label: definition.label, unit: definition.unit, value: record.metrics[key],
      }));
    },
  },
};
</script>

<style scoped>
.customer-assessments { display: grid; gap: 10px; }
.assessment-history-head { display: grid; grid-template-columns: 32px minmax(0, 1fr); align-items: center; gap: 8px; padding: 15px; color: var(--brand-primary); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.assessment-history-head h1, .assessment-history-head p { margin: 0; }
.assessment-history-head h1 { color: var(--text-primary); font-size: 16px; }
.assessment-history-head p { margin-top: 2px; color: var(--text-muted); font-size: 10px; }
.assessment-history { padding-left: 16px; }
.assessment-history article { position: relative; padding: 0 0 12px 18px; border-left: 2px solid #e0e4e7; }
.assessment-history__dot { position: absolute; top: 18px; left: -6px; width: 10px; height: 10px; background: var(--brand-primary); border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px #f2c8c4; }
.assessment-history__card { overflow: hidden; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.assessment-history__card header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 13px; border-bottom: 1px solid var(--border); }
.assessment-history__card header strong, .assessment-history__card header span { display: block; }
.assessment-history__card header strong { font-size: 13px; }
.assessment-history__card header span, .assessment-history__card header small { margin-top: 2px; color: var(--text-muted); font-size: 10px; }
.assessment-history__card dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; margin: 0; background: var(--border); }
.assessment-history__card dl div { min-width: 0; padding: 10px 12px; background: var(--surface); }
.assessment-history__card dt, .assessment-history__card dd { margin: 0; }
.assessment-history__card dt { color: var(--text-muted); font-size: 9px; }
.assessment-history__card dd { margin-top: 3px; font-size: 12px; font-weight: 600; }
.assessment-history__card a { display: flex; min-height: 40px; align-items: center; justify-content: flex-end; gap: 3px; padding: 0 12px; color: var(--brand-primary); font-size: 11px; }
.assessment-history-empty { display: grid; min-height: 260px; align-content: center; justify-items: center; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); text-align: center; }
.assessment-history-empty strong { margin-top: 10px; color: var(--text-primary); }
.assessment-history-empty span { margin-top: 3px; font-size: 11px; }
.assessment-history-note { margin: 0; color: var(--text-muted); font-size: 10px; text-align: center; }
</style>
