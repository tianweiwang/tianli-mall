<template>
  <div class="points-page">
    <section class="points-hero">
      <span>当前积分</span>
      <strong data-testid="points-balance">{{ overview.balance }}</strong>
      <small>累计获得 {{ overview.totalEarned }} · 累计使用 {{ overview.totalSpent }}</small>
    </section>

    <section class="points-section">
      <header><Coins :size="18" aria-hidden="true" /><h2>积分规则</h2></header>
      <ul class="points-rules">
        <li><span>下单奖励</span><strong>每实付 ¥1 得 1 积分</strong></li>
        <li><span>预约奖励</span><strong>每次预约到店 +100 积分</strong></li>
        <li><span>使用规则</span><strong>积分可在兑换区抵扣演示优惠券</strong></li>
      </ul>
    </section>

    <section class="points-section">
      <header><Gift :size="18" aria-hidden="true" /><h2>积分兑换（演示）</h2></header>
      <div class="points-redeem-list">
        <div v-for="item in redeemItems" :key="item.points" class="points-redeem-item">
          <div>
            <strong>{{ item.title }}</strong>
            <span>{{ item.desc }}</span>
          </div>
          <button type="button" @click="redeem(item)">
            {{ item.points }} 分兑换
          </button>
        </div>
      </div>
    </section>

    <section class="points-section">
      <header><ReceiptText :size="18" aria-hidden="true" /><h2>积分明细</h2></header>
      <div class="points-ledger">
        <div v-for="entry in overview.ledger" :key="entry.id" class="points-ledger-item">
          <span :class="['points-ledger-dot', entry.amount < 0 ? 'is-spend' : 'is-earn']" />
          <div>
            <strong>{{ entry.title }}</strong>
            <small>{{ formatTime(entry.at) }}</small>
          </div>
          <em :class="entry.amount < 0 ? 'is-spend' : 'is-earn'">
            {{ entry.amount > 0 ? `+${entry.amount}` : entry.amount }}
          </em>
          <span class="points-ledger-after">余额 {{ entry.balanceAfter }}</span>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import { Coins, Gift, ReceiptText } from 'lucide-vue';
import { demoStore } from '../../mock/store';
import { redeemPoints } from '../../mock/transitions';
import { pointsOverview } from '../../mock/selectors';

const redeemItems = [
  { title: '10 元无门槛券', desc: '下单直接抵扣，演示券不真实发放', points: 100 },
  { title: '20 元满减券', desc: '满 299 可用，演示券不真实发放', points: 180 },
  { title: '检测加项券', desc: '到店检测时增加一个演示指标项', points: 300 },
];

export default {
  name: 'CustomerPoints',
  components: { Coins, Gift, ReceiptText },
  data() {
    return { redeemItems };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return pointsOverview(this.state);
    },
  },
  methods: {
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    redeem(item) {
      try {
        demoStore.commit((draft) => {
          redeemPoints(draft, { title: item.title, points: item.points });
        });
        this.$message.success(`已兑换「${item.title}」`);
      } catch (error) {
        this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.points-page {
  display: grid;
  gap: 10px;
}

.points-hero {
  display: grid;
  justify-items: center;
  gap: 3px;
  padding: 24px 16px;
  color: #fff;
  background: linear-gradient(135deg, #d9543c, #b93a28);
  border-radius: var(--radius-lg);
}

.points-hero span,
.points-hero small {
  color: #ffe6e0;
}

.points-hero strong {
  margin: 4px 0;
  font-size: 38px;
  letter-spacing: 1px;
}

.points-hero small {
  font-size: 11px;
}

.points-section {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.points-section > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 10px;
  color: var(--brand-primary);
}

.points-section h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.points-rules {
  margin: 0;
  padding: 0;
  list-style: none;
}

.points-rules li {
  display: grid;
  min-height: 38px;
  grid-template-columns: 72px minmax(0, 1fr);
  align-items: center;
  color: var(--text-muted);
  border-top: 1px solid var(--border);
  font-size: 11px;
}

.points-rules li:first-child {
  border-top: 0;
}

.points-rules strong {
  color: var(--text-regular);
  text-align: right;
  font-size: 12px;
}

.points-redeem-list {
  display: grid;
  gap: 8px;
}

.points-redeem-item {
  display: grid;
  min-height: 54px;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  background: var(--surface-subtle);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.points-redeem-item strong,
.points-redeem-item span {
  display: block;
}

.points-redeem-item strong {
  font-size: 13px;
}

.points-redeem-item span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 10px;
}

.points-redeem-item button {
  min-height: 34px;
  padding: 0 12px;
  color: var(--brand-primary);
  background: #fff5f4;
  border: 1px solid var(--brand-primary);
  border-radius: var(--radius-md);
  font-size: 11px;
  cursor: pointer;
}

.points-ledger {
  display: grid;
}

.points-ledger-item {
  display: grid;
  min-height: 58px;
  grid-template-columns: 8px minmax(0, 1fr) auto 58px;
  align-items: center;
  gap: 8px;
  border-top: 1px solid var(--border);
}

.points-ledger-item:first-child {
  border-top: 0;
}

.points-ledger-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.points-ledger-dot.is-earn {
  background: var(--success);
}

.points-ledger-dot.is-spend {
  background: var(--danger);
}

.points-ledger-item strong,
.points-ledger-item small {
  display: block;
}

.points-ledger-item strong {
  font-size: 12px;
}

.points-ledger-item small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.points-ledger-item em {
  font-size: 13px;
  font-style: normal;
}

.points-ledger-item em.is-earn {
  color: var(--success);
}

.points-ledger-item em.is-spend {
  color: var(--danger);
}

.points-ledger-after {
  color: var(--text-muted);
  text-align: right;
  font-size: 10px;
}
</style>
