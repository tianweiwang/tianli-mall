<template>
  <div v-if="store" class="admin-page brand-store-detail">
    <section class="brand-store-detail__heading">
      <router-link to="/brand/stores" aria-label="返回加盟门店"><ArrowLeft :size="19" /></router-link>
      <div><h1>{{ store.name }}</h1><p>{{ store.id }} · {{ store.address }}</p></div>
      <AdminStatusTag :status="store.status" />
      <AdminStatusTag :status="store.onboardingStatus" />
    </section>

    <AdminMetricStrip :items="metrics" />

    <div class="brand-store-detail__grid">
      <section class="admin-panel">
        <header class="admin-panel__header"><div><h2>门店资料</h2><p>加盟与支付信息</p></div></header>
        <div class="admin-panel__body">
          <dl class="store-detail-list">
            <div><dt>经营地址</dt><dd>{{ store.address }}</dd></div>
            <div><dt>营业时间</dt><dd>{{ store.openHours }}</dd></div>
            <div><dt>联系人</dt><dd>{{ store.contactName }} {{ store.contactPhone }}</dd></div>
            <div><dt>子商户号</dt><dd>{{ store.subMchId }}</dd></div>
            <div><dt>商品订单协议比例</dt><dd>门店{{ 100 - store.commissionRate }}% / 品牌{{ store.commissionRate }}%</dd></div>
            <div><dt>代理归属</dt><dd><router-link v-if="regionalAgent" :to="`/brand/agents/${regionalAgent.id}`" class="admin-link-button">{{ regionalAgent.name }}</router-link><span v-else>集团直营</span></dd></div>
            <div><dt>特色/经典项目结算模板</dt><dd>{{ regionalAgent ? '门店50% / 集团35% / 代理15%' : '门店50% / 集团50%' }}</dd></div>
          </dl>
        </div>
      </section>

      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header"><div><h2>最近订单</h2><p>该门店归属订单</p></div></header>
        <el-table :data="orders" style="width: 100%">
          <el-table-column prop="id" label="订单号" min-width="160" />
          <el-table-column label="实付" width="110"><template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template></el-table-column>
          <el-table-column label="门店收入" width="120"><template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.storeIncomeCents) }}</span></template></el-table-column>
          <el-table-column label="状态" width="100"><template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template></el-table-column>
          <el-table-column label="操作" width="72" fixed="right">
            <template slot-scope="{ row }">
              <router-link :to="orderDetailPath(row)" :aria-label="`查看订单 ${row.id}`" class="admin-link-button">查看</router-link>
            </template>
          </el-table-column>
        </el-table>
      </section>
    </div>
  </div>
  <section v-else class="admin-panel admin-empty">未找到该加盟门店</section>
</template>

<script>
import {
  ArrowLeft,
  BadgeDollarSign,
  CalendarCheck2,
  ClipboardList,
  TrendingUp,
} from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { formatMoney, ordersForStore, storeOverview } from '../../mock/selectors';
import { regionalAgentForStore } from '../../mock/regionalAgent';

export default {
  name: 'BrandStoreDetail',
  components: { AdminMetricStrip, AdminStatusTag, ArrowLeft },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    overview() {
      return this.store ? storeOverview(this.state, this.store.id) : {};
    },
    regionalAgent() { return this.store ? regionalAgentForStore(this.state, this.store.id) : null; },
    orders() {
      return this.store ? ordersForStore(this.state, this.store.id).slice(0, 5) : [];
    },
    metrics() {
      return [
        { label: '门店成交额', value: formatMoney(this.overview.grossSalesCents), help: '退款订单不计入', icon: TrendingUp, tone: 'primary' },
        { label: '归属订单', value: `${this.overview.orderCount} 笔`, help: `${this.overview.revenueOrderCount} 笔计入成交`, icon: ClipboardList, tone: 'success' },
        { label: '预约到店率', value: `${this.overview.arrivalRate}%`, help: `${this.overview.pendingBookingCount} 条待到店`, icon: CalendarCheck2, tone: 'warning' },
        { label: '门店预计收入', value: formatMoney(this.overview.expectedIncomeCents), help: `品牌抽成 ${this.store.commissionRate}%`, icon: BadgeDollarSign, tone: 'success' },
      ];
    },
  },
  methods: {
    formatMoney,
    orderDetailPath(order) {
      return `/brand/orders?recordId=${encodeURIComponent(order.id)}`;
    },
  },
};
</script>

<style scoped>
.brand-store-detail__heading {
  display: grid;
  min-height: 86px;
  grid-template-columns: 38px minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 12px;
  padding: 16px 20px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.brand-store-detail__heading > a {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  color: var(--text-regular);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
}

.brand-store-detail__heading h1,
.brand-store-detail__heading p {
  margin: 0;
}

.brand-store-detail__heading h1 {
  font-size: 19px;
}

.brand-store-detail__heading p {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 11px;
}

.brand-store-detail__grid {
  display: grid;
  grid-template-columns: minmax(340px, 0.75fr) minmax(0, 1.35fr);
  gap: 16px;
}

.store-detail-list {
  display: grid;
  margin: 0;
}

.store-detail-list div {
  display: grid;
  min-height: 48px;
  grid-template-columns: 100px minmax(0, 1fr);
  align-items: center;
  border-bottom: 1px solid var(--border);
}

.store-detail-list div:last-child {
  border-bottom: 0;
}

.store-detail-list dt,
.store-detail-list dd {
  margin: 0;
  font-size: 12px;
}

.store-detail-list dt {
  color: var(--text-muted);
}

.store-detail-list dd {
  text-align: right;
}
</style>
