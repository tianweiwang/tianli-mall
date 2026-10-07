<template>
  <div class="admin-page">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-filter-bar" aria-label="结算筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="分账编号或业务单号" prefix-icon="el-icon-search" />
      <el-select v-model="status" aria-label="分账状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleRecords.length }} 笔</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleRecords" style="width: 100%" empty-text="暂无符合条件的结算记录">
        <el-table-column prop="id" label="分账编号" min-width="165" />
        <el-table-column label="业务单号" min-width="165"><template slot-scope="{ row }">{{ row.orderId || row.businessId }}</template></el-table-column>
        <el-table-column label="订单实付" width="120">
          <template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template>
        </el-table-column>
        <el-table-column label="集团/品牌收入" width="120">
          <template slot-scope="{ row }">{{ formatMoney(row.brandCommissionCents) }}</template>
        </el-table-column>
        <el-table-column label="区域代理收益" width="125">
          <template slot-scope="{ row }">{{ row.settlementSnapshot ? formatMoney(row.regionalAgentIncomeCents) : '-' }}</template>
        </el-table-column>
        <el-table-column label="门店收入" width="125">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.storeIncomeCents) }}</span></template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer title="结算详情" :visible.sync="drawerOpen" size="460px">
      <div v-if="selectedRecord" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>结算信息</h3>
          <dl class="admin-detail-list">
            <div><dt>分账编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>业务单号</dt><dd>{{ selectedRecord.orderId || selectedRecord.businessId }}</dd></div>
            <div><dt>所属门店</dt><dd>{{ store.name }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedRecord.status" :text="statusText(selectedRecord.status)" /></dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>资金拆分</h3>
          <dl class="admin-detail-list">
            <div><dt>订单实付</dt><dd>{{ formatMoney(selectedRecord.paidCents) }}</dd></div>
            <div><dt>门店收入</dt><dd class="admin-value-positive">{{ formatMoney(selectedRecord.storeIncomeCents) }}</dd></div>
            <div><dt>{{ selectedRecord.settlementSnapshot ? '集团收入' : '品牌抽成' }}</dt><dd>{{ formatMoney(selectedRecord.brandCommissionCents) }}</dd></div>
            <div v-if="selectedRecord.settlementSnapshot"><dt>区域代理收益</dt><dd>{{ formatMoney(selectedRecord.regionalAgentIncomeCents) }}</dd></div>
            <div v-else><dt>品牌承担分销成本</dt><dd>{{ formatMoney(selectedRecord.distributionCostCents) }}</dd></div>
          </dl>
          <p v-if="selectedRecord.settlementSnapshot" class="settlement-equation">{{ formatMoney(selectedRecord.storeIncomeCents) }} + {{ formatMoney(selectedRecord.brandCommissionCents) }} + {{ formatMoney(selectedRecord.regionalAgentIncomeCents) }} = {{ formatMoney(selectedRecord.paidCents) }}</p>
          <p v-else class="settlement-equation">{{ formatMoney(selectedRecord.storeIncomeCents) }} + {{ formatMoney(selectedRecord.brandCommissionCents) }} = {{ formatMoney(selectedRecord.paidCents) }}</p>
        </section>
        <section class="admin-detail-section">
          <h3>状态记录</h3>
          <div class="settlement-timeline">
            <div v-for="(item, index) in selectedRecord.timeline" :key="`${item.status}-${index}`">
              <span /><p><strong>{{ statusText(item.status) }}</strong><small>{{ formatTime(item.at) }}</small></p>
            </div>
          </div>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import {
  BadgeDollarSign,
  CircleDollarSign,
  Clock3,
  WalletCards,
} from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import {
  activeStore,
  formatMoney,
  profitSharingForStore,
  storeOverview,
  sumBy,
} from '../../mock/selectors';

const statusLabels = {
  pending: '待分账',
  processing: '处理中',
  completed: '已完成',
  failed: '分账失败',
  returning: '回退中',
  returned: '已回退',
};

export default {
  name: 'StoreSettlements',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      drawerOpen: false,
      keyword: '',
      selectedRecord: null,
      status: 'all',
      statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    records() {
      return profitSharingForStore(this.state, this.store.id);
    },
    overview() {
      return storeOverview(this.state, this.store.id);
    },
    metrics() {
      const completed = this.records.filter((item) => item.status === 'completed');
      return [
        { label: '累计结算基数', value: formatMoney(sumBy(this.records, 'paidCents')), help: `${this.records.length} 笔业务结算`, icon: CircleDollarSign, tone: 'primary' },
        { label: '门店预计收入', value: formatMoney(sumBy(this.records, 'storeIncomeCents')), help: '按业务结算快照', icon: WalletCards, tone: 'success' },
        { label: '处理中收入', value: formatMoney(this.overview.openSettlementIncomeCents), help: `${this.overview.openSettlementCount} 笔未完成`, icon: Clock3, tone: 'warning' },
        { label: '已完成收入', value: formatMoney(sumBy(completed, 'storeIncomeCents')), help: `${completed.length} 笔已完成`, icon: BadgeDollarSign, tone: 'success' },
      ];
    },
    visibleRecords() {
      const keyword = this.keyword.toLowerCase();
      return this.records.filter((record) => {
        const matchesStatus = this.status === 'all' || record.status === this.status;
        const searchable = `${record.id} ${record.orderId || ''} ${record.businessId || ''}`.toLowerCase();
        return matchesStatus && (!keyword || searchable.includes(keyword));
      });
    },
  },
  methods: {
    formatMoney,
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    openDetail(record) {
      this.selectedRecord = record;
      this.drawerOpen = true;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
  },
};
</script>

<style scoped>
.settlement-equation {
  margin: 14px 0 0;
  padding: 10px;
  color: #2268b9;
  background: #eef6ff;
  border: 1px solid #d5e8ff;
  border-radius: var(--radius-md);
  text-align: center;
  font-size: 12px;
}

.settlement-timeline {
  display: grid;
  gap: 0;
}

.settlement-timeline > div {
  display: grid;
  min-height: 48px;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 8px;
}

.settlement-timeline > div > span {
  position: relative;
  display: block;
  width: 8px;
  height: 8px;
  margin-top: 7px;
  background: var(--admin-primary);
  border-radius: 50%;
}

.settlement-timeline > div:not(:last-child) > span::after {
  position: absolute;
  top: 10px;
  left: 3px;
  width: 1px;
  height: 31px;
  background: var(--border-strong);
  content: '';
}

.settlement-timeline p,
.settlement-timeline strong,
.settlement-timeline small {
  display: block;
  margin: 0;
}

.settlement-timeline strong {
  font-size: 12px;
}

.settlement-timeline small {
  color: var(--text-muted);
  font-size: 10px;
}
</style>
