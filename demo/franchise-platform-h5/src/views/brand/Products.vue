<template>
  <div class="admin-page">
    <section class="admin-filter-bar" aria-label="品牌目录筛选">
      <div class="catalog-tabs" role="tablist" aria-label="目录类型">
        <button
          type="button"
          role="tab"
          :class="{ 'is-active': tab === 'services' }"
          @click="tab = 'services'"
        >服务项目 {{ state.services.length }}</button>
        <button
          type="button"
          role="tab"
          :class="{ 'is-active': tab === 'products' }"
          @click="tab = 'products'"
        >实物商品 {{ state.products.length }}</button>
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
      <el-button type="primary" :data-testid="`create-${tabType}`" @click="openCreate">
        新增{{ tab === 'services' ? '服务项目' : '商品' }}
      </el-button>
    </section>

    <section v-if="tab === 'services'" class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>集团服务项目</h2><p>加盟门店共享服务目录 · 单次与疗程统一定价</p></div>
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
        <el-table-column label="适用门店" min-width="150">
          <template slot-scope="{ row }">{{ storeNames(row.storeIds) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="190" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openService(row)">查看</el-button>
            <el-button type="text" :data-testid="`edit-service-${row.id}`" @click="openEdit(row)">编辑</el-button>
            <el-button type="text" :data-testid="`status-service-${row.id}`" @click="toggleStatus(row)">{{ row.status === 'active' ? '下架' : '上架' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section v-else class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>集团统一商品</h2><p>维养系列 · 品牌统一配送</p></div>
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
        <el-table-column prop="stock" label="集团库存" width="105" />
        <el-table-column prop="sales" label="累计销量" width="105" />
        <el-table-column label="适用门店" min-width="150">
          <template slot-scope="{ row }">{{ storeNames(row.storeIds) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="190" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openProduct(row)">查看</el-button>
            <el-button type="text" :data-testid="`edit-product-${row.id}`" @click="openEdit(row)">编辑</el-button>
            <el-button type="text" :data-testid="`status-product-${row.id}`" @click="toggleStatus(row)">{{ row.status === 'active' ? '下架' : '上架' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :title="selectedService ? '服务详情' : '商品详情'" :visible.sync="drawerOpen" size="430px">
      <div v-if="selectedService" class="admin-detail-body">
        <section class="brand-product-preview">
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
            <div><dt>适用门店</dt><dd>{{ storeNames(selectedService.storeIds) }}</dd></div>
            <div><dt>项目状态</dt><dd>{{ statusText(selectedService.status) }}</dd></div>
          </dl>
        </section>
      </div>
      <div v-if="selectedProduct" class="admin-detail-body">
        <section class="brand-product-preview">
          <img :src="selectedProduct.image" :alt="selectedProduct.name">
          <h3>{{ selectedProduct.name }}</h3>
          <strong>{{ formatMoney(selectedProduct.priceCents) }} / {{ selectedProduct.unit }}</strong>
        </section>
        <section class="admin-detail-section">
          <dl class="admin-detail-list">
            <div><dt>商品系列</dt><dd>{{ selectedProduct.series }}</dd></div>
            <div><dt>商品分类</dt><dd>{{ selectedProduct.category }}</dd></div>
            <div><dt>销售规格</dt><dd>{{ selectedProduct.specs.join(' / ') }}</dd></div>
            <div><dt>集团库存</dt><dd>{{ selectedProduct.stock }}</dd></div>
            <div><dt>累计销量</dt><dd>{{ selectedProduct.sales }}</dd></div>
            <div><dt>适用门店</dt><dd>{{ storeNames(selectedProduct.storeIds) }}</dd></div>
            <div><dt>销售状态</dt><dd><AdminStatusTag :status="selectedProduct.status" :text="statusText(selectedProduct.status)" /></dd></div>
          </dl>
        </section>
      </div>
    </el-drawer>
    <CatalogEditorDialog
      :visible.sync="editorOpen"
      :type="tabType"
      :value="editingItem"
      :stores="activeStores"
      @submit="saveCatalogItem"
    />
  </div>
</template>

<script>
import { Activity } from 'lucide-vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import CatalogEditorDialog from '../../components/brand/CatalogEditorDialog.vue';
import {
  createCatalogItem,
  setCatalogItemStatus,
  updateCatalogItem,
} from '../../mock/catalogTransitions';
import { demoStore } from '../../mock/store';
import { catalogOverview, formatMoney, serviceSeriesList } from '../../mock/selectors';

export default {
  name: 'BrandProducts',
  components: { Activity, AdminStatusTag, CatalogEditorDialog },
  data() {
    return {
      category: 'all',
      drawerOpen: false,
      editorOpen: false,
      editingItem: null,
      keyword: '',
      selectedProduct: null,
      selectedService: null,
      series: 'all',
      status: 'all',
      tab: 'services',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return catalogOverview(this.state);
    },
    products() {
      return this.state.products;
    },
    services() {
      return this.state.services;
    },
    categories() {
      return [...new Set(this.products.map((item) => item.category))];
    },
    seriesList() {
      return serviceSeriesList(this.state);
    },
    activeStores() {
      return this.state.stores.filter((item) => item.status === 'active');
    },
    tabType() {
      return this.tab === 'services' ? 'service' : 'product';
    },
    visibleServices() {
      const keyword = this.keyword.toLowerCase();
      return this.services.filter((service) => {
        const matchesSeries = this.series === 'all' || service.series === this.series;
        const matchesStatus = this.status === 'all' || service.status === this.status;
        return matchesSeries && matchesStatus
          && (!keyword || service.name.toLowerCase().includes(keyword));
      });
    },
    visibleProducts() {
      const keyword = this.keyword.toLowerCase();
      return this.products.filter((product) => {
        const matchesCategory = this.category === 'all' || product.category === this.category;
        const matchesStatus = this.status === 'all' || product.status === this.status;
        return matchesCategory && matchesStatus
          && (!keyword || product.name.toLowerCase().includes(keyword));
      });
    },
  },
  methods: {
    formatMoney,
    openCreate() {
      this.editingItem = null;
      this.editorOpen = true;
    },
    openEdit(item) {
      this.editingItem = item;
      this.editorOpen = true;
    },
    saveCatalogItem(payload) {
      try {
        demoStore.commit((draft) => {
          if (this.editingItem) {
            updateCatalogItem(draft, this.tabType, this.editingItem.id, payload);
          } else {
            createCatalogItem(draft, this.tabType, payload);
          }
        });
        this.editorOpen = false;
        this.editingItem = null;
        this.$message.success('目录信息已保存');
      } catch (error) {
        this.$message.error(error.message);
      }
    },
    async toggleStatus(item) {
      const nextStatus = item.status === 'active' ? 'inactive' : 'active';
      const action = nextStatus === 'active' ? '上架' : '下架';
      try {
        await this.$confirm(`确认${action}“${item.name}”？`, `${action}目录`, {
          confirmButtonText: `确认${action}`,
          cancelButtonText: '取消',
          type: 'warning',
        });
        demoStore.commit((draft) => {
          setCatalogItemStatus(draft, this.tabType, item.id, nextStatus);
        });
        this.$message.success(`已${action}`);
      } catch (error) {
        if (error !== 'cancel' && error?.message !== 'cancel') {
          this.$message.error(error.message || `${action}失败`);
        }
      }
    },
    statusText(status) {
      return status === 'active' ? '已上架' : '已下架';
    },
    storeNames(storeIds) {
      const names = (storeIds || []).map(
        (storeId) => this.state.stores.find((store) => store.id === storeId)?.name || storeId,
      );
      return names.join('、') || '未投放';
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

.brand-product-preview {
  padding: 4px 0 20px;
  text-align: center;
  border-bottom: 1px solid var(--border);
}

.brand-product-preview img {
  width: 150px;
  height: 150px;
  object-fit: cover;
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
}

.brand-product-preview h3 {
  margin: 12px 0 2px;
  font-size: 15px;
}

.brand-product-preview > strong {
  color: var(--brand-primary);
  font-size: 20px;
}
</style>
