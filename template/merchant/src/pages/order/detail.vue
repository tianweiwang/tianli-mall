<template>
  <section class="page-panel" v-loading="loading">
    <h1 class="page-heading">订单详情</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" />
    <p v-if="loading" class="field-note" role="status">正在读取保存的订单信息</p>
    <div v-if="error" class="fulfillment-actions"><el-button class="fulfillment-control" :disabled="loading || shipmentSending" @click="load(pendingPage)">重新加载订单资料</el-button></div>
    <template v-if="order">
      <dl class="order-facts">
        <div><dt>订单号</dt><dd>{{ order.order_id }}</dd></div>
        <div><dt>下单时间</dt><dd>{{ time(order.add_time) }}</dd></div>
        <div><dt>订单状态</dt><dd>{{ status(order) }}</dd></div>
        <div><dt>支付状态</dt><dd>{{ paymentStatus(order) }}</dd></div>
        <div><dt>取消状态</dt><dd>{{ order.schema === 2 && order.fulfillment_summary.cancelled_unshipped === order.total_num ? '已取消全部未发货履约，保留付款记录' : order.is_cancel === 1 ? '已取消' : '未取消' }}</dd></div>
        <div><dt>退款状态码</dt><dd>{{ order.refund_status }}</dd></div>
        <div><dt>配送方式</dt><dd>{{ shipping(order.shipping_type) }}</dd></div>
        <div><dt>商品数量</dt><dd>{{ order.total_num }}</dd></div>
        <div><dt>商品金额（元）</dt><dd>{{ order.total_price }}</dd></div>
        <div><dt>{{ order.paid === 1 ? testPayment(order) ? '测试支付金额（元）' : '实付金额（元）' : '应付金额（元）' }}</dt><dd>{{ order.pay_price }}</dd></div>
        <div><dt>邮费（元）</dt><dd>{{ order.pay_postage }}</dd></div>
      </dl>
      <section v-if="(order.marketplace_trade_version === 1 || order.marketplace_trade_version === '1') && $store.getters.can('order.read')">
        <h2 class="section-heading">售后服务</h2>
        <p class="field-note">可在本店售后列表按付款订单号 {{ order.order_id }} 核对申请；本入口保留此订单已保存商品与金额。</p>
        <div class="fulfillment-actions"><el-button class="fulfillment-control" :disabled="loading || shipmentSending" @click="$router.push('/aftersales')">查看本店售后申请</el-button></div>
      </section>
      <h2 class="section-heading">履约信息</h2>
      <p v-if="order.fulfillment_summary === null" class="field-note" role="status">履约资料暂不可用</p>
      <dl v-else class="order-facts">
        <div><dt>履约商品数量</dt><dd>{{ order.fulfillment_summary.original }}</dd></div>
        <div><dt>已发货数量</dt><dd>{{ order.fulfillment_summary.shipped }}</dd></div>
        <div><dt>待发货数量</dt><dd>{{ order.fulfillment_summary.to_ship }}</dd></div>
        <div><dt>已收货数量</dt><dd>{{ order.fulfillment_summary.received }}</dd></div>
        <div><dt>待收货数量</dt><dd>{{ order.fulfillment_summary.to_receive }}</dd></div>
        <div><dt>包裹数量</dt><dd>{{ order.fulfillment_summary.child_count }}</dd></div>
        <div v-if="order.schema === 2"><dt>未发取消 / 已验收退件</dt><dd>{{ order.fulfillment_summary.cancelled_unshipped }} / {{ order.fulfillment_summary.returned_accepted }} 件</dd></div>
        <div v-if="order.schema === 2"><dt>暂停发货 / 待处理退件</dt><dd>{{ order.aftersale.pending_cancel_quantity }} / {{ order.aftersale.pending_return_quantity }} 件</dd></div>
        <div v-if="order.schema === 2"><dt>履约结束时间（含取消、退件）</dt><dd>{{ time(order.aftersale.fulfillment_completed_at) }}</dd></div>
      </dl>
      <p v-if="shipmentFeedback" class="field-note fulfillment-result" role="status">{{ shipmentFeedback }}</p>
      <p v-if="shipmentSending" class="field-note" role="status">正在提交本次发货，请稍候…</p>
      <el-alert v-if="shipmentError" :title="shipmentError" type="error" :closable="false" show-icon class="feedback" />
      <section v-if="shipmentPending" class="fulfillment-pending">
        <p class="field-note">本次提交结果尚未确认。重试会保留相同商品、数量与物流信息。离开或重载页面可能丢失重试内容，先前结果也可能已经保存，请先重试确认。</p>
        <div class="fulfillment-actions"><el-button class="fulfillment-control" :disabled="shipmentSending || loading" @click="sendShipment">重试本次请求</el-button></div>
      </section>
      <fulfillment-packages v-if="snapshot" :snapshot="snapshot" :loading="loading" :detail="packageDetail"
        :detail-loading="packageLoading" :detail-error="packageError" @page="load" @view="openPackage"
        @retry-detail="retryPackage" @close-detail="closePackage" />
      <shipment-form v-if="shipAvailable && !shipmentPending && !savedNeedsRefresh" :snapshot="snapshot"
        :disabled="shipDisabled" @submit="confirmShipment" />
      <section v-if="shipmentPreview" class="fulfillment-preview">
        <h3>核对本次分批发货</h3>
        <p>付款订单：{{ shipmentPreview.rootNo }}</p>
        <p v-for="item in shipmentPreview.body.items" :key="item.item_id">{{ previewItem(item.item_id).product_name }} · {{ previewItem(item.item_id).sku_name }} · 本次 {{ item.quantity }} 件</p>
        <p>承运：{{ shipmentPreview.body.delivery.carrier_name }}（{{ shipmentPreview.body.delivery.carrier_code }}）</p>
        <p>物流单号：{{ shipmentPreview.body.delivery.tracking_no }}</p>
        <div class="fulfillment-actions">
          <el-button class="fulfillment-control" :disabled="loading || shipmentSending" @click="cancelShipmentPreview">返回核对</el-button>
          <el-button class="fulfillment-control" type="primary" :disabled="loading || !!error || !shipAvailable || shipmentSending" @click="commitShipmentPreview">确认提交发货</el-button>
        </div>
      </section>
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
import { orderRecordId, orderStatus, orderPackages, orderPackageDetail, shipmentIntent, shipmentResult } from '../../domain/order';
import { formatTime } from '../../domain/people';
import FulfillmentPackages from './components/fulfillmentPackages.vue';
import ShipmentForm from './components/shipmentForm.vue';

