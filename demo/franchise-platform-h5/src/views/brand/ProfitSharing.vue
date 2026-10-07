<template>
  <div class="admin-page">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-filter-bar" aria-label="品牌分账筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="分账编号或订单号" prefix-icon="el-icon-search" />
      <el-select v-model="storeId" aria-label="所属门店">
        <el-option label="全部门店" value="all" />
        <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
      </el-select>
      <el-select v-model="status" aria-label="分账状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleRecords.length }} 笔</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleRecords" style="width: 100%" empty-text="暂无符合条件的分账记录">
        <el-table-column prop="id" label="分账编号" min-width="165" />
        <el-table-column label="所属门店" min-width="145">
          <template slot-scope="{ row }">{{ storeName(row.storeId) }}</template>
        </el-table-column>
        <el-table-column label="业务单号" min-width="165"><template slot-scope="{ row }">{{ row.orderId || row.businessId }}</template></el-table-column>
        <el-table-column label="订单实付" width="115">
          <template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template>
        </el-table-column>
        <el-table-column label="门店收入" width="115">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.storeIncomeCents) }}</span></template>
        </el-table-column>
        <el-table-column label="品牌抽成" width="115">
          <template slot-scope="{ row }"><strong>{{ formatMoney(row.brandCommissionCents) }}</strong></template>
        </el-table-column>
        <el-table-column label="区域代理" min-width="130"><template slot-scope="{ row }"><router-link v-if="row.regionalAgentId" :to="`/brand/agents/${row.regionalAgentId}`" class="admin-link-button">{{ row.regionalAgentName }}</router-link><span v-else>{{ row.settlementSnapshot ? '集团直营' : '-' }}</span></template></el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template slot-scope="{ row }"><router-link :to="`/brand/sharing/${row.id}`" class="admin-link-button">查看详情</router-link></template>
        </el-table-column>
      </el-table>
    </section>
    <p class="profit-sharing__notice">模拟品牌分账，正式上线以微信获批最大分账比例为准。</p>
  </div>
</template>

<script>
import {
  BadgeDollarSign,
  CircleDollarSign,
  Clock3,
  HandCoins,
} from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { formatMoney, sumBy } from '../../mock/selectors';

const statusLabels = {
  pending: '待分账',
  processing: '处理中',
  completed: '已完成',
  failed: '分账失败',
  returning: '回退中',
  returned: '已回退',
};

export default {
  name: 'BrandProfitSharing',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      keyword: '',
      status: 'all',
      storeId: 'all',
      statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    records() {
      return this.state.profitSharingRecords;
    },
    metrics() {
      const openRecords = this.records.filter((item) => !['completed', 'returned'].includes(item.status));
      return [
        { label: '累计订单实付', value: formatMoney(sumBy(this.records, 'paidCents')), help: `${this.records.length} 笔分账记录`, icon: CircleDollarSign, tone: 'primary' },
        { label: '累计品牌抽成', value: formatMoney(sumBy(this.records, 'brandCommissionCents')), help: '不重复扣减门店收入', icon: BadgeDollarSign, tone: 'success' },
        { label: '品牌分销成本', value: formatMoney(sumBy(this.records, 'distributionCostCents')), help: '从品牌抽成中列示', icon: HandCoins, tone: 'warning' },
        { label: '待处理分账', value: `${openRecords.length} 笔`, help: `${formatMoney(sumBy(openRecords, 'brandCommissionCents'))} 品牌抽成`, icon: Clock3, tone: 'danger' },
      ];
    },
    visibleRecords() {
      const keyword = this.keyword.toLowerCase();
      return this.records.filter((record) => {
        const matchesStore = this.storeId === 'all' || record.storeId === this.storeId;
        const matchesStatus = this.status === 'all' || record.status === this.status;
        const searchable = `${record.id} ${record.orderId || ''} ${record.businessId || ''}`.toLowerCase();
        return matchesStore && matchesStatus && (!keyword || searchable.includes(keyword));
      });
    },
  },
  methods: {
    formatMoney,
    statusText(status) {
      return statusLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
  },
};
</script>

<style scoped>
.profit-sharing__notice { margin: 0; padding: 12px 14px; color: #7a5813; background: #fff8e8; border: 1px solid #f0d99d; border-radius: var(--radius-md); font-size: 11px; }
</style>
