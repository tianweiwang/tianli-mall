<template>
  <div class="admin-page brand-dashboard">
    <AdminMetricStrip :items="metrics" />

    <div class="brand-dashboard__grid">
      <section class="admin-panel">
        <header class="admin-panel__header">
          <div><h2>近 7 日平台成交</h2><p>已排除退款订单</p></div>
          <router-link to="/brand/analytics" class="admin-link-button">经营分析<ArrowRight :size="15" /></router-link>
        </header>
        <div class="admin-panel__body"><AdminLineChart :points="trend" aria-label="品牌近 7 日成交额趋势图" /></div>
      </section>

      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header">
          <div><h2>门店排行</h2><p>按成交金额排序</p></div>
          <router-link to="/brand/stores" class="admin-link-button">全部门店<ArrowRight :size="15" /></router-link>
        </header>
        <el-table :data="rankings" style="width: 100%">
          <el-table-column label="排名" width="54">
            <template slot-scope="{ $index }"><span class="brand-rank">{{ $index + 1 }}</span></template>
          </el-table-column>
          <el-table-column prop="storeName" label="门店" min-width="120" />
          <el-table-column label="成交额" width="105">
            <template slot-scope="{ row }"><strong>{{ formatMoney(row.grossSalesCents) }}</strong></template>
          </el-table-column>
          <el-table-column label="订单" width="65" prop="revenueOrderCount" />
          <el-table-column label="操作" width="72" fixed="right">
            <template slot-scope="{ row }">
              <router-link :to="storeDetailPath(row)" :aria-label="`查看门店 ${row.storeId}`" class="admin-link-button">查看</router-link>
            </template>
          </el-table-column>
        </el-table>
      </section>
    </div>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>待处理分账</h2><p>跨门店分账状态</p></div>
        <router-link to="/brand/sharing" class="admin-link-button">分账流水<ArrowRight :size="15" /></router-link>
      </header>
      <el-table :data="openSharing" style="width: 100%">
        <el-table-column prop="id" label="分账编号" min-width="165" />
        <el-table-column label="门店" min-width="150">
          <template slot-scope="{ row }">{{ storeName(row.storeId) }}</template>
        </el-table-column>
        <el-table-column prop="orderId" label="订单号" min-width="165" />
        <el-table-column label="订单实付" width="120">
          <template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template>
        </el-table-column>
        <el-table-column label="品牌抽成" width="120">
          <template slot-scope="{ row }"><strong>{{ formatMoney(row.brandCommissionCents) }}</strong></template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="sharingStatus(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <router-link :to="sharingDetailPath(row)" :aria-label="`查看分账 ${row.id}`" class="admin-link-button">查看</router-link>
          </template>
        </el-table-column>
      </el-table>
    </section>
  </div>
</template>

<script>
import {
  ArrowRight,
  BadgeDollarSign,
  Building2,
  CircleDollarSign,
  Split,
  Network,
} from 'lucide-vue';
import AdminLineChart from '../../components/admin/AdminLineChart.vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import {
  brandOverview,
  formatMoney,
  salesTrendForBrand,
  storeRanking,
} from '../../mock/selectors';
import { regionalAgentList } from '../../mock/regionalAgent';

const sharingLabels = {
  pending: '待分账',
  processing: '处理中',
  failed: '分账失败',
  completed: '已完成',
  returning: '回退中',
  returned: '已回退',
};

export default {
  name: 'BrandDashboard',
  components: { AdminLineChart, AdminMetricStrip, AdminStatusTag, ArrowRight },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return brandOverview(this.state);
    },
    metrics() {
      const agentPendingCents = regionalAgentList(this.state)
        .reduce((sum, item) => sum + item.pendingCents, 0);
      return [
        { label: '营业门店', value: `${this.overview.activeStoreCount} 家`, help: `${this.overview.reviewingStoreCount} 家进件审核中`, icon: Building2, tone: 'primary' },
        { label: '平台成交额', value: formatMoney(this.overview.grossSalesCents), help: `${this.overview.revenueOrderCount} 笔成交订单`, icon: CircleDollarSign, tone: 'success' },
        { label: '品牌抽成', value: formatMoney(this.overview.brandCommissionCents), help: '按门店协议比例', icon: BadgeDollarSign, tone: 'warning' },
        { label: '待处理分账', value: `${this.overview.openSettlementCount} 笔`, help: `${formatMoney(this.overview.openBrandCommissionCents)} 品牌抽成`, icon: Split, tone: 'danger' },
        { label: '代理待结', value: formatMoney(agentPendingCents), help: '区域代理结算', icon: Network, tone: 'warning', path: '/brand/agents' },
      ];
    },
    trend() {
      return salesTrendForBrand(this.state);
    },
    rankings() {
      return storeRanking(this.state);
    },
    openSharing() {
      return this.state.profitSharingRecords.filter(
        (item) => !['completed', 'returned'].includes(item.status),
      );
    },
  },
  methods: {
    formatMoney,
    sharingDetailPath(record) {
      return `/brand/sharing/${record.id}`;
    },
    sharingStatus(status) {
      return sharingLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
    storeDetailPath(store) {
      return `/brand/stores/${store.storeId}`;
    },
  },
};
</script>

<style scoped>
.brand-dashboard__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.65fr) minmax(360px, 0.8fr);
  gap: 16px;
}

.brand-rank {
  display: inline-grid;
  width: 24px;
  height: 24px;
  place-items: center;
  color: #fff;
  background: var(--admin-primary);
  border-radius: var(--radius-sm);
  font-size: 11px;
}
</style>
