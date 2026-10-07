<template>
  <div class="customer-home">
    <button type="button" class="shop-search" @click="openCategory">
      <Search :size="17" aria-hidden="true" />
      <span>搜索服务与商品</span>
    </button>

    <section class="store-summary" aria-label="当前门店信息">
      <div>
        <span class="store-summary__state">营业中</span>
        <h1>{{ store.name }}</h1>
        <p><MapPin :size="14" aria-hidden="true" />{{ store.address }}</p>
        <p><Clock3 :size="14" aria-hidden="true" />{{ store.openHours }}</p>
      </div>
      <router-link :to="bookingPath" aria-label="预约到店">
        <CalendarDays :size="24" aria-hidden="true" />
        <span>预约到店</span>
        <ChevronRight :size="16" aria-hidden="true" />
      </router-link>
    </section>

    <router-link :to="featuredPath" class="featured-product">
      <div>
        <span>门店精选</span>
        <h2>{{ featured.name }}</h2>
        <strong>{{ formatMoney(featured.priceCents) }}</strong>
        <small>{{ featured.series }} · 品牌统一配送</small>
      </div>
      <img :src="featured.image" :alt="featured.name">
    </router-link>

    <router-link v-if="liveCount > 0" :to="livePath" class="live-entry" aria-label="进入直播">
      <Video :size="24" aria-hidden="true" />
      <div><strong>{{ liveCount }} 个直播间正在直播</strong><span>进店好物同步上架，点击观看</span></div>
      <ChevronRight :size="16" aria-hidden="true" />
    </router-link>

    <nav class="shop-categories" aria-label="商品与服务分类">
      <router-link :to="servicesCategoryPath">
        <Activity :size="22" aria-hidden="true" />
        <span>门店项目</span>
      </router-link>
      <button type="button" @click="openCategory('营养补充')">
        <Sparkles :size="22" aria-hidden="true" />
        <span>营养补充</span>
      </button>
      <button type="button" @click="openCategory('健康穿戴')">
        <Watch :size="22" aria-hidden="true" />
        <span>健康穿戴</span>
      </button>
    </nav>

    <section class="customer-section">
      <header>
        <h2>热门服务</h2>
        <router-link :to="servicesCategoryPath">查看全部项目<ChevronRight :size="15" aria-hidden="true" /></router-link>
      </header>
      <div class="product-grid">
        <ServiceCard
          v-for="service in popularServices"
          :key="service.id"
          :service="service"
          :store-id="store.id"
        />
      </div>
    </section>

    <section class="customer-section">
      <header>
        <h2>推荐商品</h2>
        <router-link :to="categoryPath">查看全部<ChevronRight :size="15" aria-hidden="true" /></router-link>
      </header>
      <div class="product-grid">
        <ProductCard
          v-for="product in products"
          :key="product.id"
          :product="product"
          :store-id="store.id"
        />
      </div>
    </section>
  </div>
</template>

<script>
import {
  Activity,
  CalendarDays,
  ChevronRight,
  Clock3,
  MapPin,
  Search,
  Sparkles,
  Video,
  Watch,
} from 'lucide-vue';
import ProductCard from '../../components/customer/ProductCard.vue';
import ServiceCard from '../../components/customer/ServiceCard.vue';
import { formatMoney, liveRoomsForStore } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { productsForStore, servicesForStore } from '../../mock/selectors';

export default {
  name: 'CustomerHome',
  components: {
    Activity,
    CalendarDays,
    ChevronRight,
    Clock3,
    MapPin,
    ProductCard,
    Search,
    ServiceCard,
    Sparkles,
    Video,
    Watch,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    products() {
      return productsForStore(this.state, this.store.id);
    },
    popularServices() {
      return servicesForStore(this.state, this.store.id)
        .filter((item) => item.series !== '评估系列' && item.series !== '定制系列')
        .slice(0, 4);
    },
    featured() {
      return this.products[0];
    },
    featuredPath() {
      return this.featured
        ? `/c/store/${this.store.id}/product/${this.featured.id}`
        : `/c/store/${this.store.id}/category`;
    },
    categoryPath() {
      return `/c/store/${this.store.id}/category`;
    },
    servicesCategoryPath() {
      return `/c/store/${this.store.id}/category?tab=services`;
    },
    bookingPath() {
      return `/c/store/${this.store.id}/booking`;
    },
    livePath() {
      return `/c/store/${this.store.id}/live`;
    },
    liveCount() {
      return liveRoomsForStore(this.state, this.store.id)
        .filter((item) => item.status === 'live').length;
    },
  },
  methods: {
    formatMoney,
    openCategory(category) {
      this.$router.push({
        path: this.categoryPath,
        query: category ? { category } : {},
      });
    },
  },
};
</script>

<style scoped>
.customer-home {
  display: grid;
  gap: 12px;
}

.shop-search {
  display: flex;
  width: 100%;
  height: 38px;
  align-items: center;
  gap: 8px;
  padding: 0 13px;
  color: var(--text-muted);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  cursor: pointer;
}

.store-summary {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 92px;
  gap: 12px;
  padding: 16px;
  color: #fff;
  background: #2d3038;
  border-radius: var(--radius-lg);
}

.store-summary__state {
  color: #7ee2a7;
  font-size: 11px;
}

.store-summary h1 {
  margin: 2px 0 7px;
  font-size: 18px;
}

.store-summary p {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 3px 0;
  color: #c8ccd3;
  font-size: 11px;
}

.store-summary > a {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 4px;
  color: #fff;
  background: var(--brand-primary);
  border-radius: var(--radius-md);
  font-size: 12px;
}

.featured-product {
  display: grid;
  min-height: 164px;
  grid-template-columns: minmax(0, 1fr) 44%;
  align-items: center;
  overflow: hidden;
  padding: 18px 8px 18px 18px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.featured-product span,
.featured-product small {
  display: block;
  color: var(--text-muted);
  font-size: 11px;
}

.featured-product h2 {
  margin: 6px 0 9px;
  font-size: 17px;
  line-height: 1.35;
}

.featured-product strong {
  display: block;
  margin-bottom: 2px;
  color: var(--brand-primary);
  font-size: 20px;
}

.featured-product img {
  width: 100%;
  aspect-ratio: 1;
  object-fit: cover;
}

.live-entry {
  display: grid;
  min-height: 62px;
  grid-template-columns: 30px minmax(0, 1fr) 18px;
  align-items: center;
  gap: 9px;
  padding: 10px 14px;
  color: #fff;
  background: linear-gradient(135deg, #b93a28, #e06a3f);
  border-radius: var(--radius-lg);
}

.live-entry svg:first-child {
  color: #ffe6e0;
}

.live-entry strong,
.live-entry span {
  display: block;
}

.live-entry strong {
  font-size: 13px;
}

.live-entry span {
  margin-top: 2px;
  color: #ffe0d8;
  font-size: 10px;
}

.shop-categories {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 12px 6px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.shop-categories button,
.shop-categories a {
  display: flex;
  min-width: 0;
  min-height: 54px;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 5px;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  font-size: 12px;
  cursor: pointer;
}

.shop-categories svg {
  color: var(--brand-primary);
}

.customer-section > header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 5px 2px 10px;
}

.customer-section h2 {
  margin: 0;
  font-size: 17px;
}

.customer-section header a {
  display: inline-flex;
  align-items: center;
  color: var(--text-muted);
  font-size: 12px;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
</style>
