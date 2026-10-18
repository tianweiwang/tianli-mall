<template>
  <view class="receipt-controls">
    <view v-if="feedback" class="receipt-notice" role="status">{{ feedback }}</view>
    <view v-if="error" class="receipt-error" role="alert">{{ error }}</view>
    <view v-if="sending" class="receipt-notice" role="status">正在提交本次收货，请稍候…</view>
    <view v-if="pending" class="receipt-pending">
      <view class="receipt-notice">本次提交结果尚未确认。重试会保持相同的收货内容。离开或重载页面可能丢失重试内容，先前结果也可能已经保存，请先重试确认。</view>
      <button class="receipt-button" :disabled="sending || reading" @click="$emit('retry')">重试本次请求</button>
    </view>
    <template v-else-if="receipt && receipt.allowed_actions.receive && !disabled">
      <view class="receipt-heading">确认本包裹收货</view>
      <view class="receipt-notice">仅确认已实际收到的商品。当前资料可确认 {{ eligibleTotal }} 件，提交时按最新状态复核。</view>
      <view class="receipt-buttons">
        <button class="receipt-button" :disabled="sending || reading" @click="$emit('submit', { mode: 'all_eligible' })">确认当前可收全部</button>
        <button class="receipt-button" :disabled="sending || reading" @click="partial = !partial">{{ partial ? '收起部分选择' : '部分确认' }}</button>
      </view>
      <view v-if="partial">
        <view class="receipt-notice">件号仅用于本包裹内的商品单位，不是商品编号。可添加多个不交叠范围。</view>
        <view v-for="row in rows" :key="row.item_id" class="receipt-item">
          <view class="receipt-heading">{{ row.product_name }}</view>
          <view class="receipt-note">{{ row.sku_name }} · 共 {{ row.quantity }} 件 / 已收 {{ row.received }} 件 / 可确认 {{ row.eligible }} 件</view>
          <view v-if="receipt.package.schema === 2" class="receipt-note">已验收退件 {{ row.returned }} 件 · 收货与退件交集 {{ row.overlap }} 件 · 暂停确认 {{ row.paused }} 件</view>
          <view class="receipt-note">当前可选件号：{{ segmentText(row.available) }}</view>
          <view v-for="range in row.segments" :key="range.key" class="receipt-range">
            <view class="receipt-input-group"><text>起始件号</text><input v-model="range.first" type="number" maxlength="10" :disabled="sending || reading" placeholder="起始件号" /></view>
            <view class="receipt-input-group"><text>结束件号</text><input v-model="range.last" type="number" maxlength="10" :disabled="sending || reading" placeholder="结束件号" /></view>
            <button class="receipt-button receipt-small" :disabled="sending || reading" @click="removeRange(row, range.key)">移除范围</button>
          </view>
          <view v-if="row.eligible > 0" class="receipt-buttons">
            <button class="receipt-button receipt-small" :disabled="sending || reading" @click="addRange(row)">添加范围</button>
            <button class="receipt-button receipt-small" :disabled="sending || reading" @click="chooseAll(row)">选择该商品可收范围</button>
          </view>
        </view>
        <button class="receipt-button receipt-submit" :disabled="sending || reading" @click="submitPartial">确认所选范围</button>
      </view>
    </template>
    <view v-else-if="receipt && !pending && receipt.allowed_actions.receive === false" class="receipt-notice">{{ unavailableText }}</view>
    <view v-else-if="receipt && disabled" class="receipt-notice" role="status">包裹操作资料尚未就绪，请完成当前确认或重新加载资料。</view>
  </view>
