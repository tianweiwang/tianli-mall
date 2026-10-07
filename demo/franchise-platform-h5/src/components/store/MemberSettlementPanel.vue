<template>
  <section v-if="booking" class="member-settlement-panel">
    <header><div><span>会员项目</span><h3>项目结算</h3></div><AdminStatusTag :status="booking.memberSettlement ? 'completed' : booking.status" :text="settlementStatus" /></header>
    <div v-if="booking.memberSettlement" class="settlement-result">
      <CheckCircle2 :size="25" aria-hidden="true" />
      <div><strong>结算完成</strong><span>{{ sourceLabel(booking.memberSettlement.paymentSource) }} · {{ settlementAmount(booking.memberSettlement) }}</span></div>
    </div>
    <dl v-if="booking.memberSettlement?.regionalSettlement" class="settlement-split">
      <div><dt>结算类型</dt><dd>{{ booking.memberSettlement.regionalSettlement.cashSettlement ? '模拟品牌分账' : '非现金结算' }}</dd></div>
      <div><dt>门店收入</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.storeIncomeCents) }}</dd></div>
      <div><dt>集团资金</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.groupIncomeCents) }}</dd></div>
      <div><dt>区域代理</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.regionalAgentIncomeCents) }}</dd></div>
    </dl>
    <p v-else-if="booking.status !== 'verified'" class="settlement-notice">预约核销后才能结算。</p>
    <p v-else-if="!profile" class="settlement-notice">当前预约未关联会员客户，无法使用会员资产结算。</p>
    <template v-else>
      <dl class="settlement-account">
        <div><dt>客户</dt><dd>{{ profile.customer.name }}</dd></div>
        <div><dt>储值本金</dt><dd>{{ formatMoney(profile.account.principalBalanceCents) }}</dd></div>
        <div><dt>赠送积分</dt><dd>{{ profile.account.pointsBalance }}分</dd></div>
      </dl>
      <div class="settlement-source" role="radiogroup" aria-label="项目支付来源">
        <label :class="{ 'is-disabled': !principalAvailable }">
          <input v-model="paymentSource" type="radio" value="principal" :disabled="!principalAvailable">
          <span><strong>储值本金</strong><small>会员6.6折</small></span>
          <em>{{ formatMoney(principalPricing.principalCents) }}</em>
        </label>
        <label :class="{ 'is-disabled': !pointsAvailable }">
          <input v-model="paymentSource" type="radio" value="points" :disabled="!pointsAvailable">
          <span><strong>赠送积分</strong><small>原价1元=1积分</small></span>
          <em>{{ pointsPricing.points }}分</em>
        </label>
      </div>
      <div class="settlement-breakdown">
        <span>项目原价 {{ formatMoney(service.unitPriceCents) }}</span>
        <strong>实扣 {{ selectedAmount }}</strong>
      </div>
      <el-button data-testid="confirm-settlement" type="primary" :disabled="!canPay" @click="confirmSettlement">确认结算</el-button>
    </template>
  </section>
</template>

