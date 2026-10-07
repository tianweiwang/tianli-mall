<template>
  <div v-if="product" class="product-detail">
    <div class="product-detail__media">
      <img :src="product.image" :alt="product.name">
    </div>
    <section class="product-detail__summary">
      <div class="product-detail__price">
        <strong>{{ formatMoney(product.priceCents) }}</strong>
        <del>{{ formatMoney(product.originalPriceCents) }}</del>
        <span>已售 {{ product.sales }}</span>
      </div>
      <h1>{{ product.name }}</h1><span class="product-detail__series">{{ product.series }} · {{ product.unit }}</span>
      <p>集团统一商品，由品牌统一配送；当前订单归属 {{ store.name }}。</p>
    </section>

    <section class="product-detail__options">
      <h2>选择规格</h2>
      <div>
        <button
          v-for="spec in product.specs"
          :key="spec"
          type="button"
          :class="{ 'is-active': selectedSpec === spec }"
          @click="selectedSpec = spec"
        >
          {{ spec }}
        </button>
      </div>
    </section>

    <section class="delivery-row">
      <Truck :size="20" aria-hidden="true" />
      <div>
        <strong>品牌统一配送</strong>
        <span>本 Demo 不产生真实发货</span>
      </div>
      <PackageCheck :size="19" aria-hidden="true" />
    </section>

    <div class="product-detail__actions">
      <div class="quantity-stepper" aria-label="购买数量">
        <button type="button" aria-label="减少数量" @click="changeQuantity(-1)">
          <Minus :size="16" aria-hidden="true" />
        </button>
        <span>{{ quantity }}</span>
        <button type="button" aria-label="增加数量" @click="changeQuantity(1)">
          <Plus :size="16" aria-hidden="true" />
        </button>
      </div>
      <button type="button" class="primary-shop-button" @click="addProduct">
        加入购物车
      </button>
    </div>
  </div>
  <div v-else class="product-detail__missing">商品已下架或当前门店不可用</div>
</template>

<script>
import {
  Minus,
  PackageCheck,
  Plus,
  Truck,
} from 'lucide-vue';
import { addCartItem } from '../../mock/cart';
import { formatMoney, productForStoreById } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerProductDetail',
  components: {
    Minus,
    PackageCheck,
    Plus,
    Truck,
  },
  data() {
    return {
      quantity: 1,
      selectedSpec: '',
    };
  },
  computed: {
    product() {
      return productForStoreById(
        demoStore.state.data,
        this.$route.params.storeId,
        Number(this.$route.params.productId),
      );
    },
    store() {
      return demoStore.state.data.stores.find(
        (item) => item.id === this.$route.params.storeId,
      );
    },
  },
  created() {
    this.selectedSpec = this.product?.specs[0] || '';
  },
  methods: {
    formatMoney,
    changeQuantity(amount) {
      this.quantity = Math.max(1, this.quantity + amount);
    },
    addProduct() {
      demoStore.commit((draft) => {
        addCartItem(draft, {
          productId: this.product.id,
          spec: this.selectedSpec,
          quantity: this.quantity,
        });
      });
      this.$message.success('已加入购物车');
    },
  },
};
</script>

<style scoped>
.product-detail {
  display: grid;
  gap: 10px;
}

.product-detail__missing {
  padding: 30px;
  color: var(--text-muted);
  text-align: center;
}

.product-detail__media,
.product-detail__summary,
.product-detail__options,
.delivery-row {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.product-detail__media {
  overflow: hidden;
  aspect-ratio: 1;
}

.product-detail__media img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-detail__summary,
.product-detail__options {
  padding: 15px;
}

.product-detail__series {
  display: inline-block;
  margin-top: 4px;
  color: var(--text-muted);
  font-size: 12px;
}

.product-detail__price {
  display: flex;
  min-height: 28px;
  align-items: baseline;
  gap: 8px;
}

.product-detail__price strong {
  color: var(--brand-primary);
  font-size: 22px;
}

.product-detail__price del,
.product-detail__price span {
  color: var(--text-muted);
  font-size: 11px;
}

.product-detail__price span {
  margin-left: auto;
}

.product-detail h1 {
  margin: 9px 0 7px;
  font-size: 18px;
  line-height: 1.45;
}

.product-detail__summary p {
  margin: 0;
  color: var(--text-regular);
  font-size: 12px;
}

.product-detail__options h2 {
  margin: 0 0 10px;
  font-size: 14px;
}

.product-detail__options > div {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.product-detail__options button {
  min-height: 34px;
  padding: 0 12px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.product-detail__options button.is-active {
  color: var(--brand-primary);
  background: #fff5f4;
  border-color: var(--brand-primary);
}

.delivery-row {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) 24px;
  align-items: center;
  gap: 9px;
  padding: 14px 15px;
  color: var(--brand-primary);
}

.delivery-row strong,
.delivery-row span {
  display: block;
}

.delivery-row strong {
  color: var(--text-primary);
  font-size: 13px;
}

.delivery-row span {
  color: var(--text-muted);
  font-size: 11px;
}

.product-detail__actions {
  position: sticky;
  z-index: 20;
  bottom: calc(60px + env(safe-area-inset-bottom));
  display: grid;
  grid-template-columns: 118px minmax(0, 1fr);
  gap: 10px;
  padding: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.quantity-stepper {
  display: grid;
  height: 42px;
  grid-template-columns: 36px 1fr 36px;
  align-items: center;
  text-align: center;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
}

.quantity-stepper button {
  display: grid;
  height: 100%;
  padding: 0;
  place-items: center;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  cursor: pointer;
}

.primary-shop-button {
  min-height: 42px;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-weight: 600;
  cursor: pointer;
}
</style>
