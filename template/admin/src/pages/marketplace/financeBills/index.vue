<template>
  <section class="marketplace-console finance-bills">
    <el-card shadow="never">
      <h1 class="marketplace-heading">测试账单对账</h1>
      <el-alert type="info" :closable="false" show-icon title="本机测试资金记录"
        description="导入标准账单，与已保存的测试收款、退款逐笔核对。当前尚未接入服务商正式账单，也不会执行收退款或结算打款。" />
      <el-alert v-if="error" class="feedback" type="error" :closable="false" show-icon :title="error" />
      <p v-if="notice" class="notice" role="status">{{ notice }}</p>

      <section class="bill-section" aria-labelledby="bill-import-heading">
        <h2 id="bill-import-heading">导入账单</h2>
        <el-form label-position="top" @submit.native.prevent="submit">
          <div class="bill-grid">
            <el-form-item label="商家编号" for="finance-bill-merchant" :error="merchant && !merchantId ? '请输入有效的商家编号' : ''">
              <el-input id="finance-bill-merchant" v-model.trim="merchant" inputmode="numeric" maxlength="10"
                :disabled="busy" @input="merchantChanged" />
            </el-form-item>
            <el-form-item label="原收款账户">
              <el-select v-model="anchor" placeholder="先填写商家编号并读取账户" :disabled="busy || !accountRows.length" @change="sourceChanged">
                <el-option v-for="item in accountRows" :key="item.anchor_attempt_id" :value="item.anchor_attempt_id"
                  :label="item.account_ref + ' · 第' + item.account_version + '版'" />
              </el-select>
            </el-form-item>
            <el-form-item label="起始日期（上海时区）">
              <el-date-picker v-model="dateFrom" type="date" value-format="yyyy-MM-dd" :disabled="busy" placeholder="请选择" @change="sourceChanged" />
            </el-form-item>
            <el-form-item label="结束日期（包含当日）">
              <el-date-picker v-model="dateTo" type="date" value-format="yyyy-MM-dd" :disabled="busy" placeholder="请选择" @change="sourceChanged" />
            </el-form-item>
          </div>
          <div class="marketplace-actions">
            <el-button :disabled="busy || !merchantId || !can('read')" @click="loadAccounts">读取原付款账户</el-button>
            <el-button :disabled="busy || !scopeReady || !can('read')" @click="loadMovements">查看期间本地流水</el-button>
            <el-button :disabled="busy" @click="downloadTemplate">下载标准 CSV 模板</el-button>
          </div>
          <p class="note">最长31天，最多1000条。模板中的流水引用需与本地记录对应；渠道扣费填正数、退费填负数，未知费用保留空白。</p>
          <div v-if="can('import')" class="file-field">
            <label for="finance-bill-file">选择 UTF-8 CSV 文件（最多 1 MiB）</label>
            <input id="finance-bill-file" ref="file" type="file" accept=".csv,text/csv" :disabled="busy || !scopeReady" @change="chooseFile" />
            <p v-if="filename" class="note">待导入：{{ filename }}</p>
          </div>
          <div class="marketplace-actions">
            <el-button v-if="can('import')" type="primary" native-type="submit" :disabled="busy || !scopeReady || !filename" :loading="sending === 'import'">导入并对账</el-button>
            <span class="note" role="status">{{ busy ? '正在处理，请稍候…' : '重复提交同一账户、期间和原文件会返回已有批次。' }}</span>
          </div>
        </el-form>
        <div v-if="localRows !== null" class="local-facts">
          <h3>期间本地已保存流水（{{ localRows.length }} 条）</h3>
          <p class="note">以下是本地参照记录，不是渠道出具的账单。</p>
          <el-table :data="localRows" border empty-text="期间没有已保存的资金流水">
            <el-table-column prop="local_ref" label="本地流水引用" min-width="180" />
            <el-table-column label="类型" width="85"><template slot-scope="s">{{ kindText(s.row.kind) }}</template></el-table-column>
            <el-table-column prop="amount" label="金额（元）" min-width="100" />
            <el-table-column label="渠道流水号" min-width="210"><template slot-scope="s">{{ s.row.provider_no || '待确认' }}</template></el-table-column>
            <el-table-column label="发生时间" min-width="170"><template slot-scope="s">{{ timeText(s.row.occurred_at) }}</template></el-table-column>
          </el-table>
        </div>
      </section>

      <section class="bill-section" aria-labelledby="bill-list-heading">
        <h2 id="bill-list-heading">已保存账单</h2>
        <div class="marketplace-actions">
          <el-button :disabled="busy || !can('read')" @click="loadList()">刷新第一页</el-button>
          <el-button :disabled="busy || !nextBefore || !can('read')" @click="loadList(nextBefore)">下一页</el-button>
        </div>
        <el-table :data="bills" border empty-text="暂无已导入账单">
          <el-table-column prop="filename" label="原文件" min-width="180" />
          <el-table-column prop="mer_id" label="商家" width="75" />
          <el-table-column label="期间" min-width="200"><template slot-scope="s">{{ s.row.date_from }} 至 {{ s.row.date_to }}</template></el-table-column>
          <el-table-column label="结果" min-width="150"><template slot-scope="s">{{ statusText(s.row.status) }}<div class="note">相符 {{ s.row.matched_count }} · 差异 {{ s.row.difference_count }}</div></template></el-table-column>
          <el-table-column label="操作" width="100"><template slot-scope="s"><el-button size="small" :disabled="busy" @click="loadDetail(s.row.id)">查看</el-button></template></el-table-column>
        </el-table>
      </section>

      <section v-if="record" class="bill-section" aria-labelledby="bill-result-heading">
        <h2 id="bill-result-heading">账单 {{ record.id }} · 对账结果</h2>
        <dl class="bill-summary">
          <div><dt>账户</dt><dd>商家 {{ record.mer_id }} · {{ record.account.account_ref }} · 第{{ record.account.version }}版</dd></div>
          <div><dt>期间</dt><dd>{{ record.date_from }} 至 {{ record.date_to }}</dd></div>
          <div><dt>核对结果</dt><dd>{{ record.comparison.rows.length ? statusText(record.status) : '期间暂无记录' }} · 相符 {{ record.comparison.counts.matched }} · 差异 {{ record.comparison.counts.differences }}</dd></div>
          <div><dt>读取开始时间</dt><dd>{{ timeText(record.snapshot_at) }}</dd></div>
        </dl>
        <p v-if="record.period_open" class="notice" role="status">该期间尚未结束，后续仍可能新增流水；当前结果只是本次读取快照。</p>
        <ul v-if="record.source_issues.length" class="source-issues"><li v-for="(issue, index) in record.source_issues" :key="index">根单 {{ issue.root_order_id }}：{{ issue.reason === 'PAYMENT_ON_HOLD' ? '付款存在暂缓事项' : '原退款渠道流水号待确认' }} {{ issue.local_ref || '' }}</li></ul>
        <div class="marketplace-actions result-actions">
          <el-select v-model="resultChoice" aria-label="查看保存的对账记录" :disabled="busy" @change="loadResult">
            <el-option v-for="item in record.results" :key="item.id" :value="item.id" :label="'记录 ' + item.id + ' · ' + timeText(item.snapshot_at)" />
            <el-option v-if="!record.results.some(item => item.id === record.result_id)" :value="record.result_id" :label="'历史记录 ' + record.result_id" />
          </el-select>
          <el-button v-if="can('reconcile')" :disabled="busy" :loading="sending === 'reconcile'" @click="reconcileCurrent">重新读取并对账</el-button>
        </div>
        <p class="note">每次明确重新对账会保留新记录；原账单和历史差异不会被覆盖。相符结果不代表真实资金到账或已完成商家结算。</p>
        <el-table :data="record.comparison.rows" border empty-text="没有可比较的流水">
          <el-table-column type="expand" width="48"><template slot-scope="s">
            <dl class="comparison-values"><div v-for="(label, field) in fieldLabels" :key="field"><dt>{{ label }}</dt><dd>账单：{{ fieldValue(s.row.bill, field) }}<br />本地：{{ fieldValue(s.row.local, field) }}</dd></div></dl>
          </template></el-table-column>
          <el-table-column prop="local_ref" label="本地流水引用" min-width="180" />
          <el-table-column label="类型" width="85"><template slot-scope="s">{{ kindText(s.row.kind) }}</template></el-table-column>
          <el-table-column label="结果" min-width="200"><template slot-scope="s">{{ statusText(s.row.status) }}<div class="note">{{ s.row.differences.map(field => fieldLabels[field] || field).join('、') }}</div></template></el-table-column>
          <el-table-column label="账单金额（元）" min-width="130"><template slot-scope="s">{{ s.row.bill ? s.row.bill.amount : '—' }}</template></el-table-column>
          <el-table-column label="本地金额（元）" min-width="130"><template slot-scope="s">{{ s.row.local ? s.row.local.amount : '—' }}</template></el-table-column>
          <el-table-column label="账单通道费（元）" min-width="150"><template slot-scope="s">{{ s.row.bill && s.row.bill.channel_fee !== null ? s.row.bill.channel_fee : '未知' }}</template></el-table-column>
        </el-table>
        <section v-if="canStatement('read')" class="bill-section" aria-labelledby="bill-statement-heading">
          <h3 id="bill-statement-heading">测试结算试算单</h3>
          <p class="note">按已保存的对账记录和指定费用规则生成试算。试算单只供核对，实际结算金额尚未产生。</p>
          <el-alert v-if="statement.error" class="feedback" type="error" :closable="false" show-icon :title="statement.error" />
          <p v-if="statement.notice" class="notice" role="status">{{ statement.notice }}</p>
          <el-form v-if="canStatement('create')" class="statement-form" label-position="top" @submit.native.prevent="generateStatement">
            <el-form-item label="费用规则版本" for="bill-statement-rule">
              <el-select id="bill-statement-rule" v-model="statement.revision" :disabled="busy || !statement.versions.length"
                placeholder="请选择费用规则版本">
                <el-option v-for="version in statement.versions" :key="version.revision"
                  :value="version.revision" :label="ruleOption(version)" />
              </el-select>
            </el-form-item>
            <el-button type="primary" native-type="submit" :disabled="busy || statement.revision === null"
              :loading="sending === 'statement'">生成试算单</el-button>
          </el-form>
          <p v-if="canStatement('create')" class="note">本次试算使用当前显示的对账记录 {{ record.result_id }}。相同账单、对账记录和规则版本会返回已有试算单。</p>
          <div class="marketplace-actions">
            <el-button :disabled="busy" @click="loadStatements()">刷新已保存试算单</el-button>
            <el-button :disabled="busy || !statement.next" @click="loadStatements(statement.next)">下一页</el-button>
          </div>
          <el-table :data="statement.rows" border empty-text="暂无已保存试算单">
            <el-table-column prop="id" label="试算单" min-width="95" />
            <el-table-column prop="result_id" label="对账记录" min-width="100" />
            <el-table-column label="费用规则" min-width="120"><template slot-scope="s">{{ ruleText(s.row.rule_revision) }}</template></el-table-column>
            <el-table-column label="状态" min-width="100"><template slot-scope="s">{{ statementStatus(s.row.status) }}</template></el-table-column>
            <el-table-column label="预计商家应收（元）" min-width="150"><template slot-scope="s">{{ moneyText(s.row.totals.merchant_receivable) }}</template></el-table-column>
            <el-table-column label="生成时间" min-width="170"><template slot-scope="s">{{ timeText(s.row.created_at) }}</template></el-table-column>
            <el-table-column label="操作" width="100"><template slot-scope="s"><el-button size="small" :disabled="busy"
              :aria-label="'查看试算单 ' + s.row.id" @click="loadStatement(s.row.id)">查看</el-button></template></el-table-column>
          </el-table>
          <div v-if="statement.detail" class="bill-section" aria-labelledby="bill-statement-detail-heading">
            <h3 id="bill-statement-detail-heading">试算单 {{ statement.detail.id }} · {{ statementStatus(statement.detail.status) }}</h3>
            <p class="note">对账记录 {{ statement.detail.result_id }} · {{ ruleText(statement.detail.rule_revision) }} · {{ statement.detail.date_from }} 至 {{ statement.detail.date_to }}</p>
            <ul v-if="statement.detail.reasons.length" class="source-issues">
              <li v-for="reason in statement.detail.reasons" :key="reason">{{ reasonText(reason) }}</li>
            </ul>
            <dl class="bill-summary">
              <div v-for="(label, key) in statementAmounts" :key="key">
                <dt>{{ label }}（元）</dt><dd>{{ moneyText(statement.detail.totals[key]) }}</dd>
              </div>
            </dl>
            <h4>试算采用的费用规则</h4>
            <dl class="bill-summary">
              <div v-for="item in feeRuleItems(statement.detail.fee_rule)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></div>
            </dl>
            <h4>逐单试算（元）</h4>
            <el-table :data="statement.detail.orders" border empty-text="没有可列出的订单">
              <el-table-column prop="root_order_id" label="根订单" min-width="95" />
              <el-table-column label="销售归属" min-width="100"><template slot-scope="s">{{ s.row.sale_in_period ? '本账期' : '往期' }}</template></el-table-column>
              <el-table-column v-for="(label, key) in statementAmounts" :key="key" :label="label" min-width="120">
                <template slot-scope="s">{{ moneyText(s.row.amounts[key]) }}</template>
              </el-table-column>
              <el-table-column label="待核事项" min-width="200"><template slot-scope="s">{{ s.row.reasons.map(reasonText).join('、') || '—' }}</template></el-table-column>
            </el-table>
          </div>
        </section>
      </section>
    </el-card>
  </section>
