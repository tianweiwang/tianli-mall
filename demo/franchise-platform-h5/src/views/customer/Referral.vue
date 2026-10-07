<template>
  <div class="referral-page">
    <section class="referral-hero">
      <div>
        <span>我的推荐码</span>
        <strong data-testid="referral-code">{{ referral.code }}</strong>
      </div>
      <button type="button" @click="copyLink">
        <Copy :size="17" aria-hidden="true" />复制邀请链接
      </button>
    </section>

    <section class="referral-card">
      <header><Megaphone :size="18" aria-hidden="true" /><h2>推荐有礼</h2></header>
      <p>邀请好友注册并完成首单，每单奖励 <strong>{{ formatMoney(referral.rewardCents) }}</strong>（演示数据）。</p>
      <QrCode
        :value="referral.inviteUrl"
        label="好友扫码即可进入门店（演示）"
        :download-name="`referral-${referral.code}.png`"
      />
      <div class="referral-stats">
        <div><strong>{{ referral.invitedCount }}</strong><span>已邀请</span></div>
        <div><strong>{{ referral.rewardedCount }}</strong><span>已首单</span></div>
        <div><strong>{{ formatMoney(referral.rewardPaidCents) }}</strong><span>已发奖励</span></div>
      </div>
    </section>

    <section class="referral-card">
      <header><UserPlus :size="18" aria-hidden="true" /><h2>模拟邀请好友</h2></header>
      <div class="referral-form">
        <label>好友姓名<input v-model="name" type="text" aria-label="好友姓名" placeholder="例如：赵女士"></label>
        <label>好友手机<input v-model="phone" type="text" aria-label="好友手机" placeholder="138****0000"></label>
        <button type="button" :disabled="!name.trim() || !phone.trim()" @click="invite">
          <UserPlus :size="16" aria-hidden="true" />添加邀请记录
        </button>
      </div>
    </section>

    <section class="referral-card">
      <header><Users :size="18" aria-hidden="true" /><h2>邀请记录</h2></header>
      <div class="referral-list">
        <div v-for="item in state.referrals" :key="item.id" class="referral-item">
          <div class="referral-avatar"><UserRound :size="17" aria-hidden="true" /></div>
          <div>
            <strong>{{ item.name }}　{{ item.phone }}</strong>
            <small>{{ formatTime(item.invitedAt) }}</small>
          </div>
          <span :class="['referral-status', `is-${item.status}`]">{{ statusText(item.status) }}</span>
          <em v-if="item.rewardCents > 0">+{{ formatMoney(item.rewardCents) }}</em>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import { Copy, Megaphone, UserPlus, Users, UserRound } from 'lucide-vue';
import QrCode from '../../components/qr/QrCode.vue';
import { referralOverview } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { addReferral } from '../../mock/transitions';

const statusLabels = {
  pending: '待首单',
  ordered: '已下单',
  rewarded: '已奖励',
};

export default {
  name: 'CustomerReferral',
  components: { Copy, Megaphone, QrCode, UserPlus, Users, UserRound },
  data() {
    return {
      name: '',
      phone: '',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    referral() {
      return referralOverview(this.state);
    },
  },
  methods: {
    formatMoney(cents) {
      return `¥${(Number(cents) / 100).toFixed(2)}`;
    },
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    async copyLink() {
      try {
        await navigator.clipboard.writeText(this.referral.inviteUrl);
        this.$message.success('邀请链接已复制');
      } catch (_error) {
        this.$message.success(`邀请链接：${this.referral.inviteUrl}`);
      }
    },
    invite() {
      demoStore.commit((draft) => {
        addReferral(draft, { name: this.name.trim(), phone: this.phone.trim() });
      });
      this.$message.success('已添加邀请记录');
      this.name = '';
      this.phone = '';
    },
  },
};
</script>

<style scoped>
.referral-page {
  display: grid;
  gap: 10px;
}

.referral-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 16px;
  color: #fff;
  background: #2d3038;
  border-radius: var(--radius-lg);
}

.referral-hero span,
.referral-hero strong {
  display: block;
}

.referral-hero span {
  color: #c8ccd3;
  font-size: 11px;
}

.referral-hero strong {
  margin-top: 4px;
  font-size: 26px;
  letter-spacing: 2px;
}

.referral-hero button {
  display: flex;
  min-height: 36px;
  align-items: center;
  gap: 6px;
  padding: 0 12px;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-size: 11px;
  cursor: pointer;
}

.referral-card {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.referral-card > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 10px;
  color: var(--brand-primary);
}

.referral-card h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.referral-card > p {
  margin: 0 0 12px;
  color: var(--text-regular);
  font-size: 12px;
  line-height: 1.6;
}

.referral-card > p strong {
  color: var(--brand-primary);
}

.referral-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.referral-stats div {
  text-align: center;
}

.referral-stats strong,
.referral-stats span {
  display: block;
}

.referral-stats strong {
  font-size: 18px;
}

.referral-stats span {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.referral-form {
  display: grid;
  gap: 8px;
}

.referral-form label {
  display: grid;
  min-height: 38px;
  grid-template-columns: 62px minmax(0, 1fr);
  align-items: center;
  color: var(--text-regular);
  border-bottom: 1px solid var(--border);
  font-size: 12px;
}

.referral-form input {
  width: 100%;
  min-width: 0;
  height: 34px;
  text-align: right;
  background: transparent;
  border: 0;
  outline: 0;
}

.referral-form button {
  display: flex;
  min-height: 38px;
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

.referral-form button:disabled {
  background: #c8c9cc;
  cursor: not-allowed;
}

.referral-list {
  display: grid;
}

.referral-item {
  display: grid;
  min-height: 58px;
  grid-template-columns: 34px minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 8px;
  border-top: 1px solid var(--border);
}

.referral-item:first-child {
  border-top: 0;
}

.referral-avatar {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  color: var(--brand-primary);
  background: #fff5f4;
  border-radius: 50%;
}

.referral-item strong,
.referral-item small {
  display: block;
}

.referral-item strong {
  font-size: 12px;
}

.referral-item small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.referral-status {
  padding: 2px 8px;
  color: var(--text-muted);
  background: #f2f4f7;
  border-radius: 999px;
  font-size: 10px;
}

.referral-status.is-rewarded {
  color: var(--success);
  background: #edf9f2;
}

.referral-item em {
  color: var(--success);
  font-size: 11px;
  font-style: normal;
}
</style>
