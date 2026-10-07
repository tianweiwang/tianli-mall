<template>
  <div v-if="record" class="admin-page brand-sharing-detail">
    <section class="brand-sharing-detail__heading">
      <router-link to="/brand/sharing" aria-label="返回分账流水"><ArrowLeft :size="19" /></router-link>
      <div><h1>{{ record.id }}</h1><p>{{ storeName(record.storeId) }} · 业务 {{ record.orderId || record.businessId }}</p></div>
      <AdminStatusTag :status="record.status" :text="statusText(record.status)" />
    </section>

    <AdminMetricStrip :items="metrics" />

    <div class="brand-sharing-detail__grid">
      <section class="admin-panel">
        <header class="admin-panel__header"><div><h2>资金拆分</h2><p>{{ record.settlementSnapshot ? '特色与经典项目模拟品牌分账' : '门店收入与品牌抽成' }}</p></div></header>
        <div class="admin-panel__body">
          <dl class="admin-detail-list brand-sharing-detail__money">
            <div><dt>订单实付</dt><dd>{{ formatMoney(record.paidCents) }}</dd></div>
            <div><dt>门店预计收入</dt><dd class="admin-value-positive">{{ formatMoney(record.storeIncomeCents) }}</dd></div>
            <div><dt>{{ record.settlementSnapshot ? '集团补贴资金池入账' : '品牌抽成' }}</dt><dd>{{ formatMoney(record.brandCommissionCents) }}</dd></div>
            <div v-if="record.settlementSnapshot"><dt>区域代理 {{ record.settlementSnapshot.regionalAgentRate }}%</dt><dd>{{ formatMoney(record.regionalAgentIncomeCents) }}</dd></div>
            <div v-if="record.settlementSnapshot"><dt>代理归属</dt><dd><router-link v-if="record.regionalAgentId" :to="`/brand/agents/${record.regionalAgentId}`" class="admin-link-button">{{ record.regionalAgentName }}</router-link><span v-else>集团直营</span></dd></div>
            <div v-if="!record.settlementSnapshot"><dt>个人分享佣金</dt><dd>{{ formatMoney(record.distributionCostCents) }}</dd></div>
          </dl>
          <p v-if="record.settlementSnapshot" class="brand-sharing-detail__equation">{{ formatMoney(record.storeIncomeCents) }} + {{ formatMoney(record.brandCommissionCents) }} + {{ formatMoney(record.regionalAgentIncomeCents) }} = {{ formatMoney(record.paidCents) }}</p>
          <p v-else class="brand-sharing-detail__equation">{{ formatMoney(record.storeIncomeCents) }} + {{ formatMoney(record.brandCommissionCents) }} = {{ formatMoney(record.paidCents) }}</p>
          <p v-if="record.settlementSnapshot" class="brand-sharing-detail__simulation">模拟品牌分账，正式上线以微信获批最大分账比例为准。集团{{ record.settlementSnapshot.groupRate }}%进入公共补贴资金池，不代表单笔收入已覆盖客户完整100期补贴。</p>
          <div v-if="commission" class="brand-sharing-detail__commission">
            <h3>分享归因佣金</h3>
            <p>个人分享佣金由集团推广预算承担，不进入区域代理分账或客户补贴资金池。</p>
            <dl class="admin-detail-list">
              <div><dt>分销员</dt><dd>{{ distributorName }}</dd></div>
              <div><dt>佣金比例</dt><dd>{{ commission.commissionRate }}%</dd></div>
              <div><dt>佣金金额</dt><dd class="admin-value-positive">{{ formatMoney(commission.amountCents) }}</dd></div>
              <div><dt>佣金状态</dt><dd>{{ commissionStatusText }}</dd></div>
            </dl>
          </div>
        </div>
      </section>

      <section class="admin-panel">
        <header class="admin-panel__header"><div><h2>状态时间线</h2><p>本地模拟分账记录</p></div></header>
        <div class="admin-panel__body brand-sharing-detail__timeline">
          <div v-for="(item, index) in record.timeline" :key="`${item.status}-${index}`">
            <span />
            <p><strong>{{ statusText(item.status) }}</strong><small>{{ formatTime(item.at) }}</small></p>
          </div>
        </div>
      </section>
    </div>

    <section v-if="nextActions.length" class="admin-panel brand-sharing-detail__actions">
      <div><h2>模拟状态推进</h2><p>该操作只修改当前浏览器中的演示数据</p></div>
      <div>
        <el-button
          v-for="action in nextActions"
          :key="action.status"
          :type="action.type"
          @click="confirmAdvance(action)"
        >{{ action.label }}</el-button>
      </div>
    </section>
  </div>
  <div v-else class="admin-page">
    <section class="admin-panel admin-empty">未找到分账记录</section>
  </div>
</template>

<script>
import {
  ArrowLeft,
  BadgeDollarSign,
  CircleDollarSign,
  HandCoins,
  WalletCards,
} from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { commissionForOrder, formatMoney } from '../../mock/selectors';
import { advanceSharing } from '../../mock/transitions';

const statusLabels = {
  pending: '待分账',
  processing: '处理中',
  completed: '已完成',
  failed: '分账失败',
  returning: '回退中',
  returned: '已回退',
};

const actionsByStatus = {
  pending: [{ status: 'processing', label: '开始分账', type: 'primary' }],
  processing: [
    { status: 'completed', label: '确认完成', type: 'primary' },
    { status: 'failed', label: '模拟失败', type: 'danger' },
    { status: 'returning', label: '发起回退', type: 'danger' },
  ],
  failed: [{ status: 'processing', label: '重新处理', type: 'primary' }],
  completed: [{ status: 'returning', label: '发起回退', type: 'danger' }],
  returning: [{ status: 'returned', label: '确认回退', type: 'primary' }],
  returned: [],
};

