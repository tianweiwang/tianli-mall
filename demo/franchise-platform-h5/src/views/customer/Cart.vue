<template>
  <div class="cart-page">
    <section class="cart-store">
      <MapPin :size="18" aria-hidden="true" />
      <div>
        <span>订单归属门店</span>
        <strong>{{ store.name }}</strong>
      </div>
    </section>

    <section v-if="items.length" class="cart-list">
      <article
        v-for="item in items"
        :key="`${item.productId}-${item.spec}`"
        class="cart-item"
      >
        <img :src="item.product.image" :alt="item.product.name">
        <div class="cart-item__body">
          <h1>{{ item.product.name }}</h1>
          <span>{{ item.spec }}</span>
          <strong>{{ formatMoney(item.product.priceCents) }}</strong>
          <div class="cart-item__controls">
            <div class="cart-stepper" aria-label="商品数量">
              <button type="button" aria-label="减少数量" @click="setQuantity(item, item.quantity - 1)">
                <Minus :size="14" aria-hidden="true" />
              </button>
              <span>{{ item.quantity }}</span>
              <button type="button" aria-label="增加数量" @click="setQuantity(item, item.quantity + 1)">
                <Plus :size="14" aria-hidden="true" />
              </button>
            </div>
            <button type="button" class="cart-remove" aria-label="删除商品" @click="removeItem(item)">
              <Trash2 :size="16" aria-hidden="true" />
            </button>
          </div>
        </div>
      </article>
    </section>

    <section v-else class="cart-empty">
      <ShoppingBag :size="38" aria-hidden="true" />
      <h1>购物车还是空的</h1>
      <router-link :to="categoryPath">去选商品</router-link>
    </section>

    <footer class="cart-summary">
      <div>
        <span>合计</span>
        <strong>{{ formatMoney(totalCents) }}</strong>
      </div>
      <router-link v-if="items.length" :to="checkoutPath">去结算</router-link>
      <button v-else type="button" disabled>去结算</button>
    </footer>
  </div>
</template>

<script>
import {
  MapPin,
  Minus,
  Plus,
  ShoppingBag,
  Trash2,
} from 'lucide-vue';
import { removeCartItem, setCartItemQuantity } from '../../mock/cart';
import { formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerCart',
  components: {
    MapPin,
    Minus,
    Plus,
    ShoppingBag,
    Trash2,
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
    categoryPath() {
      return `/c/store/${this.store.id}/category`;
    },
    checkoutPath() {
      return `/c/store/${this.store.id}/checkout`;
    },
  },
  methods: {
    formatMoney,
    setQuantity(item, quantity) {
      demoStore.commit((draft) => {
        setCartItemQuantity(draft, item, quantity);
      });
    },
    removeItem(item) {
      demoStore.commit((draft) => {
        removeCartItem(draft, item);
      });
    },
  },
};
</script>

<style scoped>
.cart-page {
  display: grid;
  gap: 10px;
}

.cart-store,
.cart-list,
.cart-empty,
.cart-summary {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.cart-store {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 12px 14px;
  color: var(--brand-primary);
}

.cart-store span,
.cart-store strong {
  display: block;
}

.cart-store span {
  color: var(--text-muted);
  font-size: 11px;
}

.cart-store strong {
  color: var(--text-primary);
  font-size: 13px;
}

.cart-list {
  padding: 0 12px;
}

.cart-item {
  display: grid;
  grid-template-columns: 92px minmax(0, 1fr);
  gap: 11px;
  padding: 14px 0;
  border-bottom: 1px solid var(--border);
}

.cart-item:last-child {
  border-bottom: 0;
}

.cart-item > img {
  width: 92px;
  height: 92px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.cart-item__body {
  min-width: 0;
}

.cart-item h1 {
  margin: 0 0 3px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
}

.cart-item__body > span {
  display: block;
  color: var(--text-muted);
  font-size: 11px;
}

.cart-item__body > strong {
  display: block;
  margin-top: 5px;
  color: var(--brand-primary);
  font-size: 15px;
}

.cart-item__controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 6px;
}

.cart-stepper {
  display: grid;
  width: 102px;
  height: 30px;
  grid-template-columns: 30px 1fr 30px;
  align-items: center;
  text-align: center;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.cart-stepper button,
.cart-remove {
  display: grid;
  height: 100%;
  padding: 0;
  place-items: center;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  cursor: pointer;
}

.cart-remove {
  width: 30px;
  height: 30px;
  color: var(--text-muted);
}

.cart-empty {
  display: grid;
  min-height: 280px;
  align-content: center;
  justify-items: center;
  color: var(--text-muted);
}

.cart-empty h1 {
  margin: 10px 0 14px;
  color: var(--text-primary);
  font-size: 16px;
}

.cart-empty a {
  min-height: 36px;
  padding: 7px 16px;
  color: #fff;
  background: var(--brand-primary);
  border-radius: var(--radius-md);
}

.cart-summary {
  position: sticky;
  z-index: 20;
  bottom: calc(60px + env(safe-area-inset-bottom));
  display: grid;
  grid-template-columns: minmax(0, 1fr) 126px;
  align-items: center;
  padding: 10px 10px 10px 14px;
}

.cart-summary span,
.cart-summary strong {
  display: block;
}

.cart-summary span {
  color: var(--text-muted);
  font-size: 11px;
}

.cart-summary strong {
  color: var(--brand-primary);
  font-size: 18px;
}

.cart-summary a,
.cart-summary button {
  display: grid;
  min-height: 42px;
  padding: 0;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-weight: 600;
}

.cart-summary button:disabled {
  background: #c8c9cc;
}
</style>
