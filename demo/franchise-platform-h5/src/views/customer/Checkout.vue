<template>
  <div class="checkout-page">
    <section class="checkout-address">
      <MapPin :size="21" aria-hidden="true" />
      <div>
        <strong>{{ profile.customer.name }}　{{ profile.customer.phone }}</strong>
        <span>四川省成都市锦江区春熙路 66 号</span>
      </div>
      <ChevronRight :size="18" aria-hidden="true" />
    </section>

    <section class="checkout-store">
      <header>
        <Store :size="18" aria-hidden="true" />
        <strong>{{ store.name }}</strong>
        <span>归属门店</span>
      </header>
      <article v-for="item in items" :key="`${item.productId}-${item.spec}`">
        <img :src="item.product.image" :alt="item.product.name">
        <div>
          <h1>{{ item.product.name }}</h1>
          <span>{{ item.spec }}</span>
          <strong>{{ formatMoney(item.product.priceCents) }}</strong>
        </div>
        <span>x{{ item.quantity }}</span>
      </article>
    </section>

    <section class="checkout-delivery">
      <div>
        <Truck :size="18" aria-hidden="true" />
        <span>配送方式</span>
      </div>
      <strong>品牌统一配送</strong>
    </section>

    <section v-if="binding" class="checkout-referral">
      <Gift :size="19" aria-hidden="true" />
      <div>
        <strong>推荐关系已绑定</strong>
        <span>由 {{ state.distributor.name }} 推荐，本次订单将计入分享归因</span>
      </div>
    </section>

    <section class="checkout-payment-options" aria-label="支付方式">
      <header><WalletCards :size="18" aria-hidden="true" /><strong>支付方式</strong></header>
      <label>
        <input v-model="paymentSource" type="radio" value="wechat">
        <span><strong>微信模拟支付</strong><small>不计入集团补贴消费</small></span>
      </label>
      <label :class="{ 'is-disabled': !principalAvailable }">
        <input v-model="paymentSource" type="radio" value="principal" :disabled="!principalAvailable">
        <span><strong>储值本金</strong><small>余额 {{ formatMoney(profile.account.principalBalanceCents) }} · 本单计入补贴消费</small></span>
      </label>
    </section>

    <section class="checkout-breakdown">
      <div><span>商品金额</span><strong>{{ formatMoney(totalCents) }}</strong></div>
      <div v-if="pricing.discountRate < 100"><span>会员商品优惠（8.5折）</span><strong>-{{ formatMoney(totalCents - pricing.payableCents) }}</strong></div>
      <div><span>配送费</span><strong>{{ formatMoney(0) }}</strong></div>
      <div><span>实付金额</span><strong>{{ formatMoney(pricing.payableCents) }}</strong></div>
    </section>

    <footer class="checkout-submit">
      <div><span>合计</span><strong>{{ formatMoney(pricing.payableCents) }}</strong></div>
      <router-link v-if="items.length" :to="paymentPath">去支付</router-link>
      <router-link v-else :to="cartPath" class="is-empty">返回购物车</router-link>
    </footer>
  </div>
</template>

<script>
import {
  ChevronRight,
  Gift,
  MapPin,
  Store,
  Truck,
  WalletCards,
} from 'lucide-vue';
import { activeDistributionBinding, formatMoney } from '../../mock/selectors';
import { calculateGoodsPricing, memberProfileForCustomer } from '../../mock/memberSelectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerCheckout',
  components: {
    ChevronRight,
    Gift,
    MapPin,
    Store,
    Truck,
    WalletCards,
  },
  data() { return { paymentSource: 'wechat' }; },
  computed: {
    state() {
      return demoStore.state.data;
    },
    binding() {
      return activeDistributionBinding(this.state);
    },
    profile() {
      return memberProfileForCustomer(this.state, this.state.activeCustomerId);
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    items() {
      return this.state.cart.map((item) => ({
        ...item,
        product: this.state.products.find((product) => product.id === item.productId),
      }));
    },
    totalCents() {
      return this.items.reduce(
        (sum, item) => sum + item.product.priceCents * item.quantity,
        0,
      );
    },
    pricing() {
      return calculateGoodsPricing(this.state, {
        customerId: this.state.activeCustomerId,
        originalCents: this.totalCents,
      });
    },
    principalAvailable() {
      return this.profile.account.principalBalanceCents >= this.pricing.payableCents;
    },
    paymentPath() {
      return `/c/store/${this.store.id}/payment?source=${this.paymentSource}`;
    },
    cartPath() {
      return `/c/store/${this.store.id}/cart`;
    },
  },
  methods: { formatMoney },
};
</script>