</template>
<script>
export default {
  name: 'MarketplaceReceipt',
  props: {
    receipt: { type: Object, default: null }, pending: { type: Boolean, default: false },
    sending: { type: Boolean, default: false }, reading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false }, error: { type: String, default: '' }, feedback: { type: String, default: '' }
  },
  data() { return { partial: false, rows: [], nextRange: 0 }; },
  computed: {
    eligibleTotal() { return this.receipt ? this.receipt.receipt_selection.items.reduce((sum, item) => sum + item.eligible_quantity, 0) : 0; },
    unavailableText() {
      const child = this.receipt && this.receipt.package;
      if (!child || child.schema !== 2) return '当前包裹暂无可执行的收货操作。';
      if (child.received === child.shipped) return '本包裹已全部实际收货。';
      if (child.closed) return '本包裹已由收货或退件结束履约，退件不计为收货。';
      if (this.receipt.receipt_selection.items.some(item => item.paused_quantity > 0)) return '剩余待收货商品正在售后处理中，暂停确认收货。';
      return '当前包裹暂无可执行的收货操作，请重新加载资料。';
    }
  },
  watch: { receipt: { immediate: true, handler(value) {
    this.partial = false;
    this.rows = value ? value.receipt_selection.items.map((item, index) => ({
      item_id: item.item_id, product_name: value.package.items[index].product_name, sku_name: value.package.items[index].sku_name,
      quantity: item.quantity, received: item.received_quantity, eligible: item.eligible_quantity,
      returned: value.package.schema === 2 ? item.returned_quantity : 0, overlap: value.package.schema === 2 ? item.returned_after_receipt : 0, paused: value.package.schema === 2 ? item.paused_quantity : 0,
      available: item.eligible_segments, segments: []
    })) : [];
  } } },
  methods: {
    segmentText(segments) { return segments.length ? segments.map(item => item.first === item.last ? String(item.first) : item.first + '–' + item.last).join('、') : '暂无'; },
    addRange(row) { if (!this.sending && !this.reading) row.segments.push({ key: ++this.nextRange, first: '', last: '' }); },
    removeRange(row, key) { row.segments = row.segments.filter(item => item.key !== key); },
    chooseAll(row) { row.segments = row.available.map(item => ({ key: ++this.nextRange, first: String(item.first), last: String(item.last) })); },
    submitPartial() { this.$emit('submit', { mode: 'items', items: this.rows.map(row => ({ item_id: row.item_id, segments: row.segments.map(item => ({ first: item.first, last: item.last })) })) }); }
  }
};
</script>
<style scoped>
.receipt-controls { box-sizing: border-box; width: 100%; min-width: 0; padding: 30rpx; margin-top: 16rpx; background: #fff; color: #282828; font-size: 28rpx; line-height: 1.6; overflow-wrap: anywhere; }
.receipt-heading { font-weight: 600; }
.receipt-notice { padding: 12rpx 0; color: #606266; }
.receipt-note { color: #868686; font-size: 26rpx; margin-top: 8rpx; }
.receipt-error { border-left: 3px solid #b65353; padding: 16rpx; background: #fff3f3; color: #9b3232; }
.receipt-item { min-width: 0; margin-top: 20rpx; padding-top: 20rpx; border-top: 1px solid #eee; }
.receipt-buttons, .receipt-range { display: flex; flex-wrap: wrap; align-items: stretch; justify-content: center; gap: 16rpx; margin-top: 16rpx; min-width: 0; }
.receipt-input-group { flex: 1 1 180rpx; min-width: 0; }
.receipt-input-group input { box-sizing: border-box; width: 100%; min-width: 0; height: 44px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 28rpx; }
.receipt-button { box-sizing: border-box; display: flex; align-items: center; justify-content: center; min-height: 44px; height: auto; min-width: 0; max-width: 100%; flex: 0 1 auto; margin: 0; padding: 10px 16px; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: var(--view-theme, #333); font-size: 28rpx; line-height: 1.4; text-align: center; white-space: normal; }
.receipt-button::after { border: 0; }
.receipt-button[disabled] { color: #999; background: #f5f5f5; }
.receipt-small { font-size: 26rpx; }
.receipt-submit { width: 100%; margin-top: 24rpx; }
</style>