</template>

<script>
import * as api from '@/api/marketplace-finance-bills';
import * as statements from '@/api/marketplace-finance-statements';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import '../style.css';
function today() {
  const parts = new Intl.DateTimeFormat('en', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const value = name => parts.find(part => part.type === name).value; return value('year') + '-' + value('month') + '-' + value('day');
}
function emptyStatement() {
  return { rows: [], next: null, versions: [], revision: null, detail: null, error: '', notice: '' };
}
export default {
  name: 'MarketplaceFinanceBills',
  data: () => ({ alive: true, context: null, contextRoute: '', busy: false, sending: '', error: '', notice: '', merchant: '',
    anchor: '', accountRows: [], dateFrom: today(), dateTo: today(), csv: '', filename: '', fileJob: 0, localRows: null,
    bills: [], nextBefore: null, record: null, resultChoice: '', fieldLabels: api.fieldLabels,
    statement: emptyStatement(), statementAmounts: statements.amountLabels }),
  computed: {
    merchantId() {
      if (!/^[1-9]\d{0,9}$/.test(this.merchant)) return null;
      const id = Number(this.merchant);
      return id <= 4294967295 ? id : null;
    },
    scopeReady() { return !!this.anchor && !!this.dateFrom && !!this.dateTo; },
  },
  created() {
    this.unsubscribe = this.$store.subscribe(() => this.syncIdentity()); this.unsubscribeEpoch = subscribePlatformAuthEpoch(() => this.syncIdentity());
    window.addEventListener('focus', this.syncIdentity); window.addEventListener('storage', this.syncIdentity); this.reset();
  },
  watch: { '$route.fullPath'() { this.reset(); } },
  activated() { if (this.alive && this.activeRoute() && !this.context) this.reset(); },
  deactivated() { this.clear(); },
  beforeRouteLeave(to, from, next) { this.clear(); next(); },
  beforeDestroy() {
    this.alive = false; if (this.unsubscribe) this.unsubscribe(); if (this.unsubscribeEpoch) this.unsubscribeEpoch();
    window.removeEventListener('focus', this.syncIdentity); window.removeEventListener('storage', this.syncIdentity); this.clear();
  },
  methods: {
    can(action) { return api.can(this.$store, action); },
    canStatement(action) { return statements.can(this.$store, action); },
    activeRoute() { return this.$route.name === 'marketplace_finance_bills'; },
    current(context) { return this.alive && this.activeRoute() && context === this.context && this.contextRoute === this.$route.fullPath && api.currentContext(context); },
    clearFile() { this.fileJob++; this.csv = ''; this.filename = ''; if (this.$refs.file) this.$refs.file.value = ''; },
    clear() {
      api.closeContext(this.context); this.context = null; this.contextRoute = ''; this.busy = false; this.sending = '';
      this.accountRows = []; this.anchor = ''; this.localRows = null; this.bills = []; this.nextBefore = null;
      this.record = null; this.resultChoice = ''; this.error = ''; this.notice = ''; this.statement = emptyStatement(); this.clearFile();
    },
    reset() {
      this.clear(); if (!this.alive || !this.activeRoute()) return;
      try { this.context = api.captureContext(this.$store); this.contextRoute = this.$route.fullPath; this.loadList(); }
      catch (error) { this.error = '当前登录身份未确认，请重新进入账单对账'; }
    },
    syncIdentity() {
      if (this.context && (!api.currentContext(this.context) || !this.can('read'))) { this.clear(); this.error = '身份或账单查看权限已变化，请重新进入'; }
      else if (!this.canStatement('read') && (this.statement.rows.length || this.statement.detail || this.statement.versions.length))
        this.statement = emptyStatement();
    },
    async perform(action, success, sending = '', feedback = this) {
      if (this.busy || !this.context) return;
      const context = this.context;
      this.busy = true; this.sending = sending; feedback.error = ''; feedback.notice = '';
      try {
        await api.refreshPermissions(context, () => this.current(context));
        const value = await action(context); if (this.current(context)) success(value);
      } catch (error) { if (this.current(context)) feedback.error = error.message || '账单操作未完成'; }
      finally { if (this.current(context)) { this.busy = false; this.sending = ''; } }
    },
    merchantChanged() { this.accountRows = []; this.anchor = ''; this.sourceChanged(); },
    sourceChanged() { this.localRows = null; this.clearFile(); },
    loadAccounts() { this.perform(context => api.accounts(context, this.merchantId), value => { this.accountRows = value.accounts; this.anchor = ''; this.sourceChanged(); if (!value.accounts.length) this.notice = '该商家尚无已保存的测试付款账户。'; }); },
    loadMovements() { this.perform(context => api.movements(context, this.period()), value => { if (!value.movements || !Array.isArray(value.movements.rows)) throw new Error('本地流水响应未确认'); this.localRows = value.movements.rows; }); },
    period() { return { anchor_attempt_id: this.anchor, date_from: this.dateFrom, date_to: this.dateTo }; },
    async chooseFile(event) {
      const file = event.target.files && event.target.files[0]; const context = this.context; const job = ++this.fileJob;
      this.csv = ''; this.filename = ''; this.error = ''; if (!file) return;
      try {
        if (file.size > 1048576) throw new Error('请选择不超过 1 MiB 的 CSV 文件');
        const bytes = await file.arrayBuffer(); const csv = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
        if (this.current(context) && job === this.fileJob) { this.csv = csv; this.filename = file.name; }
      } catch (error) { if (this.current(context) && job === this.fileJob) this.error = '文件读取失败，请确认大小与 UTF-8 编码'; }
    },
    submit() {
      if (!this.scopeReady || !this.filename) return; const body = { ...this.period(), filename: this.filename, csv: this.csv };
      this.perform(context => api.importBill(context, body), value => { this.showRecord(value.record); this.notice = value.reused_batch ? '已读取原有批次，没有重复建立账单。' : '账单及首次对账结果已保存。'; }, 'import');
    },
    loadList(before) { this.perform(context => api.list(context, before), value => { this.bills = value.list; this.nextBefore = value.next_before_id; }); },
    showRecord(record) {
      const changed = !this.record || this.record.id !== record.id;
      this.record = record; this.resultChoice = record.result_id;
      if (changed) {
        this.statement = emptyStatement();
        this.$nextTick(() => { if (this.record === record) this.loadStatements(); });
      }
    },
    loadDetail(id, result) { this.perform(context => api.read(context, id, result), value => this.showRecord(value.record)); },
    loadResult() { if (this.record) this.loadDetail(this.record.id, this.resultChoice); },
    reconcileCurrent() {
      if (!this.record) return; const id = this.record.id; const key = api.requestKey();
      this.perform(context => api.reconcile(context, id, key), value => { this.showRecord(value.record); this.notice = value.reused_result ? '已读取本次请求原有结果。' : '新的对账结果已保存，原记录已保留。'; }, 'reconcile');
    },
    loadStatements(before) {
      if (!this.record || !this.canStatement('read')) return;
      const billId = this.record.id;
      this.perform(async context => {
        const page = await statements.list(context, billId, before);
        const versions = !before && this.canStatement('create') ? await statements.rules(context) : null;
        return { page, versions };
      }, ({ page, versions }) => {
        this.statement.rows = page.list;
        this.statement.next = page.next_before_id;
        if (versions) {
          this.statement.versions = versions;
          if (!versions.some(item => item.revision === this.statement.revision)) this.statement.revision = null;
        }
      }, '', this.statement);
    },
    loadStatement(id) {
      if (!this.record || !this.canStatement('read')) return;
      const billId = this.record.id;
      this.perform(context => statements.read(context, billId, id), value => { this.statement.detail = value; }, '', this.statement);
    },
    generateStatement() {
      if (!this.record || !this.canStatement('create') || this.statement.revision === null) return;
      const { id, result_id: resultId } = this.record;
      const revision = this.statement.revision;
      this.perform(async context => {
        const data = await statements.create(context, id, resultId, revision);
        let page = null;
        try { page = await statements.list(context, id); } catch (error) { /* Preserve the confirmed saved record. */ }
        return { data, page };
      }, ({ data, page }) => {
        if (!this.canStatement('read')) return;
        this.statement.detail = data.record;
        if (page) {
          this.statement.rows = page.list;
          this.statement.next = page.next_before_id;
        } else this.statement.error = '试算单已保存，列表读取未完成，请刷新列表。';
        this.statement.notice = data.reused_statement ? '已读取原试算单。' : '本次试算单已保存。';
      }, 'statement', this.statement);
    },
    ruleText(revision) { return revision === 0 ? '未配置规则' : '规则第' + revision + '版'; },
    ruleOption(version) { return this.ruleText(version.revision) + (version.created_at ? ' · ' + this.timeText(version.created_at) : ''); },
    statementStatus(status) { return status === 'ESTIMATED' ? '已试算' : '待核查'; },
    moneyText(value) { return value === null ? '待确认' : value; },
    reasonText(code) { return statements.reasons[code] || '另有待核事项'; },
    feeRuleItems: statements.feeRuleItems,
    downloadTemplate() {
      const url = URL.createObjectURL(new Blob(['\ufeff' + api.columns.join(',') + '\r\n'], { type: 'text/csv;charset=utf-8' }));
      const link = document.createElement('a'); link.href = url; link.download = '测试账单标准模板.csv'; document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    kindText(value) { return value === 'payment' ? '收款' : '退款'; },
    statusText(value) { return api.statusLabels[value] || '状态待确认'; },
    timeText(value) { return new Date(value * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false }); },
    fieldValue(row, field) { return !row ? '缺少记录' : row[field] === null ? '待确认' : field === 'occurred_at' ? this.timeText(row[field]) : row[field]; },
  },
};
</script>

<style scoped>
.finance-bills { min-width: 0; color: #303133; }
.finance-bills h2 { margin: 0 0 18px; font-size: 17px; }
.finance-bills h3 { font-size: 15px; margin: 20px 0 10px; }
.bill-section { margin-top: 26px; padding-top: 24px; border-top: 1px solid #ebeef5; min-width: 0; }
.bill-grid, .bill-summary, .comparison-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; }
.bill-grid .el-select, .bill-grid .el-input, .bill-grid .el-date-editor { width: 100%; min-width: 0; max-width: 100%; }
.bill-grid >>> .el-form-item { margin-bottom: 4px; }
.marketplace-actions { align-items: center; margin: 16px 0; }
.note { margin: 8px 0; color: #606266; line-height: 1.7; overflow-wrap: anywhere; }
.feedback { margin-top: 16px; }
.notice, .source-issues { margin: 16px 0; padding: 12px 16px; border-left: 4px solid #2d8cf0; background: #f5f7fa; line-height: 1.7; overflow-wrap: anywhere; }
.source-issues { padding-left: 32px; border-left-color: #e6a23c; }
.file-field { margin: 18px 0; }
.file-field label { display: block; margin-bottom: 10px; }
.file-field input { display: block; max-width: 100%; }
.bill-summary dt, .comparison-values dt { color: #606266; margin-bottom: 6px; }
.bill-summary dd, .comparison-values dd { margin: 0; line-height: 1.7; overflow-wrap: anywhere; }
.result-actions .el-select { width: 340px; max-width: 100%; }
.comparison-values { padding: 0 14px; }
.statement-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin: 16px 0; }
.statement-form .el-form-item { width: 320px; max-width: 100%; margin-bottom: 0; }
.statement-form .el-select { width: 100%; }
@media (max-width: 760px) {
  .bill-grid, .bill-summary, .comparison-values { grid-template-columns: minmax(0, 1fr); }
  .finance-bills >>> .el-card__body { padding: 16px; }
  .marketplace-actions .el-button, .result-actions .el-select { width: 100%; }
  .marketplace-actions .note { flex-basis: 100%; }
  .statement-form .el-form-item, .statement-form .el-button { width: 100%; }
}
</style>
