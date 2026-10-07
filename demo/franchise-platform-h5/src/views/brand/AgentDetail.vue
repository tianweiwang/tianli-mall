<template>
  <div v-if="overview.agent" class="admin-page regional-agent-detail">
    <section class="regional-agent-detail__heading">
      <router-link to="/brand/agents" aria-label="返回区域代理"><ArrowLeft :size="19" /></router-link>
      <div><h1>{{ overview.agent.name }}</h1><p>{{ overview.agent.id }} · {{ overview.agent.contactName }} {{ overview.agent.contactPhone }}</p></div>
      <AdminStatusTag :status="overview.agent.status" :text="statusText" />
    </section>

    <AdminMetricStrip :items="metrics" />

    <div class="regional-agent-detail__grid">
      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header"><div><h2>归属门店</h2><p>当前与历史门店归属</p></div></header>
        <el-table :data="overview.stores" style="width: 100%" empty-text="暂无归属门店">
          <el-table-column prop="name" label="门店" min-width="150" />
          <el-table-column prop="id" label="编码" width="90" />
          <el-table-column label="状态" width="90"><template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template></el-table-column>
          <el-table-column label="操作" width="74"><template slot-scope="{ row }"><router-link :to="`/brand/stores/${row.id}`" class="admin-link-button">查看</router-link></template></el-table-column>
        </el-table>
      </section>

      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header"><div><h2>代理结算流水</h2><p>引用原模拟分账记录</p></div></header>
        <el-table :data="overview.records" style="width: 100%" empty-text="暂无代理结算流水">
          <el-table-column label="业务单号" min-width="145"><template slot-scope="{ row }">{{ row.orderId || row.businessId }}</template></el-table-column>
          <el-table-column label="代理收益" width="110"><template slot-scope="{ row }">{{ formatMoney(row.regionalAgentIncomeCents) }}</template></el-table-column>
          <el-table-column label="状态" width="90"><template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="sharingStatus(row.status)" /></template></el-table-column>
          <el-table-column label="操作" width="74"><template slot-scope="{ row }"><router-link :to="`/brand/sharing/${row.id}`" class="admin-link-button">查看</router-link></template></el-table-column>
        </el-table>
      </section>
    </div>
    <p class="regional-agent-detail__notice">模拟品牌分账，正式上线以微信获批最大分账比例为准。</p>
  </div>
  <section v-else class="admin-panel admin-empty">未找到区域代理</section>
</template>

<script>
import { ArrowLeft, Building2, CircleDollarSign, Clock3, Percent } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { formatMoney } from '../../mock/selectors';
import { regionalAgentOverview } from '../../mock/regionalAgent';

const sharingLabels = { pending: '待分账', processing: '处理中', failed: '分账失败', completed: '已完成', returning: '回退中', returned: '已回退' };

export default {
  name: 'BrandAgentDetail',
  components: { AdminMetricStrip, AdminStatusTag, ArrowLeft },
  computed: {
    state() { return demoStore.state.data; },
    overview() { return regionalAgentOverview(this.state, this.$route.params.agentId); },
    statusText() { return this.overview.agent?.status === 'active' ? '合作中' : '已停用'; },
    metrics() {
      return [
        { label: '归属门店', value: `${this.overview.stores.length} 家`, help: '门店固定归属', icon: Building2, tone: 'primary' },
        { label: '代理比例', value: `${this.overview.agent.commissionRate}%`, help: '特色与经典项目', icon: Percent, tone: 'warning' },
        { label: '待结金额', value: formatMoney(this.overview.pendingCents), help: '含处理中与失败记录', icon: Clock3, tone: 'warning' },
        { label: '累计已结收益', value: formatMoney(this.overview.settledCents), help: '仅统计已完成分账', icon: CircleDollarSign, tone: 'success' },
      ];
    },
  },
  methods: {
    formatMoney,
    sharingStatus(status) { return sharingLabels[status] || status; },
  },
};
</script>

<style scoped>
.regional-agent-detail__heading { display: grid; min-height: 82px; grid-template-columns: 38px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 15px 18px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.regional-agent-detail__heading > a { display: grid; width: 34px; height: 34px; place-items: center; color: var(--text-regular); border: 1px solid var(--border-strong); border-radius: var(--radius-md); }
.regional-agent-detail__heading h1, .regional-agent-detail__heading p { margin: 0; }
.regional-agent-detail__heading h1 { font-size: 19px; }
.regional-agent-detail__heading p { margin-top: 2px; color: var(--text-muted); font-size: 11px; }
.regional-agent-detail__grid { display: grid; grid-template-columns: minmax(340px, .8fr) minmax(0, 1.2fr); gap: 16px; }
.regional-agent-detail__notice { margin: 0; padding: 12px 14px; color: #7a5813; background: #fff8e8; border: 1px solid #f0d99d; border-radius: var(--radius-md); font-size: 11px; }
@media (max-width: 900px) { .regional-agent-detail__grid { grid-template-columns: 1fr; } }
</style>
