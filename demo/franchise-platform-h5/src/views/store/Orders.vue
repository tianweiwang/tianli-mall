<template>
  <div class="admin-page">
    <section class="admin-filter-bar" aria-label="订单筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="订单号或商品名称" prefix-icon="el-icon-search" />
      <el-select v-model="status" aria-label="订单状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleOrders.length }} 笔</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleOrders" style="width: 100%" empty-text="暂无符合条件的订单">
        <el-table-column prop="id" label="订单号" min-width="165" />
        <el-table-column label="商品信息" min-width="250">
          <template slot-scope="{ row }">
            <div class="admin-product-cell">
              <img :src="row.items[0].image" :alt="row.items[0].name">
              <div><strong>{{ row.items[0].name }}</strong><span>{{ row.items[0].spec }} · 共 {{ itemCount(row) }} 件</span></div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="实付金额" width="120">
          <template slot-scope="{ row }"><strong>{{ formatMoney(row.paidCents) }}</strong></template>
        </el-table-column>
        <el-table-column label="预计收入" width="120">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.storeIncomeCents) }}</span></template>
        </el-table-column>
        <el-table-column label="下单时间" min-width="155">
          <template slot-scope="{ row }">{{ formatTime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer title="订单详情" :visible.sync="drawerOpen" size="460px">
      <div v-if="selectedOrder" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>订单信息</h3>
          <dl class="admin-detail-list">
            <div><dt>订单号</dt><dd>{{ selectedOrder.id }}</dd></div>
            <div><dt>订单状态</dt><dd><AdminStatusTag :status="selectedOrder.status" /></dd></div>
            <div><dt>门店归属</dt><dd>{{ store.name }}</dd></div>
            <div><dt>配送方式</dt><dd>品牌统一配送</dd></div>
            <div><dt>下单时间</dt><dd>{{ formatTime(selectedOrder.createdAt) }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>商品明细</h3>
          <div v-for="item in selectedOrder.items" :key="`${item.productId}-${item.spec}`" class="admin-product-cell order-detail-product">
            <img :src="item.image" :alt="item.name">
            <div><strong>{{ item.name }}</strong><span>{{ item.spec }} · {{ formatMoney(item.priceCents) }} × {{ item.quantity }}</span></div>
          </div>
        </section>
        <section class="admin-detail-section">
          <h3>金额拆分</h3>
          <dl class="admin-detail-list">
            <div><dt>订单实付</dt><dd>{{ formatMoney(selectedOrder.paidCents) }}</dd></div>
            <div><dt>品牌抽成</dt><dd>-{{ formatMoney(selectedOrder.brandCommissionCents) }}</dd></div>
            <div><dt>门店预计收入</dt><dd class="admin-value-positive">{{ formatMoney(selectedOrder.storeIncomeCents) }}</dd></div>
          </dl>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { activeStore, formatMoney, ordersForStore } from '../../mock/selectors';

export default {
  name: 'StoreOrders',
  components: { AdminStatusTag },
  data() {
    return {
      drawerOpen: false,
      keyword: '',
      selectedOrder: null,
      status: 'all',
      statusOptions: [
        { label: '已支付', value: 'paid' },
        { label: '配送中', value: 'shipping' },
        { label: '已完成', value: 'completed' },
        { label: '已退款', value: 'refunded' },
      ],
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    storeOrders() {
      return ordersForStore(this.state, this.store.id);
    },
    visibleOrders() {
      const keyword = this.keyword.toLowerCase();
      return this.storeOrders.filter((order) => {
        const matchesStatus = this.status === 'all' || order.status === this.status;
        const searchable = `${order.id} ${order.items.map((item) => item.name).join(' ')}`.toLowerCase();
        return matchesStatus && (!keyword || searchable.includes(keyword));
      });
    },
  },
  created() {
    this.openRequestedRecord();
  },
  watch: {
    '$route.query.recordId'() {
      this.openRequestedRecord();
    },
  },
  methods: {
    formatMoney,
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    itemCount(order) {
      return order.items.reduce((sum, item) => sum + item.quantity, 0);
    },
    openDetail(order) {
      this.selectedOrder = order;
      this.drawerOpen = true;
    },
    openRequestedRecord() {
      const recordId = this.$route?.query?.recordId;
      const order = recordId && this.storeOrders.find((item) => item.id === recordId);
      if (order) this.openDetail(order);
    },
  },
};
</script>

<style scoped>
.order-detail-product {
  margin-top: 12px;
}

.order-detail-product:first-of-type {
  margin-top: 0;
}
</style>
