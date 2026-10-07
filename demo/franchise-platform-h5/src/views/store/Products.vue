<template>
  <div class="admin-page" data-testid="store-products-readonly">
    <section class="admin-filter-bar" aria-label="服务与商品筛选">
      <div class="catalog-tabs" role="tablist" aria-label="目录类型">
        <button
          type="button"
          role="tab"
          :class="{ 'is-active': tab === 'services' }"
          @click="tab = 'services'"
        >服务项目 {{ services.length }}</button>
        <button
          type="button"
          role="tab"
          :class="{ 'is-active': tab === 'products' }"
          @click="tab = 'products'"
        >实物商品 {{ products.length }}</button>
      </div>
      <el-input v-if="tab === 'services'" v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="项目名称" prefix-icon="el-icon-search" />
      <el-select v-if="tab === 'services'" v-model="series" aria-label="服务系列">
        <el-option label="全部系列" value="all" />
        <el-option v-for="item in seriesList" :key="item" :label="item" :value="item" />
      </el-select>
      <el-input v-else v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="商品名称" prefix-icon="el-icon-search" />
      <el-select v-else v-model="category" aria-label="商品分类">
        <el-option label="全部分类" value="all" />
        <el-option v-for="item in categories" :key="item" :label="item" :value="item" />
      </el-select>
      <span class="admin-filter-bar__count">{{ tab === 'services' ? `共 ${visibleServices.length} 项` : `共 ${visibleProducts.length} 款` }}</span>
    </section>

    <section v-if="tab === 'services'" class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>本店服务项目</h2><p>集团统一服务目录 · 当前门店可预约项目</p></div>
      </header>
      <el-table :data="visibleServices" style="width: 100%" empty-text="暂无符合条件的服务项目">
        <el-table-column label="服务信息" min-width="240">
          <template slot-scope="{ row }">
            <div class="admin-product-cell">
              <div class="catalog-service-icon"><Activity :size="18" aria-hidden="true" /></div>
              <div><strong>{{ row.name }}</strong><span>{{ row.series }} · {{ row.durationMinutes }} 分钟</span></div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="系列" prop="series" width="120" />
        <el-table-column label="单次价" width="120">
          <template slot-scope="{ row }">
            <strong>{{ row.priceModel === 'quote' ? formatMoney(row.unitPriceCents) + ' 起' : formatMoney(row.unitPriceCents) }}</strong>
          </template>
        </el-table-column>
        <el-table-column label="疗程价" width="150">
          <template slot-scope="{ row }">
            <template v-if="row.packagePriceCents">{{ formatMoney(row.packagePriceCents) }} / {{ row.packageCount }}{{ row.packageUnit }}</template>
            <span v-else>到店定制</span>
          </template>
        </el-table-column>
        <el-table-column label="本店可约" width="110">
          <template slot-scope="{ row }">{{ availabilityText(row) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openService(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <section v-else class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>本店商品</h2><p>集团统一商品 · 当前门店销售目录</p></div>
      </header>
      <el-table :data="visibleProducts" style="width: 100%" empty-text="暂无符合条件的商品">
        <el-table-column label="商品信息" min-width="300">
          <template slot-scope="{ row }">
            <div class="admin-product-cell">
              <img :src="row.image" :alt="row.name">
              <div><strong>{{ row.name }}</strong><span>{{ row.specs.join(' / ') }} · {{ row.unit }}</span></div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="category" label="分类" width="130" />
        <el-table-column label="统一售价" width="140">
          <template slot-scope="{ row }"><strong>{{ formatMoney(row.priceCents) }}</strong><span class="catalog-price-unit">/ {{ row.unit }}</span></template>
        </el-table-column>
        <el-table-column label="参考库存" width="110" prop="stock" />
        <el-table-column label="累计销量" width="110" prop="sales" />
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openProduct(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :title="selectedService ? '服务详情' : '商品详情'" :visible.sync="drawerOpen" size="430px">
      <div v-if="selectedService" class="admin-detail-body">
        <section class="store-product-preview">
          <div class="catalog-service-icon catalog-service-icon--lg"><Activity :size="34" aria-hidden="true" /></div>
          <h3>{{ selectedService.name }}</h3>
          <strong>{{ selectedService.priceModel === 'quote' ? formatMoney(selectedService.unitPriceCents) + ' 起' : formatMoney(selectedService.unitPriceCents) }}</strong>
        </section>
        <section class="admin-detail-section">
          <dl class="admin-detail-list">
            <div><dt>所属系列</dt><dd>{{ selectedService.series }}</dd></div>
            <div><dt>服务时长</dt><dd>{{ selectedService.durationMinutes }} 分钟</dd></div>
            <div v-if="selectedService.packagePriceCents"><dt>疗程方案</dt><dd>{{ formatMoney(selectedService.packagePriceCents) }} / {{ selectedService.packageCount }}{{ selectedService.packageUnit }}</dd></div>
            <div v-else><dt>价格模式</dt><dd>到店评估后定制报价</dd></div>
            <div><dt>项目说明</dt><dd>{{ selectedService.description }}</dd></div>
          </dl>
        </section>
      </div>
      <div v-if="selectedProduct" class="admin-detail-body">
        <section class="store-product-preview">
          <img :src="selectedProduct.image" :alt="selectedProduct.name">
          <h3>{{ selectedProduct.name }}</h3>
          <strong>{{ formatMoney(selectedProduct.priceCents) }} / {{ selectedProduct.unit }}</strong>
        </section>
        <section class="admin-detail-section">
          <dl class="admin-detail-list">
            <div><dt>商品系列</dt><dd>{{ selectedProduct.series }}</dd></div>
            <div><dt>商品分类</dt><dd>{{ selectedProduct.category }}</dd></div>
            <div><dt>销售规格</dt><dd>{{ selectedProduct.specs.join(' / ') }}</dd></div>
            <div><dt>参考库存</dt><dd>{{ selectedProduct.stock }}</dd></div>
            <div><dt>累计销量</dt><dd>{{ selectedProduct.sales }}</dd></div>
            <div><dt>销售状态</dt><dd><AdminStatusTag :status="selectedProduct.status" /></dd></div>
          </dl>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { Activity } from 'lucide-vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import {
  formatMoney,
  productsForStore,
  serviceAvailability,
  servicesForStore,
} from '../../mock/selectors';

export default {
  name: 'StoreProducts',
  components: { Activity, AdminStatusTag },
  data() {
    return {
      category: 'all',
      drawerOpen: false,
      keyword: '',
      selectedProduct: null,
      selectedService: null,
      series: 'all',
      tab: 'services',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.state.activeStoreId);
    },
    products() {
      return productsForStore(this.state, this.store.id);
    },
    services() {
      return servicesForStore(this.state, this.store.id);
    },
    categories() {
      return [...new Set(this.products.map((item) => item.category))];
    },
    seriesList() {
      return [...new Set(this.services.map((item) => item.series))];
    },
    visibleServices() {
      const keyword = this.keyword.toLowerCase();
      return this.services.filter((service) => {
        const matchesSeries = this.series === 'all' || service.series === this.series;
        return matchesSeries && (!keyword || service.name.toLowerCase().includes(keyword));
      });
    },
    visibleProducts() {
      const keyword = this.keyword.toLowerCase();
      return this.products.filter((product) => (
        !keyword || product.name.toLowerCase().includes(keyword)
      ) && (this.category === 'all' || product.category === this.category));
    },
  },
  methods: {
    formatMoney,
    availabilityText(service) {
      const availability = serviceAvailability(this.state, this.store.id, service.id);
      if (availability.hasOnlineBooking) {
        return `剩余 ${availability.remainingTotal} 个名额`;
      }
      return '到店咨询';
    },
    openProduct(product) {
      this.selectedProduct = product;
      this.selectedService = null;
      this.drawerOpen = true;
    },
    openService(service) {
      this.selectedService = service;
      this.selectedProduct = null;
      this.drawerOpen = true;
    },
  },
};
</script>

<style scoped>
.catalog-tabs {
  display: flex;
  gap: 6px;
}

.catalog-tabs button {
  padding: 7px 14px;
  color: var(--text-regular);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: 12px;
  cursor: pointer;
}

.catalog-tabs button.is-active {
  color: #fff;
  background: var(--brand-primary);
  border-color: var(--brand-primary);
}

.catalog-service-icon {
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 auto;
  place-items: center;
  color: var(--brand-primary);
  background: #fff5f4;
  border-radius: var(--radius-md);
}

.catalog-service-icon--lg {
  width: 84px;
  height: 84px;
  margin: 0 auto;
  border-radius: 50%;
}

.catalog-price-unit {
  margin-left: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.store-product-preview {
  padding: 4px 0 20px;
  text-align: center;
  border-bottom: 1px solid var(--border);
}

.store-product-preview img {
  width: 150px;
  height: 150px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.store-product-preview h3 {
  margin: 12px 0 2px;
  font-size: 15px;
}

.store-product-preview > strong {
  color: var(--brand-primary);
  font-size: 20px;
}
</style>
