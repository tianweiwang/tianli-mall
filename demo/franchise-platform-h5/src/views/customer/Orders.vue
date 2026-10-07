<template>
  <div class="customer-orders">
    <nav aria-label="订单状态筛选">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        type="button"
        :class="{ 'is-active': activeStatus === tab.value }"
        @click="activeStatus = tab.value"
      >
        {{ tab.label }}
      </button>
    </nav>

    <section v-if="visibleOrders.length" class="order-list">
      <article v-for="order in visibleOrders" :key="order.id">
        <header>
          <span>{{ order.id }}</span>
          <strong :class="`status-${order.status}`">{{ statusLabel(order.status) }}</strong>
        </header>
        <div v-for="item in order.items" :key="`${order.id}-${item.productId}`" class="order-item">
          <img :src="item.image" :alt="item.name">
          <div>
            <h1>{{ item.name }}</h1>
            <span>{{ item.spec }}　x{{ item.quantity }}</span>
          </div>
        </div>
        <footer>
          <span>实付 {{ formatMoney(order.paidCents) }}</span>
          <router-link :to="detailPath(order)">查看详情</router-link>
        </footer>
      </article>
    </section>
    <section v-else class="orders-empty">
      <ClipboardList :size="36" aria-hidden="true" />
      <span>暂无该状态订单</span>
    </section>
  </div>
</template>

<script>
import { ClipboardList } from 'lucide-vue';
import { formatMoney, ordersForCustomer } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

const labels = {
  pending_payment: '待支付',
  paid: '已支付',
  shipping: '配送中',
  completed: '已完成',
  refunding: '退款中',
  refunded: '已退款',
};

export default {
  name: 'CustomerOrders',
  components: { ClipboardList },
  data() {
    return {
      activeStatus: 'all',
      tabs: [
        { label: '全部', value: 'all' },
        { label: '待支付', value: 'pending_payment' },
        { label: '已支付', value: 'paid' },
        { label: '配送中', value: 'shipping' },
        { label: '已完成', value: 'completed' },
      ],
    };
  },
  computed: {
    orders() {
      const state = demoStore.state.data;
      return ordersForCustomer(state, state.activeCustomerId);
    },
    visibleOrders() {
      if (this.activeStatus === 'all') {
        return this.orders;
      }
      return this.orders.filter((item) => item.status === this.activeStatus);
    },
  },
  methods: {
    formatMoney,
    statusLabel(status) {
      return labels[status] || status;
    },
    detailPath(order) {
      return `/c/store/${this.$route.params.storeId}/orders/${order.id}`;
    },
  },
};
</script>

<style scoped>
.customer-orders {
  display: grid;
  gap: 10px;
}

.customer-orders > nav {
  display: grid;
  height: 42px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.customer-orders > nav button {
  min-width: 0;
  padding: 0 2px;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  font-size: 11px;
  cursor: pointer;
}

.customer-orders > nav button.is-active {
  color: var(--brand-primary);
  border-bottom-color: var(--brand-primary);
  font-weight: 600;
}

.order-list {
  display: grid;
  gap: 10px;
}

.order-list > article,
.orders-empty {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.order-list article > header,
.order-list article > footer {
  display: flex;
  min-height: 42px;
  align-items: center;
  justify-content: space-between;
  padding: 0 13px;
}

.order-list article > header {
  border-bottom: 1px solid var(--border);
}

.order-list header span {
  color: var(--text-muted);
  font-size: 11px;
}

.order-list header strong {
  color: var(--brand-primary);
  font-size: 12px;
}

.order-list header .status-completed,
.order-list header .status-refunded {
  color: var(--text-muted);
}

.order-list header .status-shipping {
  color: var(--admin-primary);
}

.order-item {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr);
  gap: 10px;
  padding: 12px 13px 0;
}

.order-item img {
  width: 72px;
  height: 72px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.order-item h1 {
  margin: 3px 0 4px;
  font-size: 13px;
  font-weight: 500;
}

.order-item span {
  color: var(--text-muted);
  font-size: 11px;
}

.order-list article > footer {
  justify-content: flex-end;
  gap: 12px;
}

.order-list footer span {
  font-size: 12px;
}

.order-list footer a {
  display: grid;
  min-width: 78px;
  min-height: 32px;
  place-items: center;
  color: var(--brand-primary);
  border: 1px solid var(--brand-primary);
  border-radius: var(--radius-md);
  font-size: 12px;
}

.orders-empty {
  display: grid;
  min-height: 260px;
  align-content: center;
  justify-items: center;
  gap: 8px;
  color: var(--text-muted);
}
</style>
