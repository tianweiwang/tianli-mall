<template>
  <section class="aftersale-console page-panel">
    <h1 class="aftersale-heading">{{ target ? '售后详情' : '多商户售后' }}</h1>
    <p class="aftersale-note">保存版本 V1 · 业务处理、实物退件和资金结果分别显示</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="aftersale-feedback" />
    <el-alert v-if="commandError" :title="commandError" type="error" :closable="false" show-icon class="aftersale-feedback" />
    <p v-if="notice" class="aftersale-notice" role="status" aria-live="polite">{{ notice }}</p>
    <p v-if="loading" role="status">正在读取当前售后资料…</p>
    <div class="aftersale-actions">
      <el-button v-if="target && can('list')" :disabled="sending" @click="$router.push(listPath)">返回售后列表</el-button>
      <el-button :disabled="loading || sending || !context" @click="load">重新读取当前资料</el-button>
    </div>
    <template v-if="!target">
      <el-form class="aftersale-filter" label-position="top" @submit.native.prevent="search">
        <el-form-item label="业务状态" for="aftersale-state"><el-select id="aftersale-state" v-model="businessState" :disabled="loading"><el-option value="" label="全部状态" /><el-option v-for="(label, value) in businessLabels" :key="value" :value="value" :label="label" /></el-select></el-form-item>
        
        <el-button native-type="submit" :disabled="loading || !context">查询</el-button>
      </el-form>
      <el-table v-loading="loading" v-table-scroll="'售后列表，可横向滚动'" :data="rows" border>
        <el-table-column prop="refund_no" label="售后单号" min-width="190" />
        <el-table-column prop="root_order_no" label="付款订单号" min-width="190" />
        <el-table-column prop="merchant_name_snapshot" label="保存商家名称" min-width="150" />
        <el-table-column label="申请类型" min-width="140"><template slot-scope="scope">{{ kindLabels[scope.row.operation_kind] }}</template></el-table-column>
        <el-table-column label="业务 / 实物" min-width="210"><template slot-scope="scope">{{ businessLabels[scope.row.business_state] }}<br>{{ physicalLabels[scope.row.physical_state] }}</template></el-table-column>
        <el-table-column label="资金状态" min-width="170"><template slot-scope="scope">{{ financeLabels[scope.row.finance_state] }}</template></el-table-column>
        <el-table-column prop="requested_amount" label="申请金额（元）" min-width="135" />
        <el-table-column label="操作" min-width="110"><template slot-scope="scope"><el-button v-if="can('read')" size="small" :aria-label="'查看售后 ' + scope.row.refund_no" :disabled="loading" @click="$router.push(listPath + '/' + scope.row.id)">查看详情</el-button><span v-else>无详情权限</span></template></el-table-column>
      </el-table>
      <div class="aftersale-pagination"><el-pagination :current-page="page" :page-size="limit" :total="count" :pager-count="5" layout="prev, pager, next" @current-change="changePage" /></div>
    </template>
    <template v-else-if="view">
      <dl class="aftersale-facts">
        <div><dt>售后单号</dt><dd>{{ view.refund_no }}</dd></div><div><dt>付款订单号</dt><dd>{{ view.root_order_no }}</dd></div>
        <div><dt>保存商家名称</dt><dd>{{ view.merchant_name_snapshot }}</dd></div><div><dt>申请类型</dt><dd>{{ kindLabels[view.operation_kind] }}</dd></div>
        <div><dt>业务状态</dt><dd>{{ businessLabels[view.business_state] }}</dd></div><div><dt>实物状态</dt><dd>{{ physicalLabels[view.physical_state] }}</dd></div>
        <div><dt>资金状态</dt><dd>{{ financeLabels[view.finance_state] }}</dd></div><div><dt>申请 / 确认 / 占用金额（元）</dt><dd>{{ view.requested_amount }} / {{ view.confirmed_amount }} / {{ view.held_amount }}</dd></div>
        <div><dt>申请时间</dt><dd>{{ timeText(view.created_at) }}</dd></div><div><dt>更新时间</dt><dd>{{ timeText(view.updated_at) }}</dd></div>
      </dl>
      <h2>申请理由</h2><p class="aftersale-saved">{{ view.reason }}</p>
      <template v-if="view.business_state === 'REJECTED'">
        <h2>拒绝原因</h2><p class="aftersale-saved">{{ view.rejection_reason || '此记录未提供拒绝原因，请联系商家确认。' }}</p>
      </template>
      <h2>原保存商品与规格</h2>
      <el-table v-table-scroll="'原保存商品，可横向滚动'" :data="view.items" border>
        <el-table-column label="商品及规格" min-width="260"><template slot-scope="scope"><div class="aftersale-product"><img v-if="scope.row.item.image" :src="scope.row.item.image" alt="原商品保存图片"><div>{{ scope.row.item.product_name }}<p class="aftersale-note">{{ scope.row.item.sku_name }}</p></div></div></template></el-table-column>
        <el-table-column label="申请数量 / 已验收" min-width="175"><template slot-scope="scope">{{ scope.row.selected_quantity }} / {{ scope.row.accepted_quantity }}</template></el-table-column>
        <el-table-column label="原成交单价（元）" min-width="145"><template slot-scope="scope">{{ scope.row.item.unit_price }}</template></el-table-column>
        <el-table-column label="所选商品金额 / 分摊运费（元）" min-width="195"><template slot-scope="scope">{{ scope.row.item.line_price }} / {{ scope.row.item.postage_price }}</template></el-table-column>
        <el-table-column prop="confirmed_amount" label="本商品确认金额（元）" min-width="175" />
      </el-table>
      <h2>顾客私有凭证</h2>
      <p v-if="!view.images.length" class="aftersale-note">此申请未附凭证</p>
      <p v-else-if="!can('ticket') || !can('content')" class="aftersale-note">当前账号无完整凭证读取权限</p>
      <div v-else class="aftersale-images"><figure v-for="(image, index) in imageRows" :key="image.id">
        <img v-if="image.url" :key="image.url" :src="image.url" :alt="'顾客私有凭证 ' + (index + 1)" @load="imageLoaded(image, $event)" @error="imageFailed(image, $event)">
        <figcaption>凭证 {{ index + 1 }}<span v-if="image.loading"> · 正在读取</span><span v-else-if="image.url && !image.loaded"> · 正在显示</span></figcaption>
        <p v-if="image.error" class="aftersale-image-error" role="status">{{ image.error }}</p>
        <el-button v-if="image.error || !image.url" size="small" :disabled="image.loading || loading || !context" @click="loadImage(image)">重新读取凭证 {{ index + 1 }}</el-button>
      </figure></div>
      <template v-if="view.operation_kind === 'return_goods'">
        <h2>本次回寄地址</h2>
        <dl v-if="view.return_destination" class="aftersale-facts">
          <div><dt>收件人</dt><dd>{{ view.return_destination.recipient_name }}</dd></div>
          <div><dt>联系电话</dt><dd>{{ view.return_destination.phone }}</dd></div>
          <div><dt>完整地址</dt><dd class="aftersale-saved">{{ view.return_destination.address }}</dd></div>
        </dl>
        <p v-else class="aftersale-note">{{ view.business_state === 'REQUESTED' ? '批准退货时填写本次回寄地址。' : view.business_state === 'APPROVED' && view.physical_state !== 'RETURN_ACCEPTED' ? '此记录未登记回寄地址，请与顾客确认后再寄回。' : '此记录未登记回寄地址。' }}</p>
      </template>
      <h2>退件信息</h2>
      <p v-if="view.return_delivery" class="aftersale-saved">{{ view.return_delivery.carrier_name }} · {{ view.return_delivery.tracking_no }}</p><p v-else class="aftersale-note">尚无顾客退件运单</p>
      <template v-if="view.return_selection">
        <p class="aftersale-saved">实际包裹：{{ view.return_selection.child_order_no }}</p>
        <el-table v-table-scroll="'退件序号区间，可横向滚动'" :data="view.return_selection.items" border>
          <el-table-column prop="item_id" label="实际包裹商品编号" min-width="155" />
          <el-table-column label="申请序号" min-width="170"><template slot-scope="scope">{{ segmentText(scope.row.selected_segments) }}</template></el-table-column>
          <el-table-column label="已验收序号" min-width="170"><template slot-scope="scope">{{ segmentText(scope.row.accepted_segments) || '—' }}</template></el-table-column>
          <el-table-column label="当前可验收序号" min-width="175"><template slot-scope="scope">{{ segmentText(scope.row.eligible_segments) || '—' }}</template></el-table-column>
        </el-table>
      </template>
      <section v-if="pending" class="aftersale-pending">
        <p role="status">本次{{ actionLabels[pending.action] }}结果尚未确认。重试会使用原目标、原内容及原提交编号。离开页面将清除本地重试内容。</p>
        <p v-if="pending.body.reason" class="aftersale-saved">本次拒绝原因：{{ pending.body.reason }}</p><p v-for="item in pending.body.items || []" :key="item.item_id">本次实际包裹商品 {{ item.item_id }} · 序号 {{ segmentText(item.segments) }}</p>
        <div v-if="pending.body.return_destination">
          <p class="aftersale-saved">回寄收件人：{{ pending.body.return_destination.recipient_name }} · 联系电话：{{ pending.body.return_destination.phone }}</p>
          <p class="aftersale-saved">完整回寄地址：{{ pending.body.return_destination.address }}</p>
        </div>
        <div class="aftersale-actions"><el-button :disabled="loading || sending || !can(pending.action)" @click="sendPending">重试同一提交</el-button></div>
      </section>
      <template v-else-if="fresh && !needsRefresh">
        <div class="aftersale-actions"><el-button v-if="allowed('approve')" :disabled="loading || sending" @click="choose('approve')">批准申请</el-button><el-button v-if="allowed('reject')" :disabled="loading || sending" @click="choose('reject')">拒绝申请</el-button><el-button v-if="allowed('accept_return')" :disabled="loading || sending" @click="choose('accept_return')">登记本次实际退件验收</el-button></div>
        <el-form v-if="choice && !preview" class="aftersale-command" label-position="top" @submit.native.prevent="prepare">
          <h2>{{ actionLabels[choice] }}</h2>
          <p v-if="choice === 'approve'">请核对本申请保存的商品、金额和理由，再确认本次批准。</p>
          <template v-if="choice === 'approve' && view.operation_kind === 'return_goods'">
            <p class="aftersale-note">填写本次收件信息。批准后顾客将按此地址回寄，保存后不能在本次售后中修改。</p>
            <el-form-item label="回寄收件人" for="aftersale-return-recipient" required><el-input id="aftersale-return-recipient" v-model="returnDestination.recipient_name" maxlength="64" :disabled="loading || sending" /></el-form-item>
            <el-form-item label="回寄联系电话" for="aftersale-return-phone" required><el-input id="aftersale-return-phone" v-model="returnDestination.phone" type="tel" maxlength="32" :disabled="loading || sending" /></el-form-item>
            <el-form-item label="完整回寄地址" for="aftersale-return-address" required><el-input id="aftersale-return-address" v-model="returnDestination.address" type="textarea" :rows="3" maxlength="500" :disabled="loading || sending" placeholder="省市区、街道及门牌等完整收件地址" /></el-form-item>
          </template>
          <el-form-item v-if="choice === 'reject'" label="拒绝原因" for="aftersale-reject-reason" required><el-input id="aftersale-reject-reason" v-model="rejectReason" type="textarea" :rows="4" :disabled="loading || sending" /></el-form-item>
          <template v-if="choice === 'accept_return' && view.return_selection">
            <p class="aftersale-note">逐项填写本次实际收到的序号，可只登记部分；格式如 1-2, 4。可用区间以刚读取的记录为准。</p>
            <el-form-item v-for="row in view.return_selection.items" :key="row.item_id" :label="'包裹商品 ' + row.item_id + ' · 可验收 ' + (segmentText(row.eligible_segments) || '无')" :for="'aftersale-ordinal-' + row.item_id"><el-input :id="'aftersale-ordinal-' + row.item_id" v-model="ordinalInputs[row.item_id]" :disabled="loading || sending || !row.eligible_quantity" placeholder="本次实际序号，未收到可留空" maxlength="16000" /></el-form-item>
          </template>
          <div class="aftersale-actions"><el-button native-type="submit" :disabled="loading || sending || !allowed(choice)">核对本次处理</el-button><el-button :disabled="sending" @click="choice = ''">取消</el-button></div>
        </el-form>
        <section v-if="preview" class="aftersale-command">
          <h2>确认{{ actionLabels[preview.action] }}</h2><p>售后单：{{ preview.refundNo }}</p><p v-if="preview.action === 'reject'" class="aftersale-saved">拒绝原因：{{ preview.body.reason }}</p>
          <div v-if="preview.body.return_destination">
            <p class="aftersale-saved">回寄收件人：{{ preview.body.return_destination.recipient_name }} · 联系电话：{{ preview.body.return_destination.phone }}</p>
            <p class="aftersale-saved">完整回寄地址：{{ preview.body.return_destination.address }}</p>
          </div>
          <p v-for="item in preview.body.items || []" :key="item.item_id">实际包裹商品 {{ item.item_id }} · 本次序号 {{ segmentText(item.segments) }}</p>
          <div class="aftersale-actions"><el-button type="primary" :disabled="loading || sending || !allowed(preview.action)" @click="commitPreview">确认提交</el-button><el-button :disabled="sending" @click="preview = null">返回修改</el-button></div>
        </section>
      </template>
      <p v-if="sending" role="status">正在提交原请求，请稍候…</p>
    </template>
  </section>
