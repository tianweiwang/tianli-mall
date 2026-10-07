<template>
  <div class="distribution-page">
    <section class="distributor-hero">
      <div class="distributor-avatar"><UserRound :size="26" aria-hidden="true" /></div>
      <div>
        <span>分销员档案</span>
        <strong data-testid="distributor-name">{{ overview.account.name }} · {{ overview.account.level }}</strong>
        <small>分销编号 {{ overview.account.id }} · 佣金比例 {{ overview.account.commissionRate }}%</small>
      </div>
    </section>

    <section class="distributor-metrics">
      <div><span>累计佣金</span><strong>{{ formatMoney(overview.account.totalCommissionCents) }}</strong></div>
      <div><span>已结算</span><strong>{{ formatMoney(overview.account.settledCents) }}</strong></div>
      <div><span>待结算</span><strong>{{ formatMoney(overview.account.pendingCents) }}</strong></div>
      <div class="is-accent"><span>可提现</span><strong data-testid="distributor-withdrawable">{{ formatMoney(overview.account.withdrawableCents) }}</strong></div>
    </section>

    <section class="distributor-card">
      <header><Wallet :size="18" aria-hidden="true" /><h2>申请提现（演示）</h2></header>
      <div class="withdraw-form">
        <label>提现金额
          <input v-model.number="amount" type="number" min="1" aria-label="提现金额" placeholder="0.00">
        </label>
        <label>到账渠道
          <select v-model="channel" aria-label="到账渠道">
            <option value="bank">银行卡</option>
            <option value="alipay">支付宝</option>
          </select>
        </label>
        <label>收款账号
          <input v-model="accountNo" type="text" aria-label="收款账号" placeholder="请输入演示账号">
        </label>
        <button type="button" :disabled="!canWithdraw" @click="withdraw">
          <Send :size="16" aria-hidden="true" />提交提现申请
        </button>
      </div>
    </section>

    <router-link :to="teamPath" class="distributor-card team-card" aria-label="查看我的团队详情">
      <header>
        <Users :size="18" aria-hidden="true" />
        <h2>我的团队</h2>
        <ChevronRight :size="18" aria-hidden="true" />
      </header>
      <div class="team-row">
        <div><strong>{{ overview.teamCount }}</strong><span>邀请好友</span></div>
        <div><strong>{{ referral.rewardedCount }}</strong><span>已产生首单</span></div>
        <div><strong>{{ referral.rewardPaidCents > 0 ? formatMoney(referral.rewardPaidCents) : '¥0.00' }}</strong><span>推荐奖励</span></div>
      </div>
    </router-link>

    <section class="distributor-card">
      <header><ReceiptText :size="18" aria-hidden="true" /><h2>佣金明细</h2></header>
      <div class="commission-list">
        <div v-for="record in overview.records" :key="record.id" class="commission-item">
          <div>
            <strong>{{ record.customerName }} · {{ record.orderId }}</strong>
            <small>{{ formatTime(record.at) }}</small>
          </div>
          <em>+{{ formatMoney(record.amountCents) }}</em>
          <span :class="['commission-status', `is-${record.status}`]">{{ record.status === 'settled' ? '已结算' : '待结算' }}</span>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import { ChevronRight, ReceiptText, Send, UserRound, Users, Wallet } from 'lucide-vue';
import { distributorOverview, referralOverview } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { applyWithdrawal } from '../../mock/transitions';

export default {
  name: 'CustomerDistribution',
  components: { ChevronRight, ReceiptText, Send, UserRound, Users, Wallet },
  data() {
    return {
      amount: null,
      channel: 'bank',
      accountNo: '',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return distributorOverview(this.state);
    },
    referral() {
      return referralOverview(this.state);
    },
    teamPath() {
      return `/c/store/${this.state.activeStoreId}/referral`;
    },
    canWithdraw() {
      return Number(this.amount) > 0 && this.accountNo.trim().length >= 4;
    },
  },
  methods: {
    formatMoney(cents) {
      return `¥${(Number(cents) / 100).toFixed(2)}`;
    },
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    withdraw() {
      const amountCents = Math.round(Number(this.amount) * 100);
      try {
        demoStore.commit((draft) => {
          applyWithdrawal(draft, {
            role: 'distributor',
            distributorId: draft.distributor.id,
            applicantName: draft.distributor.name,
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
.distribution-page {
  display: grid;
  gap: 10px;
}

.distributor-hero {
  display: grid;
  grid-template-columns: 52px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  padding: 16px;
  color: #fff;
  background: linear-gradient(135deg, #2d3038, #454a55);
  border-radius: var(--radius-lg);
}

.distributor-avatar {
  display: grid;
  width: 50px;
  height: 50px;
  place-items: center;
  color: #ffe6e0;
  background: var(--brand-primary);
  border-radius: 50%;
}

.distributor-hero span,
.distributor-hero strong,
.distributor-hero small {
  display: block;
}

.distributor-hero span,
.distributor-hero small {
  color: #c8ccd3;
}

.distributor-hero strong {
  margin: 3px 0;
  font-size: 16px;
}

.distributor-hero small {
  font-size: 10px;
}

.distributor-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  padding: 14px 4px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.distributor-metrics div {
  text-align: center;
  border-right: 1px solid var(--border);
}

.distributor-metrics div:last-child {
  border-right: 0;
}

.distributor-metrics div.is-accent strong {
  color: var(--brand-primary);
}

.distributor-metrics span,
.distributor-metrics strong {
  display: block;
}

.distributor-metrics span {
  color: var(--text-muted);
  font-size: 10px;
}

.distributor-metrics strong {
  margin-top: 4px;
  font-size: 15px;
}

.distributor-card {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.distributor-card > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 10px;
  color: var(--brand-primary);
}

.distributor-card h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.team-card {
  color: inherit;
}

.team-card > header svg:last-child {
  margin-left: auto;
  color: var(--text-muted);
}

.withdraw-form {
  display: grid;
  gap: 8px;
}

.withdraw-form label {
  display: grid;
  min-height: 38px;
  grid-template-columns: 70px minmax(0, 1fr);
  align-items: center;
  color: var(--text-regular);
  border-bottom: 1px solid var(--border);
  font-size: 12px;
}

.withdraw-form input,
.withdraw-form select {
  width: 100%;
  min-width: 0;
  height: 34px;
  text-align: right;
  background: transparent;
  border: 0;
  outline: 0;
}

.withdraw-form select {
  direction: rtl;
}

.withdraw-form button {
  display: flex;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-size: 12px;
  cursor: pointer;
}

.withdraw-form button:disabled {
  background: #c8c9cc;
  cursor: not-allowed;
}

.team-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.team-row div {
  text-align: center;
}

.team-row strong,
.team-row span {
  display: block;
}

.team-row strong {
  font-size: 18px;
}

.team-row span {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.commission-list {
  display: grid;
}

.commission-item {
  display: grid;
  min-height: 58px;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 10px;
  border-top: 1px solid var(--border);
}

.commission-item:first-child {
  border-top: 0;
}

.commission-item strong,
.commission-item small {
  display: block;
}

.commission-item strong {
  font-size: 12px;
}

.commission-item small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.commission-item em {
  color: var(--success);
  font-size: 12px;
  font-style: normal;
}

.commission-status {
  padding: 2px 8px;
  color: var(--text-muted);
  background: #f2f4f7;
  border-radius: 999px;
  font-size: 10px;
}

.commission-status.is-settled {
  color: var(--success);
  background: #edf9f2;
}
</style>
