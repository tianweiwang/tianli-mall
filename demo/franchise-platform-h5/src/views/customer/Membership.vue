<template>
  <div class="membership-page">
    <section class="membership-hero">
      <div>
        <span>{{ profile.customer.name }}</span>
        <h1>{{ membershipTitle }}</h1>
        <p>服务项目与商品分别按已开通方案计算会员价</p>
      </div>
      <Crown :size="34" aria-hidden="true" />
    </section>

    <section class="membership-plan membership-plan--card">
      <header><div><span>会员卡</span><h2>510元会员卡</h2></div><strong>¥510</strong></header>
      <ul>
        <li><Gift :size="16" aria-hidden="true" />398元入会礼品</li>
        <li><Sparkles :size="16" aria-hidden="true" />600元店内项目权益</li>
        <li><BadgePercent :size="16" aria-hidden="true" />服务6.6折 · 商品8.5折</li>
        <li><Ticket :size="16" aria-hidden="true" />100元服务券2张 · 50元商品券3张</li>
      </ul>
      <button type="button" :disabled="busy || benefits.hasMembership" @click="purchase('membership-510')">
        <Check v-if="benefits.hasMembership" :size="17" aria-hidden="true" />
        {{ benefits.hasMembership ? '已开通' : '模拟支付510元开通' }}
      </button>
    </section>

    <section class="membership-plan membership-plan--stored">
      <header><div><span>储值方案</span><h2>5100元储值方案</h2></div><strong>¥5,100</strong></header>
      <div class="stored-value-split">
        <div><span>储值本金</span><strong>¥5,100</strong><small>服务项目享6.6折</small></div>
        <div><span>赠送积分</span><strong>10,200</strong><small>按原价1元=1积分</small></div>
      </div>
      <p>赠送积分不享6.6折，本方案不自动赠送会员卡礼品与代金券。</p>
      <button type="button" :disabled="busy" @click="purchase('stored-value-5100')">
        <WalletCards :size="17" aria-hidden="true" />{{ benefits.hasStoredValue ? '再次储值5100元' : '模拟储值5100元' }}
      </button>
    </section>

    <section v-if="profile.benefits.length" class="membership-benefits">
      <header><h2>已到账权益</h2><router-link :to="assetsPath">查看全部</router-link></header>
      <div class="benefit-grid">
        <article v-for="benefit in profile.benefits" :key="benefit.id">
          <span>{{ benefit.title }}</span><strong>{{ benefitStatus(benefit.status) }}</strong>
        </article>
      </div>
    </section>
  </div>
</template>

<script>
import {
  BadgePercent,
  Check,
  Crown,
  Gift,
  Sparkles,
  Ticket,
  WalletCards,
} from 'lucide-vue';
import { memberProfileForCustomer } from '../../mock/memberSelectors';
import { purchaseMembership, rechargeStoredValue } from '../../mock/memberTransitions';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerMembership',
  components: { BadgePercent, Check, Crown, Gift, Sparkles, Ticket, WalletCards },
  data() {
    return { busy: false };
  },
  computed: {
    state() { return demoStore.state.data; },
    profile() { return memberProfileForCustomer(this.state, this.state.activeCustomerId); },
    benefits() { return this.profile.effectiveBenefits; },
    membershipTitle() {
      if (this.benefits.hasMembership && this.benefits.hasStoredValue) return '双重权益会员';
      if (this.benefits.hasMembership) return '510会员';
      if (this.benefits.hasStoredValue) return '储值会员';
      return '普通客户';
    },
    assetsPath() { return `/c/store/${this.$route.params.storeId}/assets`; },
  },
  methods: {
    benefitStatus(status) { return status === 'available' ? '可使用' : '已使用'; },
    async purchase(planType) {
      if (this.busy || (planType === 'membership-510' && this.benefits.hasMembership)) return;
      const isCard = planType === 'membership-510';
      try {
        await this.$confirm(
          isCard ? '确认模拟支付510元开通会员卡？' : '确认模拟支付5100元完成储值？',
          isCard ? '开通会员' : '确认储值',
          { confirmButtonText: '确认支付', cancelButtonText: '取消', type: 'warning' },
        );
        this.busy = true;
        demoStore.commit((draft) => {
          const input = {
            customerId: draft.activeCustomerId,
            paymentAttemptId: `${isCard ? 'member' : 'recharge'}-${draft.sequence.membership + 1}`,
          };
          if (isCard) purchaseMembership(draft, input);
          else rechargeStoredValue(draft, input);
        });
        this.$message.success(isCard ? '会员卡已开通，权益已到账' : '储值本金与赠送积分已到账');
      } catch (error) {
        if (error?.message) this.$message.error(error.message);
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.membership-page { display: grid; gap: 10px; }
.membership-page > section { border: 1px solid var(--border); border-radius: var(--radius-lg); }
.membership-hero { display: flex; min-height: 132px; align-items: center; justify-content: space-between; padding: 20px; color: #fff; background: #21352e; }
.membership-hero span { font-size: 12px; opacity: .8; }
.membership-hero h1 { margin: 4px 0; font-size: 23px; }
.membership-hero p { margin: 0; font-size: 11px; opacity: .75; }
.membership-plan, .membership-benefits { padding: 15px; background: var(--surface); }
.membership-plan header, .membership-benefits header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.membership-plan header span { color: var(--text-muted); font-size: 10px; }
.membership-plan h2, .membership-benefits h2 { margin: 2px 0 0; font-size: 16px; }
.membership-plan header > strong { color: var(--brand-primary); font-size: 22px; }
.membership-plan ul { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin: 15px 0; padding: 0; list-style: none; }
.membership-plan li { display: flex; align-items: center; gap: 6px; color: var(--text-regular); font-size: 11px; }
.membership-plan li svg { color: var(--brand-primary); }
.membership-plan button { display: flex; width: 100%; min-height: 44px; align-items: center; justify-content: center; gap: 7px; color: #fff; background: var(--brand-primary); border: 0; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; }
.membership-plan button:disabled { color: var(--text-muted); background: var(--surface-subtle); cursor: default; }
.membership-plan--stored { border-color: #d9c6a2 !important; }
.stored-value-split { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 14px 0 9px; }
.stored-value-split div { padding: 11px; background: var(--surface-subtle); border-radius: var(--radius-md); }
.stored-value-split span, .stored-value-split small { display: block; color: var(--text-muted); font-size: 10px; }
.stored-value-split strong { display: block; margin: 3px 0; font-size: 17px; }
.membership-plan > p { margin: 0 0 13px; color: var(--text-muted); font-size: 10px; }
.membership-benefits header { align-items: center; margin-bottom: 12px; }
.membership-benefits header a { color: var(--brand-primary); font-size: 11px; }
.benefit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.benefit-grid article { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px; background: var(--surface-subtle); border-radius: var(--radius-md); }
.benefit-grid span { overflow-wrap: anywhere; font-size: 11px; }
.benefit-grid strong { flex: 0 0 auto; color: var(--success); font-size: 10px; }
</style>
