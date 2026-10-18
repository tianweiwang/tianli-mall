<template>
  <view class="aftersale-page" :style="colorStyle">
    <view class="aftersale-card">
      <text class="aftersale-title">{{ mode === 'apply' ? '申请售后' : mode === 'detail' ? '售后详情' : '多商户售后' }}</text>
      <text class="aftersale-note">保存订单的售后记录与私有凭证</text>
      <view class="aftersale-actions"><button v-if="mode !== 'list'" class="aftersale-button" :disabled="busy || loading || uploadBusy || !!pending" @tap="showList">查看售后列表</button><button class="aftersale-button" :disabled="busy || loading || uploadBusy" @tap="refresh">{{ loading ? '正在读取' : '重新读取资料' }}</button></view>
    </view>
    <view v-if="error" class="aftersale-feedback aftersale-error" role="alert">{{ error }}</view>
    <view v-if="notice" class="aftersale-feedback" role="status" aria-live="polite">{{ notice }}</view>
    <view v-if="pending" class="aftersale-card">
      <text class="aftersale-heading">原提交结果待确认</text><text class="aftersale-note">已保留本次内容和提交编号。重新读取失败或当前资格变化不会更换原请求。</text>
      <button class="aftersale-button aftersale-primary" :disabled="busy || loading || !connected" @tap="sendPending">{{ busy ? '正在确认原提交' : '重试同一提交' }}</button>
    </view>
    <template v-if="mode === 'list'">
      <view v-if="!loading && !rows.length && !error" class="aftersale-card">暂无多商户售后记录。可从原订单详情进入新申请。</view>
      <view v-for="row in rows" :key="row.id" class="aftersale-card">
        <text class="aftersale-heading">{{ row.merchant_name_snapshot }}</text><text class="aftersale-note">售后单 {{ row.refund_no }}</text><text class="aftersale-note">原订单 {{ row.root_order_no }}</text>
        <view>{{ kinds[row.operation_kind] }} · {{ businesses[row.business_state] }}</view><view>申请金额 ¥{{ row.requested_amount }}</view><text class="aftersale-note">{{ physicals[row.physical_state] }} · {{ finances[row.finance_state] }}</text>
        <button class="aftersale-button" :disabled="loading" @tap="showDetail(row.refund_no)">查看此售后</button>
      </view>
      <view class="aftersale-actions"><button class="aftersale-button" :disabled="loading || page <= 1" @tap="changePage(page - 1)">上一页</button><text class="aftersale-page-count">第 {{ page }} 页 · {{ count }} 条</text><button class="aftersale-button" :disabled="loading || page * limit >= count" @tap="changePage(page + 1)">下一页</button></view>
    </template>
    <template v-if="mode === 'apply' && quote">
      <view class="aftersale-card"><text class="aftersale-heading">{{ quote.root.merchant_name_snapshot }}</text><text class="aftersale-note">原订单 {{ quote.root.order_no }}</text><view>已付 ¥{{ quote.capacity.paid }} · 已确认 ¥{{ quote.capacity.confirmed }}</view><view>处理中占用 ¥{{ quote.capacity.held }} · 当前可用 ¥{{ quote.capacity.available }}</view><text v-if="!quote.allowed_actions.apply" class="aftersale-note">当前不能新建申请。已存在售后记录仍可从列表查看。</text></view>
      <view class="aftersale-card">
        <text class="aftersale-heading">申请内容</text>
        <text class="aftersale-label">售后类型</text><picker :range="kindOptions" range-key="label" :value="kindIndex" :disabled="formLocked" @change="chooseKind"><view class="aftersale-picker">{{ kindOptions[kindIndex].label }} · 点击选择</view></picker>
        <text class="aftersale-label">申请金额（元）</text><input v-model="draft.amount" class="aftersale-input" type="digit" maxlength="13" placeholder="例如 12.00" :disabled="formLocked" aria-label="申请金额，填写两位小数" />
        <text class="aftersale-note">按实际诉求填写；金额许可由保存订单、已处理记录和所选商品共同核定。</text>
        <text class="aftersale-label">申请原因</text><textarea v-model="draft.reason" class="aftersale-textarea" :maxlength="-1" placeholder="请描述实际情况" :disabled="formLocked" aria-label="申请原因" />
      </view>
      <view v-if="draft.operation_kind !== 'return_goods'" class="aftersale-card">
        <text class="aftersale-heading">选择保存商品和数量</text>
        <view v-for="row in quote.root_items" :key="row.item.id" class="aftersale-item">
          <view class="aftersale-product"><image :src="row.item.image" class="aftersale-product-image" mode="aspectFit" /><view class="aftersale-product-text"><view>{{ row.item.product_name }}</view><text class="aftersale-note">{{ row.item.sku_name }}</text><view>保存单价 ¥{{ row.item.unit_price }} · 原数量 {{ row.item.quantity }}</view></view></view>
          <text class="aftersale-note">{{ draft.operation_kind === 'cancel_unshipped' ? '当前可取消数量 ' + row.available_cancel_quantity : '此商品当前可用金额 ¥' + row.available_money_amount }}</text>
          <text class="aftersale-label">本次申请数量（留空不选择）</text><input v-model="draft.quantities[row.item.id]" class="aftersale-input" type="number" maxlength="10" :disabled="formLocked || (draft.operation_kind === 'cancel_unshipped' && row.available_cancel_quantity === 0)" :aria-label="row.item.product_name + '本次申请数量'" />
        </view>
      </view>
      <view v-else class="aftersale-card">
        <text class="aftersale-heading">选择已发包裹和实际退回商品</text><text class="aftersale-note">按包裹中第几件选择，可只退其中一部分；退件和实际收货分别记录。</text>
        <view v-if="!quote.packages.list.length" class="aftersale-note">当前页没有已发包裹。</view>
        <view v-for="entry in quote.packages.list" :key="entry.package.id" class="aftersale-item">
          <button class="aftersale-button" :class="{ 'aftersale-selected': draft.child_id === entry.package.id }" :disabled="formLocked" @tap="choosePackage(entry.package.id)">{{ draft.child_id === entry.package.id ? '已选择包裹 ' : '选择包裹 ' }}{{ entry.package.order_id }}</button>
          <view v-if="draft.child_id === entry.package.id">
            <view v-for="row in entry.return_selection.items" :key="row.item_id" class="aftersale-item">
              <view class="aftersale-product"><image :src="packageItem(entry, row.item_id).image" class="aftersale-product-image" mode="aspectFit" /><view class="aftersale-product-text"><view>{{ packageItem(entry, row.item_id).product_name }}</view><text class="aftersale-note">{{ packageItem(entry, row.item_id).sku_name }} · 保存单价 ¥{{ packageItem(entry, row.item_id).unit_price }}</text></view></view>
              <text class="aftersale-note">包裹内共 {{ row.quantity }} 件 · 可选择第 {{ segments(row.eligible_segments) || '暂无' }} 件</text>
              <text class="aftersale-label">本次退回第几件（留空不选择）</text><input v-model="draft.segments[row.item_id]" class="aftersale-input" :disabled="formLocked || row.eligible_quantity === 0" placeholder="例如 1-2, 4" :aria-label="packageItem(entry, row.item_id).product_name + '退回序号'" />
            </view>
          </view>
        </view>
        <view class="aftersale-actions"><button class="aftersale-button" :disabled="formLocked || packagePage <= 1" @tap="changePackagePage(packagePage - 1)">上一页包裹</button><text class="aftersale-page-count">第 {{ packagePage }} 页 · {{ quote.packages.count }} 包裹</text><button class="aftersale-button" :disabled="formLocked || packagePage * packageLimit >= quote.packages.count" @tap="changePackagePage(packagePage + 1)">下一页包裹</button></view>
      </view>
      <view class="aftersale-card">
        <text class="aftersale-heading">私有凭证（至少一张，最多九张）</text><text class="aftersale-note">仅此售后的授权身份可读取。支持 JPEG、PNG、WebP，单张不超过 5 MiB，宽高不超过 4096。</text>
        <view v-for="(upload, index) in uploads" :key="upload.key" class="aftersale-item"><view>凭证 {{ index + 1 }} · {{ upload.loading ? '正在上传' : upload.file ? '已上传' : '上传待确认' }}</view><text v-if="upload.error" class="aftersale-note">{{ upload.error }}</text><view class="aftersale-actions"><button v-if="!upload.file" class="aftersale-button" :disabled="upload.loading || busy || !!pending" @tap="uploadOne(upload)">重试同一文件</button><button class="aftersale-button" :disabled="upload.loading || busy || !!pending" @tap="removeUpload(upload.key)">移除此草稿凭证</button></view></view>
        <button class="aftersale-button" :disabled="formLocked || uploadBusy || uploads.length >= 9" @tap="chooseEvidence">{{ uploadBusy ? '正在上传凭证' : '选择并上传凭证' }}</button>
        <private-evidence v-if="uploadedIds.length" :key="'draft-' + resourceRevision" :files="uploadedIds" :reader="readDraftEvidence" />
        <button class="aftersale-button aftersale-primary" :disabled="formLocked || uploadBusy || !uploadedIds.length || !allUploaded" @tap="submitApply">提交本次售后申请</button>
      </view>
    </template>
    <template v-if="mode === 'detail' && view">
      <view class="aftersale-card"><text class="aftersale-heading">{{ view.merchant_name_snapshot }}</text><view>{{ kinds[view.operation_kind] }} · {{ businesses[view.business_state] }}</view><text class="aftersale-note">售后单 {{ view.refund_no }}</text><text class="aftersale-note">原订单 {{ view.root_order_no }}</text><text class="aftersale-note">申请 {{ time(view.created_at) }} · 最近更新 {{ time(view.updated_at) }}</text><view>实物：{{ physicals[view.physical_state] }}</view><view>资金：{{ finances[view.finance_state] }}</view><view>申请 ¥{{ view.requested_amount }} · 已确认 ¥{{ view.confirmed_amount }} · 处理中占用 ¥{{ view.held_amount }}</view><text class="aftersale-note">业务或退件完成不代表资金退款已成功。</text><text class="aftersale-label">申请原因</text><text class="aftersale-plain">{{ view.reason }}</text></view>
      <view class="aftersale-card"><text class="aftersale-heading">申请商品</text><view v-for="row in view.items" :key="row.item.id" class="aftersale-item"><view class="aftersale-product"><image :src="row.item.image" class="aftersale-product-image" mode="aspectFit" /><view class="aftersale-product-text"><view>{{ row.item.product_name }}</view><text class="aftersale-note">{{ row.item.sku_name }}</text><view>保存单价 ¥{{ row.item.unit_price }} · 申请 {{ row.selected_quantity }} 件</view><view>实际退件已验收 {{ row.accepted_quantity }} 件 · 已确认 ¥{{ row.confirmed_amount }}</view></view></view></view></view>
      <view class="aftersale-card"><text class="aftersale-heading">私有凭证</text><private-evidence :key="'detail-' + view.id + '-' + resourceRevision" :files="view.images" :reader="readDetailEvidence" /></view>
      <view v-if="view.return_selection" class="aftersale-card"><text class="aftersale-heading">退回包裹 {{ view.return_selection.child_order_no }}</text><view v-for="(row, index) in view.return_selection.items" :key="row.item_id" class="aftersale-item"><view>退件商品 {{ index + 1 }} · 选择 {{ row.selected_quantity }} 件 · 实际验收 {{ row.accepted_quantity }} 件</view><text class="aftersale-note">选择第 {{ segments(row.selected_segments) }} 件；已验收第 {{ segments(row.accepted_segments) || '暂无' }} 件</text></view></view>
      <view v-if="view.return_delivery" class="aftersale-card"><text class="aftersale-heading">已保存退货运单</text><view>{{ view.return_delivery.carrier_name }}</view><text class="aftersale-plain">{{ view.return_delivery.tracking_no }}</text></view>
      <view v-if="view.allowed_actions.submit_return" class="aftersale-card"><text class="aftersale-heading">填写本次退货运单</text><text class="aftersale-label">承运商</text><input v-model="delivery.carrier_name" class="aftersale-input" :maxlength="-1" :disabled="busy || !!pending || loading" aria-label="退货承运商" /><text class="aftersale-label">运单号</text><input v-model="delivery.tracking_no" class="aftersale-input" :maxlength="-1" :disabled="busy || !!pending || loading" aria-label="退货运单号" /><button class="aftersale-button aftersale-primary" :disabled="busy || !!pending || loading" @tap="beginCommand('express')">保存本次运单</button></view>
      <view v-if="view.allowed_actions.withdraw" class="aftersale-card"><text class="aftersale-note">当前申请仍可撤回。</text><button class="aftersale-button" :disabled="busy || !!pending || loading" @tap="beginCommand('withdraw')">撤回此申请</button></view>
    </template>
  </view>
