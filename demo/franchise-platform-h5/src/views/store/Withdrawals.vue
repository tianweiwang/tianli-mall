<template>
  <div class="admin-page store-withdrawals">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-panel withdraw-form-panel">
      <header class="admin-panel__header">
        <div><h2>申请提现（演示）</h2><p>可提现余额按已完成结算收入扣除在途提现计算</p></div>
      </header>
      <div class="withdraw-form-grid">
        <el-input v-model.number="amount" type="number" min="0.01" aria-label="提现金额" placeholder="提现金额（元）" clearable />
        <el-select v-model="channel" aria-label="到账渠道">
          <el-option label="银行卡" value="bank" />
          <el-option label="支付宝" value="alipay" />
        </el-select>
        <el-input v-model.trim="accountNo" aria-label="收款账号" placeholder="收款账号（演示）" clearable />
        <el-button type="primary" :disabled="!canSubmit" @click="submit">提交申请</el-button>
      </div>
    </section>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>提现记录</h2><p>仅显示 {{ store.name }} 的提现申请</p></div>
      </header>
      <el-table :data="overview.records" style="width: 100%" empty-text="暂无提现记录">
        <el-table-column prop="id" label="提现编号" min-width="170" />
        <el-table-column label="申请主体" min-width="130">
          <template slot-scope="{ row }">{{ row.applicantName }}</template>
        </el-table-column>
        <el-table-column label="金额" width="120">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.amountCents) }}</span></template>
        </el-table-column>
        <el-table-column label="渠道" width="100">
          <template slot-scope="{ row }">{{ channelText(row.channel) }}</template>
        </el-table-column>
        <el-table-column prop="accountNo" label="收款账号" min-width="150" />
        <el-table-column label="状态" width="110">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="申请时间" min-width="160">
          <template slot-scope="{ row }">{{ formatTime(row.appliedAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看提现 ${row.id}`" @click="openDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :visible.sync="drawerOpen" size="430px">
      <template slot="title"><h2 class="admin-drawer-title">提现详情</h2></template>
      <div v-if="selectedRecord" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>申请信息</h3>
          <dl class="admin-detail-list">
            <div><dt>提现编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>申请主体</dt><dd>{{ selectedRecord.applicantName }}</dd></div>
            <div><dt>提现金额</dt><dd class="admin-value-positive">{{ formatMoney(selectedRecord.amountCents) }}</dd></div>
            <div><dt>到账渠道</dt><dd>{{ channelText(selectedRecord.channel) }}</dd></div>
            <div><dt>收款账号</dt><dd>{{ selectedRecord.accountNo }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedRecord.status" :text="statusText(selectedRecord.status)" /></dd></div>
            <div><dt>申请时间</dt><dd>{{ formatTime(selectedRecord.appliedAt) }}</dd></div>
          </dl>
        </section>
        <div class="admin-drawer-actions"><el-button @click="drawerOpen = false">关闭</el-button></div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { BadgeDollarSign, CircleDollarSign, Clock3, WalletCards } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { activeStore, formatMoney, storeWithdrawalOverview } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { applyWithdrawal } from '../../mock/transitions';

const statusLabels = {
  pending: '待审核',
  processing: '处理中',
  completed: '已到账',
  rejected: '已驳回',
};

const channelLabels = {
  bank: '银行卡',
  alipay: '支付宝',
};

export default {
  name: 'StoreWithdrawals',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      amount: null,
      channel: 'bank',
      drawerOpen: false,
      accountNo: '',
      selectedRecord: null,
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    overview() {
      return storeWithdrawalOverview(this.state, this.store.id);
    },
    metrics() {
      return [
        { label: '可提现余额', value: formatMoney(this.overview.availableCents), help: '扣除在途提现', icon: WalletCards, tone: 'success' },
        { label: '累计结算收入', value: formatMoney(this.overview.settledIncomeCents), help: '已完成分账', icon: CircleDollarSign, tone: 'primary' },
        { label: '在途提现', value: formatMoney(this.overview.pendingCents), help: '待审核与处理中', icon: Clock3, tone: 'warning' },
        { label: '已提现到账', value: formatMoney(this.overview.completedCents), help: `${this.overview.records.filter((item) => item.status === 'completed').length} 笔`, icon: BadgeDollarSign, tone: 'success' },
      ];
    },
    canSubmit() {
      return Number(this.amount) > 0 && this.accountNo.trim().length >= 4;
    },
  },
  methods: {
    formatMoney,
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    channelText(channel) {
      return channelLabels[channel] || channel;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    openDetail(record) {
      this.selectedRecord = record;
      this.drawerOpen = true;
    },
    submit() {
      const amountCents = Math.round(Number(this.amount) * 100);
      try {
        demoStore.commit((draft) => {
          applyWithdrawal(draft, {
            role: 'store',
            storeId: draft.activeStoreId,
            applicantName: draft.stores.find((item) => item.id === draft.activeStoreId)?.name || '当前门店',
            amountCents,
            channel: this.channel,
            accountNo: this.accountNo.trim(),
          });
        });
        this.$message.success('提现申请已提交（演示）');
        this.amount = null;
        this.accountNo = '';
      } catch (error) {
        this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.admin-drawer-title { margin: 0; font-size: 18px; }
.admin-drawer-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>

<style scoped>
.withdraw-form-panel {
  margin-bottom: 16px;
}

.withdraw-form-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) 150px minmax(0, 1fr) 120px;
  gap: 12px;
  padding: 4px 18px 18px;
}
</style>
