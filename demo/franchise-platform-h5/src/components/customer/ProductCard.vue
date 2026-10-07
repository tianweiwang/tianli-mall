<template>
  <article class="product-card" :data-testid="`product-${product.id}`">
    <router-link :to="productPath" class="product-card__link">
      <div class="product-card__media">
        <img :src="product.image" :alt="product.name">
      </div>
      <h3>{{ product.name }}</h3>
    </router-link>
    <div class="product-card__sales">已售 {{ product.sales }} {{ product.unit || '件' }} · {{ product.series }}</div>
    <div class="product-card__footer">
      <div>
        <strong>{{ formatMoney(product.priceCents) }}</strong><span class="product-card__unit" v-if="product.unit">/{{ product.unit }}</span>
        <del>{{ formatMoney(product.originalPriceCents) }}</del>
      </div>
      <button type="button" aria-label="加入购物车" @click="addProduct">
        <ShoppingCart :size="17" aria-hidden="true" />
      </button>
    </div>
  </article>
</template>

<script>
import { ShoppingCart } from 'lucide-vue';
import { addCartItem } from '../../mock/cart';
import { formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'ProductCard',
  components: { ShoppingCart },
  props: {
    product: {
      type: Object,
      required: true,
    },
    storeId: {
      type: String,
      required: true,
    },
  },
  computed: {
    productPath() {
      return `/c/store/${this.storeId}/product/${this.product.id}`;
    },
  },
  methods: {
    formatMoney,
    addProduct() {
      demoStore.commit((draft) => {
        addCartItem(draft, {
          productId: this.product.id,
          spec: this.product.specs[0],
          quantity: 1,
        });
      });
      this.$message.success('已加入购物车');
    },
  },
};
</script>

<style scoped>
.product-card {
  min-width: 0;
  padding: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.product-card__link {
  display: block;
}

.product-card__media {
  overflow: hidden;
  aspect-ratio: 1;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.product-card__media img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-card h3 {
  display: -webkit-box;
  min-height: 40px;
  margin: 9px 0 4px;
  overflow: hidden;
  font-size: 13px;
  font-weight: 500;
  line-height: 20px;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.product-card__sales {
  color: var(--text-muted);
  font-size: 11px;
}

.product-card__footer {
  display: flex;
  min-height: 36px;
  align-items: flex-end;
  justify-content: space-between;
  gap: 6px;
}

.product-card__footer strong,
.product-card__footer del {
  display: block;
}

.product-card__footer strong {
  color: var(--brand-primary);
  font-size: 15px;
}

.product-card__unit {
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 400;
}

.product-card__footer del {
  color: var(--text-muted);
  font-size: 10px;
}

.product-card__footer button {
  display: grid;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  padding: 0;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: 50%;
  cursor: pointer;
}
</style>
