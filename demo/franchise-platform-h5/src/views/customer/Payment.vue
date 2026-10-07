<template>
  <div class="payment-page">
    <div class="demo-payment-notice">
      <ShieldAlert :size="19" aria-hidden="true" />
      <strong>演示支付，不会产生真实扣款</strong>
    </div>

    <section class="payment-panel">
      <div class="payment-brand">
        <MessageCircleMore :size="30" aria-hidden="true" />
        <div>
          <strong>{{ paymentSource === 'principal' ? '储值本金支付' : '微信支付' }}</strong>
          <span>{{ paymentSource === 'principal' ? `可用余额 ${formatMoney(profile.account.principalBalanceCents)}` : '本地模拟支付确认' }}</span>
        </div>
      </div>
      <div class="payment-amount">
        <span>支付金额</span>
        <strong>{{ formatMoney(paymentAmountCents) }}</strong>
      </div>
      <div class="payment-source" role="radiogroup" aria-label="支付方式">
        <label><input v-model="paymentSource" type="radio" value="wechat"><span>微信模拟支付</span></label>
        <label :class="{ 'is-disabled': !principalAvailable }"><input v-model="paymentSource" type="radio" value="principal" :disabled="!principalAvailable"><span>储值本金</span></label>
      </div>
      <div class="payment-meta">
        <div><span>收款方</span><strong>天俪品牌商城</strong></div>
        <div><span>归属门店</span><strong>{{ store.name }}</strong></div>
        <div><span>配送方式</span><strong>品牌统一配送</strong></div>
      </div>
      <button
        v-if="items.length || isPaying"
        type="button"
        :disabled="isPaying || !canPay"
        @click="pay"
      >
        <LoaderCircle v-if="isPaying" :size="18" class="is-spinning" aria-hidden="true" />
        <CheckCircle2 v-else :size="18" aria-hidden="true" />
        {{ isPaying ? '支付处理中' : (canPay ? '确认支付' : '储值本金余额不足') }}
      </button>
      <router-link v-else :to="cartPath">购物车为空，返回重新选择</router-link>
    </section>
  </div>
</template>

<script>
import {
  CheckCircle2,
  LoaderCircle,
  MessageCircleMore,
  ShieldAlert,
} from 'lucide-vue';
import { formatMoney } from '../../mock/selectors';
import { calculateGoodsPricing, memberProfileForCustomer } from '../../mock/memberSelectors';
import { demoStore } from '../../mock/store';
import { createPaidOrder } from '../../mock/transitions';

export default {
  name: 'CustomerPayment',
  components: {
    CheckCircle2,
    LoaderCircle,
    MessageCircleMore,
    ShieldAlert,
  },
  data() {
    return {
      isPaying: false,
      paymentAttemptId: '',
      paymentSource: 'wechat',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
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
    profile() {
      return memberProfileForCustomer(this.state, this.state.activeCustomerId);
    },
    pricing() {
      return calculateGoodsPricing(this.state, {
        customerId: this.state.activeCustomerId,
        originalCents: this.totalCents,
      });
    },
    paymentAmountCents() {
      return this.pricing.payableCents;
    },
    principalAvailable() {
      return this.profile.account.principalBalanceCents >= this.paymentAmountCents;
    },
    canPay() {
      return this.paymentSource !== 'principal' || this.principalAvailable;
    },
    cartPath() {
      return `/c/store/${this.store.id}/cart`;
    },
  },
  created() {
    this.paymentSource = this.$route.query.source === 'principal' ? 'principal' : 'wechat';
    if (!this.principalAvailable) this.paymentSource = 'wechat';
    this.paymentAttemptId = `demo-payment-${this.state.sequence.order + 1}`;
  },
  methods: {
    formatMoney,
    async pay() {
      if (this.isPaying || !this.items.length || !this.canPay) {
        return;
      }
      this.isPaying = true;
      await this.$nextTick();
      try {
        let order;
        demoStore.commit((draft) => {
          order = createPaidOrder(draft, {
            addressId: 'ADDR1',
            delivery: 'express',
            paymentAttemptId: this.paymentAttemptId,
            paymentSource: this.paymentSource,
          });
        });
        await this.$router.push(
          `/c/store/${order.storeId}/payment-success/${order.id}`,
        );
      } catch (error) {
        this.isPaying = false;
        this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.payment-page {
  display: grid;
  gap: 12px;
}

.demo-payment-notice {
  display: flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #9a6200;
  background: #fff7e6;
  border: 1px solid #f6d58c;
  border-radius: var(--radius-lg);
  font-size: 12px;
}

.payment-panel {
  padding: 22px 18px 18px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.payment-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--success);
}

.payment-brand strong,
.payment-brand span {
  display: block;
}

.payment-brand strong {
  color: var(--text-primary);
  font-size: 15px;
}

.payment-brand span {
  color: var(--text-muted);
  font-size: 11px;
}

.payment-amount {
  padding: 32px 0 28px;
  text-align: center;
}

.payment-amount span,
.payment-amount strong {
  display: block;
}

.payment-amount span {
  color: var(--text-muted);
  font-size: 12px;
}

.payment-amount strong {
  margin-top: 4px;
  font-size: 34px;
}

.payment-meta {
  display: grid;
  gap: 10px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
}

.payment-source {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
}

.payment-source label {
  display: flex;
  min-height: 42px;
  align-items: center;
  gap: 7px;
  padding: 0 10px;
  background: var(--surface-subtle);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: 11px;
}

.payment-source label.is-disabled { cursor: not-allowed; opacity: .55; }

.payment-meta div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.payment-meta span,
.payment-meta strong {
  font-size: 12px;
}

.payment-meta span {
  color: var(--text-muted);
}

.payment-meta strong {
  text-align: right;
}

.payment-panel > button,
.payment-panel > a {
  display: flex;
  width: 100%;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  margin-top: 12px;
  color: #fff;
  background: #07c160;
  border: 0;
  border-radius: var(--radius-md);
  font-weight: 600;
  cursor: pointer;
}

.payment-panel > button:disabled {
  cursor: wait;
  opacity: 0.75;
}

.payment-panel > a {
  background: var(--text-muted);
}

.is-spinning {
  animation: payment-spin 0.8s linear infinite;
}

@keyframes payment-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .is-spinning { animation: none; }
}
</style>
