<template>
  <section class="review-console"><el-card shadow="never">
    <h1 class="review-heading">{{ target ? '评价详情' : '多商户商品评价' }}</h1>
    <p class="review-note">查看顾客原评价与商家回复，按当前权限审核评价。</p>
    <el-alert v-if="error" class="review-feedback" :title="error" type="error" :closable="false" show-icon />
    <p v-if="notice" class="review-notice" role="status">{{ notice }}</p>
    <div class="review-actions">
      <el-button v-if="target && can('list')" :disabled="sending" @click="$router.push(listPath)">返回评价列表</el-button>
      <el-button :disabled="loading || sending || !context" @click="readCurrent">{{ pending ? '读取当前记录，核对原提交' : '重新读取当前资料' }}</el-button>
    </div>
    <template v-if="!target">
      <el-form class="review-filter" label-position="top" @submit.native.prevent="search">
        <el-form-item label="审核状态" for="review-status"><el-select id="review-status" v-model="statusFilter" :disabled="loading"><el-option value="" label="全部状态" /><el-option v-for="status in [0, 1, 2]" :key="status" :value="status" :label="statusText(status)" /></el-select></el-form-item>
        <el-form-item label="商家回复" for="review-replied"><el-select id="review-replied" v-model="replyFilter" :disabled="loading"><el-option value="" label="全部" /><el-option :value="0" label="未回复" /><el-option :value="1" label="已回复" /></el-select></el-form-item>
        <el-form-item label="商品编号（可选）" for="review-product"><el-input id="review-product" v-model="productId" :disabled="loading" maxlength="10" placeholder="原保存商品编号" /></el-form-item>
        <el-form-item label="商家编号（可选）" for="review-merchant"><el-input id="review-merchant" v-model="merchantId" :disabled="loading" maxlength="10" placeholder="全部已授权商家" /></el-form-item>
        <el-button native-type="submit" :disabled="loading || !context || !can('list')">查询</el-button>
      </el-form>
      <el-table v-loading="loading" v-table-scroll="'评价列表，可横向滚动'" :data="rows" border empty-text="暂无符合条件的评价">
        <el-table-column prop="id" label="评价编号" min-width="100" />
        <el-table-column prop="root_order_id" label="付款订单编号" min-width="130" />
        <el-table-column prop="product_id" label="商品编号" min-width="105" />
        <el-table-column prop="suk" label="原保存规格" min-width="160" />
        <el-table-column label="商品 / 服务评分" min-width="150"><template slot-scope="scope">{{ scope.row.product_score }} / {{ scope.row.service_score }}</template></el-table-column>
        <el-table-column label="顾客评价" min-width="280"><template slot-scope="scope"><p class="review-saved review-table-copy">{{ scope.row.comment || '顾客未填写文字' }}</p><span class="review-note">图片 {{ scope.row.pics.length }} 张 · {{ scope.row.nickname }}</span></template></el-table-column>
        <el-table-column label="审核 / 回复" min-width="150"><template slot-scope="scope">{{ statusText(scope.row.status) }}<br>{{ scope.row.is_reply ? '商家已回复' : '商家未回复' }}</template></el-table-column>
        <el-table-column label="评价时间" min-width="175"><template slot-scope="scope">{{ timeText(scope.row.add_time) }}</template></el-table-column>
        <el-table-column label="操作" min-width="115"><template slot-scope="scope"><el-button v-if="can('read')" size="small" :disabled="loading" :aria-label="'查看评价 ' + scope.row.id" @click="$router.push(listPath + '/' + scope.row.id)">查看详情</el-button><span v-else>无详情权限</span></template></el-table-column>
      </el-table>
      <div class="review-pagination"><el-pagination :current-page="page" :page-size="limit" :total="count" :pager-count="5" layout="prev, pager, next" :disabled="loading" @current-change="changePage" /></div>
    </template>
    <template v-else-if="view">
      <div class="review-author"><img v-if="view.avatar" :src="view.avatar" alt="顾客头像"><div><strong>{{ view.nickname || '顾客' }}</strong><p class="review-note">{{ timeText(view.add_time) }}</p></div></div>
      <dl class="review-facts">
        <div><dt>评价编号</dt><dd>{{ view.id }}</dd></div><div><dt>付款订单编号</dt><dd>{{ view.root_order_id }}</dd></div>
        <div><dt>原订单商品条目编号</dt><dd>{{ view.source_item_id }}</dd></div><div><dt>原保存商品编号</dt><dd>{{ view.product_id }}</dd></div>
        <div><dt>原保存规格</dt><dd>{{ view.suk || '无' }}</dd></div><div><dt>审核状态</dt><dd>{{ statusText(view.status) }}</dd></div>
        <div><dt>商品评分</dt><dd><el-rate :value="view.product_score" disabled show-score text-color="#606266" :score-template="view.product_score + ' / 5'" /></dd></div>
        <div><dt>服务评分</dt><dd><el-rate :value="view.service_score" disabled show-score text-color="#606266" :score-template="view.service_score + ' / 5'" /></dd></div>
      </dl>
      <h2>顾客原评价</h2><p class="review-saved">{{ view.comment || '顾客未填写文字' }}</p>
      <h2>顾客图片</h2><p v-if="!view.pics.length" class="review-note">此评价未附图片</p>
      <div v-else class="review-images"><figure v-for="(picture, index) in imageRows" :key="picture.key">
        <a :href="picture.url" target="_blank" rel="noopener noreferrer" :aria-label="'查看顾客图片 ' + (index + 1)"><img :src="picture.url" :alt="'顾客评价图片 ' + (index + 1)" @load="imageLoaded(picture, $event)" @error="imageFailed(picture, $event)"></a>
        <figcaption>图片 {{ index + 1 }}<span v-if="!picture.loaded && !picture.error"> · 正在显示</span></figcaption><p v-if="picture.error" class="review-image-error" role="status">{{ picture.error }}</p>
      </figure></div>
      <h2>商家回复</h2><p v-if="view.is_reply" class="review-saved">{{ view.merchant_reply_content }}</p><p v-else class="review-note">商家尚未回复</p><p v-if="view.is_reply" class="review-note">{{ timeText(view.merchant_reply_time) }}</p>
      <section v-if="pending" class="review-pending">
        <h2>原提交内容保留中</h2><p role="status">本次{{ operationLabel }}结果尚未确认。请先读取当前记录核对；读取失败时仍保留原内容。离开页面将清除本地内容。</p>
        <p v-if="operation === 'reply'" class="review-saved">{{ pending.body.content }}</p><p v-else>本次审核结果：{{ statusText(pending.body.status) }}</p>
        <div class="review-actions"><el-button :disabled="loading || sending || !context || !can('read')" @click="reconcilePending">读取当前记录，核对原提交</el-button><el-button v-if="canRetry" :disabled="loading || sending" @click="sendPending">按原内容再次提交</el-button></div>
        <p v-if="recoveryReady" class="review-note">当前记录尚未显示本次内容。再次提交会按以上原内容重新处理。</p>
      </section>
      <el-form v-else-if="canChange && !needsRefresh" class="review-command" label-position="top" @submit.native.prevent="prepare">
        <h2>{{ operationLabel }}</h2>
        <template v-if="operation === 'reply'"><p class="review-note">回复会显示在这条顾客评价下。</p><el-form-item label="回复内容（1–300字）" for="review-reply" required><el-input id="review-reply" v-model="replyContent" type="textarea" :rows="5" :disabled="loading || sending || !!preview" /></el-form-item></template>
        <el-form-item v-else label="审核结果" for="review-moderation" required><el-radio-group id="review-moderation" v-model="moderationStatus" :disabled="loading || sending || !!preview"><el-radio :label="1">审核通过</el-radio><el-radio :label="2">审核不通过</el-radio></el-radio-group></el-form-item>
        <el-alert v-if="commandError" :title="commandError" type="error" :closable="false" show-icon />
        <div v-if="!preview" class="review-actions"><el-button native-type="submit" :disabled="loading || sending || !canChange">核对本次{{ operationLabel }}</el-button></div>
        <section v-else class="review-preview"><h3>确认本次{{ operationLabel }}</h3><p>评价编号：{{ preview.reviewId }}</p><p v-if="operation === 'reply'" class="review-saved">{{ preview.body.content }}</p><p v-else>审核结果：{{ statusText(preview.body.status) }}</p><div class="review-actions"><el-button type="primary" :disabled="loading || sending || !canChange" @click="commitPreview">确认提交</el-button><el-button :disabled="sending" @click="preview = null">返回修改</el-button></div></section>
      </el-form>
      <p v-else-if="!pending && operation === 'reply' && view.status !== 1" class="review-note">审核通过后可回复此评价。</p>
      <p v-if="sending" role="status">正在提交原请求，请稍候…</p>
    </template>
  </el-card></section>
