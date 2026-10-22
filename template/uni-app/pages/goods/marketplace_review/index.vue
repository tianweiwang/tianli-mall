<template>
  <view class="review-page" :style="colorStyle">
    <view class="review-card">
      <text class="review-title">{{ itemId ? '商品评价及记录' : '选择原订单商品' }}</text>
      <text class="review-note">按原订单保存的商品、规格和价格评价，已有评价原文保持不变。</text>
      <button v-if="!connected && !identityInvalid" class="review-button" :disabled="connecting || !rootId" @tap="connect">{{ connecting ? '正在读取登录资料' : '重试登录资料读取' }}</button>
      <button v-if="connected" class="review-button" :disabled="loading || busy || uploadBusy" @tap="refresh">{{ loading ? '正在读取' : pending ? '查询原提交结果' : '重新读取资料' }}</button>
    </view>
    <view v-if="error" class="review-feedback review-error" role="alert">{{ error }}</view>
    <view v-if="notice" class="review-feedback" role="status" aria-live="polite">{{ notice }}</view>
    <view v-if="pending" class="review-card">
      <text class="review-heading">原评价内容已保留</text>
      <text class="review-note">结果未确认时先查询此商品的保存记录。原正文、评分和图片不会因读取失败或资格变化而替换。</text>
      <view>原商品评分 {{ pending.body.product_score }} / 5 · 原服务评分 {{ pending.body.service_score }} / 5</view>
      <text class="review-plain">{{ pending.body.comment || '本次原提交未填写文字评价' }}</text>
      <text v-if="pending.body.pics.length" class="review-label">本次原提交图片</text>
      <view v-if="pending.body.pics.length" class="review-pictures"><image v-for="(upload, index) in uploads" :key="upload.key" :src="upload.preview" class="review-picture" mode="aspectFit" @tap="previewDraft(index)" /></view>
      <button class="review-button" :disabled="loading || busy || !connected" @tap="checkPending">查询原提交结果</button>
      <button v-if="retryReady" class="review-button review-primary" :disabled="loading || busy || !connected" @tap="resendPending">{{ quote && quote.review ? '用原评价内容确认保存结果' : '重发同一评价内容' }}</button>
    </view>
    <template v-if="!itemId">
      <view v-if="!loading && !items.length && !error" class="review-card">暂无可读取的原订单商品，请从原订单详情重新进入。</view>
      <view v-for="item in items" :key="item.id" class="review-card">
        <view class="review-product"><image :src="item.image" class="review-product-image" mode="aspectFit" /><view class="review-product-text"><view>{{ item.product_name }}</view><text class="review-note">{{ item.sku_name }}</text><view>保存单价 ¥{{ item.unit_price }} · 数量 {{ item.quantity }}</view><text class="review-note">商品金额 ¥{{ item.line_price }} · 运费 ¥{{ item.postage_price }}</text></view></view>
        <button class="review-button" :disabled="loading || !connected" @tap="openItem(item.id)">查询此商品评价资格及记录</button>
      </view>
    </template>
    <template v-if="itemId && quote">
      <view class="review-card"><view class="review-product"><image :src="quote.item.image" class="review-product-image" mode="aspectFit" /><view class="review-product-text"><view>{{ quote.item.product_name }}</view><text class="review-note">{{ quote.item.sku_name }}</text><view>保存单价 ¥{{ quote.item.unit_price }} · 数量 {{ quote.item.quantity }}</view><text class="review-note">商品金额 ¥{{ quote.item.line_price }} · 运费 ¥{{ quote.item.postage_price }}</text></view></view></view>
      <view v-if="quote.review" class="review-card">
        <text class="review-heading">已保存评价 · {{ status(quote.review.status) }}</text><text class="review-note">评价编号 {{ quote.review.id }} · {{ time(quote.review.add_time) }}</text>
        <view>商品评分 {{ quote.review.product_score }} / 5 · 服务评分 {{ quote.review.service_score }} / 5</view><text class="review-plain">{{ quote.review.comment || '未填写文字评价' }}</text>
        <view class="review-pictures"><image v-for="(picture, index) in quote.review.pics" :key="index" :src="picture" class="review-picture" mode="aspectFit" @tap="previewSaved(index)" /></view>
        <view v-if="quote.review.is_reply === 1" class="review-reply"><text class="review-heading">商家回复</text><text class="review-note">{{ time(quote.review.merchant_reply_time) }}</text><text class="review-plain">{{ quote.review.merchant_reply_content }}</text></view>
      </view>
      <view v-else-if="!quote.allowed_actions.submit" class="review-card"><text class="review-heading">当前不能新增评价</text><text class="review-note">评价资格按此原订单项的实际收货、取消和已有记录核定。重新读取可查看最新结果。</text></view>
      <view v-else class="review-card">
        <text class="review-heading">本次商品评价</text>
        <text class="review-label">商品评分（1 至 5 分）</text><view class="review-scores"><button v-for="score in scores" :key="'product-' + score" class="review-score" :class="{ 'review-score-selected': draft.product_score === score }" :disabled="formLocked" :aria-label="'商品评分' + score + '分'" @tap="setScore('product_score', score)">{{ score }} 分{{ draft.product_score === score ? ' · 已选' : '' }}</button></view>
        <text class="review-label">服务评分（1 至 5 分）</text><view class="review-scores"><button v-for="score in scores" :key="'service-' + score" class="review-score" :class="{ 'review-score-selected': draft.service_score === score }" :disabled="formLocked" :aria-label="'服务评分' + score + '分'" @tap="setScore('service_score', score)">{{ score }} 分{{ draft.service_score === score ? ' · 已选' : '' }}</button></view>
        <text class="review-label">评价原文（最多 512 字）</text><textarea v-model="draft.comment" class="review-textarea" :maxlength="-1" :disabled="formLocked" placeholder="分享实际商品和服务体验，可不填写文字" aria-label="评价原文，最多512字" />
        <text class="review-label">公开评价图片（最多 8 张）</text><text class="review-note">评价通过审核后，文字、评分和所选图片可公开展示。请勿上传私密凭证。</text>
        <view v-for="(upload, index) in uploads" :key="upload.key" class="review-upload">
          <image :src="upload.preview || upload.path" class="review-picture" mode="aspectFit" @tap="previewDraft(index)" /><view class="review-upload-content"><view>图片 {{ index + 1 }} · {{ upload.loading ? '正在上传' : upload.reference ? '已上传' : '上传未确认' }}</view><text v-if="upload.error" class="review-note">{{ upload.error }}</text><view class="review-actions"><button v-if="!upload.reference" class="review-button" :disabled="formLocked || upload.loading || uploadBusy" @tap="uploadOne(upload)">重新上传此图片</button><button class="review-button" :disabled="formLocked || uploadBusy" @tap="removeUpload(upload.key)">移除此草稿图片</button></view></view>
        </view>
        <button class="review-button" :disabled="formLocked || uploadBusy || uploads.length >= 8" @tap="choosePictures">{{ uploadBusy ? '正在处理图片' : '选择并上传公开图片' }}</button>
        <button class="review-button review-primary" :disabled="formLocked || uploadBusy || !allUploaded" @tap="submit">提交此商品评价</button>
      </view>
    </template>
  </view>
