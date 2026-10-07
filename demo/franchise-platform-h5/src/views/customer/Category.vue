<template>
  <div class="category-page">
    <nav class="category-tabs" role="tablist" aria-label="分类类型">
      <button
        type="button"
        role="tab"
        :class="{ 'is-active': tab === 'services' }"
        @click="selectTab('services')"
      >门店项目 {{ services.length }}</button>
      <button
        type="button"
        role="tab"
        :class="{ 'is-active': tab === 'products' }"
        @click="selectTab('products')"
      >健康好物 {{ products.length }}</button>
    </nav>

    <div class="category-body">
      <aside :aria-label="tab === 'services' ? '服务系列' : '商品分类'">
        <template v-if="tab === 'services'">
          <button
            v-for="series in seriesList"
            :key="series"
            type="button"
            :class="{ 'is-active': selectedSeries === series }"
            @click="selectSeries(series)"
          >
            {{ series }}
            <span>{{ seriesCounts[series] || 0 }}</span>
          </button>
        </template>
        <template v-else>
          <button
            v-for="category in categories"
            :key="category"
            type="button"
            :class="{ 'is-active': selectedCategory === category }"
            @click="selectCategory(category)"
          >
            {{ category }}
          </button>
        </template>
      </aside>

      <section>
        <header>
          <template v-if="tab === 'services'">
            <h1>{{ selectedSeries }}</h1>
            <small>门店项目 · 支持单次与疗程预约</small>
            <span>{{ visibleServices.length }} 项</span>
          </template>
          <template v-else>
            <h1>{{ selectedCategory }}</h1>
            <small>健康好物 · 品牌统一配送</small>
            <span>{{ visibleProducts.length }} 款好物</span>
          </template>
        </header>
        <div v-if="tab === 'services'" class="category-grid">
          <ServiceCard
            v-for="service in visibleServices"
            :key="service.id"
            :service="service"
            :store-id="storeId"
          />
        </div>
        <div v-else class="category-grid">
          <ProductCard
            v-for="product in visibleProducts"
            :key="product.id"
            :product="product"
            :store-id="storeId"
          />
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import ProductCard from '../../components/customer/ProductCard.vue';
import ServiceCard from '../../components/customer/ServiceCard.vue';
import { demoStore } from '../../mock/store';
import { productsForStore, servicesForStore } from '../../mock/selectors';

export default {
  name: 'CustomerCategory',
  components: { ProductCard, ServiceCard },
  data() {
    const query = this.$route.query;
    return {
      tab: query.tab === 'services' ? 'services' : 'products',
      selectedSeries: query.series || '全部项目',
      selectedCategory: query.category || '全部商品',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    services() {
      return servicesForStore(this.state, this.storeId);
    },
    products() {
      return productsForStore(this.state, this.storeId);
    },
    storeId() {
      return this.$route.params.storeId;
    },
    categories() {
      return ['全部商品', ...new Set(this.products.map((item) => item.category))];
    },
    seriesList() {
      return ['全部项目', ...new Set(this.services.map((item) => item.series))];
    },
    seriesCounts() {
      return this.services.reduce((result, item) => {
        result[item.series] = (result[item.series] || 0) + 1;
        return result;
      }, {});
    },
    visibleServices() {
      if (this.selectedSeries === '全部项目') {
        return this.services;
      }
      return this.services.filter((item) => item.series === this.selectedSeries);
    },
    visibleProducts() {
      if (this.selectedCategory === '全部商品') {
        return this.products;
      }
      return this.products.filter((item) => item.category === this.selectedCategory);
    },
  },
  watch: {
    '$route.query': {
      handler(query) {
        if (query.tab === 'services' || query.tab === 'products') {
          this.tab = query.tab;
        }
        if (query.series && this.seriesList.includes(query.series)) {
          this.selectedSeries = query.series;
        }
        if (query.category && this.categories.includes(query.category)) {
          this.selectedCategory = query.category;
        }
      },
    },
  },
  methods: {
    selectTab(tab) {
      this.tab = tab;
    },
    selectSeries(series) {
      this.selectedSeries = series;
    },
    selectCategory(category) {
      this.selectedCategory = category;
    },
  },
};
</script>

<style scoped>
.category-page {
  display: flex;
  min-height: calc(100vh - 162px);
  flex-direction: column;
  gap: 10px;
}

.category-tabs {
  display: flex;
  gap: 6px;
}

.category-tabs button {
  padding: 7px 14px;
  color: var(--text-regular);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: 12px;
  cursor: pointer;
}

.category-tabs button.is-active {
  color: #fff;
  background: var(--brand-primary);
  border-color: var(--brand-primary);
}

.category-body {
  display: grid;
  flex: 1;
  grid-template-columns: 92px minmax(0, 1fr);
  gap: 10px;
}

.category-body aside {
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.category-body aside button {
  display: flex;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  padding: 5px 8px;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  border-left: 3px solid transparent;
  font-size: 12px;
  text-align: left;
  cursor: pointer;
}

.category-body aside button span {
  color: var(--text-muted);
  font-size: 10px;
}

.category-body aside button.is-active {
  color: var(--brand-primary);
  background: #fff5f4;
  border-left-color: var(--brand-primary);
  font-weight: 600;
}

.category-body section {
  min-width: 0;
}

.category-body section > header {
  display: flex;
  min-height: 38px;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.category-body h1 {
  margin: 0;
  font-size: 16px;
}

.category-body header small {
  color: var(--text-muted);
  font-size: 10px;
}

.category-body header span {
  color: var(--text-muted);
  font-size: 11px;
}

.category-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

@media (max-width: 380px) {
  .category-body {
    grid-template-columns: 82px minmax(0, 1fr);
  }

  .category-grid {
    grid-template-columns: 1fr;
  }
}
</style>
