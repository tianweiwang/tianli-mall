<template>
  <section class="marketplace-console finance-settings">
    <el-card shadow="never">
      <h1 class="marketplace-heading">资金设置</h1>
      <p class="finance-note">先保存经营方案草稿，未确定的项目可以保留未配置。草稿版本仅用于记录修改。</p>
      <el-alert title="真实资金未启用" description="保存草稿不会启用真实收款、退款、自动结算或提现，也不会改变已有订单与测试支付方式。" type="info" :closable="false" show-icon />
      <el-alert v-if="error" class="finance-feedback" :title="error" type="error" :closable="false" show-icon />
      <p v-if="notice" class="finance-notice" role="status">{{ notice }}</p>
      <div class="marketplace-actions finance-actions">
        <el-button :disabled="loading || sending || !can('read')" @click="readCurrent">{{ pending ? '重新读取，核对保存结果' : '重新读取已保存草稿' }}</el-button>
        <span v-if="loading" class="finance-note" role="status">正在读取资金设置…</span>
      </div>
      <template v-if="view">
        <dl class="finance-summary">
          <div><dt>当前草稿版本</dt><dd>{{ view.revision === 0 ? '初始草稿 · 尚未保存' : '第 ' + view.revision + ' 版' }}</dd></div>
          <div><dt>最近修改</dt><dd>{{ view.updated_at === null ? '尚无修改记录' : timeText(view.updated_at) + ' · 操作者 ' + view.updated_by }}</dd></div>
        </dl>
        <section class="finance-completeness" aria-labelledby="finance-missing-title">
          <h2 id="finance-missing-title">已保存草稿的完整性</h2>
          <p v-if="!view.missing_fields.length" class="finance-note">规划字段已填写完整。正式启用仍需完成以下条件核实。</p>
          <p v-else class="finance-note">还有 {{ view.missing_fields.length }} 项未配置：{{ view.missing_fields.map(item => item.label).join('、') }}。可以继续保存未完成的草稿。</p>
          <ul class="finance-blockers"><li v-for="(blocker, index) in view.activation_blockers" :key="index">{{ blocker }}</li></ul>
        </section>
        <p v-if="!can('write')" class="finance-note" role="status">当前账号可查看草稿与历史，没有修改权限。</p>
        <p v-if="pending" class="finance-notice" role="status">本次提交内容保留在表单中，保存结果尚未确认。请重新读取当前草稿核对；系统不会自动再次提交。</p>
        <el-form label-position="top" class="finance-form" @submit.native.prevent="saveDraft">
          <section v-for="section in sections" :key="section.key" class="finance-section" :aria-labelledby="'finance-section-' + section.key">
            <h2 :id="'finance-section-' + section.key">{{ section.title }}</h2><p class="finance-note">{{ section.note }}</p>
            <div class="finance-grid">
              <el-form-item v-for="field in fieldsFor(section.key)" :key="field.key" :label="field.label" :for="'finance-' + field.key">
                <el-select v-if="field.options" :id="'finance-' + field.key" v-model="draft[field.key]" :disabled="!canEdit" placeholder="未配置">
                  <el-option :value="null" label="未配置" />
                  <el-option v-for="option in field.options" :key="String(option.value)" :value="option.value" :label="option.label" />
                </el-select>
                <el-input v-else :id="'finance-' + field.key" :value="draft[field.key] === null ? '' : draft[field.key]" :disabled="!canEdit" :maxlength="field.type === 'text' ? 240 : field.type === 'days' ? 3 : 6" :inputmode="field.type === 'days' ? 'numeric' : field.type === 'rate' ? 'decimal' : 'text'" :placeholder="field.type === 'rate' ? '未配置；0.00至100.00' : field.type === 'days' ? '未配置；0至365天' : '未配置；最多120字'" @input="setField(field.key, $event)" />
              </el-form-item>
            </div>
          </section>
          <el-alert v-if="validationError" :title="validationError" type="error" :closable="false" show-icon />
          <div class="marketplace-actions finance-actions">
            <el-button v-if="can('write')" type="primary" native-type="submit" :disabled="!canEdit" :loading="sending">保存草稿</el-button>
            <span class="finance-note" role="status">{{ sending ? '正在保存，请稍候…' : changed ? '当前表单有未保存内容；保存后更新完整性提示。' : '当前表单与已保存草稿一致。' }}</span>
          </div>
        </el-form>
        <section class="finance-section finance-estimate" aria-labelledby="finance-estimate-title">
          <h2 id="finance-estimate-title">平台费草稿测算</h2>
          <p class="finance-note">使用当前表单中的平台费规则，仅预估平台费，不是真实入账；不估算通道手续费或实际到账金额。</p>
          <el-form label-position="top" class="finance-grid" @submit.native.prevent>
            <el-form-item label="商品实付（元，不含运费）" for="finance-estimate-goods"><el-input id="finance-estimate-goods" v-model="goodsAmount" inputmode="decimal" maxlength="11" placeholder="例如 100.00" :disabled="loading || sending || !fresh" /></el-form-item>
            <el-form-item label="运费（元）" for="finance-estimate-shipping"><el-input id="finance-estimate-shipping" v-model="shippingAmount" inputmode="decimal" maxlength="11" placeholder="例如 10.00；无运费填0" :disabled="loading || sending || !fresh" /></el-form-item>
          </el-form>
          <div class="finance-estimate-result" role="status">
            <template v-if="estimate.ready"><p>预估平台费：<strong>{{ yuan(estimate.fee_cents) }} 元</strong></p><p v-if="estimate.basis_cents !== null">计费基数：{{ yuan(estimate.basis_cents) }} 元 · 拟承担方：{{ fieldText('platform_fee_payer', draft.platform_fee_payer) }}</p></template>
            <p>{{ estimate.message }}</p>
          </div>
        </section>
        <section class="finance-section" aria-labelledby="finance-history-title">
          <h2 id="finance-history-title">修改历史</h2>
          <p class="finance-note">共 {{ view.history_total }} 次有效修改，显示最近20版。历史值仅供查看，不能在此编辑或删除。</p>
          <p v-if="!view.history.length" class="finance-note">尚无修改记录。</p>
          <template v-else>
            <el-form label-position="top" @submit.native.prevent><el-form-item label="查看历史版本" for="finance-history-version"><el-select id="finance-history-version" v-model="selectedRevision" class="finance-history-select" placeholder="选择要查看的版本" :disabled="loading || sending"><el-option v-for="row in view.history" :key="row.revision" :value="row.revision" :label="'第 ' + row.revision + ' 版 · ' + timeText(row.created_at)" /></el-select></el-form-item></el-form>
            <template v-if="selectedHistory">
              <p class="finance-note">第 {{ selectedHistory.revision }} 版 · 操作者 {{ selectedHistory.actor_id }} · {{ timeText(selectedHistory.created_at) }}</p>
              <p class="finance-note">变更字段：{{ selectedHistory.changed_fields.map(fieldLabel).join('、') }}</p>
              <dl class="finance-history-values"><div v-for="field in fields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ fieldText(field.key, selectedHistory.payload[field.key]) }}</dd></div></dl>
            </template>
          </template>
        </section>
      </template>
    </el-card>
  </section>