</template>
<script>
import { openCustomerReviews } from '@/api/marketplace-review';
import { routeId, reviewQuote, createIntent, reviewWrite, errorText, statusText, timeText } from '@/utils/marketplace-review';
function emptyDraft() { return { product_score: 5, service_score: 5, comment: '' }; }
export default {
  data: () => ({ rootId: null, itemId: null, items: [], quote: null, quoteFresh: false, draft: emptyDraft(), uploads: [], pending: null, retryReady: false, loading: false, busy: false, choosing: false, connected: false, connecting: false, identityInvalid: false, error: '', notice: '', colorStyle: '', scores: [1, 2, 3, 4, 5] }),
  computed: {
    authRevision() { return this.$store.state.app.authRevision; }, ownerUid() { return this.$store.state.app.verifiedUid; },
    uploadBusy() { return this.choosing || this.uploads.some(row => row.loading); },
    allUploaded() { return this.uploads.every(row => typeof row.reference === 'string' && !!row.reference); },
    formLocked() { return this.loading || this.busy || !!this.pending || !this.connected || !this.quoteFresh || !this.quote || this.quote.allowed_actions.submit !== true; }
  },
  watch: { authRevision() { if (this._client) this.identityLost(); }, ownerUid() { if (this._client) this.identityLost(); } },
  onLoad(options) {
    this._alive = true; this._revision = 0; this._client = null; this._connecting = false; this._uploadSequence = 0; this.colorStyle = uni.getStorageSync('viewColor') || '';
    try { this.rootId = routeId(options.root_id); if (options.item_id !== undefined) this.itemId = routeId(options.item_id); this.connect(); }
    catch (error) { this.error = errorText(error); }
  },
  onShow() { if (this._alive && this._client && !this._client.current()) this.identityLost(); },
  onUnload() { this._alive = false; this._revision++; if (this._client) this._client.dispose(); this._client = null; this.connected = false; this.quote = null; this.items = []; this.uploads = []; this.pending = null; },
  methods: {
    status: statusText, time: timeText,
    scope(client, revision) { if (!this._alive || !client || this._client !== client || this._revision !== revision) return false; if (!client.current()) { this.identityLost(); return false; } return true; },
    identityLost() { this._revision++; if (this._client) this._client.dispose(); this._client = null; this.connected = false; this.identityInvalid = true; this.loading = false; this.busy = false; this.choosing = false; this.quoteFresh = false; this.quote = null; this.items = []; this.uploads = []; this.pending = null; this.retryReady = false; this.draft = emptyDraft(); this.notice = ''; this.error = '登录身份已变化，请从当前身份的订单重新进入评价'; },
    async connect() {
      if (this._connecting || this._client || !this._alive || this.identityInvalid || !this.rootId) return; this._connecting = true; this.connecting = true; this.error = ''; const revision = this._revision;
      try { const client = await openCustomerReviews(); if (!this._alive || revision !== this._revision) { client.dispose(); return; } this._client = client; this.connected = client.current(); await this.refresh(); }
      catch (error) { if (this._alive && revision === this._revision) this.error = errorText(error); }
      finally { this._connecting = false; if (this._alive) this.connecting = false; }
    },
    async refresh() {
      if (this.pending) return this.checkPending(); if (!this._client || this.loading || this.busy || this.uploadBusy || !this.scope(this._client, this._revision)) return;
      const client = this._client, revision = this._revision; this.loading = true; this.error = ''; this.quoteFresh = false;
      try {
        if (this.itemId) { const result = reviewQuote(await client.quote(this.rootId, this.itemId), this.rootId, this.itemId); if (!this.scope(client, revision)) return; this.quote = result; this.quoteFresh = true; }
        else { const result = await client.sourceItems(this.rootId); if (!this.scope(client, revision)) return; this.items = result.root_items.map(row => row.item); }
      } catch (error) { if (this.scope(client, revision)) this.error = errorText(error); }
      finally { if (this.scope(client, revision)) this.loading = false; }
    },
    openItem(itemId) { if (!this.connected || this.loading || !this._client || !this.scope(this._client, this._revision) || !this.items.some(item => item.id === itemId)) return; uni.navigateTo({ url: '/pages/goods/marketplace_review/index?root_id=' + this.rootId + '&item_id=' + itemId }); },
    setScore(key, score) { if (!this.formLocked && this.scope(this._client, this._revision) && (key === 'product_score' || key === 'service_score')) this.draft[key] = score; },
    choosePictures() {
      if (this.formLocked || this.uploadBusy || this.uploads.length >= 8 || !this.scope(this._client, this._revision)) return; const client = this._client, revision = this._revision; this.choosing = true;
      uni.chooseImage({ count: 8 - this.uploads.length, sizeType: ['compressed'], sourceType: ['album', 'camera'],
        success: async result => {
          if (!this.scope(client, revision)) return; const paths = Array.isArray(result.tempFilePaths) ? result.tempFilePaths : []; const rows = [];
          for (const path of paths.slice(0, 8 - this.uploads.length)) { if (typeof path !== 'string' || !path) continue; const row = { key: 'picture-' + (++this._uploadSequence), path, preview: '', reference: '', loading: false, error: '' }; this.uploads.push(row); rows.push(row); }
          this.choosing = false; for (const row of rows) { if (!this.scope(client, revision) || this.pending) return; await this.uploadOne(row); }
        }, fail: () => { if (this.scope(client, revision)) this.choosing = false; }, complete: () => { if (this.scope(client, revision)) this.choosing = false; } });
    },
    async uploadOne(row) {
      if (this.formLocked || row.loading || row.reference || !this.uploads.includes(row)) return; const client = this._client, revision = this._revision, rootId = this.rootId, itemId = this.itemId; row.loading = true; row.error = '';
      try { const result = await client.upload(row.path, rootId, itemId); if (!this.scope(client, revision) || this.rootId !== rootId || this.itemId !== itemId || !this.uploads.includes(row) || this.pending) return; row.reference = result.reference; row.preview = result.preview; }
      catch (error) { if (this.scope(client, revision) && this.rootId === rootId && this.itemId === itemId && this.uploads.includes(row)) row.error = errorText(error); }
      finally { if (this.scope(client, revision) && this.rootId === rootId && this.itemId === itemId && this.uploads.includes(row)) row.loading = false; }
    },
    removeUpload(key) { if (!this.formLocked && !this.uploadBusy && this.scope(this._client, this._revision)) this.uploads = this.uploads.filter(row => row.key !== key); },
    previewDraft(index) { const urls = this.uploads.map(row => row.preview || row.path); if (this.connected && this.scope(this._client, this._revision) && urls[index]) uni.previewImage({ urls, current: urls[index] }); },
    previewSaved(index) { if (!this.connected || !this.quote || !this.quote.review || !this.scope(this._client, this._revision)) return; const urls = this.quote.review.pics.slice(); if (urls[index]) uni.previewImage({ urls, current: urls[index] }); },
    async submit() {
      if (this.formLocked || this.uploadBusy || !this.allUploaded || !this.scope(this._client, this._revision)) return;
      try { this.pending = createIntent(this.quote, { ...this.draft, pics: this.uploads.map(row => row.reference) }); this.retryReady = false; this.notice = ''; this.error = ''; await this.sendFrozen(); }
      catch (error) { if (this._alive) this.error = errorText(error); }
    },
    async sendFrozen() {
      if (!this.pending || this.busy || this.loading || !this._client || !this.scope(this._client, this._revision)) return;
      const client = this._client, revision = this._revision, intent = this.pending; this.busy = true; this.retryReady = false; this.error = '';
      try {
        const saved = reviewWrite(await client.create(intent), intent); if (!this.scope(client, revision) || this.pending !== intent) return;
        this.pending = null; this.uploads = []; this.draft = emptyDraft(); this.quoteFresh = false; this.notice = '评价已保存（编号 ' + saved.id + '），正在读取最新记录';
        const result = reviewQuote(await client.quote(this.rootId, this.itemId), this.rootId, this.itemId); if (!this.scope(client, revision)) return;
        if (!result.review || result.review.id !== saved.id) throw new Error('评价已保存，最新记录尚未读回，请重新读取'); reviewWrite(result.review, intent); this.quote = result; this.quoteFresh = true; this.notice = '评价已保存，审核状态以此真实记录为准';
      } catch (error) { if (this.scope(client, revision)) { this.error = errorText(error); this.quoteFresh = false; if (this.pending === intent) this.notice = '原提交结果未确认，内容已保留。请先查询保存记录。'; } }
      finally { if (this.scope(client, revision)) this.busy = false; }
    },
    async checkPending() {
      if (!this.pending || this.loading || this.busy || !this._client || !this.scope(this._client, this._revision)) return;
      const client = this._client, revision = this._revision, intent = this.pending; this.loading = true; this.retryReady = false; this.error = ''; this.quoteFresh = false;
      try {
        const result = reviewQuote(await client.quote(intent.rootId, intent.itemId), intent.rootId, intent.itemId); if (!this.scope(client, revision) || this.pending !== intent) return;
        this.quote = result; this.quoteFresh = true;
        if (result.review) {
          if (intent.body.pics.length > 0) {
            this.retryReady = true;
            this.notice = '已读取保存记录，但暂不能确认其图片与本次原图完全一致。原内容仍保留，请核对原评分、原文和图片后，明确点击按钮用原内容确认。';
          } else {
            const saved = reviewWrite(result.review, intent); this.pending = null; this.uploads = []; this.draft = emptyDraft(); this.notice = '已读回原评价（编号 ' + saved.id + '），无需重发';
          }
        } else { this.retryReady = result.allowed_actions.submit === true; this.notice = this.retryReady ? '当前未读到保存记录。原内容仍保留，可点击下方按钮明确重发同一评价。' : '当前未读到保存记录且不能新增。原内容仍保留，请稍后重新查询。'; }
      } catch (error) { if (this.scope(client, revision) && this.pending === intent) { this.error = errorText(error); this.notice = '查询未确认原提交，原内容仍保留'; } }
      finally { if (this.scope(client, revision)) this.loading = false; }
    },
    resendPending() {
      if (!this.retryReady || !this.pending || !this.quoteFresh || !this.quote || this.loading || this.busy || !this.connected || !this.scope(this._client, this._revision)) return;
      const intent = this.pending;
      if (intent.rootId !== this.rootId || intent.itemId !== this.itemId || this.quote.root_order_id !== intent.rootId || this.quote.source_item_id !== intent.itemId) return;
      if (this.quote.review === null ? this.quote.allowed_actions.submit !== true : intent.body.pics.length === 0) return;
      return this.sendFrozen();
    }
  }
};
</script>
<style scoped>
.review-page { box-sizing: border-box; padding: 24rpx; background: #f5f5f5; min-height: 100vh; color: #333; font-size: 28rpx; line-height: 1.6; }
.review-card, .review-feedback { box-sizing: border-box; min-width: 0; width: 100%; margin-bottom: 24rpx; padding: 24rpx; background: #fff; border-radius: 12rpx; overflow-wrap: anywhere; }
.review-title, .review-heading, .review-label, .review-note, .review-plain { display: block; }
.review-title { font-size: 36rpx; font-weight: 600; }.review-heading { margin-bottom: 12rpx; font-size: 30rpx; font-weight: 600; }.review-label { margin-top: 24rpx; margin-bottom: 12rpx; }.review-note { color: #666; font-size: 24rpx; }.review-plain { margin-top: 20rpx; white-space: pre-wrap; word-break: break-word; }.review-error { border-left: 6rpx solid #b42318; }.review-feedback { border-left: 6rpx solid #687b91; }
.review-button, .review-score { display: flex; align-items: center; justify-content: center; box-sizing: border-box; max-width: 100%; min-width: 0; min-height: 44px; height: auto; margin: 20rpx 0 0; padding: 12px 16px; border: 1px solid #ccc; border-radius: 8rpx; background: #fff; color: #333; font-size: 28rpx; line-height: 1.4; text-align: center; white-space: normal; }
.review-button { width: 100%; }.review-button::after, .review-score::after { border: 0; }.review-primary { background: var(--view-theme, #b62f25); color: #fff; border-color: var(--view-theme, #b62f25); }.review-button[disabled], .review-score[disabled] { opacity: .55; }.review-scores { display: flex; flex-wrap: wrap; margin: 0 -6rpx; }.review-score { flex: 1 1 100rpx; margin: 6rpx; padding: 10px 6px; }.review-score-selected { border: 2px solid var(--view-theme, #b62f25); font-weight: 600; }
.review-product { display: flex; align-items: flex-start; gap: 20rpx; min-width: 0; }.review-product-image { flex: 0 0 140rpx; width: 140rpx; height: 140rpx; }.review-product-text { flex: 1; min-width: 0; word-break: break-word; }.review-textarea { box-sizing: border-box; width: 100%; min-height: 220rpx; padding: 20rpx; border: 1px solid #ccc; border-radius: 8rpx; font-size: 28rpx; line-height: 1.6; }.review-pictures { display: flex; flex-wrap: wrap; gap: 16rpx; margin-top: 20rpx; }.review-picture { width: 150rpx; height: 150rpx; flex: 0 0 150rpx; }.review-upload { display: flex; gap: 20rpx; padding-top: 24rpx; min-width: 0; }.review-upload-content { flex: 1; min-width: 0; }.review-reply { margin-top: 24rpx; padding-top: 20rpx; border-top: 1px solid #eee; }.review-actions { display: flex; flex-wrap: wrap; gap: 12rpx; }.review-actions .review-button { flex: 1 1 160rpx; width: auto; }
</style>
