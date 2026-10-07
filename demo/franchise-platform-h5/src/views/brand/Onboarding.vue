<template>
  <div class="admin-page">
    <section class="admin-filter-bar" aria-label="进件筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="门店名称或编码" prefix-icon="el-icon-search" />
      <el-select v-model="status" aria-label="进件状态">
        <el-option label="全部状态" value="all" />
        <el-option label="已通过" value="approved" />
        <el-option label="审核中" value="reviewing" />
        <el-option label="已驳回" value="rejected" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleStores.length }} 家</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleStores" style="width: 100%" empty-text="暂无符合条件的进件记录">
        <el-table-column label="申请门店" min-width="220">
          <template slot-scope="{ row }"><strong>{{ row.name }}</strong><br><span class="onboarding-muted">{{ row.id }} · {{ row.address }}</span></template>
        </el-table-column>
        <el-table-column label="联系人" width="160">
          <template slot-scope="{ row }">{{ row.contactName }}<br><span class="onboarding-muted">{{ row.contactPhone }}</span></template>
        </el-table-column>
        <el-table-column prop="subMchId" label="子商户号" width="125" />
        <el-table-column label="抽成比例" width="100"><template slot-scope="{ row }">{{ row.commissionRate }}%</template></el-table-column>
        <el-table-column label="进件状态" width="110"><template slot-scope="{ row }"><AdminStatusTag :status="row.onboardingStatus" /></template></el-table-column>
        <el-table-column label="操作" width="80" fixed="right"><template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button></template></el-table-column>
      </el-table>
    </section>

    <el-drawer title="进件详情" :visible.sync="drawerOpen" size="440px">
      <div v-if="selectedStore" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>申请信息</h3>
          <dl class="admin-detail-list">
            <div><dt>门店名称</dt><dd>{{ selectedStore.name }}</dd></div>
            <div><dt>门店编码</dt><dd>{{ selectedStore.id }}</dd></div>
            <div><dt>经营地址</dt><dd>{{ selectedStore.address }}</dd></div>
            <div><dt>运营联系人</dt><dd>{{ selectedStore.contactName }} {{ selectedStore.contactPhone }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>支付进件</h3>
          <dl class="admin-detail-list">
            <div><dt>进件状态</dt><dd><AdminStatusTag :status="selectedStore.onboardingStatus" /></dd></div>
            <div><dt>子商户号</dt><dd>{{ selectedStore.subMchId }}</dd></div>
            <div><dt>品牌抽成</dt><dd>{{ selectedStore.commissionRate }}%</dd></div>
            <div><dt>资料校验</dt><dd>{{ documentStatus(selectedStore) }}</dd></div>
          </dl>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';

export default {
  name: 'BrandOnboarding',
  components: { AdminStatusTag },
  data() {
    return { drawerOpen: false, keyword: '', selectedStore: null, status: 'all' };
  },
  computed: {
    stores() {
      return demoStore.state.data.stores;
    },
    visibleStores() {
      const keyword = this.keyword.toLowerCase();
      return this.stores.filter((store) => {
        const matchesStatus = this.status === 'all' || store.onboardingStatus === this.status;
        const searchable = `${store.name} ${store.id}`.toLowerCase();
        return matchesStatus && (!keyword || searchable.includes(keyword));
      });
    },
  },
  methods: {
    documentStatus(store) {
      return store.onboardingStatus === 'rejected' ? '存在需补充资料' : '资料已提交';
    },
    openDetail(store) {
      this.selectedStore = store;
      this.drawerOpen = true;
    },
  },
};
</script>

<style scoped>
.onboarding-muted {
  color: var(--text-muted);
  font-size: 11px;
}
</style>