export default {
  components: { FulfillmentPackages, ShipmentForm },
  directives: { 'table-scroll': tableScroll },
  data: () => ({ order: null, snapshot: null, loading: false, error: '', revision: 0, pendingPage: 1, readIdentity: '',
    packageDetail: null, packageLoading: false, packageError: '', packageId: null, packageRevision: 0,
    shipmentPreview: null, shipmentPreviewIdentity: '', shipmentPending: null, shipmentPendingIdentity: '', shipmentSending: false, shipmentPreparing: false, shipmentError: '', shipmentFeedback: '',
    shipmentGeneration: 0, savedNeedsRefresh: false }),
  computed: {
    merchantActor() {
      const profile = this.$store.state.profile;
      if (!profile || !profile.employee || !profile.merchant) return '';
      const actualId = value => {
        if (typeof value === 'number') return Number.isSafeInteger(value) && value > 0 && value <= 4294967295 ? String(value) : '';
        return typeof value === 'string' && /^[1-9][0-9]{0,9}$/.test(value) && (value.length < 10 || value <= '4294967295') ? value : '';
      };
      const mer = actualId(profile.merchant.mer_id); const employee = actualId(profile.employee.id);
      const employeeMer = actualId(profile.employee.mer_id);
      return mer && employee && employeeMer === mer ? mer + ':' + employee : '';
    },
    merchantSession() {
      const token = this.$store.state.token; const revision = this.$store.state.sessionRevision;
      if (typeof token !== 'string' || !token || !Number.isSafeInteger(revision) || revision < 0) return '';
      return String(revision) + ':' + token;
    },
    shipAvailable() { return this.readIdentity === this.identity() && !!this.snapshot && this.snapshot.root.allowed_actions.ship === true && this.snapshot.shipping_items !== null && this.$store.getters.can('order.ship'); },
    shipDisabled() { return this.loading || !!this.error || this.shipmentSending || this.shipmentPreparing; }
  },
  created() { this.resetAndLoad(); },
  watch: {
    '$route.params.id'() { this.resetAndLoad(); },
    merchantActor() { this.resetAndLoad(); },
    merchantSession() { this.resetAndLoad(); }
  },
  beforeDestroy() { this.clearState(); },
  methods: {
    time: formatTime, status: orderStatus,
    testPayment(order) { return (order.marketplace_trade_version === 1 || order.marketplace_trade_version === '1') && order.pay_type === 'marketplace_test'; },
    paymentStatus(order) { if (order.paid !== 1) return '未支付'; return this.testPayment(order) ? '已支付（测试支付）' : '已支付'; },
    shipping(value) { return ({ 1: '快递配送', 2: '到店自提' })[value] || '配送方式：' + value; },
    identity() { return this.merchantActor + ':' + String(this.$route.params.id) + ':' + this.merchantSession; },
    current(identity) { return !!this.merchantActor && !!this.merchantSession && identity === this.identity(); },
    clearState() {
      this.revision += 1; this.packageRevision += 1; this.shipmentGeneration += 1;
      this.order = null; this.snapshot = null; this.loading = false; this.error = ''; this.readIdentity = '';
      this.packageDetail = null; this.packageId = null; this.packageLoading = false; this.packageError = '';
      this.shipmentPreview = null; this.shipmentPreviewIdentity = ''; this.shipmentPending = null; this.shipmentPendingIdentity = ''; this.shipmentSending = false; this.shipmentPreparing = false;
      this.shipmentError = ''; this.shipmentFeedback = ''; this.savedNeedsRefresh = false;
    },
    resetAndLoad() {
      this.clearState();
      if (this.merchantActor && this.merchantSession) this.load(1);
      else if (this.merchantSession) this.error = '当前员工资料不完整，请重新登录';
    },
    async load(page = 1) {
      const identity = this.identity(); const revision = ++this.revision;
      this.cancelShipmentPreview(); this.loading = true; this.error = ''; this.pendingPage = page; this.readIdentity = identity; this.closePackage();
      try {
        const id = orderRecordId(this.$route.params.id);
        const data = orderPackages(await api.orderPackages(id, page, 20), id, page, 20);
        if (revision !== this.revision || !this.current(identity)) return false;
        this.snapshot = data; this.order = data.root; if (this.savedNeedsRefresh && this.shipmentFeedback) this.shipmentFeedback = '发货结果已保存'; this.savedNeedsRefresh = false; return true;
      } catch (error) {
        if (revision === this.revision && this.current(identity)) this.error = error && error.message || '订单读取失败，请重试';
        return false;
      } finally { if (revision === this.revision && this.current(identity)) this.loading = false; }
    },
    closePackage() { this.packageRevision += 1; this.packageDetail = null; this.packageId = null; this.packageLoading = false; this.packageError = ''; },
    async openPackage(childId) {
      if (this.loading || this.error || !this.snapshot || !this.snapshot.packages
        || !this.snapshot.packages.list.some(child => child.id === childId)) return;
      const identity = this.identity(); const revision = ++this.packageRevision; const root = this.snapshot.root;
      this.packageDetail = null; this.packageId = childId; this.packageLoading = true; this.packageError = '';
      try {
        const child = orderPackageDetail(await api.fulfillmentPackage(childId), root, childId);
        if (revision !== this.packageRevision || !this.current(identity)) return;
        this.packageDetail = child;
      } catch (error) {
        if (revision === this.packageRevision && this.current(identity)) this.packageError = error && error.message || '包裹读取失败，请重试';
      } finally { if (revision === this.packageRevision && this.current(identity)) this.packageLoading = false; }
    },
    retryPackage() { if (this.packageId !== null) this.openPackage(this.packageId); },
    confirmShipment(draft) {
      if (!this.shipAvailable || this.shipDisabled || this.shipmentPending || this.savedNeedsRefresh) return;
      this.shipmentError = '';
      try {
        this.shipmentPreview = shipmentIntent(this.snapshot, draft);
        this.shipmentPreviewIdentity = this.identity(); this.shipmentPreparing = true;
      } catch (error) { this.shipmentError = error && error.message || '请核对发货内容'; }
    },
    cancelShipmentPreview() { this.shipmentPreview = null; this.shipmentPreviewIdentity = ''; this.shipmentPreparing = false; },
    previewItem(id) { return this.snapshot.root.items.find(item => item.id === id); },
    commitShipmentPreview() {
      if (!this.shipmentPreview || this.shipmentPreviewIdentity !== this.identity() || !this.shipAvailable
        || this.loading || this.error || this.shipmentPending || this.shipmentSending) return;
      this.shipmentPending = this.shipmentPreview; this.shipmentPendingIdentity = this.shipmentPreviewIdentity;
      this.cancelShipmentPreview(); this.shipmentFeedback = ''; this.sendShipment();
    },
    async sendShipment() {
      const intent = this.shipmentPending;
      if (!intent || this.shipmentPendingIdentity !== this.identity() || this.shipmentSending || this.loading || !this.merchantActor || !this.merchantSession) return;
      const identity = this.identity(); const generation = ++this.shipmentGeneration;
      this.shipmentSending = true; this.shipmentError = '';
      try {
        if (intent.wire !== JSON.stringify(intent.body) || orderRecordId(intent.rootId) !== orderRecordId(this.$route.params.id)) throw new Error('本次请求保存内容不一致');
        const value = await api.shipOrder(intent.rootId, intent.body);
        if (generation !== this.shipmentGeneration || !this.current(identity) || this.shipmentPending !== intent) return;
        shipmentResult(value, intent);
        this.shipmentPending = null; this.shipmentFeedback = '发货结果已保存'; this.savedNeedsRefresh = true;
        const refreshed = await this.load(1);
        if (!refreshed && this.savedNeedsRefresh && generation === this.shipmentGeneration && this.current(identity)) this.shipmentFeedback = '发货结果已保存，最新资料尚未读回，请重新加载';
      } catch (error) {
        if (generation === this.shipmentGeneration && this.current(identity) && this.shipmentPending === intent)
          this.shipmentError = '本次提交结果尚未确认。' + (error && error.message || '请重试本次请求');
      } finally { if (generation === this.shipmentGeneration && this.current(identity)) this.shipmentSending = false; }
    }
  }
};
</script>
<style scoped>
.fulfillment-actions { display: flex; justify-content: center; align-items: stretch; flex-wrap: wrap; gap: 12px; min-width: 0; margin: 16px 0; }
.fulfillment-actions .fulfillment-control + .fulfillment-control { margin-left: 0; }
.fulfillment-control { display: inline-flex; justify-content: center; align-items: center; box-sizing: border-box; max-width: 100%; min-width: 0; min-height: 44px; height: auto; margin: 0; white-space: normal; line-height: 1.4; text-align: center; }
.fulfillment-preview { box-sizing: border-box; min-width: 0; padding: 16px; margin-top: 16px; border: 1px solid #ebeef5; overflow-wrap: anywhere; }
.fulfillment-result, .fulfillment-pending { padding: 16px 0; overflow-wrap: anywhere; }
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
