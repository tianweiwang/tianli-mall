<template>
  <div v-if="order" class="payment-success">
    <section>
      <div class="payment-success__icon">
        <Check :size="34" aria-hidden="true" />
      </div>
      <h1>支付成功</h1>
      <strong>{{ formatMoney(order.paidCents) }}</strong>
      <p>订单已归属 {{ store.name }}，商品将由品牌统一配送。</p>
      <p
        v-if="order.distributionAttribution"
        class="payment-success__attribution"
        data-testid="attribution-success"
      >
        本单已计入分享归因，推荐奖励与分销佣金已生成
      </p>
      <span data-testid="order-id">{{ order.id }}</span>
    </section>
    <div class="payment-success__actions">
      <router-link :to="orderPath">查看订单</router-link>
      <router-link :to="homePath">返回首页</router-link>
    </div>
  </div>
</template>

<script>
import { Check } from 'lucide-vue';
import { formatMoney, orderForCustomer } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerPaymentSuccess',
  components: { Check },
  computed: {
    order() {
      const state = demoStore.state.data;
      return orderForCustomer(
        state,
        state.activeCustomerId,
        this.$route.params.orderId,
      );
    },
    store() {
      return this.order
        ? demoStore.state.data.stores.find((item) => item.id === this.order.storeId)
        : null;
    },
    homePath() {
      return `/c/store/${this.$route.params.storeId}`;
    },
    orderPath() {
      return this.order
        ? `/c/store/${this.$route.params.storeId}/orders/${this.order.id}`
        : `/c/store/${this.$route.params.storeId}/orders`;
    },
  },
  methods: { formatMoney },
};
</script>

<style scoped>
.payment-success {
  display: grid;
  min-height: calc(100vh - 162px);
  align-content: center;
  gap: 14px;
}

.payment-success section {
  padding: 32px 22px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  text-align: center;
}

.payment-success__icon {
  display: grid;
  width: 62px;
  height: 62px;
  margin: 0 auto;
  place-items: center;
  color: #fff;
  background: var(--success);
  border-radius: 50%;
}

.payment-success h1 {
  margin: 15px 0 5px;
  font-size: 21px;
}

.payment-success section > strong {
  display: block;
  font-size: 25px;
}

.payment-success p {
  margin: 10px auto 13px;
  color: var(--text-regular);
  font-size: 12px;
}

.payment-success .payment-success__attribution {
  padding: 8px 10px;
  color: var(--brand-primary);
  background: #fff5f4;
  border-radius: var(--radius-md);
}

.payment-success section > span {
  color: var(--text-muted);
  font-size: 11px;
}

.payment-success__actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.payment-success__actions a {
  display: grid;
  min-height: 42px;
  place-items: center;
  color: var(--brand-primary);
  background: var(--surface);
  border: 1px solid var(--brand-primary);
  border-radius: var(--radius-md);
}

.payment-success__actions a:first-child {
  color: #fff;
  background: var(--brand-primary);
}
</style>
