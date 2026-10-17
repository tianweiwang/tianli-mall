<template>
  <section class="page-panel" v-loading="loading">
    <h1 class="page-heading">订单详情</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" />
    <p v-if="loading" class="field-note" role="status">正在读取保存的订单信息</p>
    <template v-if="order">
      <dl class="order-facts">
        <div><dt>订单号</dt><dd>{{ order.order_id }}</dd></div>
        <div><dt>下单时间</dt><dd>{{ time(order.add_time) }}</dd></div>
        <div><dt>订单状态</dt><dd>{{ status(order) }}</dd></div>
        <div><dt>支付状态</dt><dd>{{ paymentStatus(order) }}</dd></div>
        <div><dt>取消状态</dt><dd>{{ order.is_cancel === 1 ? '已取消' : '未取消' }}</dd></div>
        <div><dt>退款状态码</dt><dd>{{ order.refund_status }}</dd></div>
        <div><dt>配送方式</dt><dd>{{ shipping(order.shipping_type) }}</dd></div>
        <div><dt>商品数量</dt><dd>{{ order.total_num }}</dd></div>
        <div><dt>商品金额（元）</dt><dd>{{ order.total_price }}</dd></div>
        <div><dt>{{ order.paid === 1 ? testPayment(order) ? '测试支付金额（元）' : '实付金额（元）' : '应付金额（元）' }}</dt><dd>{{ order.pay_price }}</dd></div>
        <div><dt>邮费（元）</dt><dd>{{ order.pay_postage }}</dd></div>
      </dl>
      <h2 class="section-heading">履约信息</h2>
      <p v-if="order.fulfillment_summary === null" class="field-note" role="status">履约资料暂不可用</p>
      <dl v-else class="order-facts">
        <div><dt>履约商品数量</dt><dd>{{ order.fulfillment_summary.original }}</dd></div>
        <div><dt>已发货数量</dt><dd>{{ order.fulfillment_summary.shipped }}</dd></div>
        <div><dt>待发货数量</dt><dd>{{ order.fulfillment_summary.to_ship }}</dd></div>
        <div><dt>已收货数量</dt><dd>{{ order.fulfillment_summary.received }}</dd></div>
        <div><dt>待收货数量</dt><dd>{{ order.fulfillment_summary.to_receive }}</dd></div>
        <div><dt>包裹数量</dt><dd>{{ order.fulfillment_summary.child_count }}</dd></div>
      </dl>
      <h2 class="section-heading">收货信息</h2>
      <dl class="order-facts">
        <div><dt>收货人</dt><dd>{{ order.recipient.real_name }}</dd></div>
        <div><dt>联系电话</dt><dd>{{ order.recipient.user_phone }}</dd></div>
        <div class="full-row"><dt>收货地址</dt><dd>{{ order.recipient.user_address }}</dd></div>
      </dl>
      <h2 class="section-heading">保存商品</h2>
      <el-table class="marketplace-table-scroll" v-table-scroll="'保存商品，可横向滚动'" :data="order.items" border>
        <el-table-column label="商品及规格" min-width="260"><template slot-scope="scope">
          <div class="order-item"><img :src="scope.row.image" class="table-image" alt="商品保存图片">
            <div class="order-text"><div>{{ scope.row.product_name }}</div><p class="field-note">{{ scope.row.sku_name }}</p></div></div>
        </template></el-table-column>
        <el-table-column prop="quantity" label="数量" width="80" />
        <el-table-column label="成交单价（元）" min-width="130"><template slot-scope="scope">{{ scope.row.unit_price }}</template></el-table-column>
        <el-table-column label="商品行金额（元）" min-width="145"><template slot-scope="scope">{{ scope.row.line_price }}</template></el-table-column>
        <el-table-column label="整行运费（元）" min-width="130"><template slot-scope="scope">{{ scope.row.postage_price }}</template></el-table-column>
      </el-table>
      <h2 class="section-heading">顾客留言</h2>
      <p class="saved-value">{{ order.mark === '' ? '未填写' : order.mark }}</p>
      <h2 class="section-heading">已保存表单</h2>
      <p v-if="!order.custom_form.length" class="field-note">此订单没有保存表单</p>
      <section v-for="group in order.custom_form" :key="String(group.product_id) + ':' + group.definition_sha256" class="saved-group">
        <h3>{{ group.product_name }}</h3>
        <dl class="saved-fields">
          <div v-for="field in group.fields" :key="field.field_index">
            <dt>{{ field.field_index + 1 }}. {{ field.title }}</dt>
            <dd v-if="field.label === 'img'">
              <div v-if="field.value.length" class="saved-images"><img v-for="(image, index) in field.value" :key="index" :src="image" alt="已保存表单图片"></div>
              <span v-else class="field-note">未填写</span>
            </dd>
            <dd v-else class="saved-value">{{ field.value === '' ? '未填写' : field.value }}</dd>
          </div>
        </dl>
      </section>
    </template>
    <div class="actions"><el-button @click="$router.push('/orders')">返回订单列表</el-button></div>
  </section>
</template>
<script>
import { api } from '../../api';
import { tableScroll } from '../../accessibility';
import { orderDetail, orderRecordId, orderStatus } from '../../domain/order';
import { formatTime } from '../../domain/people';

export default {
  directives: { 'table-scroll': tableScroll },
  data: () => ({ order: null, loading: false, error: '', revision: 0 }),
  created() { this.load(); },
  watch: { '$route.params.id'() { this.load(); } },
  beforeDestroy() { this.revision += 1; },
  methods: {
    time: formatTime, status: orderStatus,
    testPayment(order) { return (order.marketplace_trade_version === 1 || order.marketplace_trade_version === '1') && order.pay_type === 'marketplace_test'; },
    paymentStatus(order) { if (order.paid !== 1) return '未支付'; return this.testPayment(order) ? '已支付（测试支付）' : '已支付'; },
    shipping(value) { return ({ 1: '快递配送', 2: '到店自提' })[value] || '配送方式：' + value; },
    async load() {
      const revision = ++this.revision; this.order = null; this.loading = true; this.error = '';
      try {
        const id = orderRecordId(this.$route.params.id);
        const data = orderDetail(await api.order(id));
        if (revision !== this.revision) return;
        if (orderRecordId(data.id) !== id) throw new Error('订单详情与当前编号不一致，请返回列表重新查询');
        this.order = data;
      } catch (error) { if (revision === this.revision) this.error = error.message; }
      finally { if (revision === this.revision) this.loading = false; }
    },
  },
};
</script>
<style scoped>
.order-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; margin: 0; }
.order-facts > div, .saved-fields > div { min-width: 0; }
dt { margin: 0 0 6px; color: #606266; }
dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
.order-item { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
.order-item .table-image { flex: 0 0 52px; }
.order-text { min-width: 0; overflow-wrap: anywhere; }
.saved-value { white-space: pre-wrap; overflow-wrap: anywhere; }
.saved-group { margin-top: 18px; padding: 16px; border: 1px solid #ebeef5; }
.saved-group h3 { margin: 0 0 16px; font-size: 16px; overflow-wrap: anywhere; }
.saved-fields { display: grid; gap: 16px; margin: 0; }
.saved-fields dt { white-space: pre-wrap; overflow-wrap: anywhere; }
.saved-images { display: flex; flex-wrap: wrap; gap: 12px; }
.saved-images img { width: 110px; height: 90px; max-width: 100%; object-fit: contain; border: 1px solid #ebeef5; }
@media (max-width: 760px) { .order-facts { grid-template-columns: minmax(0, 1fr); } }
</style>
