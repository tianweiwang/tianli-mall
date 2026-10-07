<template>
  <div class="admin-page brand-analytics">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-panel">
      <header class="admin-panel__header"><div><h2>近 7 日平台成交趋势</h2><p>全平台已排除退款订单</p></div></header>
      <div class="admin-panel__body"><AdminLineChart :points="trend" aria-label="全平台近 7 日成交额趋势图" /></div>
    </section>

    <div class="brand-analytics__grid">
      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header"><div><h2>门店经营排行</h2><p>按成交金额排序</p></div></header>
        <el-table :data="rankings" style="width: 100%">
          <el-table-column label="排名" width="68">
            <template slot-scope="{ $index }"><span :class="['brand-analytics__rank', { 'is-top': $index < 3 }]">{{ $index + 1 }}</span></template>
          </el-table-column>
          <el-table-column prop="storeName" label="门店" min-width="145" />
          <el-table-column label="成交额" width="120">
            <template slot-scope="{ row }"><strong>{{ formatMoney(row.grossSalesCents) }}</strong></template>
          </el-table-column>
          <el-table-column prop="revenueOrderCount" label="成交订单" width="90" />
          <el-table-column label="到店率" width="85">
            <template slot-scope="{ row }">{{ row.arrivalRate }}%</template>
          </el-table-column>
          <el-table-column label="操作" width="72" fixed="right">
            <template slot-scope="{ row }">
              <router-link :to="storeDetailPath(row)" :aria-label="`查看门店 ${row.storeId}`" class="admin-link-button">查看</router-link>
            </template>
          </el-table-column>
        </el-table>
      </section>

      <section class="admin-panel brand-analytics__categories">
        <header class="admin-panel__header"><div><h2>品类销售结构</h2><p>按成交金额汇总</p></div></header>
        <div class="brand-analytics__category-list">
          <div v-for="item in categories" :key="item.category">
            <p><strong>{{ item.category }}</strong><span>{{ item.quantity }} 件 · {{ formatMoney(item.salesCents) }}</span></p>
            <div><span :style="{ width: categoryWidth(item.salesCents) }" /></div>
          </div>
        </div>
      </section>
    </div>

    <section class="admin-panel brand-analytics__bookings">
      <header class="admin-panel__header"><div><h2>预约履约分布</h2><p>全平台预约状态</p></div></header>
      <div class="brand-analytics__booking-list">
        <div><span>待到店</span><strong>{{ statusCount('pending') }}</strong></div>
        <div><span>已核销</span><strong>{{ statusCount('verified') }}</strong></div>
        <div><span>已取消</span><strong>{{ statusCount('cancelled') }}</strong></div>
        <div><span>已过期</span><strong>{{ statusCount('expired') }}</strong></div>
      </div>
    </section>
  </div>
</template>

<script>
import {
  BadgeDollarSign,
  CalendarCheck2,
  CircleDollarSign,
  Store,
} from 'lucide-vue';
import AdminLineChart from '../../components/admin/AdminLineChart.vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import { demoStore } from '../../mock/store';
import {
  brandOverview,
  categorySalesForBrand,
  formatMoney,
  salesTrendForBrand,
  storeRanking,
} from '../../mock/selectors';

export default {
  name: 'BrandAnalytics',
  components: { AdminLineChart, AdminMetricStrip },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return brandOverview(this.state);
    },
    metrics() {
      return [
        { label: '平台成交额', value: formatMoney(this.overview.grossSalesCents), help: `${this.overview.revenueOrderCount} 笔成交订单`, icon: CircleDollarSign, tone: 'primary' },
        { label: '品牌抽成', value: formatMoney(this.overview.brandCommissionCents), help: '按门店协议汇总', icon: BadgeDollarSign, tone: 'success' },
        { label: '营业门店', value: `${this.overview.activeStoreCount} 家`, help: `${this.state.stores.length} 家加盟门店`, icon: Store, tone: 'warning' },
        { label: '预约到店率', value: `${this.overview.arrivalRate}%`, help: `${this.overview.verifiedBookingCount} 条已核销`, icon: CalendarCheck2, tone: 'success' },
      ];
    },
    trend() {
      return salesTrendForBrand(this.state);
    },
    rankings() {
      return storeRanking(this.state);
    },
    categories() {
      return categorySalesForBrand(this.state);
    },
    maxCategorySales() {
      return Math.max(...this.categories.map((item) => item.salesCents), 1);
    },
  },
  methods: {
    formatMoney,
    categoryWidth(value) {
      return `${Math.max(8, Math.round((value / this.maxCategorySales) * 100))}%`;
    },
    statusCount(status) {
      return this.state.bookings.filter((item) => item.status === status).length;
    },
    storeDetailPath(store) {
      return `/brand/stores/${store.storeId}`;
    },
  },
};
</script>

<style scoped>
.brand-analytics__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(340px, 0.65fr);
  gap: 16px;
}

.brand-analytics__rank {
  display: inline-grid;
  width: 24px;
  height: 24px;
  place-items: center;
  color: var(--text-muted);
  background: var(--surface-subtle);
  border-radius: var(--radius-sm);
  font-size: 11px;
}

.brand-analytics__rank.is-top {
  color: #fff;
  background: var(--admin-primary);
}

.brand-analytics__category-list {
  display: grid;
  gap: 20px;
  padding: 22px 20px;
}

.brand-analytics__category-list p {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 0 0 8px;
}

.brand-analytics__category-list strong {
  font-size: 13px;
}

.brand-analytics__category-list p span {
  color: var(--text-muted);
  font-size: 11px;
}

.brand-analytics__category-list > div > div {
  height: 8px;
  overflow: hidden;
  background: var(--surface-subtle);
  border-radius: var(--radius-sm);
}

.brand-analytics__category-list > div > div span {
  display: block;
  height: 100%;
  background: var(--admin-primary);
  border-radius: inherit;
}

.brand-analytics__booking-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.brand-analytics__booking-list div {
  padding: 20px;
  border-right: 1px solid var(--border);
}

.brand-analytics__booking-list div:last-child {
  border-right: 0;
}

.brand-analytics__booking-list span,
.brand-analytics__booking-list strong {
  display: block;
}

.brand-analytics__booking-list span {
  color: var(--text-muted);
  font-size: 12px;
}

.brand-analytics__booking-list strong {
  margin-top: 4px;
  font-size: 24px;
}
</style>
