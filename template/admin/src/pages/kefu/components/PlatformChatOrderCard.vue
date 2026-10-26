<template>
  <div class="platform-chat-order-card">
    <div class="card-heading">咨询订单摘要</div>
    <div class="card-meta">订单号：{{ order.order_id }}</div>
    <div class="card-meta">商家：{{ order.merchant_name_snapshot }}</div>
    <div class="card-meta">下单时间：{{ order.add_time_y }} {{ order.add_time_h }}</div>
    <div v-for="item in order.cartInfo" :key="item.id" class="card-product">
      <img :src="item.productInfo.image" alt="" class="card-image" />
      <div class="card-product-info">
        <div class="card-product-name">{{ item.productInfo.store_name }}</div>
        <div class="card-meta">规格：{{ item.sku_name }}</div>
        <div class="card-meta">数量：{{ item.cart_num }}</div>
        <div>下单单价：¥{{ item.productInfo.price }}</div>
      </div>
    </div>
    <div class="card-summary">
      <div>商品总数量：{{ order.total_num }}</div>
      <div>{{ order.paid === 1 ? '实付金额' : '订单金额' }}：¥{{ order.pay_price }}</div>
    </div>
    <div class="card-note">仅供咨询查看</div>
  </div>
</template>

<script>
export default {
  name: 'PlatformChatOrderCard',
  props: {
    order: { type: Object, required: true },
  },
};
</script>

<style scoped>
.platform-chat-order-card {
  display: inline-block;
  width: 320px;
  max-width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 12px;
  border: 1px solid #e7e9ed;
  border-radius: 8px;
  background: #fff;
  color: #333;
  font-size: 13px;
  line-height: 1.5;
  text-align: left;
  overflow-wrap: anywhere;
}
.card-heading { margin-bottom: 6px; font-size: 14px; font-weight: 600; }
.card-meta, .card-note { color: #606266; }
.card-product { display: flex; align-items: flex-start; gap: 10px; margin-top: 12px; }
.card-image { flex: 0 0 48px; width: 48px; height: 48px; border-radius: 4px; object-fit: cover; }
.card-product-info { flex: 1; min-width: 0; }
.card-product-name { font-weight: 500; }
.card-summary { margin-top: 12px; padding-top: 8px; border-top: 1px solid #eceff3; }
.card-note { margin-top: 6px; font-size: 12px; }
</style>