</template>
<script>
import { openCustomerAftersale } from '@/api/marketplace-aftersale';
import { quoteView, refundView, refundPage, applyIntent, commandIntent, actionResult, routeId, text, newKey, segmentText, errorText, timeText, businessLabels, physicalLabels, financeLabels, kindLabels } from '@/utils/marketplace-aftersale';
import PrivateEvidence from '@/components/marketplaceAftersaleEvidence/index.vue';
function draft() { return { operation_kind: 'money_only', amount: '', reason: '', quantities: {}, segments: {}, child_id: null }; }
export default {
  components: { PrivateEvidence },
  data: () => ({ mode: 'list', rootId: null, refundNo: '', quote: null, view: null, rows: [], page: 1, limit: 20, count: 0, packagePage: 1, packageLimit: 20,
    loading: false, busy: false, connected: false, error: '', notice: '', pending: null, uploads: [], choosing: false, draft: draft(), delivery: { carrier_name: '', tracking_no: '' }, resourceRevision: 0, colorStyle: '',
    kinds: kindLabels, businesses: businessLabels, physicals: physicalLabels, finances: financeLabels,
    kindOptions: [{ value: 'money_only', label: kindLabels.money_only }, { value: 'cancel_unshipped', label: kindLabels.cancel_unshipped }, { value: 'return_goods', label: kindLabels.return_goods }] }),
  computed: {
    authRevision() { return this.$store.state.app.authRevision; }, ownerUid() { return this.$store.state.app.verifiedUid; },
    formLocked() { return this.loading || this.busy || !!this.pending || !this.connected || !this.quote || this.quote.allowed_actions.apply !== true; },
    kindIndex() { return this.kindOptions.findIndex(row => row.value === this.draft.operation_kind); },
    uploadBusy() { return this.choosing || this.uploads.some(row => row.loading); },
    uploadedIds() { return this.uploads.filter(row => row.file).map(row => row.file.id); },
    allUploaded() { return this.uploads.length > 0 && this.uploads.every(row => !!row.file); }
  },
  watch: { authRevision() { if (this._client) this.identityLost(); }, ownerUid() { if (this._client) this.identityLost(); } },
  onLoad(options) {
    this._alive = true; this._revision = 0; this._client = null; this.colorStyle = uni.getStorageSync('viewColor') || '';
    try {
      if (options.root_id && options.refund_no) throw new Error('售后目标不完整，请从订单重新进入');
      if (options.root_id) { this.rootId = routeId(options.root_id); this.mode = 'apply'; }
      else if (options.refund_no) { this.refundNo = text(options.refund_no, 50, true); this.mode = 'detail'; }
      this.connect();
    } catch (error) { this.error = errorText(error); }
  },
  onShow() { if (this._client && !this._client.current()) this.identityLost(); },
  onUnload() { this._alive = false; this._revision += 1; this.closeResources(); this.pending = null; this.uploads = []; this.quote = null; this.view = null; this.rows = []; },
  methods: {
    time: timeText, segments: segmentText,
    current(revision) { return this._alive && revision === this._revision && this._client && this._client.current(); },
    closeResources() { if (this._client) this._client.dispose(); this._client = null; this.connected = false; this.resourceRevision += 1; },
    identityLost() { this._revision += 1; this.closeResources(); this.pending = null; this.quote = null; this.view = null; this.rows = []; this.uploads = []; this.draft = draft(); this.delivery = { carrier_name: '', tracking_no: '' }; this.busy = false; this.loading = false; this.choosing = false; this.notice = ''; this.error = '登录身份已变化，原页面资料已清理，请重新读取'; },
    async connect() {
      if (this.loading || !this._alive) return; const revision = ++this._revision; this.loading = true; this.error = '';
      try { const client = await openCustomerAftersale(); if (!this._alive || revision !== this._revision) { client.dispose(); return; } this._client = client; this.connected = true; }
      catch (error) { if (this._alive && revision === this._revision) this.error = errorText(error); }
      finally { if (this._alive && revision === this._revision) this.loading = false; }
      if (this.current(revision)) { const client = this._client; await this.load(); return this._client === client && client.current() ? client : null; }
    },
    refresh() { if (this.busy || this.loading || this.uploadBusy) return; if (!this._client || !this._client.current()) { if (this._client) this.identityLost(); this.connect(); } else this.load(); },
    async load() {
      if (this.loading || this.busy || this.uploadBusy || !this._client) return; const revision = ++this._revision; const mode = this.mode; const target = mode === 'apply' ? this.rootId : mode === 'detail' ? this.refundNo : this.page;
      const page = this.page, packagePage = this.packagePage; this.loading = true; this.error = '';
      // Current flags are unavailable during a failed refresh. The frozen original submission is deliberately retained.
      if (mode === 'detail') this.view = null; if (mode === 'apply') this.quote = null;
      try {
        if (mode === 'list') { const result = refundPage(await this._client.page(page, this.limit), page, this.limit); if (!this.current(revision) || this.mode !== mode || this.page !== target) return; this.rows = result.list; this.count = result.count; }
        else if (mode === 'detail') { const result = refundView(await this._client.detail(target)); if (!this.current(revision) || this.mode !== mode || this.refundNo !== target || result.refund_no !== target) return; this.view = result; }
        else { const result = quoteView(await this._client.quote(target, packagePage, this.packageLimit), target, packagePage, this.packageLimit); if (!this.current(revision) || this.mode !== mode || this.rootId !== target) return; this.quote = result;
          result.root_items.forEach(row => { if (!(row.item.id in this.draft.quantities)) this.$set(this.draft.quantities, row.item.id, ''); });
          if (!result.packages.list.some(entry => entry.package.id === this.draft.child_id)) { this.draft.child_id = null; this.draft.segments = {}; }
        }
      } catch (error) { if (this._alive && revision === this._revision) { if (error.staleIdentity) { this.identityLost(); return; } this.error = errorText(error); if (mode === 'list') { this.rows = []; this.count = 0; } } }
      finally { if (this._alive && revision === this._revision) this.loading = false; }
    },
    showList() { if (this.busy || this.loading || this.uploadBusy || this.pending) return; this._revision += 1; this.closeResources(); this.mode = 'list'; this.quote = null; this.view = null; this.uploads = []; this.draft = draft(); this.notice = ''; this.page = 1; this.connect(); },
    showDetail(no) { if (this.loading || this.busy || this.pending) return; this._revision += 1; this.closeResources(); this.mode = 'detail'; this.refundNo = no; this.view = null; this.delivery = { carrier_name: '', tracking_no: '' }; this.notice = ''; this.connect(); },
    changePage(page) { if (!this.loading && !this.busy) { this.page = page; this.load(); } },
    chooseKind(event) { if (this.formLocked) return; const row = this.kindOptions[Number(event.detail.value)]; if (row) this.draft.operation_kind = row.value; },
    packageItem(entry, id) { return entry.package.items.find(item => item.id === id); },
    choosePackage(id) { if (this.formLocked) return; this.draft.child_id = id; this.draft.segments = {}; const entry = this.quote.packages.list.find(row => row.package.id === id); if (entry) entry.return_selection.items.forEach(row => this.$set(this.draft.segments, row.item_id, '')); },
    changePackagePage(page) { if (this.formLocked) return; this.packagePage = page; this.draft.child_id = null; this.draft.segments = {}; this.load(); },
    chooseEvidence() {
      if (this.formLocked || this.uploadBusy || this.uploads.length >= 9) return; const revision = this._revision; this.choosing = true; this.error = '';
      uni.chooseImage({ count: 9 - this.uploads.length, sizeType: ['original'], sourceType: ['album', 'camera'], success: async result => {
        if (!this.current(revision) || this.mode !== 'apply') return;
        try {
          if (!Array.isArray(result.tempFilePaths) || !result.tempFilePaths.length || result.tempFilePaths.length + this.uploads.length > 9) throw new Error('所选凭证文件不可用');
          const added = result.tempFilePaths.map(path => ({ key: newKey('file', this.rootId), path, file: null, loading: false, error: '' })); this.uploads.push(...added);
          for (const row of added) { if (!this.current(revision) || this.mode !== 'apply') return; await this.uploadOne(row); }
        } catch (error) { if (this.current(revision)) this.error = errorText(error); }
      }, fail: error => { if (this.current(revision) && (!error || !String(error.errMsg).includes('cancel'))) this.error = '未能选择凭证，请重试'; }, complete: () => { if (this._alive && revision === this._revision) this.choosing = false; } });
    },
    async uploadOne(row) {
      if (!this._client || row.loading || row.file || this.pending || this.busy || this.loading || !this.uploads.includes(row)) return;
      const revision = this._revision, rootId = this.rootId; row.loading = true; row.error = '';
      try { const file = await this._client.upload(rootId, row.path, row.key); if (!this.current(revision) || this.mode !== 'apply' || this.rootId !== rootId || !this.uploads.includes(row)) return; row.file = file; }
      catch (error) { if (this._alive && revision === this._revision && this.uploads.includes(row)) { if (error.staleIdentity) { this.identityLost(); return; } row.error = errorText(error); } }
      finally { if (this._alive && revision === this._revision && this.uploads.includes(row)) row.loading = false; }
    },
    removeUpload(key) { if (this.busy || this.pending) return; const row = this.uploads.find(item => item.key === key); if (row && !row.loading) this.uploads = this.uploads.filter(item => item !== row); },
    async readDraftEvidence(id) {
      const client = this._client, revision = this._revision, rootId = this.rootId;
      if (!client || !this.current(revision) || this.mode !== 'apply' || !this.uploadedIds.includes(id)) throw new Error('当前凭证目标已变化');
      const image = await client.image(rootId, id, null);
      if (!this.current(revision) || this._client !== client || this.mode !== 'apply' || this.rootId !== rootId || !this.uploadedIds.includes(id)) { image.release(); throw new Error('当前凭证目标已变化'); }
      return image;
    },
    async readDetailEvidence(id) {
      const client = this._client, revision = this._revision, view = this.view;
      if (!client || !this.current(revision) || !view || this.mode !== 'detail' || !view.images.includes(id)) throw new Error('当前凭证目标已变化');
      const rootId = view.root_order_id, refundId = view.id, refundNo = view.refund_no;
      const image = await client.image(rootId, id, refundId);
      if (!this.current(revision) || this._client !== client || this.mode !== 'detail' || !this.view || this.refundNo !== refundNo || this.view.id !== refundId || this.view.refund_no !== refundNo || this.view.root_order_id !== rootId || !this.view.images.includes(id)) { image.release(); throw new Error('当前凭证目标已变化'); }
      return image;
    },
    submitApply() {
      if (this.formLocked || this.uploadBusy || this.uploads.some(row => !row.file)) return; this.error = ''; this.notice = '';
      try { this.pending = applyIntent(this.quote, { ...this.draft, images: this.uploadedIds }); this.sendPending(); } catch (error) { this.error = errorText(error); }
    },
    beginCommand(action) {
      if (!this.view || this.loading || this.busy || this.pending) return; this.error = ''; this.notice = '';
      try { this.pending = commandIntent(this.view, action, action === 'express' ? this.delivery : {}); this.sendPending(); } catch (error) { this.error = errorText(error); }
    },
    async sendPending() {
      if (!this.pending || this.busy || this.loading || !this._client) return; if (!this._client.current()) { this.identityLost(); return; }
      const intent = this.pending, revision = ++this._revision; this.busy = true; this.error = ''; this.notice = '正在发送已固定的本次提交';
      let confirmed = false;
      try {
        const saved = actionResult(await this._client.submit(intent), intent);
        if (!this.current(revision) || this.pending !== intent) return;
        confirmed = true; this.pending = null; this.notice = '本次售后操作已保存，最新资料尚未读回，请重新读取'; this.closeResources(); this.uploads = []; this.draft = draft();
        this.mode = 'detail'; this.refundNo = saved.refund.refund_no; this.view = null; this.quote = null; this.delivery = { carrier_name: '', tracking_no: '' };
      } catch (error) {
        if (this._alive && revision === this._revision) { if (error.staleIdentity) { this.identityLost(); return; } this.notice = ''; this.error = '本次提交结果尚未确认，已保留原内容和编号：' + errorText(error); }
      } finally { if (this._alive && revision === this._revision) this.busy = false; }
      if (confirmed && this._alive && revision === this._revision) { const no = this.refundNo, client = await this.connect(); if (this._alive && client && this._client === client && client.current() && this.mode === 'detail' && this.refundNo === no) this.notice = this.view ? '本次售后操作已保存，当前资料已重新读取' : '本次售后操作已保存，最新资料尚未读回，请重新读取'; }
    }
  }
};
</script>
<style scoped>
.aftersale-page { box-sizing: border-box; width: 100%; min-width: 0; min-height: 100vh; padding: 24rpx 24rpx 48rpx; background: #f5f5f5; color: #282828; font-size: 28rpx; line-height: 1.6; overflow-wrap: anywhere; }
.aftersale-card { box-sizing: border-box; width: 100%; min-width: 0; margin-bottom: 20rpx; padding: 28rpx; background: #fff; border-radius: 12rpx; }
.aftersale-title, .aftersale-heading, .aftersale-note, .aftersale-label, .aftersale-plain { display: block; }
.aftersale-title { font-size: 36rpx; font-weight: 600; }.aftersale-heading { font-size: 30rpx; font-weight: 600; }.aftersale-note { margin: 10rpx 0; color: #606266; font-size: 25rpx; }.aftersale-label { margin: 20rpx 0 10rpx; }.aftersale-plain { white-space: pre-wrap; }
.aftersale-feedback { box-sizing: border-box; margin-bottom: 20rpx; padding: 24rpx; background: #edf5ef; border-left: 3px solid #497057; color: #2e4e37; white-space: pre-wrap; }.aftersale-error { background: #fff3f3; border-color: #b65353; color: #9b3232; }
.aftersale-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 16rpx; margin-top: 20rpx; min-width: 0; }.aftersale-page-count { font-size: 24rpx; text-align: center; }
.aftersale-button { box-sizing: border-box; display: flex; align-items: center; justify-content: center; width: auto; min-width: 0; max-width: 100%; min-height: 44px; height: auto; padding: 10px 16px; margin: 16rpx 0 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: #333; font-size: 28rpx; line-height: 1.4; text-align: center; white-space: normal; }.aftersale-button::after { border: 0; }.aftersale-button[disabled] { background: #f5f5f5; color: #999; }.aftersale-primary { width: 100%; border-color: var(--view-theme, #333); color: var(--view-theme, #333); }.aftersale-selected { border: 2px solid var(--view-theme, #333); font-weight: 600; }
.aftersale-input, .aftersale-textarea, .aftersale-picker { box-sizing: border-box; width: 100%; min-width: 0; padding: 10px 12px; border: 1px solid #dcdfe6; border-radius: 6px; background: #fff; color: #333; font-size: 28rpx; }.aftersale-input { height: 44px; }.aftersale-textarea { min-height: 120px; }.aftersale-picker { min-height: 44px; }
.aftersale-item { min-width: 0; margin-top: 20rpx; padding-top: 20rpx; border-top: 1px solid #eee; }.aftersale-product { display: flex; align-items: flex-start; gap: 20rpx; min-width: 0; }.aftersale-product-image { flex: 0 0 100rpx; width: 100rpx; height: 100rpx; }.aftersale-product-text { flex: 1; min-width: 0; }
</style>