</template>
<script>
import { captureContext, currentContext, closeContext, can, page, detail, command, refreshPermissions } from '@/api/marketplace-review';
import { replyIntent, moderateIntent, commandMatches, routeId, errorText, statusText, timeText } from '@/libs/marketplace-review';
import setting from '@/setting';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import '../style.css';
const tableScroll = { inserted: labelTable, componentUpdated: labelTable };
function labelTable(element, binding) { const wrappers = element.querySelectorAll('.el-table__body-wrapper'); if (wrappers.length === 1) { wrappers[0].setAttribute('tabindex', '0'); wrappers[0].setAttribute('role', 'region'); wrappers[0].setAttribute('aria-label', binding.value); } }
export default {
  name: 'marketplaceReviews', directives: { 'table-scroll': tableScroll },
  data: () => ({ operation: 'moderate', operationLabel: '平台审核', context: null, contextTarget: '', alive: true, generation: 0, readJob: 0, sendJob: 0, loading: false, sending: false, error: '', commandError: '', notice: '', rows: [], count: 0, page: 1, limit: 20, statusFilter: '', replyFilter: '', productId: '', merchantId: '', view: null, fresh: false, preview: null, pending: null, recoveryReady: false, needsRefresh: false, replyContent: '', moderationStatus: 1, imageRows: [], unsubscribe: null, unsubscribeEpoch: null }),
  computed: {
    target() { return this.$route.params.id || ''; }, listPath() { return setting.routePre + '/marketplace/reviews'; },
    canChange() { return this.fresh && !this.loading && !this.error && !!this.view && !!this.context && currentContext(this.context) && String(this.view.id) === String(this.target) && this.can(this.operation) && (this.operation !== 'reply' || this.view.status === 1); },
    canRetry() { return !!this.pending && this.recoveryReady && this.canChange && String(this.pending.reviewId) === String(this.target); }
  },
  created() { this.unsubscribeEpoch = subscribePlatformAuthEpoch(() => this.syncIdentity()); this.unsubscribe = this.$store.subscribe(() => this.syncIdentity()); window.addEventListener('focus', this.syncIdentity); window.addEventListener('storage', this.syncIdentity); this.reset(); },
  watch: { '$route.fullPath'() { this.reset(); } },
  activated() { if (this.alive && this.activeRoute() && !this.context) this.reset(); },
  deactivated() { this.clear(); },
  beforeDestroy() { this.alive = false; if (this.unsubscribe) this.unsubscribe(); if (this.unsubscribeEpoch) this.unsubscribeEpoch(); window.removeEventListener('focus', this.syncIdentity); window.removeEventListener('storage', this.syncIdentity); this.clear(); },
  methods: {
    timeText, statusText,
    activeRoute() { return ['marketplace_reviews', 'marketplace_review_detail'].includes(this.$route.name); },
    can(action) { return can(this.$store, action); },
    current(context, generation) { if (!this.alive || !this.activeRoute() || context !== this.context || generation !== this.generation || this.contextTarget !== String(this.target)) return false; if (!currentContext(context)) { this.syncIdentity(); return false; } return true; },
    clear() { closeContext(this.context); this.context = null; this.contextTarget = ''; this.generation += 1; this.readJob += 1; this.sendJob += 1; this.rows = []; this.count = 0; this.view = null; this.fresh = false; this.loading = false; this.sending = false; this.preview = null; this.pending = null; this.recoveryReady = false; this.needsRefresh = false; this.replyContent = ''; this.moderationStatus = 1; this.imageRows = []; this.notice = ''; this.error = ''; this.commandError = ''; },
    reset() { this.clear(); if (!this.alive || !this.activeRoute()) return; try { this.context = captureContext(this.$store); this.contextTarget = String(this.target); this.load(); } catch (error) { this.error = errorText(error); } },
    syncIdentity() {
      if (this.context && !currentContext(this.context)) { this.clear(); this.error = '身份已变化，请重新进入评价页面'; return; }
      if (this.context && !this.can(this.target ? 'read' : 'list')) { this.rows = []; this.count = 0; this.view = null; this.imageRows = []; this.fresh = false; this.preview = null; this.recoveryReady = false; this.error = '当前评价读取权限已变化'; }
      if (!this.can(this.operation)) { this.preview = null; this.recoveryReady = false; }
    },
    search() { if (!this.loading) { this.page = 1; this.load(); } },
    changePage(value) { if (!this.loading && Number.isSafeInteger(value) && value > 0) { this.page = value; this.load(); } },
    readCurrent() { if (this.pending) this.reconcilePending(); else this.load(); },
    query() {
      const query = { page: this.page, limit: this.limit };
      if (this.statusFilter !== '') query.status = this.statusFilter; if (this.replyFilter !== '') query.is_reply = this.replyFilter;
      if (this.productId !== '') query.product_id = routeId(this.productId);
      if (this.merchantId !== '') { if (!/^[1-9][0-9]{0,9}(?![\s\S])/.test(this.merchantId) || Number(this.merchantId) > 4294967295) throw new Error('请填写有效商家编号'); query.mer_id = Number(this.merchantId); }
      return query;
    },
    setView(value) { this.view = value; this.imageRows = value.pics.map((url, index) => ({ key: this.generation + ':' + this.readJob + ':' + index, url, loaded: false, error: '' })); },
    async load() {
      if (this.loading || this.sending || !this.context || this.pending) return false; const context = this.context; const generation = this.generation; const job = ++this.readJob;
      this.loading = true; this.fresh = false; this.error = ''; this.preview = null; this.commandError = '';
      try {
        await refreshPermissions(context, () => this.current(context, generation) && job === this.readJob && !this.$router.history.pending); if (!this.current(context, generation) || job !== this.readJob) return false;
        if (this.target) {
          const value = await detail(context, routeId(this.target)); if (!this.current(context, generation) || job !== this.readJob) return false;
          this.setView(value); this.replyContent = value.merchant_reply_content; this.fresh = true; this.needsRefresh = false;
        } else {
          const value = await page(context, this.query()); if (!this.current(context, generation) || job !== this.readJob) return false;
          this.rows = value.list; this.count = value.count;
        }
        return true;
      } catch (error) { if (this.current(context, generation) && job === this.readJob) this.error = errorText(error); return false; }
      finally { if (this.current(context, generation) && job === this.readJob) this.loading = false; }
    },
    imageLoaded(picture, event) { if (this.context && this.current(this.context, this.generation) && this.imageRows.includes(picture) && event.target.getAttribute('src') === picture.url) picture.loaded = true; },
    imageFailed(picture, event) { if (this.context && this.current(this.context, this.generation) && this.imageRows.includes(picture) && event.target.getAttribute('src') === picture.url) { picture.loaded = false; picture.error = '图片未能显示，可打开原图查看'; } },
    prepare() {
      if (this.pending || !this.canChange) return; this.commandError = '';
      try { this.preview = this.operation === 'reply' ? replyIntent(this.view, this.replyContent) : moderateIntent(this.view, this.moderationStatus); }
      catch (error) { this.commandError = errorText(error); }
    },
    commitPreview() { if (!this.preview || this.pending || !this.canChange) return; this.pending = this.preview; this.preview = null; this.recoveryReady = false; this.sendPending(true); },
    async sendPending(initial = false) {
      if (!this.pending || this.loading || this.sending || !this.context || (initial === true ? !this.canChange : !this.canRetry)) return;
      const context = this.context; const generation = this.generation; const intent = this.pending; const job = ++this.sendJob;
      if (String(this.target) !== String(intent.reviewId) || !this.current(context, generation)) return;
      this.sending = true; this.fresh = false; this.recoveryReady = false; this.error = ''; this.commandError = ''; this.notice = ''; let confirmed = false;
      try {
        await command(context, intent); if (!this.current(context, generation) || job !== this.sendJob || this.pending !== intent) return;
        this.pending = null; this.needsRefresh = true; this.notice = '本次' + this.operationLabel + '返回已确认，正在读取当前记录'; confirmed = true;
      } catch (error) { if (this.current(context, generation) && job === this.sendJob && this.pending === intent) this.error = '本次结果尚未确认：' + errorText(error); }
      finally { if (this.current(context, generation) && job === this.sendJob) this.sending = false; }
      if (!this.current(context, generation) || job !== this.sendJob) return;
      if (confirmed) { const loaded = await this.load(); if (this.current(context, generation)) this.notice = loaded ? '本次' + this.operationLabel + '返回已确认，当前记录已重新读取' : '本次' + this.operationLabel + '返回已确认，最新记录尚未读回'; }
      else if (this.pending === intent) await this.reconcilePending();
    },
    async reconcilePending() {
      if (!this.pending || this.loading || this.sending || !this.context || !this.can('read')) return false;
      const context = this.context; const generation = this.generation; const intent = this.pending; const job = ++this.readJob;
      if (String(this.target) !== String(intent.reviewId) || !this.current(context, generation)) return false;
      this.loading = true; this.fresh = false; this.recoveryReady = false; this.error = ''; let matched = false;
      try {
        await refreshPermissions(context, () => this.current(context, generation) && job === this.readJob && !this.$router.history.pending); if (!this.current(context, generation) || job !== this.readJob) return false;
        const value = await detail(context, intent.reviewId); if (!this.current(context, generation) || job !== this.readJob || this.pending !== intent) return false;
        this.setView(value); matched = commandMatches(value, intent); this.fresh = true;
        if (matched) { this.pending = null; this.needsRefresh = true; this.notice = '当前记录已核对到原提交内容，正在再次读取最新记录'; }
        else { this.recoveryReady = true; this.notice = '当前记录尚未显示原提交内容，原内容继续保留'; }
      } catch (error) { if (this.current(context, generation) && job === this.readJob && this.pending === intent) this.error = '原内容仍保留：' + errorText(error); }
      finally { if (this.current(context, generation) && job === this.readJob) this.loading = false; }
      if (matched && this.current(context, generation) && job === this.readJob) { const loaded = await this.load(); if (this.current(context, generation)) this.notice = loaded ? '当前记录已核对到原提交内容，最新记录已读回' : '已核对到原提交内容，最新记录尚未读回'; }
      return matched;
    }
  }
};
</script>
<style scoped>
.review-console { min-width: 0; }
.review-console * { box-sizing: border-box; }
.review-heading { margin: 0 0 12px; font-size: 21px; }
.review-console h2 { margin: 26px 0 14px; font-size: 17px; }
.review-console h3 { margin: 0 0 12px; font-size: 16px; }
.review-note { color: #606266; line-height: 1.7; margin: 8px 0; overflow-wrap: anywhere; }
.review-feedback, .review-notice { margin: 16px 0; }
.review-notice { padding: 12px 16px; border-left: 4px solid #2d8cf0; background: #f5f7fa; line-height: 1.7; overflow-wrap: anywhere; }
.review-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0; }
.review-console .el-button { display: inline-flex; align-items: center; justify-content: center; text-align: center; max-width: 100%; white-space: normal; }
.review-actions .el-button + .el-button { margin-left: 0; }
.review-filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-top: 20px; }
.review-filter .el-form-item { width: 205px; max-width: 100%; margin-bottom: 0; }
.review-filter .el-select { width: 100%; }
.review-author { display: flex; align-items: center; gap: 12px; margin: 24px 0; }
.review-author img { width: 44px; height: 44px; object-fit: cover; border-radius: 50%; flex: 0 0 44px; }
.review-author > div { min-width: 0; overflow-wrap: anywhere; }
.review-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.review-facts dt { color: #606266; margin-bottom: 6px; }
.review-facts dd { margin: 0; line-height: 1.7; overflow-wrap: anywhere; }
.review-saved { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; }
.review-table-copy { margin: 0 0 8px; }
.review-images { display: flex; flex-wrap: wrap; gap: 16px; }
.review-images figure { width: 190px; max-width: 100%; margin: 0; padding: 12px; border: 1px solid #ebeef5; }
.review-images a { display: block; }
.review-images img { display: block; width: 100%; height: 150px; object-fit: contain; }
.review-images figcaption { margin: 10px 0; line-height: 1.6; }
.review-image-error { color: #c45656; line-height: 1.6; overflow-wrap: anywhere; }
.review-command, .review-pending { padding: 16px; border: 1px solid #dcdfe6; margin: 20px 0; line-height: 1.7; }
.review-command h2, .review-pending h2 { margin-top: 0; }
.review-preview { padding-top: 18px; border-top: 1px solid #ebeef5; }
.review-console .el-radio-group { display: flex; flex-wrap: wrap; gap: 16px; max-width: 100%; }
.review-console .el-radio { display: inline-flex; align-items: center; justify-content: center; text-align: center; margin-right: 0; max-width: 100%; }
.review-pagination { display: flex; justify-content: flex-end; margin-top: 20px; max-width: 100%; }
.review-pagination >>> .el-pagination { display: flex; flex-wrap: wrap; justify-content: inherit; gap: 6px; max-width: 100%; white-space: normal; padding: 0; }
.review-pagination >>> .el-pager { display: flex; flex-wrap: wrap; justify-content: center; max-width: 100%; }
@media (max-width: 760px) { .review-facts { grid-template-columns: minmax(0, 1fr); } .review-filter { display: block; } .review-filter .el-form-item { width: 100%; margin-bottom: 16px; } .review-pagination { justify-content: flex-start; } }
</style>
