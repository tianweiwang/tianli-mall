<template>
  <div class="admin-page store-performance">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-panel">
      <header class="admin-panel__header">
        <div><h2>近 7 日成交趋势</h2><p>{{ store.name }} 归属订单</p></div>
      </header>
      <div class="admin-panel__body"><AdminLineChart :points="trend" aria-label="门店近 7 日成交额趋势图" /></div>
    </section>

    <div class="store-performance__grid">
      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header"><div><h2>商品销售排行</h2><p>按本店成交金额排序</p></div></header>
        <el-table :data="ranking" style="width: 100%">
          <el-table-column label="排名" width="70">
            <template slot-scope="{ $index }"><span :class="['ranking-number', { 'is-top': $index < 3 }]">{{ $index + 1 }}</span></template>
          </el-table-column>
          <el-table-column prop="name" label="商品" min-width="220" />
          <el-table-column prop="quantity" label="销量" width="90" />
          <el-table-column label="成交金额" width="130">
            <template slot-scope="{ row }"><strong>{{ formatMoney(row.salesCents) }}</strong></template>
          </el-table-column>
        </el-table>
      </section>

      <section class="admin-panel performance-breakdown">
        <header class="admin-panel__header"><div><h2>预约履约</h2><p>本店预约状态分布</p></div></header>
        <div class="performance-breakdown__body">
          <div><span>待到店</span><strong>{{ statusCount('pending') }}</strong></div>
          <div><span>已核销</span><strong>{{ statusCount('verified') }}</strong></div>
          <div><span>已取消</span><strong>{{ statusCount('cancelled') }}</strong></div>
          <div><span>已过期</span><strong>{{ statusCount('expired') }}</strong></div>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import {
  BadgeDollarSign,
  CalendarCheck2,
  ClipboardList,
  TrendingUp,
} from 'lucide-vue';
import AdminLineChart from '../../components/admin/AdminLineChart.vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import { demoStore } from '../../mock/store';
import {
  activeStore,
  bookingsForStore,
  formatMoney,
  productRankingForStore,
  salesTrendForStore,
  storeOverview,
} from '../../mock/selectors';

export default {
  name: 'StorePerformance',
  components: { AdminLineChart, AdminMetricStrip },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    overview() {
      return storeOverview(this.state, this.store.id);
    },
    bookings() {
      return bookingsForStore(this.state, this.store.id);
    },
    metrics() {
      return [
        { label: '成交额', value: formatMoney(this.overview.grossSalesCents), help: '退款订单不计入', icon: TrendingUp, tone: 'primary' },
        { label: '成交订单', value: `${this.overview.revenueOrderCount} 笔`, help: `归属订单 ${this.overview.orderCount} 笔`, icon: ClipboardList, tone: 'success' },
        { label: '预约到店率', value: `${this.overview.arrivalRate}%`, help: `${this.overview.verifiedBookingCount} 条已核销`, icon: CalendarCheck2, tone: 'warning' },
        { label: '预计门店收入', value: formatMoney(this.overview.expectedIncomeCents), help: '按当前抽成比例', icon: BadgeDollarSign, tone: 'success' },
      ];
    },
    trend() {
      return salesTrendForStore(this.state, this.store.id);
    },
    ranking() {
      return productRankingForStore(this.state, this.store.id);
    },
  },
  methods: {
    formatMoney,
    statusCount(status) {
      return this.bookings.filter((item) => item.status === status).length;
    },
  },
};
</script>

<style scoped>
.store-performance__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.8fr);
  gap: 16px;
}

.ranking-number {
  display: inline-grid;
  width: 24px;
  height: 24px;
  place-items: center;
  color: var(--text-muted);
  background: var(--surface-subtle);
  border-radius: var(--radius-sm);
  font-size: 11px;
}

.ranking-number.is-top {
  color: #fff;
  background: var(--admin-primary);
}

.performance-breakdown__body {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0;
  padding: 18px;
}

.performance-breakdown__body div {
  min-height: 92px;
  padding: 18px;
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}

.performance-breakdown__body div:nth-child(2n) {
  border-right: 0;
}

.performance-breakdown__body div:nth-last-child(-n + 2) {
  border-bottom: 0;
}

.performance-breakdown span,
.performance-breakdown strong {
  display: block;
}

.performance-breakdown span {
  color: var(--text-muted);
  font-size: 12px;
}

.performance-breakdown strong {
  margin-top: 4px;
  font-size: 24px;
}
</style>
