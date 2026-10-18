<template>
  <section class="packages-section">
    <h2 class="section-heading">履约包裹</h2>
    <p v-if="loading" class="field-note" role="status">正在读取包裹页…</p>
    <p v-if="snapshot.packages === null" class="field-note" role="status">包裹资料暂不可用</p>
    <template v-else>
      <p v-if="snapshot.packages.count === 0" class="field-note">暂无履约包裹</p>
      <p v-else-if="!snapshot.packages.list.length" class="field-note">本页暂无包裹</p>
      <section v-for="child in snapshot.packages.list" :key="child.id" class="package-record">
        <h3>包裹 {{ child.order_id }}</h3>
        <p>{{ state(child) }} · 已发 {{ child.shipped }} 件 / 已收 {{ child.received }} 件 / 待收 {{ child.to_receive }} 件</p>
        <p class="field-note">{{ child.merchant_name_snapshot }} · 承运：{{ child.carrier_name || '—' }} · 单号：{{ child.tracking_no || '—' }}</p>
        <div class="package-actions"><el-button class="package-control" :disabled="loading" @click="$emit('view', child.id)">查看包裹</el-button></div>
      </section>
      <div v-if="snapshot.packages.count > 0" class="package-pagination">
        <p class="field-note">第 {{ snapshot.packages.page }} 页 · 共 {{ snapshot.packages.count }} 个包裹</p>
        <div class="package-actions">
          <el-button v-if="!snapshot.packages.list.length && snapshot.packages.page > 1" class="package-control" :disabled="loading" @click="$emit('page', 1)">回到第一页</el-button>
          <el-button class="package-control" :disabled="loading || snapshot.packages.page <= 1" @click="$emit('page', snapshot.packages.page - 1)">上一页</el-button>
          <el-button class="package-control" :disabled="loading || snapshot.packages.page * snapshot.packages.limit >= snapshot.packages.count" @click="$emit('page', snapshot.packages.page + 1)">下一页</el-button>
        </div>
      </div>
    </template>
    <p v-if="detailLoading" class="field-note" role="status">正在读取所选包裹详情…</p>
    <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" show-icon />
    <div v-if="detailError" class="package-actions"><el-button class="package-control" :disabled="detailLoading" @click="$emit('retry-detail')">重新读取包裹</el-button></div>
    <section v-if="detail" class="package-detail">
      <h3>包裹详情 {{ detail.order_id }}</h3>
      <dl>
        <div><dt>对应付款订单</dt><dd>{{ detail.root_order_no }}</dd></div>
        <div><dt>付款方式</dt><dd>{{ detail.pay_type === 'marketplace_test' ? '测试支付' : detail.pay_type_name }}</dd></div>
        <div><dt>付款时间</dt><dd>{{ time(detail.pay_time) }}</dd></div>
        <div><dt>履约状态</dt><dd>{{ state(detail) }}</dd></div>
        <div><dt>承运 / 单号</dt><dd>{{ detail.carrier_name || '—' }} / {{ detail.tracking_no || '—' }}</dd></div>
        <div><dt>发货时间</dt><dd>{{ time(detail.shipped_at) }}</dd></div>
        <div><dt>预计自动收货时间</dt><dd>{{ time(detail.receipt_due_at) }}</dd></div>
        <div><dt>全部收货时间</dt><dd>{{ time(detail.received_at) }}</dd></div>
      </dl>
      <div v-for="item in detail.items" :key="item.id" class="package-item">
        <img :src="item.image" class="table-image" alt="商品保存图片">
        <div class="package-copy"><div>{{ item.product_name }}</div><p class="field-note">{{ item.sku_name }}</p>
          <p>￥{{ item.unit_price }} × {{ item.quantity }}</p><p class="field-note">商品金额 ￥{{ item.line_price }} · 运费 ￥{{ item.postage_price }}</p>
        </div>
      </div>
      <h4>收件资料</h4><p>{{ detail.recipient.real_name }} {{ detail.recipient.user_phone }}</p><p>{{ detail.recipient.user_address }}</p>
      <p v-if="detail.mark">留言：{{ detail.mark }}</p>
      <section v-for="group in detail.custom_form" :key="group.product_id" class="package-saved">
        <h4>{{ group.product_name }} · 保存表单</h4>
        <div v-for="field in group.fields" :key="field.field_index"><p>{{ field.title }}</p>
          <div v-if="field.label === 'img'" class="package-images"><img v-for="(src,index) in field.value" :key="index" :src="src" alt="已保存表单图片"></div>
          <p v-else>{{ field.value || '未填写' }}</p>
        </div>
      </section>
      <div class="package-actions"><el-button class="package-control" @click="$emit('close-detail')">收起包裹详情</el-button></div>
    </section>
  </section>
</template>
<script>
import { formatTime } from '../../../domain/people';
export default {
  name: 'FulfillmentPackages',
  props: { snapshot: { type: Object, required: true }, loading: { type: Boolean, default: false },
    detail: { type: Object, default: null }, detailLoading: { type: Boolean, default: false }, detailError: { type: String, default: '' } },
  methods: {
    state(child) { return child.receipt_state === 'received' ? '已收货' : child.receipt_state === 'partial' ? '部分收货' : '待收货'; },
    time(value) { return value === null || value === 0 ? '—' : formatTime(value); }
  }
};
</script>
<style scoped>
.packages-section, .package-copy, .package-record, .package-detail { min-width: 0; overflow-wrap: anywhere; }
.package-record, .package-detail { padding: 16px; border: 1px solid #ebeef5; margin-top: 16px; }
.package-record h3, .package-detail h3 { margin: 0 0 12px; }
.package-pagination { text-align: center; padding: 16px 0; }
.package-actions { display: flex; align-items: stretch; justify-content: center; flex-wrap: wrap; gap: 12px; min-width: 0; margin-top: 12px; }
.package-actions .package-control + .package-control { margin-left: 0; }
.package-control { box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; max-width: 100%; min-width: 0; min-height: 44px; height: auto; margin: 0; white-space: normal; line-height: 1.4; text-align: center; }
.package-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; min-width: 0; }
.package-item .table-image { flex: 0 0 52px; }
.package-copy { flex: 1; }
.package-detail dl { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; margin: 0; }
.package-detail dt { color: #606266; margin-bottom: 6px; }
.package-detail dd { margin: 0; }
.package-detail dl > div { min-width: 0; }
.package-images { display: flex; flex-wrap: wrap; gap: 12px; }
.package-images img { width: 110px; height: 90px; max-width: 100%; object-fit: contain; border: 1px solid #ebeef5; }
.package-saved { border-top: 1px solid #ebeef5; margin-top: 16px; white-space: pre-wrap; }
@media(max-width:760px){ .package-detail dl { grid-template-columns: minmax(0,1fr); } }
</style>
