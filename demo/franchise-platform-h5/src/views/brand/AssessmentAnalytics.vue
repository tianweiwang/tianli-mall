<template>
  <div class="admin-page brand-assessment-analytics">
    <AdminMetricStrip :items="metrics" />

    <section class="assessment-demo-notice">
      <Info :size="18" aria-hidden="true" />
      <p><strong>固定演示数据</strong><span>以下指标、变化与汇总仅用于展示运营流程，不代表产品效果。</span></p>
    </section>

    <section v-if="operations.completionTrend.length >= 2" class="admin-panel assessment-trend-panel">
      <header class="admin-panel__header"><div><h2>检测完成趋势</h2><p>按报告采集日期汇总</p></div><span>演示统计</span></header>
      <div class="admin-panel__body">
        <AdminLineChart :points="operations.completionTrend" value-key="count" value-unit="次" aria-label="检测完成次数趋势图" />
      </div>
    </section>

    <section class="admin-filter-bar assessment-record-filters" aria-label="检测记录筛选">
      <el-select v-model="storeFilter" aria-label="检测门店">
        <el-option label="全部门店" value="all" />
        <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
      </el-select>
      <el-date-picker
        v-model="dateFilter"
        type="date"
        value-format="yyyy-MM-dd"
        clearable
        placeholder="检测日期"
        aria-label="检测日期"
      />
      <el-input v-model.trim="keyword" clearable placeholder="报告编号或顾客姓名" prefix-icon="el-icon-search" />
      <span>共 {{ filteredRecords.length }} 条</span>
    </section>

    <section class="admin-panel admin-table-panel assessment-record-panel">
      <header class="admin-panel__header"><div><h2>检测记录明细</h2><p>仅展示已发布报告，顾客联系方式已脱敏</p></div></header>
      <el-table :data="filteredRecords" style="width: 100%" empty-text="暂无符合条件的检测记录">
        <el-table-column prop="reportId" label="报告编号" min-width="165" />
        <el-table-column label="顾客" width="155">
          <template slot-scope="{ row }"><strong>{{ row.customerName }}</strong><br><span class="assessment-record-muted">{{ row.customerPhone }}</span></template>
        </el-table-column>
        <el-table-column prop="storeName" label="门店" min-width="150" />
        <el-table-column label="固定设备" min-width="165">
          <template slot-scope="{ row }"><strong>{{ row.deviceName }}</strong><br><span class="assessment-record-muted">{{ row.deviceModel }}</span></template>
        </el-table-column>
        <el-table-column label="检测时间" width="145">
          <template slot-scope="{ row }">{{ formatDateTime(row.measuredAt) }}</template>
        </el-table-column>
        <el-table-column label="肩高差" width="95">
          <template slot-scope="{ row }">{{ row.metrics.shoulderHeightDiffMm }} mm</template>
        </el-table-column>
        <el-table-column label="骨盆倾斜" width="100">
          <template slot-scope="{ row }">{{ row.metrics.pelvicTiltDeg }}°</template>
        </el-table-column>
        <el-table-column label="颈前倾角" width="100">
          <template slot-scope="{ row }">{{ row.metrics.neckForwardAngleDeg }}°</template>
        </el-table-column>
        <el-table-column label="不适评分" width="100">
          <template slot-scope="{ row }">{{ row.metrics.discomfortScore }}/10</template>
        </el-table-column>
        <el-table-column label="操作" width="75" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openRecord(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header"><div><h2>门店检测质量</h2><p>任务完成、有效配对与复评计划</p></div></header>
      <el-table :data="operations.stores" style="width: 100%">
        <el-table-column prop="storeName" label="门店" min-width="170" />
        <el-table-column prop="taskCount" label="任务数" width="90" />
        <el-table-column label="完成率" width="100">
          <template slot-scope="{ row }"><strong>{{ row.completionRate }}%</strong></template>
        </el-table-column>
        <el-table-column prop="pairedCustomerCount" label="配对顾客" width="100" />
        <el-table-column prop="dueReassessmentCount" label="待复评" width="90" />
        <el-table-column label="数据质量" width="110">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.dataQualityFlag" :text="row.dataQualityFlag === 'normal' ? '正常' : '需关注'" /></template>
        </el-table-column>
      </el-table>
    </section>

    <section class="admin-panel metric-average-panel">
      <header class="admin-panel__header"><div><h2>指标均值</h2><p>只对已发布的固定演示报告做算术汇总</p></div><span>演示统计</span></header>
      <div class="metric-average-grid">
        <article v-for="metric in operations.metricAverages" :key="metric.key">
          <span>{{ metric.label }}</span>
          <strong>{{ metric.average }} <small>{{ metric.unit }}</small></strong>
          <p>{{ metric.sampleCount }} 份报告样本</p>
        </article>
      </div>
    </section>

    <el-drawer title="检测报告详情" :visible.sync="drawerOpen" size="520px">
      <div v-if="selectedRecord" class="admin-detail-body assessment-record-detail">
        <section class="admin-detail-section">
          <h3>报告信息</h3>
          <dl class="admin-detail-list">
            <div><dt>报告编号</dt><dd>{{ selectedRecord.reportId }}</dd></div>
            <div><dt>顾客</dt><dd>{{ selectedRecord.customerName }} {{ selectedRecord.customerPhone }}</dd></div>
            <div><dt>所属门店</dt><dd>{{ selectedRecord.storeName }}</dd></div>
            <div><dt>检测时间</dt><dd>{{ formatDateTime(selectedRecord.measuredAt) }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>检测指标</h3>
          <div class="assessment-record-detail__metric-head" aria-hidden="true">
            <span>指标</span><span>本次</span><span>较上次</span><span>较首次</span>
          </div>
          <dl class="assessment-record-detail__metrics">
            <div v-for="metric in selectedRecord.metricList" :key="metric.key">
              <dt>{{ metric.label }}</dt>
              <dd>{{ metric.current }} {{ metric.unit }}</dd>
              <dd>{{ formatDelta(metric, 'previousDelta', selectedRecord.hasPreviousComparison) }}</dd>
              <dd>{{ formatDelta(metric, 'firstDelta', selectedRecord.hasFirstComparison) }}</dd>
            </div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>设备与版本</h3>
          <dl class="admin-detail-list">
            <div><dt>固定设备</dt><dd>{{ selectedRecord.deviceName }}</dd></div>
            <div><dt>设备型号</dt><dd>{{ selectedRecord.deviceModel }}</dd></div>
            <div><dt>软件版本</dt><dd>{{ selectedRecord.softwareVersion }}</dd></div>
            <div><dt>报告版本</dt><dd>V{{ selectedRecord.reportVersion }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>建议</h3>
          <p class="assessment-record-detail__recommendation">{{ selectedRecord.recommendation }}</p>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { Activity, ClipboardCheck, Info, RefreshCw, UsersRound } from 'lucide-vue';
import AdminLineChart from '../../components/admin/AdminLineChart.vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { assessmentOperationsForBrand } from '../../mock/assessmentSelectors';

export default {
  name: 'BrandAssessmentAnalytics',
  components: { AdminLineChart, AdminMetricStrip, AdminStatusTag, Info },
  data() {
    return {
      dateFilter: '',
      drawerOpen: false,
      keyword: '',
      selectedRecord: null,
      storeFilter: 'all',
    };
  },
  computed: {
    state() { return demoStore.state.data; },
    operations() { return assessmentOperationsForBrand(this.state); },
    filteredRecords() {
      const query = this.keyword.trim().toLowerCase();
      return this.operations.recordRows.filter((record) => {
        const matchesStore = this.storeFilter === 'all' || record.storeId === this.storeFilter;
        const matchesDate = !this.dateFilter || record.measuredAt.slice(0, 10) === this.dateFilter;
        const searchable = `${record.reportId} ${record.customerName}`.toLowerCase();
        return matchesStore && matchesDate && (!query || searchable.includes(query));
      });
    },
    reassessmentRate() {
      return this.operations.pairedCustomerCount
        ? Math.round((this.state.reassessmentPlans.length / this.operations.pairedCustomerCount) * 100)
        : 0;
    },
    pendingReviewCount() {
      return this.state.assessmentTasks.filter((item) => ['awaiting-review', 'review-required'].includes(item.status)).length;
    },
    metrics() {
      return [
        { label: '累计检测', value: `${this.operations.recordRows.length} 次`, help: '已发布演示报告', icon: Activity, tone: 'primary' },
        { label: '有效配对', value: `${this.operations.pairedCustomerCount} 人`, help: '任务与顾客已关联', icon: UsersRound, tone: 'success' },
        { label: '复评率', value: `${this.reassessmentRate}%`, help: '已有复评计划的顾客', icon: RefreshCw, tone: 'warning' },
        { label: '待复核', value: `${this.pendingReviewCount} 项`, help: '完整性或门店确认待办', icon: ClipboardCheck, tone: 'danger' },
      ];
    },
  },
  methods: {
    formatDateTime(value) {
      return value ? value.replace('T', ' ').replace('+08:00', '').replace('Z', '').slice(0, 16) : '-';
    },
    formatDelta(metric, field, available) {
      if (!available) return '暂无对比';
      const value = metric[field];
      return `${value > 0 ? '+' : ''}${value} ${metric.unit}`;
    },
    openRecord(record) {
      this.selectedRecord = record;
      this.drawerOpen = true;
    },
  },
};
</script>

<style scoped>
.assessment-demo-notice { display: grid; grid-template-columns: 24px minmax(0, 1fr); align-items: center; gap: 8px; padding: 12px 14px; color: #265f56; background: #eef7f5; border: 1px solid #cce2dd; border-radius: var(--radius-md); }
.assessment-demo-notice p { margin: 0; }
.assessment-demo-notice strong, .assessment-demo-notice span { display: block; }
.assessment-demo-notice strong { font-size: 13px; }
.assessment-demo-notice span { margin-top: 2px; font-size: 11px; }
.assessment-trend-panel .admin-panel__header > span, .metric-average-panel .admin-panel__header > span { padding: 4px 7px; color: var(--admin-primary); background: #edf5ff; border-radius: var(--radius-sm); font-size: 10px; }
.assessment-trend-panel :deep(.admin-line-chart) { height: 220px; }
.assessment-record-filters { display: grid; grid-template-columns: 170px 180px minmax(220px, 1fr) auto; align-items: center; }
.assessment-record-filters > span { color: var(--text-muted); font-size: 12px; white-space: nowrap; }
.assessment-record-muted { color: var(--text-muted); font-size: 11px; }
.assessment-record-detail__metric-head, .assessment-record-detail__metrics > div { display: grid; grid-template-columns: minmax(120px, 1.2fr) repeat(3, minmax(80px, 1fr)); gap: 8px; align-items: center; }
.assessment-record-detail__metric-head { padding: 0 0 8px; color: var(--text-muted); font-size: 10px; }
.assessment-record-detail__metrics { margin: 0; }
.assessment-record-detail__metrics > div { min-height: 54px; border-top: 1px solid var(--border); }
.assessment-record-detail__metrics dt, .assessment-record-detail__metrics dd { margin: 0; }
.assessment-record-detail__metrics dt { font-size: 12px; font-weight: 600; }
.assessment-record-detail__metrics dd { color: var(--text-regular); font-size: 11px; }
.assessment-record-detail__recommendation { margin: 0; color: var(--text-regular); font-size: 13px; line-height: 1.7; }
.metric-average-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; background: var(--border); }
.metric-average-grid article { min-width: 0; padding: 18px; background: var(--surface); }
.metric-average-grid span, .metric-average-grid strong { display: block; }
.metric-average-grid span { color: var(--text-muted); font-size: 11px; }
.metric-average-grid strong { margin-top: 7px; font-size: 22px; letter-spacing: 0; }
.metric-average-grid strong small { color: var(--text-muted); font-size: 11px; font-weight: 500; }
.metric-average-grid p { margin: 6px 0 0; color: var(--text-muted); font-size: 10px; }

@media (max-width: 1100px) {
  .assessment-record-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
