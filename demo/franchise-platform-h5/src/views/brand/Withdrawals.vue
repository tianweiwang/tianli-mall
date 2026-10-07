<template>
  <div class="admin-page brand-withdrawals">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-filter-bar" aria-label="提现审核筛选">
      <el-select v-model="role" aria-label="申请主体">
        <el-option label="全部主体" value="all" />
        <el-option label="门店" value="store" />
        <el-option label="分销员" value="distributor" />
      </el-select>
      <el-select v-model="status" aria-label="审核状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleRecords.length }} 条</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleRecords" style="width: 100%" empty-text="暂无符合条件的提现申请">
        <el-table-column prop="id" label="提现编号" min-width="170" />
        <el-table-column label="主体" width="90">
          <template slot-scope="{ row }">{{ row.role === 'store' ? '门店' : '分销员' }}</template>
        </el-table-column>
        <el-table-column prop="applicantName" label="申请主体" min-width="130" />
        <el-table-column label="金额" width="120">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.amountCents) }}</span></template>
        </el-table-column>
        <el-table-column label="渠道" width="100">
          <template slot-scope="{ row }">{{ channelText(row.channel) }}</template>
        </el-table-column>
        <el-table-column prop="accountNo" label="收款账号" min-width="140" />
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="申请时间" min-width="160">
          <template slot-scope="{ row }">{{ formatTime(row.appliedAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="190" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看提现 ${row.id}`" @click="openDetail(row)">查看</el-button>
            <template v-if="row.status === 'pending' || row.status === 'processing'">
              <el-button type="text" @click="review(row, true)">通过</el-button>
              <el-button type="text" class="admin-danger-link" @click="review(row, false)">驳回</el-button>
            </template>
            <span v-else class="admin-muted-text">已结束</span>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :visible.sync="drawerOpen" size="430px">
      <template slot="title"><h2 class="admin-drawer-title">提现详情</h2></template>
      <div v-if="selectedRecord" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>审核信息</h3>
          <dl class="admin-detail-list">
            <div><dt>提现编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>申请主体</dt><dd>{{ selectedRecord.applicantName }}</dd></div>
            <div><dt>主体类型</dt><dd>{{ selectedRecord.role === 'store' ? '门店' : '分销员' }}</dd></div>
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
import { BadgeCheck, Banknote, Clock3, XCircle } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { allWithdrawalRequests, formatMoney, sumBy } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { reviewWithdrawal } from '../../mock/transitions';

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
  name: 'BrandWithdrawals',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      drawerOpen: false,
      role: 'all',
      selectedRecord: null,
      status: 'all',
      statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    records() {
      return allWithdrawalRequests(this.state);
    },
    visibleRecords() {
      return this.records.filter((item) => {
        const matchesRole = this.role === 'all' || item.role === this.role;
        const matchesStatus = this.status === 'all' || item.status === this.status;
        return matchesRole && matchesStatus;
      });
    },
    metrics() {
      const pending = this.records.filter((item) => item.status === 'pending');
      const processing = this.records.filter((item) => item.status === 'processing');
      const completed = this.records.filter((item) => item.status === 'completed');
      const rejected = this.records.filter((item) => item.status === 'rejected');
      return [
        { label: '待审核', value: `${pending.length} 笔`, help: formatMoney(sumBy(pending, 'amountCents')), icon: Clock3, tone: 'warning' },
        { label: '处理中', value: `${processing.length} 笔`, help: formatMoney(sumBy(processing, 'amountCents')), icon: BadgeCheck, tone: 'primary' },
        { label: '已到账', value: `${completed.length} 笔`, help: formatMoney(sumBy(completed, 'amountCents')), icon: Banknote, tone: 'success' },
        { label: '已驳回', value: `${rejected.length} 笔`, help: formatMoney(sumBy(rejected, 'amountCents')), icon: XCircle, tone: 'danger' },
      ];
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
    async review(record, approved) {
      const action = approved ? '通过' : '驳回';
      try {
        if (!approved) {
          await this.$confirm(`确认驳回提现申请 ${record.id}？`, '驳回提现', {
            confirmButtonText: '确认驳回',
            cancelButtonText: '取消',
            type: 'warning',
          });
        }
        demoStore.commit((draft) => {
          reviewWithdrawal(draft, { id: record.id, approved });
        });
        this.$message.success(`已${action}（演示）`);
      } catch (_error) {
        // Cancel leaves the request unchanged.
      }
    },
  },
};
</script>

<style scoped>
.admin-danger-link {
  color: var(--danger) !important;
}

.admin-muted-text {
  color: var(--text-muted);
  font-size: 12px;
}

.admin-drawer-title { margin: 0; font-size: 18px; }
.admin-drawer-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
