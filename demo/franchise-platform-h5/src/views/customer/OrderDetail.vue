<template>
  <div v-if="order" class="order-detail">
    <section class="order-progress">
      <PackageCheck :size="29" aria-hidden="true" />
      <div>
        <h1>{{ statusLabel(order.status) }}</h1>
        <p>{{ progressText(order.status) }}</p>
      </div>
    </section>

    <section class="order-affiliation">
      <Store :size="19" aria-hidden="true" />
      <div><span>订单归属门店</span><strong>{{ store.name }}</strong></div>
      <span>{{ store.id }}</span>
    </section>

    <section class="order-detail__items">
      <article v-for="item in order.items" :key="item.productId">
        <img :src="item.image" :alt="item.name">
        <div>
          <h2>{{ item.name }}</h2>
          <span>{{ item.spec }}　x{{ item.quantity }}</span>
          <strong>{{ formatMoney(item.priceCents * item.quantity) }}</strong>
        </div>
      </article>
    </section>

    <section class="order-detail__meta">
      <div><span>订单编号</span><strong>{{ order.id }}</strong></div>
      <div><span>下单时间</span><strong>{{ displayTime(order.createdAt) }}</strong></div>
      <div><span>配送方式</span><strong>品牌统一配送</strong></div>
      <div><span>实付金额</span><strong>{{ formatMoney(order.paidCents) }}</strong></div>
    </section>

    <details class="order-sharing-note">
      <summary>查看订单归属与收益说明</summary>
      <div>
        <p><span>门店预计收入</span><strong>{{ formatMoney(order.storeIncomeCents) }}</strong></p>
        <p><span>品牌抽成</span><strong>{{ formatMoney(order.brandCommissionCents) }}</strong></p>
        <small>门店收入与品牌抽成之和等于订单实付金额。</small>
      </div>
    </details>
  </div>
</template>

<script>
import { PackageCheck, Store } from 'lucide-vue';
import { formatMoney, orderForCustomer } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

const labels = {
  pending_payment: '待支付',
  paid: '已支付',
  shipping: '配送中',
  completed: '已完成',
  refunding: '退款中',
  refunded: '已退款',
};

const progress = {
  pending_payment: '请尽快完成支付',
  paid: '品牌仓库正在准备商品',
  shipping: '商品已由品牌统一配送',
  completed: '订单已完成',
  refunding: '退款正在处理中',
  refunded: '退款流程已完成',
};

export default {
  name: 'CustomerOrderDetail',
  components: { PackageCheck, Store },
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
  },
  methods: {
    formatMoney,
    statusLabel(status) {
      return labels[status] || status;
    },
    progressText(status) {
      return progress[status] || '订单状态已更新';
    },
    displayTime(value) {
      return value.replace('T', ' ').replace('+08:00', '');
    },
  },
};
</script>

<style scoped>
.order-detail {
  display: grid;
  gap: 10px;
}

.order-progress,
.order-affiliation,
.order-detail__items,
.order-detail__meta,
.order-sharing-note {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.order-progress {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px;
  color: #fff;
  background: var(--brand-primary);
  border-color: var(--brand-primary);
}

.order-progress h1,
.order-progress p {
  margin: 0;
}

.order-progress h1 {
  font-size: 18px;
}

.order-progress p {
  opacity: 0.88;
  font-size: 11px;
}

.order-affiliation {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) auto;
  align-items: center;
  gap: 9px;
  padding: 13px 14px;
  color: var(--brand-primary);
}

.order-affiliation div span,
.order-affiliation div strong {
  display: block;
}

.order-affiliation div span,
.order-affiliation > span {
  color: var(--text-muted);
  font-size: 11px;
}

.order-affiliation div strong {
  color: var(--text-primary);
  font-size: 13px;
}

.order-detail__items {
  padding: 0 13px;
}

.order-detail__items article {
  display: grid;
  grid-template-columns: 78px minmax(0, 1fr);
  gap: 10px;
  padding: 13px 0;
  border-bottom: 1px solid var(--border);
}

.order-detail__items article:last-child {
  border-bottom: 0;
}

.order-detail__items img {
  width: 78px;
  height: 78px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.order-detail__items h2 {
  margin: 2px 0 3px;
  font-size: 13px;
  font-weight: 500;
}

.order-detail__items span,
.order-detail__items strong {
  display: block;
}

.order-detail__items span {
  color: var(--text-muted);
  font-size: 11px;
}

.order-detail__items strong {
  margin-top: 7px;
  color: var(--brand-primary);
  font-size: 14px;
}

.order-detail__meta {
  display: grid;
  gap: 9px;
  padding: 14px;
}

.order-detail__meta div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.order-detail__meta span,
.order-detail__meta strong {
  font-size: 12px;
}

.order-detail__meta span {
  color: var(--text-muted);
}

.order-detail__meta strong {
  text-align: right;
}

.order-detail__meta div:last-child {
  padding-top: 9px;
  border-top: 1px solid var(--border);
}

.order-detail__meta div:last-child strong {
  color: var(--brand-primary);
  font-size: 16px;
}

.order-sharing-note summary {
  padding: 13px 14px;
  color: var(--text-regular);
  cursor: pointer;
  font-size: 12px;
}

.order-sharing-note > div {
  padding: 0 14px 14px;
}

.order-sharing-note p {
  display: flex;
  justify-content: space-between;
  margin: 7px 0;
  font-size: 12px;
}

.order-sharing-note small {
  display: block;
  margin-top: 10px;
  color: var(--text-muted);
}
</style>