</template>
<script>
import { captureContext, currentContext, closeContext, can, page, detail, command, privateImage } from '../../api/aftersale';
import { commandIntent, routeId, errorText, timeText, segmentText, businessLabels, physicalLabels, financeLabels, kindLabels } from '../../domain/aftersale';
const tableScroll = { inserted: labelTable, componentUpdated: labelTable };
function labelTable(element, binding) { const wrappers = element.querySelectorAll('.el-table__body-wrapper'); if (wrappers.length === 1) { wrappers[0].setAttribute('tabindex', '0'); wrappers[0].setAttribute('role', 'region'); wrappers[0].setAttribute('aria-label', binding.value); } }
export default {
  name: 'merchantAftersales', directives: { 'table-scroll': tableScroll },
  data: () => ({ context: null, contextTarget: '', alive: true, generation: 0, readJob: 0, sendJob: 0, loading: false, sending: false, error: '', commandError: '', notice: '', rows: [], count: 0, page: 1, limit: 20, businessState: '', merchantId: '', view: null, fresh: false, choice: '', preview: null, pending: null, rejectReason: '', returnDestination: { recipient_name: '', phone: '', address: '' }, ordinalInputs: {}, needsRefresh: false, imageRows: [], unsubscribe: null, businessLabels, physicalLabels, financeLabels, kindLabels, actionLabels: { approve: '批准申请', reject: '拒绝申请', accept_return: '退件验收' } }),
  computed: { target() { return this.$route.params.id || ''; }, listPath() { return '/aftersales'; } },
  created() { this.unsubscribe = this.$store.subscribe(() => this.syncIdentity()); window.addEventListener('focus', this.syncIdentity); window.addEventListener('storage', this.syncIdentity); this.reset(); },
  watch: { '$route.fullPath'() { this.reset(); } },
  beforeDestroy() { this.alive = false; if (this.unsubscribe) this.unsubscribe(); window.removeEventListener('focus', this.syncIdentity); window.removeEventListener('storage', this.syncIdentity); this.clear(); },
  methods: {
    timeText, segmentText,
    can(action) { return can(this.$store, action); },
    allowed(action) { return this.fresh && !this.loading && !this.error && !!this.view && String(this.view.id) === String(this.target) && !!this.context && currentContext(this.context) && this.can(action) && this.view.allowed_actions[action] === true; },
    current(context, generation) { if (!this.alive || context !== this.context || generation !== this.generation || this.contextTarget !== String(this.target)) return false; if (!currentContext(context)) { this.syncIdentity(); return false; } return true; },
    revokeImages() { this.imageRows.forEach(image => { image.job += 1; if (image.url) window.URL.revokeObjectURL(image.url); image.url = ''; }); this.imageRows = []; },
    clear() { closeContext(this.context); this.context = null; this.contextTarget = ''; this.generation += 1; this.readJob += 1; this.sendJob += 1; this.revokeImages(); this.rows = []; this.count = 0; this.view = null; this.fresh = false; this.loading = false; this.sending = false; this.choice = ''; this.preview = null; this.pending = null; this.rejectReason = ''; this.returnDestination = { recipient_name: '', phone: '', address: '' }; this.ordinalInputs = {}; this.needsRefresh = false; this.notice = ''; this.error = ''; this.commandError = ''; },
    reset() { this.clear(); if (!this.alive) return; try { this.context = captureContext(this.$store); this.contextTarget = String(this.target); this.load(); } catch (error) { this.error = errorText(error); } },
    syncIdentity() { if (this.context && !currentContext(this.context)) { this.clear(); this.error = '身份已变化，请重新进入售后页面'; } else if (this.view && (!this.can('ticket') || !this.can('content'))) this.revokeImages(); },
    search() { if (!this.loading) { this.page = 1; this.load(); } },
    changePage(page) { if (!this.loading && Number.isSafeInteger(page) && page > 0) { this.page = page; this.load(); } },
    async load() {
      if (this.loading || !this.context) return false; const context = this.context; const generation = this.generation; const job = ++this.readJob;
      this.loading = true; this.fresh = false; this.error = ''; this.choice = ''; this.preview = null; this.returnDestination = { recipient_name: '', phone: '', address: '' };
      try {
        if (this.target) {
          const id = routeId(this.target); const data = await detail(context, id); if (!this.current(context, generation) || job !== this.readJob) return false;
          this.revokeImages(); this.view = data; this.ordinalInputs = {}; if (data.return_selection) data.return_selection.items.forEach(row => this.$set(this.ordinalInputs, row.item_id, ''));
          this.imageRows = data.images.map(id => ({ id, url: '', error: '', loading: false, loaded: false, job: 0 }));
          this.fresh = true; this.needsRefresh = false;
          if (this.can('ticket') && this.can('content')) this.imageRows.forEach(image => this.loadImage(image));
        } else {
          const query = { page: this.page, limit: this.limit }; if (this.businessState) query.business_state = this.businessState;
          
          const data = await page(context, query); if (!this.current(context, generation) || job !== this.readJob) return false;
          this.rows = data.list; this.count = data.count;
        }
        return true;
      } catch (error) { if (this.current(context, generation) && job === this.readJob) this.error = errorText(error); return false; }
      finally { if (this.current(context, generation) && job === this.readJob) this.loading = false; }
    },
    imageLoaded(image, event) { if (this.context && this.current(this.context, this.generation) && this.imageRows.includes(image) && event.target.getAttribute('src') === image.url) image.loaded = true; },
    imageFailed(image, event) { if (!this.context || !this.current(this.context, this.generation) || !this.imageRows.includes(image) || event.target.getAttribute('src') !== image.url) return; if (image.url) window.URL.revokeObjectURL(image.url); image.url = ''; image.loaded = false; image.error = '凭证未能显示，请重新读取'; },
    async loadImage(image) {
      if (!this.view || !this.context || image.loading || !this.imageRows.includes(image)) return;
      const context = this.context; const generation = this.generation; const view = this.view; const job = ++image.job;
      const locator = { target: String(this.target), readJob: this.readJob, rootId: view.root_order_id, refundId: view.id, refundNo: view.refund_no, fileId: image.id };
      if (image.url) window.URL.revokeObjectURL(image.url); image.url = ''; image.error = ''; image.loading = true; image.loaded = false;
      try {
        const url = await privateImage(context, view, image.id);
        if (!this.current(context, generation) || this.view !== view || job !== image.job || !this.imageRows.includes(image) || this.readJob !== locator.readJob || String(this.target) !== locator.target || view.root_order_id !== locator.rootId || view.id !== locator.refundId || view.refund_no !== locator.refundNo || image.id !== locator.fileId || !view.images.includes(locator.fileId)) { window.URL.revokeObjectURL(url); return; }
        image.url = url;
      } catch (error) { if (this.current(context, generation) && job === image.job && this.imageRows.includes(image)) image.error = errorText(error); }
      finally { if (this.current(context, generation) && job === image.job && this.imageRows.includes(image)) image.loading = false; }
    },
    choose(action) { if (!this.pending && this.allowed(action)) { this.choice = action; this.preview = null; this.commandError = ''; } },
    prepare() { if (this.pending || !this.allowed(this.choice)) return; this.commandError = ''; try { if (this.choice === 'approve' && this.view.operation_kind === 'return_goods' && Object.values(this.returnDestination).some(value => !value.trim())) throw new Error('请完整填写回寄收件人、联系电话和地址'); this.preview = commandIntent(this.view, this.choice, { reason: this.rejectReason, segments: this.ordinalInputs, return_destination: this.returnDestination }); } catch (error) { this.commandError = errorText(error); } },
    commitPreview() { if (!this.preview || this.pending || !this.allowed(this.preview.action)) return; this.pending = this.preview; this.preview = null; this.choice = ''; this.sendPending(); },
    async sendPending() {
      if (!this.pending || this.loading || this.sending || !this.context || !this.can(this.pending.action)) return;
      const context = this.context; const generation = this.generation; const intent = this.pending; const job = ++this.sendJob;
      if (String(this.target) !== String(intent.refundId) || !this.current(context, generation)) return;
      this.sending = true; this.error = ''; this.commandError = ''; this.notice = '';
      try {
        await command(context, intent); if (!this.current(context, generation) || job !== this.sendJob || this.pending !== intent) return;
        this.pending = null; this.needsRefresh = true; this.fresh = false; this.notice = '本次' + this.actionLabels[intent.action] + '结果已保存';
        const refreshed = await this.load(); if (this.current(context, generation) && job === this.sendJob && !refreshed) this.notice += '；最新资料尚未读回，请重新读取';
      } catch (error) { if (this.current(context, generation) && job === this.sendJob && this.pending === intent) this.error = '本次结果尚未确认：' + errorText(error); }
      finally { if (this.current(context, generation) && job === this.sendJob) this.sending = false; }
    }
  }
};
</script>
<style scoped>
.aftersale-console { min-width: 0; }
.aftersale-console * { box-sizing: border-box; }
.aftersale-heading { margin: 0 0 12px; font-size: 21px; }
.aftersale-console h2 { margin: 26px 0 14px; font-size: 17px; }
.aftersale-note { color: #606266; line-height: 1.7; margin: 8px 0; }
.aftersale-feedback, .aftersale-notice { margin: 16px 0; }
.aftersale-notice { padding: 12px 16px; border-left: 4px solid #2d8cf0; background: #f5f7fa; line-height: 1.7; }
.aftersale-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0; }
.aftersale-console .el-button { display: inline-flex; align-items: center; justify-content: center; text-align: center; max-width: 100%; white-space: normal; }
.aftersale-actions .el-button + .el-button { margin-left: 0; }
.aftersale-filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-top: 20px; }
.aftersale-filter .el-form-item { width: 220px; max-width: 100%; margin-bottom: 0; }
.aftersale-filter .el-select { width: 100%; }
.aftersale-filter > .el-button { margin-bottom: 0; }
.aftersale-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 24px; }
.aftersale-facts dt { color: #606266; margin-bottom: 6px; }
.aftersale-facts dd { margin: 0; line-height: 1.7; overflow-wrap: anywhere; }
.aftersale-saved { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; }
.aftersale-product { display: flex; align-items: center; gap: 12px; overflow-wrap: anywhere; }
.aftersale-product > div { min-width: 0; flex: 1; }
.aftersale-product img { width: 52px; height: 52px; object-fit: contain; flex: 0 0 52px; }
.aftersale-images { display: flex; flex-wrap: wrap; gap: 16px; }
.aftersale-images figure { width: 190px; max-width: 100%; margin: 0; padding: 12px; border: 1px solid #ebeef5; }
.aftersale-images img { display: block; width: 100%; height: 150px; object-fit: contain; }
.aftersale-images figcaption { margin: 10px 0; line-height: 1.6; }
.aftersale-image-error { color: #c45656; line-height: 1.6; overflow-wrap: anywhere; }
.aftersale-command, .aftersale-pending { padding: 16px; border: 1px solid #dcdfe6; margin: 20px 0; line-height: 1.7; }
.aftersale-command h2 { margin-top: 0; }
.aftersale-pagination { display: flex; justify-content: flex-end; margin-top: 20px; max-width: 100%; }
@media (max-width: 760px) { .aftersale-facts { grid-template-columns: minmax(0, 1fr); } .aftersale-filter { display: block; } .aftersale-filter .el-form-item { width: 100%; margin-bottom: 16px; } .aftersale-pagination { justify-content: flex-start; } }
</style>