<style scoped>
.checkout-page {
  display: grid;
  gap: 10px;
}

.checkout-address,
.checkout-store,
.checkout-delivery,
.checkout-referral,
.checkout-payment-options,
.checkout-breakdown,
.checkout-submit {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.checkout-address {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 9px;
  padding: 14px;
  color: var(--brand-primary);
}

.checkout-address strong,
.checkout-address span {
  display: block;
}

.checkout-address strong {
  color: var(--text-primary);
  font-size: 13px;
}

.checkout-address span {
  margin-top: 2px;
  color: var(--text-regular);
  font-size: 11px;
}

.checkout-store {
  padding: 0 13px;
}

.checkout-store header {
  display: flex;
  min-height: 46px;
  align-items: center;
  gap: 7px;
  border-bottom: 1px solid var(--border);
}

.checkout-store header svg {
  color: var(--brand-primary);
}

.checkout-store header strong {
  font-size: 13px;
}

.checkout-store header span {
  margin-left: auto;
  color: var(--text-muted);
  font-size: 11px;
}

.checkout-store article {
  display: grid;
  grid-template-columns: 78px minmax(0, 1fr) auto;
  gap: 10px;
  padding: 13px 0;
}

.checkout-store article img {
  width: 78px;
  height: 78px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.checkout-store article h1 {
  margin: 0 0 3px;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.4;
}

.checkout-store article div > span {
  display: block;
  color: var(--text-muted);
  font-size: 11px;
}

.checkout-store article div > strong {
  display: block;
  margin-top: 6px;
  color: var(--brand-primary);
  font-size: 14px;
}

.checkout-store article > span {
  align-self: end;
  color: var(--text-regular);
  font-size: 11px;
}

.checkout-delivery,
.checkout-referral,
.checkout-payment-options,
.checkout-breakdown {
  padding: 13px 14px;
}

.checkout-referral {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr);
  align-items: center;
  gap: 9px;
  color: var(--brand-primary);
}

.checkout-referral strong,
.checkout-referral span {
  display: block;
}

.checkout-referral strong {
  color: var(--text-primary);
  font-size: 12px;
}

.checkout-referral span {
  margin-top: 2px;
  color: var(--text-regular);
  font-size: 11px;
}

.checkout-delivery,
.checkout-delivery div,
.checkout-breakdown div {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.checkout-delivery div {
  gap: 7px;
  justify-content: flex-start;
}

.checkout-delivery svg {
  color: var(--brand-primary);
}

.checkout-delivery span,
.checkout-breakdown span {
  color: var(--text-regular);
  font-size: 12px;
}

.checkout-payment-options header,
.checkout-payment-options label {
  display: flex;
  align-items: center;
  gap: 9px;
}

.checkout-payment-options header {
  margin-bottom: 7px;
  color: var(--brand-primary);
}

.checkout-payment-options label {
  min-height: 48px;
  border-top: 1px solid var(--border);
  cursor: pointer;
}

.checkout-payment-options label.is-disabled {
  cursor: not-allowed;
  opacity: .55;
}

.checkout-payment-options label span,
.checkout-payment-options label strong,
.checkout-payment-options label small {
  display: block;
}

.checkout-payment-options label strong { color: var(--text-primary); font-size: 12px; }
.checkout-payment-options label small { color: var(--text-muted); font-size: 10px; }

.checkout-delivery strong,
.checkout-breakdown strong {
  font-size: 12px;
}

.checkout-breakdown {
  display: grid;
  gap: 9px;
}

.checkout-breakdown div:last-child {
  padding-top: 9px;
  border-top: 1px solid var(--border);
}

.checkout-breakdown div:last-child strong {
  color: var(--brand-primary);
  font-size: 16px;
}

.checkout-submit {
  position: sticky;
  z-index: 20;
  bottom: calc(60px + env(safe-area-inset-bottom));
  display: grid;
  grid-template-columns: minmax(0, 1fr) 126px;
  align-items: center;
  padding: 10px 10px 10px 14px;
}

.checkout-submit span,
.checkout-submit strong {
  display: block;
}

.checkout-submit span {
  color: var(--text-muted);
  font-size: 11px;
}

.checkout-submit strong {
  color: var(--brand-primary);
  font-size: 18px;
}

.checkout-submit a {
  display: grid;
  min-height: 42px;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border-radius: var(--radius-md);
  font-weight: 600;
}

.checkout-submit a.is-empty {
  background: var(--text-muted);
}
</style>
