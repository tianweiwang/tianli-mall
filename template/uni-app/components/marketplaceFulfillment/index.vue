<template>
  <view class="fulfillment">
    <view v-if="loading" class="notice" role="status">正在读取包裹资料…</view>
    <view v-if="error" class="error" role="alert">
      <text>{{ error }}</text>
      <button class="control" :disabled="loading" @click="$emit('retry')">重新加载</button>
    </view>
    <template v-if="view && view.kind === 'root'">
      <view class="heading">履约情况</view>
      <view class="notice" role="status">{{ rootText(view.summary, view.aftersale) }}</view>
      <view v-if="view.summary" class="quantities">
        <text>共 {{ view.summary.original }} 件</text><text>已发 {{ view.summary.shipped }} 件</text>
        <text>已收 {{ view.summary.received }} 件</text><text>包裹 {{ view.summary.child_count }} 个</text>
      </view>
      <view v-if="view.aftersale" class="line secondary">未发取消 {{ view.summary.cancelled_unshipped }} 件 · 已验收退件 {{ view.summary.returned_accepted }} 件 · 暂停发货 {{ view.aftersale.pending_cancel_quantity }} 件 · 待处理退件 {{ view.aftersale.pending_return_quantity }} 件</view>
      <view v-if="view.aftersale" class="line secondary">履约结束时间（含取消、退件）：{{ time(view.aftersale.fulfillment_completed_at) }}</view>
      <template v-if="view.children !== undefined">
        <view v-if="view.children === null" class="notice">包裹资料暂不可用</view>
        <template v-else>
          <view class="heading packages-heading">履约包裹</view>
          <view v-if="view.children.count === 0" class="notice">暂无履约包裹</view>
          <view v-else-if="!view.children.list.length" class="notice">本页暂无包裹</view>
          <view v-for="child in view.children.list" :key="child.id" class="package-card">
            <view class="package-title">包裹 {{ child.order_id }}</view>
            <view class="line">{{ child.merchant_name_snapshot }}</view>
            <view class="line">{{ childText(child) }} · 已发 {{ child.shipped }} 件 / 已收 {{ child.received }} 件</view>
            <view v-if="child.schema === 2" class="line secondary">已验收退件 {{ child.returned_accepted }} 件 · 待处理退件 {{ child.aftersale.pending_return_quantity }} 件</view>
            <view class="line">承运：{{ child.carrier_name || '—' }}</view>
            <view class="line">物流单号：{{ child.tracking_no || '—' }}</view>
            <view class="line secondary">对应付款订单：{{ child.root_order_no }}</view>
            <view class="line secondary">{{ child.pay_type === 'marketplace_test' ? '测试支付' : child.pay_type_name }}</view>
            <view v-for="item in visibleItems(child)" :key="item.id" class="saved-item">
              <image v-if="item.image" class="item-image" :src="item.image" mode="aspectFill" />
              <view class="item-copy"><view>{{ item.product_name }}</view><view class="secondary">{{ item.sku_name }}</view>
                <view>￥{{ item.unit_price }} × {{ item.quantity }}</view>
              </view>
            </view>
            <view class="controls">
              <button v-if="child.items.length > 1" class="control" @click="toggle(child.id)">{{ expanded[child.id] ? '收起商品' : '查看全部商品' }}</button>
              <button v-if="canViewPackage" class="control" :disabled="loading || !!error" @click="$emit('view-package', child)">查看包裹</button>
            </view>
          </view>
          <view v-if="view.children.count > 0" class="pagination">
            <text class="page-status">第 {{ view.children.page }} 页 · 共 {{ view.children.count }} 个包裹</text>
            <view class="controls">
              <button v-if="!view.children.list.length && view.children.page > 1" class="control" :disabled="loading" @click="page(1)">回到第一页</button>
              <button class="control" :disabled="loading || view.children.page <= 1" @click="page(view.children.page - 1)">上一页</button>
              <button class="control" :disabled="loading || view.children.page * view.children.limit >= view.children.count" @click="page(view.children.page + 1)">下一页</button>
            </view>
          </view>
        </template>
      </template>
    </template>
    <template v-else-if="view && view.kind === 'child'">
      <view class="heading">履约包裹 {{ view.package.order_id }}</view>
      <view class="notice" role="status">{{ childText(view.package) }}</view>
      <view class="line">{{ view.package.merchant_name_snapshot }}</view>
      <view class="line">已发 {{ view.package.shipped }} 件 / 已收 {{ view.package.received }} 件 / 待收 {{ view.package.to_receive }} 件</view>
      <view class="line">对应付款订单：{{ view.package.root_order_no }}</view>
      <view class="line">付款方式：{{ view.package.pay_type === 'marketplace_test' ? '测试支付' : view.package.pay_type_name }}</view>
      <view class="line">付款时间：{{ time(view.package.pay_time) }}</view>
      <view class="line">承运：{{ view.package.carrier_name || '—' }}</view>
      <view class="line">物流单号：{{ view.package.tracking_no || '—' }}</view>
      <view class="line">发货时间：{{ time(view.package.shipped_at) }}</view>
      <view class="line">预计自动收货时间：{{ time(view.package.receipt_due_at) }}</view>
      <view class="line">最近收货时间：{{ time(view.package.last_received_at) }}</view>
      <view class="line">全部收货时间：{{ time(view.package.received_at) }}</view>
      <view v-if="view.package.schema === 2" class="line secondary">已验收退件 {{ view.package.returned_accepted }} 件 · 待处理退件 {{ view.package.aftersale.pending_return_quantity }} 件</view>
      <view v-if="view.package.schema === 2" class="line secondary">履约结束时间（含退件）：{{ time(view.package.aftersale.fulfillment_completed_at) }}</view>
      <view v-for="item in view.package.items" :key="item.id" class="saved-item">
        <image v-if="item.image" class="item-image" :src="item.image" mode="aspectFill" />
        <view class="item-copy"><view>{{ item.product_name }}</view><view class="secondary">{{ item.sku_name }}</view>
          <view>￥{{ item.unit_price }} × {{ item.quantity }}</view><view class="secondary">商品金额 ￥{{ item.line_price }} · 运费 ￥{{ item.postage_price }}</view>
        </view>
      </view>
      <view v-if="view.package.recipient" class="detail-section">
        <view class="heading">收件资料</view><view class="line">{{ view.package.recipient.real_name }} {{ view.package.recipient.user_phone }}</view>
        <view class="line">{{ view.package.recipient.user_address }}</view><view v-if="view.package.mark" class="line">备注：{{ view.package.mark }}</view>
      </view>
      <view v-for="group in view.package.custom_form" :key="group.product_id" class="detail-section">
        <view class="heading">{{ group.product_name }} · 保存表单</view>
        <view v-for="field in group.fields" :key="field.field_index" class="line">
          <view>{{ field.title }}</view>
          <view v-if="field.label === 'img'" class="form-images"><image v-for="(src, index) in field.value" :key="index" :src="orderFormImageSource(group,field,index)" mode="aspectFit" @click="previewOrderFormImages(group,field,index)" /></view>
          <text v-else>{{ field.value || '—' }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<script>
import orderFormImages from '@/mixins/marketplace-order-form-images.js';
import { rootFulfillmentText, childFulfillmentText, fulfillmentTime } from '@/utils/marketplace-fulfillment.js';

export default {
  name: 'MarketplaceFulfillment',
  mixins: [orderFormImages],
  props: {
    view: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    canViewPackage: { type: Boolean, default: false },
    formImagesActive: { type: Boolean, default: true },
    formReader: { type: String, default: 'customer_order', validator: value => ['customer_order', 'mobile_order'].includes(value) }
  },
  data() { return { expanded: {} }; },
  computed: {
    orderFormImageContext() { return this.view && this.view.kind === 'child' ? {kind: this.formReader, rootId: this.view.package.root_order_id, childId: this.view.package.id} : null; },
    orderFormImageGroups() { return this.view && this.view.kind === 'child' ? this.view.package.custom_form : []; },
    orderFormImagesActive() { return this.formImagesActive && !this.loading && !this.error; }
  },
  watch: { view() { this.expanded = {}; } },
  methods: {
    rootText: rootFulfillmentText,
    childText: childFulfillmentText,
    time: fulfillmentTime,
    visibleItems(child) { return this.expanded[child.id] ? child.items : child.items.slice(0, 1); },
    toggle(id) { this.$set(this.expanded, id, !this.expanded[id]); },
    page(value) { if (!this.loading) this.$emit('page-change', value); }
  }
};
</script>

<style scoped lang="scss">
.fulfillment { box-sizing: border-box; width: 100%; min-width: 0; padding: 30rpx; background: #fff; color: #282828; font-size: 28rpx; line-height: 1.6; overflow-wrap: anywhere; word-break: break-word; }
.heading { font-size: 30rpx; font-weight: 600; }
.packages-heading, .detail-section { margin-top: 28rpx; }
.notice { padding: 12rpx 0; color: #666; }
.error { padding: 18rpx; margin-bottom: 20rpx; border-left: 3px solid #c45656; background: #fff4f4; color: #9b3232; }
.error > text { display: block; }
.quantities { display: flex; flex-wrap: wrap; gap: 12rpx 24rpx; }
.line { margin-top: 8rpx; min-width: 0; }
.secondary, .page-status { color: #868686; font-size: 26rpx; }
.package-card { min-width: 0; margin-top: 20rpx; padding: 24rpx; border: 1px solid #eee; border-radius: 12rpx; }
.package-title { font-weight: 600; }
.saved-item { display: flex; align-items: flex-start; gap: 20rpx; padding: 20rpx 0; }
.item-image { flex: 0 0 112rpx; width: 112rpx; height: 112rpx; border-radius: 8rpx; }
.item-copy { flex: 1; min-width: 0; }
.controls { display: flex; align-items: stretch; justify-content: center; flex-wrap: wrap; gap: 16rpx; margin-top: 16rpx; min-width: 0; }
.control { display: flex; align-items: center; justify-content: center; box-sizing: border-box; flex: 0 1 auto; min-width: 0; max-width: 100%; min-height: 44px; height: auto; margin: 0; padding: 10px 16px; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: var(--view-theme, #333); font-size: 26rpx; font-weight: 400; line-height: 1.4; text-align: center; white-space: normal; }
.control::after { border: 0; }
.control[disabled] { border-color: #ddd; background: #f5f5f5; color: #999; }
.pagination { margin-top: 24rpx; text-align: center; }
.form-images { display: flex; flex-wrap: wrap; gap: 12rpx; }
.form-images image { width: 120rpx; height: 120rpx; max-width: 100%; }
</style>