<script>
import { CheckCircle2 } from 'lucide-vue';
import AdminStatusTag from '../admin/AdminStatusTag.vue';
import { calculateServiceSettlement, memberProfileForCustomer } from '../../mock/memberSelectors';
import { settleVerifiedBooking } from '../../mock/memberTransitions';
import { formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'MemberSettlementPanel',
  components: { AdminStatusTag, CheckCircle2 },
  props: {
    bookingId: { type: String, required: true },
    storeId: { type: String, required: true },
  },
  data() { return { paymentSource: 'principal' }; },
  computed: {
    state() { return demoStore.state.data; },
    booking() { return this.state.bookings.find((item) => item.id === this.bookingId); },
    service() { return this.state.services.find((item) => item.id === this.booking?.serviceId); },
    profile() { return this.booking?.customerId ? memberProfileForCustomer(this.state, this.booking.customerId) : null; },
    principalPricing() { return this.pricingFor('principal'); },
    pointsPricing() { return this.pricingFor('points'); },
    principalAvailable() { return Boolean(this.profile && this.profile.account.principalBalanceCents >= this.principalPricing.principalCents); },
    pointsAvailable() { return Boolean(this.profile && this.profile.account.pointsBalance >= this.pointsPricing.points); },
    canPay() { return this.paymentSource === 'principal' ? this.principalAvailable : this.pointsAvailable; },
    selectedAmount() { return this.paymentSource === 'principal' ? formatMoney(this.principalPricing.principalCents) : `${this.pointsPricing.points}分`; },
    settlementStatus() { return this.booking.memberSettlement ? '已结算' : (this.booking.status === 'verified' ? '待结算' : '未核销'); },
  },
  methods: {
    formatMoney,
    pricingFor(paymentSource) {
      if (!this.service || !this.profile) return { principalCents: 0, points: 0, discountRate: 100 };
      return calculateServiceSettlement(this.state, {
        customerId: this.booking.customerId,
        originalCents: this.service.unitPriceCents,
        paymentSource,
      });
    },
    settlementAmount(settlement) { return settlement.paymentSource === 'principal' ? formatMoney(settlement.principalCents) : `${settlement.points}分`; },
    sourceLabel(source) { return source === 'principal' ? '储值本金' : '赠送积分'; },
    async confirmSettlement() {
      if (!this.canPay || this.booking.memberSettlement) return;
      try {
        await this.$confirm(
          `确认使用${this.sourceLabel(this.paymentSource)}扣除${this.selectedAmount}？`,
          '确认项目结算',
          { confirmButtonText: '确认结算', cancelButtonText: '取消', type: 'warning' },
        );
        demoStore.commit((draft) => settleVerifiedBooking(draft, {
          bookingId: this.bookingId,
          operatingStoreId: this.storeId,
          paymentSource: this.paymentSource,
          settlementAttemptId: `settlement-${this.bookingId}`,
        }));
        this.$message.success('项目结算完成');
        this.$emit('settled');
      } catch (error) {
        if (error?.message) this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.member-settlement-panel { padding: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); }
.member-settlement-panel > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 13px; }
.member-settlement-panel header span { color: var(--text-muted); font-size: 10px; }
.member-settlement-panel h3 { margin: 1px 0 0; font-size: 15px; }
.settlement-account { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 0 0 12px; padding: 10px 0; background: var(--surface-subtle); border-radius: var(--radius-sm); }
.settlement-account div { padding: 0 10px; border-right: 1px solid var(--border); }
.settlement-account div:last-child { border-right: 0; }
.settlement-account dt { color: var(--text-muted); font-size: 10px; }
.settlement-account dd { margin: 3px 0 0; overflow-wrap: anywhere; font-size: 12px; font-weight: 600; }
.settlement-source { display: grid; gap: 8px; }
.settlement-source label { display: grid; min-height: 52px; grid-template-columns: 18px minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 0 11px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; }
.settlement-source label.is-disabled { cursor: not-allowed; opacity: .55; }
.settlement-source strong, .settlement-source small { display: block; }
.settlement-source strong { font-size: 12px; }
.settlement-source small { color: var(--text-muted); font-size: 10px; }
.settlement-source em { color: var(--admin-primary); font-style: normal; font-weight: 600; }
.settlement-breakdown { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 13px 0; font-size: 11px; }
.settlement-breakdown span { color: var(--text-muted); }
.settlement-breakdown strong { font-size: 14px; }
.member-settlement-panel > .el-button { width: 100%; }
.settlement-notice { margin: 0; padding: 18px; color: var(--text-muted); background: var(--surface-subtle); border-radius: var(--radius-sm); text-align: center; font-size: 11px; }
.settlement-result { display: flex; align-items: center; gap: 10px; padding: 13px; color: var(--success); background: #f2faf5; border-radius: var(--radius-sm); }
.settlement-result strong, .settlement-result span { display: block; }
.settlement-result span { color: var(--text-regular); font-size: 11px; }
.settlement-split { display: grid; margin: 10px 0 0; padding: 10px 12px; background: var(--surface-subtle); border-radius: var(--radius-sm); }
.settlement-split div { display: flex; min-height: 28px; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); }
.settlement-split div:last-child { border-bottom: 0; }
.settlement-split dt, .settlement-split dd { margin: 0; font-size: 11px; }
.settlement-split dt { color: var(--text-muted); }
.settlement-split dd { font-weight: 600; }
</style>