export default {
  name: 'BrandProfitSharingDetail',
  components: { AdminMetricStrip, AdminStatusTag, ArrowLeft },
  computed: {
    state() {
      return demoStore.state.data;
    },
    record() {
      return this.state.profitSharingRecords.find(
        (item) => item.id === this.$route.params.recordId,
      );
    },
    commission() {
      return commissionForOrder(this.state, this.record.orderId);
    },
    commissionStatusText() {
      return this.commission?.status === 'settled' ? '已结算' : '待结算';
    },
    distributorName() {
      return this.state.distributor?.id === this.commission?.distributorId
        ? this.state.distributor.name
        : this.commission?.distributorId;
    },
    metrics() {
      return [
        { label: '订单实付', value: formatMoney(this.record.paidCents), help: this.record.orderId, icon: CircleDollarSign, tone: 'primary' },
        { label: '门店预计收入', value: formatMoney(this.record.storeIncomeCents), help: '不扣减分销成本', icon: WalletCards, tone: 'success' },
        { label: this.record.settlementSnapshot ? '集团资金' : '品牌抽成', value: formatMoney(this.record.brandCommissionCents), help: this.record.settlementSnapshot ? '进入集团公共补贴资金池' : '按门店协议比例', icon: BadgeDollarSign, tone: 'warning' },
        { label: this.record.settlementSnapshot ? '区域代理收益' : '个人推广预算', value: formatMoney(this.record.settlementSnapshot ? this.record.regionalAgentIncomeCents : this.record.distributionCostCents), help: this.record.settlementSnapshot ? `${this.record.settlementSnapshot.regionalAgentRate}% 代理快照` : '集团另行承担', icon: HandCoins, tone: 'success' },
      ];
    },
    nextActions() {
      return actionsByStatus[this.record.status] || [];
    },
  },
  methods: {
    formatMoney,
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
    confirmAdvance(action) {
      this.$confirm(
        `确认将分账状态推进为“${action.label}”吗？`,
        '模拟分账确认',
        { confirmButtonText: '确认推进', cancelButtonText: '取消', type: 'warning' },
      ).then(() => {
        demoStore.commit((draft) => {
          advanceSharing(draft, this.record.id, action.status);
        });
        this.$message.success('分账状态已更新');
      }).catch(() => {});
    },
  },
};
</script>

<style scoped>
.brand-sharing-detail__heading {
  display: flex;
  min-height: 58px;
  align-items: center;
  gap: 13px;
  padding: 0 4px;
}

.brand-sharing-detail__heading > a {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  color: var(--text-regular);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.brand-sharing-detail__heading h1,
.brand-sharing-detail__heading p {
  margin: 0;
}

.brand-sharing-detail__heading h1 {
  font-size: 18px;
}

.brand-sharing-detail__heading p {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 11px;
}

.brand-sharing-detail__heading .admin-status-tag {
  margin-left: auto;
}

.brand-sharing-detail__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(340px, 0.8fr);
  gap: 16px;
}

.brand-sharing-detail__money div {
  padding: 7px 0;
  border-bottom: 1px solid var(--border);
}

.brand-sharing-detail__equation {
  margin: 16px 0 0;
  padding: 11px;
  color: #2268b9;
  background: #eef6ff;
  border: 1px solid #d5e8ff;
  border-radius: var(--radius-md);
  text-align: center;
  font-size: 12px;
}

.brand-sharing-detail__commission {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.brand-sharing-detail__commission h3 {
  margin: 0 0 8px;
  font-size: 13px;
}

.brand-sharing-detail__commission > p,
.brand-sharing-detail__simulation {
  margin: 10px 0 0;
  color: var(--text-muted);
  font-size: 11px;
  line-height: 1.6;
}

.brand-sharing-detail__simulation {
  padding: 10px 12px;
  color: #7a5813;
  background: #fff8e8;
  border: 1px solid #f0d99d;
  border-radius: var(--radius-md);
}

.brand-sharing-detail__timeline {
  display: grid;
  gap: 0;
}

.brand-sharing-detail__timeline > div {
  display: grid;
  min-height: 52px;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 8px;
}

.brand-sharing-detail__timeline > div > span {
  position: relative;
  display: block;
  width: 8px;
  height: 8px;
  margin-top: 7px;
  background: var(--admin-primary);
  border-radius: 50%;
}

.brand-sharing-detail__timeline > div:not(:last-child) > span::after {
  position: absolute;
  top: 10px;
  left: 3px;
  width: 1px;
  height: 35px;
  background: var(--border-strong);
  content: '';
}

.brand-sharing-detail__timeline p,
.brand-sharing-detail__timeline strong,
.brand-sharing-detail__timeline small {
  display: block;
  margin: 0;
}

.brand-sharing-detail__timeline strong {
  font-size: 12px;
}

.brand-sharing-detail__timeline small {
  color: var(--text-muted);
  font-size: 10px;
}

.brand-sharing-detail__actions {
  display: flex;
  min-height: 82px;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 16px 18px;
}

.brand-sharing-detail__actions h2,
.brand-sharing-detail__actions p {
  margin: 0;
}

.brand-sharing-detail__actions h2 {
  font-size: 15px;
}

.brand-sharing-detail__actions p {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}
</style>
