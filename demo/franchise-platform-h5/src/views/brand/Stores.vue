<template>
  <div class="admin-page">
    <section class="admin-filter-bar" aria-label="门店筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="门店名称、编码或地址" prefix-icon="el-icon-search" />
      <el-select v-model="storeStatus" aria-label="营业状态">
        <el-option label="全部营业状态" value="all" />
        <el-option label="营业中" value="active" />
        <el-option label="已停用" value="disabled" />
      </el-select>
      <el-select v-model="onboardingStatus" aria-label="进件状态">
        <el-option label="全部进件状态" value="all" />
        <el-option label="已通过" value="approved" />
        <el-option label="审核中" value="reviewing" />
        <el-option label="已驳回" value="rejected" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleStores.length }} 家</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleStores" style="width: 100%" empty-text="暂无符合条件的门店">
        <el-table-column label="门店" min-width="220">
          <template slot-scope="{ row }"><strong>{{ row.name }}</strong><br><span class="store-row-muted">{{ row.id }} · {{ row.address }}</span></template>
        </el-table-column>
        <el-table-column label="营业状态" width="105">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template>
        </el-table-column>
        <el-table-column label="进件状态" width="105">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.onboardingStatus" /></template>
        </el-table-column>
        <el-table-column label="抽成比例" width="100">
          <template slot-scope="{ row }">{{ row.commissionRate }}%</template>
        </el-table-column>
        <el-table-column label="代理归属" min-width="145">
          <template slot-scope="{ row }">
            <router-link v-if="agentFor(row.id)" :to="`/brand/agents/${agentFor(row.id).id}`" class="admin-link-button">{{ agentFor(row.id).name }}</router-link>
            <span v-else>集团直营</span>
          </template>
        </el-table-column>
        <el-table-column label="成交额" width="120">
          <template slot-scope="{ row }"><strong>{{ formatMoney(summary(row.id).grossSalesCents) }}</strong></template>
        </el-table-column>
        <el-table-column label="成交订单" width="95">
          <template slot-scope="{ row }">{{ summary(row.id).revenueOrderCount }}</template>
        </el-table-column>
        <el-table-column label="待到店" width="85">
          <template slot-scope="{ row }">{{ summary(row.id).pendingBookingCount }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template slot-scope="{ row }"><router-link :to="`/brand/stores/${row.id}`" class="admin-link-button">查看详情</router-link></template>
        </el-table-column>
      </el-table>
    </section>
  </div>
</template>

<script>
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { formatMoney, storeOverview } from '../../mock/selectors';
import { regionalAgentForStore } from '../../mock/regionalAgent';

export default {
  name: 'BrandStores',
  components: { AdminStatusTag },
  data() {
    return {
      keyword: '',
      onboardingStatus: 'all',
      storeStatus: 'all',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    visibleStores() {
      const keyword = this.keyword.toLowerCase();
      return this.state.stores.filter((store) => {
        const matchesStore = this.storeStatus === 'all' || store.status === this.storeStatus;
        const matchesOnboarding = this.onboardingStatus === 'all'
          || store.onboardingStatus === this.onboardingStatus;
        const searchable = `${store.name} ${store.id} ${store.address}`.toLowerCase();
        return matchesStore && matchesOnboarding && (!keyword || searchable.includes(keyword));
      });
    },
  },
  methods: {
    formatMoney,
    agentFor(storeId) { return regionalAgentForStore(this.state, storeId); },
    summary(storeId) {
      return storeOverview(this.state, storeId);
    },
  },
};
</script>

<style scoped>
.store-row-muted {
  color: var(--text-muted);
  font-size: 11px;
}
</style>
