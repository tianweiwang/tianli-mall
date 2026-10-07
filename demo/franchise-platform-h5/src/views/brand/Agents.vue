<template>
  <div class="admin-page regional-agents">
    <header class="regional-agents__heading">
      <div><h1>区域代理</h1><p>查看代理档案、归属门店与模拟分账收益</p></div>
    </header>

    <AdminMetricStrip :items="metrics" />

    <section class="admin-filter-bar" aria-label="区域代理筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="代理名称、编码或联系人" prefix-icon="el-icon-search" />
      <el-select v-model="status" aria-label="合作状态">
        <el-option label="全部合作状态" value="all" />
        <el-option label="合作中" value="active" />
        <el-option label="已停用" value="inactive" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleSummaries.length }} 个</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleSummaries" style="width: 100%" empty-text="暂无符合条件的区域代理">
        <el-table-column label="区域代理" min-width="205">
          <template slot-scope="{ row }"><strong>{{ row.agent.name }}</strong><br><span class="agent-row-muted">{{ row.agent.id }} · {{ row.agent.contactName }}</span></template>
        </el-table-column>
        <el-table-column label="状态" width="100"><template slot-scope="{ row }"><AdminStatusTag :status="row.agent.status" :text="statusText(row.agent.status)" /></template></el-table-column>
        <el-table-column label="比例" width="78"><template slot-scope="{ row }">{{ row.agent.commissionRate }}%</template></el-table-column>
        <el-table-column label="归属门店" width="100"><template slot-scope="{ row }">{{ row.stores.length }} 家</template></el-table-column>
        <el-table-column label="待结金额" width="120"><template slot-scope="{ row }">{{ formatMoney(row.pendingCents) }}</template></el-table-column>
        <el-table-column label="累计已结" width="120"><template slot-scope="{ row }"><strong class="admin-value-positive">{{ formatMoney(row.settledCents) }}</strong></template></el-table-column>
        <el-table-column label="操作" width="92" fixed="right">
          <template slot-scope="{ row }"><router-link :to="`/brand/agents/${row.agent.id}`" :aria-label="`查看代理 ${row.agent.name}`" class="admin-link-button">查看详情</router-link></template>
        </el-table-column>
      </el-table>
    </section>

    <p class="regional-agents__notice">模拟品牌分账，正式上线以微信获批最大分账比例为准。</p>
  </div>
</template>

<script>
import { Building2, CircleDollarSign, Clock3, Network } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { formatMoney } from '../../mock/selectors';
import { regionalAgentList } from '../../mock/regionalAgent';

export default {
  name: 'BrandAgents',
  components: { AdminMetricStrip, AdminStatusTag },
  data() { return { keyword: '', status: 'all' }; },
  computed: {
    state() { return demoStore.state.data; },
    summaries() { return regionalAgentList(this.state); },
    visibleSummaries() {
      const keyword = this.keyword.toLowerCase();
      return this.summaries.filter(({ agent }) => (
        (this.status === 'all' || agent.status === this.status)
        && (!keyword || `${agent.name} ${agent.id} ${agent.contactName}`.toLowerCase().includes(keyword))
      ));
    },
    metrics() {
      const active = this.summaries.filter(({ agent }) => agent.status === 'active');
      return [
        { label: '有效代理', value: `${active.length} 个`, help: `${this.summaries.length} 个代理档案`, icon: Network, tone: 'primary' },
        { label: '覆盖门店', value: `${active.reduce((sum, item) => sum + item.stores.length, 0)} 家`, help: '按门店固定归属', icon: Building2, tone: 'success' },
        { label: '待结金额', value: formatMoney(this.summaries.reduce((sum, item) => sum + item.pendingCents, 0)), help: '含处理中与失败记录', icon: Clock3, tone: 'warning' },
        { label: '累计已结', value: formatMoney(this.summaries.reduce((sum, item) => sum + item.settledCents, 0)), help: '仅统计已完成分账', icon: CircleDollarSign, tone: 'success' },
      ];
    },
  },
  methods: {
    formatMoney,
    statusText(status) { return status === 'active' ? '合作中' : '已停用'; },
  },
};
</script>

<style scoped>
.regional-agents__heading h1, .regional-agents__heading p { margin: 0; }
.regional-agents__heading h1 { font-size: 20px; }
.regional-agents__heading p, .agent-row-muted { color: var(--text-muted); font-size: 11px; }
.regional-agents__notice { margin: 0; padding: 12px 14px; color: #7a5813; background: #fff8e8; border: 1px solid #f0d99d; border-radius: var(--radius-md); font-size: 11px; }
</style>
