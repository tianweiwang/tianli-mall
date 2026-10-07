<template>
  <div class="assets-page">
    <section class="assets-summary">
      <header><div><span>{{ profile.customer.name }}</span><h1>我的资产</h1></div><WalletCards :size="28" aria-hidden="true" /></header>
      <div class="asset-grid">
        <button v-for="item in summaries" :key="item.key" type="button" :class="{ 'is-active': activeTab === item.key }" @click="activeTab = item.key">
          <span>{{ item.label }}</span><strong>{{ item.value }}</strong><small>{{ item.note }}</small>
        </button>
      </div>
    </section>

    <nav class="asset-tabs" aria-label="资产类型">
      <button v-for="item in tabs" :key="item.key" type="button" :class="{ 'is-active': activeTab === item.key }" @click="activeTab = item.key">{{ item.label }}</button>
    </nav>

    <section v-if="activeTab === 'benefit'" class="assets-list">
      <header><h2>会员权益</h2><span>{{ profile.benefits.length }}项</span></header>
      <article v-for="item in profile.benefits" :key="item.id">
        <div><strong>{{ item.title }}</strong><small>{{ item.id }}</small></div>
        <em>{{ item.status === 'available' ? '可使用' : '已使用' }}</em>
      </article>
      <p v-if="!profile.benefits.length" class="assets-empty">暂无会员权益</p>
    </section>
    <section v-else class="assets-list">
      <header><h2>{{ activeLabel }}流水</h2><span>{{ visibleLedger.length }}笔</span></header>
      <article v-for="item in visibleLedger" :key="item.id">
        <div><strong>{{ item.title }}</strong><small>{{ formatTime(item.at) }} · {{ item.businessId }}</small></div>
        <p><em :class="item.amount > 0 ? 'is-credit' : 'is-debit'">{{ formatAmount(item) }}</em><small>余额 {{ formatBalance(item) }}</small></p>
      </article>
      <p v-if="!visibleLedger.length" class="assets-empty">暂无{{ activeLabel }}流水</p>
    </section>
  </div>
</template>

<script>
import { WalletCards } from 'lucide-vue';
import { formatMoney } from '../../mock/selectors';
import { memberProfileForCustomer } from '../../mock/memberSelectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerAssets',
  components: { WalletCards },
  data() { return { activeTab: 'principal' }; },
  computed: {
    state() { return demoStore.state.data; },
    profile() { return memberProfileForCustomer(this.state, this.state.activeCustomerId); },
    tabs() { return [{ key: 'principal', label: '本金' }, { key: 'points', label: '积分' }, { key: 'subsidy', label: '补贴' }, { key: 'benefit', label: '权益' }]; },
    activeLabel() { return this.tabs.find((item) => item.key === this.activeTab)?.label || ''; },
    summaries() {
      const account = this.profile.account;
      return [
        { key: 'principal', label: '储值本金', value: formatMoney(account.principalBalanceCents), note: `累计储值 ${formatMoney(account.principalRechargedCents)}` },
        { key: 'points', label: '赠送积分', value: `${account.pointsBalance}分`, note: `累计获得 ${account.pointsEarned}` },
        { key: 'subsidy', label: '补贴余额', value: formatMoney(account.subsidyBalanceCents), note: `累计到账 ${formatMoney(account.subsidyReceivedCents)}` },
        { key: 'benefit', label: '会员权益', value: `${this.profile.benefits.length}项`, note: '礼品、项目与代金券' },
      ];
    },
    visibleLedger() { return this.profile.ledger.filter((item) => item.assetType === this.activeTab); },
  },
  methods: {
    formatAmount(item) {
      const prefix = item.amount > 0 ? '+' : '-';
      const amount = Math.abs(item.amount);
      return item.assetType === 'points' ? `${prefix}${amount}分` : `${prefix}${formatMoney(amount)}`;
    },
    formatBalance(item) { return item.assetType === 'points' ? `${item.balanceAfter}分` : formatMoney(item.balanceAfter); },
    formatTime(value) { return value.replace('T', ' ').slice(0, 16); },
  },
};
</script>

<style scoped>
.assets-page { display: grid; gap: 10px; }
.assets-page > section, .asset-tabs { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.assets-summary { padding: 17px; }
.assets-summary header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; color: var(--brand-primary); }
.assets-summary header span { color: var(--text-muted); font-size: 10px; }
.assets-summary h1 { margin: 2px 0 0; color: var(--text-primary); font-size: 18px; }
.asset-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.asset-grid button { min-width: 0; padding: 11px; color: var(--text-primary); background: var(--surface-subtle); border: 1px solid transparent; border-radius: var(--radius-md); cursor: pointer; text-align: left; }
.asset-grid button.is-active { border-color: var(--brand-primary); background: #fff5f4; }
.asset-grid span, .asset-grid strong, .asset-grid small { display: block; overflow-wrap: anywhere; }
.asset-grid span, .asset-grid small { color: var(--text-muted); font-size: 10px; }
.asset-grid strong { margin: 3px 0; font-size: 16px; }
.asset-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); padding: 4px; }
.asset-tabs button { min-height: 36px; color: var(--text-regular); background: transparent; border: 0; border-radius: var(--radius-sm); cursor: pointer; font-size: 12px; }
.asset-tabs button.is-active { color: #fff; background: var(--brand-primary); font-weight: 600; }
.assets-list { padding: 0 14px; }
.assets-list header, .assets-list article { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.assets-list header { min-height: 48px; }
.assets-list header h2 { margin: 0; font-size: 14px; }
.assets-list header span { color: var(--text-muted); font-size: 10px; }
.assets-list article { min-height: 64px; border-top: 1px solid var(--border); }
.assets-list article strong, .assets-list article small, .assets-list article em { display: block; }
.assets-list article strong { font-size: 12px; }
.assets-list article small { max-width: 230px; color: var(--text-muted); overflow-wrap: anywhere; font-size: 9px; }
.assets-list article p { margin: 0; text-align: right; }
.assets-list article em { color: var(--success); font-size: 12px; font-style: normal; font-weight: 600; }
.assets-list article em.is-debit { color: var(--text-primary); }
.assets-empty { margin: 0; padding: 28px 0; color: var(--text-muted); text-align: center; font-size: 11px; border-top: 1px solid var(--border); }
</style>
