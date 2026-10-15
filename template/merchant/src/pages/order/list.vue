<template>
  <section class="page-panel">
    <h1 class="page-heading">订单列表</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" />
    <el-form class="toolbar order-query" inline @submit.native.prevent="search">
      <el-form-item label="完整订单号" :for="fieldId('order-number')">
        <el-input v-model="filters.order_id" maxlength="32" placeholder="填写完整订单号" :disabled="loading"
          :id="fieldId('order-number')" :label="'完整订单号'" />
      </el-form-item>
      <el-form-item label="支付状态" :for="fieldId('order-paid')">
        <el-select v-model="filters.paid" :disabled="loading" :id="fieldId('order-paid')" v-control-label="'支付状态'">
          <el-option value="" label="全部" /><el-option :value="0" label="未支付" /><el-option :value="1" label="已支付" />
        </el-select>
      </el-form-item>
      <el-form-item label="取消状态" :for="fieldId('order-cancelled')">
        <el-select v-model="filters.is_cancel" :disabled="loading" :id="fieldId('order-cancelled')" v-control-label="'取消状态'">
          <el-option value="" label="全部" /><el-option :value="0" label="未取消" /><el-option :value="1" label="已取消" />
        </el-select>
      </el-form-item>
      <el-form-item><el-button type="primary" native-type="submit" :loading="loading" :disabled="loading">查询</el-button></el-form-item>
    </el-form>
    <el-table v-loading="loading" :data="rows" border :empty-text="loading ? '正在读取订单' : error ? '订单未读取' : '暂无符合条件的订单'">
      <el-table-column label="订单" min-width="205"><template slot-scope="scope">
        <div class="order-text">{{ scope.row.order_id }}</div><p class="field-note">{{ time(scope.row.add_time) }}</p>
      </template></el-table-column>
      <el-table-column label="保存商品" min-width="280"><template slot-scope="scope">
        <div v-for="item in scope.row.items" :key="item.id" class="order-item">
          <img :src="item.image" class="table-image" alt="商品保存图片">
          <div class="order-text"><div>{{ item.product_name }}</div><p class="field-note">{{ item.sku_name }}</p>
            <div>¥{{ item.unit_price }} × {{ item.quantity }}</div></div>
        </div>
      </template></el-table-column>
      <el-table-column label="状态" min-width="130"><template slot-scope="scope">
        <div>{{ status(scope.row) }}</div><p class="field-note">{{ scope.row.paid === 1 ? '已支付' : '未支付' }} · {{ scope.row.is_cancel === 1 ? '已取消' : '未取消' }}</p>
      </template></el-table-column>
      <el-table-column prop="total_num" label="数量" width="80" />
      <el-table-column label="商品金额（元）" min-width="130"><template slot-scope="scope">{{ scope.row.total_price }}</template></el-table-column>
      <el-table-column label="结算金额（元）" min-width="130"><template slot-scope="scope">{{ scope.row.pay_price }}</template></el-table-column>
      <el-table-column label="邮费（元）" min-width="115"><template slot-scope="scope">{{ scope.row.pay_postage }}</template></el-table-column>
      <el-table-column label="操作" min-width="100"><template slot-scope="scope">
        <el-button size="mini" :disabled="loading" @click="view(scope.row)">查看详情</el-button>
      </template></el-table-column>
    </el-table>
    <div class="pagination"><el-pagination :current-page="page" :page-size="limit" :pager-count="5" :total="count"
      :disabled="loading" layout="prev, pager, next" @current-change="changePage" /></div>
  </section>
</template>
<script>
import { api } from '../../api';
import { fieldNames } from '../../accessibility';
import { orderList, orderQuery, orderRecordId, orderStatus } from '../../domain/order';
import { formatTime } from '../../domain/people';

export default {
  mixins: [fieldNames],
  data: () => ({ filters: { order_id: '', paid: '', is_cancel: '' }, rows: [], count: 0, page: 1, limit: 20, loading: false, error: '', revision: 0 }),
  created() { this.load(); },
  beforeDestroy() { this.revision += 1; },
  methods: {
    time: formatTime, status: orderStatus,
    search() { if (this.loading) return; this.page = 1; this.load(); },
    changePage(page) { if (this.loading) return; this.page = page; this.load(); },
    view(order) { this.$router.push({ name: 'order-detail', params: { id: orderRecordId(order.id) } }); },
    async load() {
      if (this.loading) return;
      const revision = ++this.revision; this.loading = true; this.error = '';
      try {
        const data = orderList(await api.orders(orderQuery(this.filters, this.page, this.limit)));
        if (revision !== this.revision) return;
        this.rows = data.list; this.count = data.count;
      } catch (error) {
        if (revision === this.revision) { this.rows = []; this.count = 0; this.error = error.message; }
      } finally { if (revision === this.revision) this.loading = false; }
    },
  },
};
</script>
<style scoped>
.order-query .el-form-item { margin: 0; max-width: 100%; }
.order-item { display: flex; align-items: flex-start; gap: 12px; min-width: 0; padding: 6px 0; }
.order-item .table-image { flex: 0 0 52px; }
.order-text { min-width: 0; overflow-wrap: anywhere; white-space: normal; }
@media (max-width: 760px) {
  .order-query { display: block; }
  .order-query .el-form-item { display: block; margin-bottom: 12px; }
}
</style>