</template>
<script>
import { captureContext, currentContext, closeContext, can, refreshPermissions, read, save } from '@/api/marketplace-finance-settings';
import { financeFields, emptyDraft, normalizeDraft, sameDraft, saveIntent, fieldLabel, fieldText, estimateFee, yuan } from '@/libs/marketplace-finance-settings';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import '../style.css';
export default {
  name: 'marketplaceFinanceSettings',
  data: () => ({
    fields: financeFields,
    sections: [
      { key: 'collection', title: '收款方案', note: '记录拟选主体、服务商和产品，不采集账户号或密钥。拟选方案不表示当前已接入该渠道。' },
      { key: 'fee', title: '平台费用', note: '费率按百分数填写。通道费的实际标准由服务商合同决定；这里仅记录拟承担方。' },
      { key: 'settlement', title: '结算与退款', note: '这些是待确认的经营规则，保存后不会执行真实结算或退款。' },
    ],
    context: null, contextRoute: '', alive: true, generation: 0, requestJob: 0, loading: false, sending: false,
    view: null, draft: emptyDraft(), fresh: false, pending: null, error: '', validationError: '', notice: '',
    goodsAmount: '', shippingAmount: '', selectedRevision: null, unsubscribe: null, unsubscribeEpoch: null,
  }),
  computed: {
    canEdit() { return this.fresh && !!this.view && !!this.context && currentContext(this.context) && this.can('read') && this.can('write') && !this.loading && !this.sending && !this.pending; },
    changed() { if (!this.view) return false; try { return !sameDraft(normalizeDraft(this.draft), this.view.draft); } catch (error) { return true; } },
    estimate() { try { return estimateFee(this.draft, this.goodsAmount, this.shippingAmount); } catch (error) { return { ready: false, message: error.message }; } },
    selectedHistory() { return this.view ? this.view.history.find(row => row.revision === this.selectedRevision) || null : null; },
  },
  created() { this.unsubscribeEpoch = subscribePlatformAuthEpoch(() => this.syncIdentity()); this.unsubscribe = this.$store.subscribe(() => this.syncIdentity()); window.addEventListener('focus', this.syncIdentity); window.addEventListener('storage', this.syncIdentity); this.reset(); },
  watch: { '$route.fullPath'() { this.reset(); } },
  activated() { if (this.alive && this.activeRoute() && !this.context) this.reset(); },
  deactivated() { this.clear(); },
  beforeRouteLeave(to, from, next) { this.clear(); next(); },
  beforeDestroy() { this.alive = false; if (this.unsubscribe) this.unsubscribe(); if (this.unsubscribeEpoch) this.unsubscribeEpoch(); window.removeEventListener('focus', this.syncIdentity); window.removeEventListener('storage', this.syncIdentity); this.clear(); },
  methods: {
    fieldLabel, fieldText, yuan,
    timeText(value) { return new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }); },
    fieldsFor(section) { return this.fields.filter(field => field.section === section); },
    setField(key, value) { if (this.canEdit) this.$set(this.draft, key, value === '' ? null : value); },
    activeRoute() { return this.$route.name === 'marketplace_finance_settings'; },
    can(action) { return can(this.$store, action); },
    current(context, generation, job) { return this.alive && this.activeRoute() && context === this.context && generation === this.generation && job === this.requestJob && this.contextRoute === this.$route.fullPath && currentContext(context); },
    clear() { closeContext(this.context); this.context = null; this.contextRoute = ''; this.generation += 1; this.requestJob += 1; this.loading = false; this.sending = false; this.view = null; this.draft = emptyDraft(); this.fresh = false; this.pending = null; this.error = ''; this.validationError = ''; this.notice = ''; this.goodsAmount = ''; this.shippingAmount = ''; this.selectedRevision = null; },
    reset() { this.clear(); if (!this.alive || !this.activeRoute()) return; try { this.context = captureContext(this.$store); this.contextRoute = this.$route.fullPath; this.load(); } catch (error) { this.error = '当前登录身份未确认，请重新进入资金设置'; } },
    syncIdentity() {
      if (this.context && !currentContext(this.context)) { this.clear(); this.error = '身份已变化，请重新进入资金设置'; return; }
      if (this.context && !this.can('read')) { this.clear(); this.error = '当前资金设置查看权限已变化'; }
    },
    readCurrent() { if (this.loading || this.sending) return; if (!this.context) this.reset(); else this.load(); },
    applyView(value) { this.view = value; this.draft = { ...value.draft }; this.fresh = true; if (!value.history.some(row => row.revision === this.selectedRevision)) this.selectedRevision = null; },
    async load() {
      if (this.loading || this.sending || !this.context) return;
      const context = this.context; const generation = this.generation; const job = ++this.requestJob; const pending = this.pending;
      this.loading = true; this.fresh = false; this.error = ''; this.validationError = ''; this.notice = '';
      try {
        await refreshPermissions(context, () => this.current(context, generation, job) && !this.$router.history.pending);
        if (!this.current(context, generation, job)) return; const value = await read(context);
        if (!this.current(context, generation, job)) return; this.applyView(value); this.pending = null;
        this.notice = pending ? sameDraft(value.draft, pending.draft) ? '当前保存草稿与原提交内容一致，请以当前版本记录为准。' : '已重新读取当前草稿。请按当前版本核对后编辑；原提交没有自动重发。' : '已读取当前保存版本。';
      } catch (error) { if (this.current(context, generation, job)) this.error = error.message || '资金设置读取失败'; }
      finally { if (this.current(context, generation, job)) this.loading = false; }
    },
    async saveDraft() {
      if (!this.canEdit) return; this.validationError = ''; let intent;
      try { intent = saveIntent(this.view.revision, this.draft); } catch (error) { this.validationError = error.message; return; }
      const context = this.context; const generation = this.generation; const job = ++this.requestJob;
      this.sending = true; this.fresh = false; this.error = ''; this.notice = ''; this.pending = intent;
      try {
        await refreshPermissions(context, () => this.current(context, generation, job) && !this.$router.history.pending);
        if (!this.current(context, generation, job)) return; const value = await save(context, intent);
        if (!this.current(context, generation, job)) return; this.applyView(value); this.pending = null;
        this.notice = value.revision === intent.expected_revision ? '草稿内容未变化，保留当前版本。真实资金仍未启用。' : '草稿已保存为第 ' + value.revision + ' 版。真实资金仍未启用。';
      } catch (error) { if (this.current(context, generation, job)) this.error = error.message || '保存结果尚未确认，请重新读取当前草稿'; }
      finally { if (this.current(context, generation, job)) this.sending = false; }
    },
  },
};
</script>
<style scoped>
.finance-settings { min-width: 0; color: #303133; }
.finance-settings >>> .el-alert--info .el-alert__title,
.finance-settings >>> .el-alert--info .el-alert__icon { color: #606266; }
.finance-settings >>> .el-alert--info .el-alert__description { color: #606266 !important; }
.finance-settings h2 { margin: 0 0 12px; font-size: 17px; }
.finance-note { color: #606266; margin: 8px 0 14px; line-height: 1.7; overflow-wrap: anywhere; }
.finance-feedback, .finance-notice { margin: 16px 0; }
.finance-notice { padding: 12px 16px; border-left: 4px solid #2d8cf0; background: #f5f7fa; line-height: 1.7; overflow-wrap: anywhere; }
.finance-actions { margin: 18px 0; align-items: center; }
.finance-actions .finance-note { flex: 1 1 240px; margin: 0; }
.finance-summary, .finance-history-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 24px; margin: 20px 0; }
.finance-summary dt, .finance-history-values dt { color: #606266; margin-bottom: 6px; }
.finance-summary dd, .finance-history-values dd { margin: 0; line-height: 1.7; overflow-wrap: anywhere; white-space: pre-wrap; }
.finance-completeness { background: #f5f7fa; padding: 18px; margin: 20px 0; }
.finance-blockers { padding-left: 20px; margin: 8px 0 0; line-height: 1.8; overflow-wrap: anywhere; }
.finance-section { margin-top: 28px; padding-top: 24px; border-top: 1px solid #ebeef5; }
.finance-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; }
.finance-grid .el-form-item, .finance-grid .el-select, .finance-grid .el-input { width: 100%; min-width: 0; max-width: 100%; }
.finance-settings >>> .el-form-item__label { width: 100%; line-height: 1.6; padding-bottom: 8px; overflow-wrap: anywhere; }
.finance-estimate-result { padding: 16px 18px; border-left: 3px solid #dcdfe6; background: #f5f7fa; line-height: 1.7; overflow-wrap: anywhere; }
.finance-estimate-result p { margin: 4px 0; }
.finance-estimate-result strong { font-size: 20px; }
.finance-history-select { width: 420px; max-width: 100%; }
.finance-history-values { padding: 18px 0; }
@media (max-width: 760px) {
  .finance-grid, .finance-summary, .finance-history-values { grid-template-columns: minmax(0, 1fr); }
  .finance-settings >>> .el-card__body { padding: 16px; }
  .finance-completeness, .finance-estimate-result { padding: 14px; }
  .finance-actions .el-button { width: 100%; }
  .finance-actions .finance-note { flex-basis: 100%; }
}
</style>
