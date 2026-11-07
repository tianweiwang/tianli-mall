<template>
  <section class="page-panel merchant-statements" aria-labelledby="merchant-statement-heading">
    <h1 id="merchant-statement-heading" class="page-heading">本店测试结算试算单</h1>
    <p class="field-note">查看平台按已保存账单和费用规则生成的本店试算。实际结算金额尚未产生。</p>
    <el-alert v-if="error" class="statement-feedback" type="error" :closable="false" show-icon :title="error" />
    <p v-if="loading" class="field-note" role="status">正在读取本店试算单…</p>
    <div class="actions statement-actions">
      <el-button :disabled="loading || !context" @click="loadList()">刷新第一页</el-button>
      <el-button :disabled="loading || !context || !nextBefore" @click="loadList(nextBefore)">下一页</el-button>
    </div>
    <el-table v-table-scroll="'本店试算单列表，可横向滚动'" :data="rows" border empty-text="暂无本店试算单">
      <el-table-column prop="id" label="试算单" min-width="100" />
      <el-table-column label="账期" min-width="210"><template slot-scope="s">{{ s.row.date_from }} 至 {{ s.row.date_to }}</template></el-table-column>
      <el-table-column label="状态" min-width="95"><template slot-scope="s">{{ statusText(s.row.status) }}</template></el-table-column>
      <el-table-column label="预计本店应收（元）" min-width="150"><template slot-scope="s">{{ moneyText(s.row.totals.merchant_receivable) }}</template></el-table-column>
      <el-table-column label="生成时间" min-width="170"><template slot-scope="s">{{ timeText(s.row.created_at) }}</template></el-table-column>
      <el-table-column label="操作" width="100"><template slot-scope="s"><el-button size="small" :disabled="loading || !context"
        :aria-label="'查看试算单 ' + s.row.id" @click="loadDetail(s.row.id)">查看</el-button></template></el-table-column>
    </el-table>
    <section v-if="record" aria-labelledby="merchant-statement-detail-heading">
      <h2 id="merchant-statement-detail-heading" ref="detailHeading" class="section-heading" tabindex="-1">
        试算单 {{ record.id }} · {{ statusText(record.status) }}
      </h2>
      <p class="field-note">账期 {{ record.date_from }} 至 {{ record.date_to }} · {{ ruleText(record.rule_revision) }} · 对账记录 {{ record.result_id }}</p>
      <ul v-if="record.reasons.length" class="statement-reasons">
        <li v-for="reason in record.reasons" :key="reason">{{ reasonText(reason) }}</li>
      </ul>
      <dl class="statement-values">
        <div v-for="(label, key) in amountLabels" :key="key">
          <dt>{{ label }}（元）</dt><dd>{{ moneyText(record.totals[key]) }}</dd>
        </div>
      </dl>
      <h3>试算采用的费用规则</h3>
      <dl class="statement-values">
        <div v-for="item in feeRuleItems(record.fee_rule)" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></div>
      </dl>
      <h3>逐单试算（元）</h3>
      <el-table v-table-scroll="'本店订单试算，可横向滚动'" :data="record.orders" border empty-text="没有可列出的订单">
        <el-table-column prop="root_order_id" label="根订单" min-width="95" />
        <el-table-column label="销售归属" min-width="100"><template slot-scope="s">{{ s.row.sale_in_period ? '本账期' : '往期' }}</template></el-table-column>
        <el-table-column v-for="(label, key) in amountLabels" :key="key" :label="label" min-width="125">
          <template slot-scope="s">{{ moneyText(s.row.amounts[key]) }}</template>
        </el-table-column>
        <el-table-column label="待核事项" min-width="200"><template slot-scope="s">{{ s.row.reasons.map(reasonText).join('、') || '—' }}</template></el-table-column>
      </el-table>
    </section>
  </section>
</template>

<script>
import * as api from '../../api/finance-statements';
import { tableScroll } from '../../accessibility';

export default {
  name: 'MerchantFinanceStatements',
  directives: { 'table-scroll': tableScroll },
  data: () => ({ context: null, loading: false, error: '', rows: [], nextBefore: null, record: null, amountLabels: api.amountLabels }),
  created() {
    this.unsubscribe = this.$store.subscribe(this.syncIdentity);
    window.addEventListener('focus', this.syncIdentity);
    window.addEventListener('storage', this.syncIdentity);
    try {
      this.context = api.captureContext(this.$store);
      this.loadList();
    } catch (error) { this.error = '当前登录身份未确认，请重新进入试算单。'; }
  },
  beforeRouteLeave(to, from, next) { this.clear(); next(); },
  beforeDestroy() {
    if (this.unsubscribe) this.unsubscribe();
    window.removeEventListener('focus', this.syncIdentity);
    window.removeEventListener('storage', this.syncIdentity);
    this.clear();
  },
  methods: {
    current(context) {
      return context === this.context && this.$route.name === 'finance-statements'
        && api.currentContext(context) && this.$store.getters.can(api.permission);
    },
    clear() {
      api.closeContext(this.context);
      this.context = null; this.loading = false; this.rows = []; this.nextBefore = null; this.record = null;
    },
    syncIdentity() {
      if (this.context && !this.current(this.context)) {
        this.clear();
        this.error = '登录身份或查看权限已变化，请重新进入试算单。';
      }
    },
    async perform(read, display) {
      const context = this.context;
      if (this.loading || !this.current(context)) return;
      this.loading = true; this.error = '';
      try {
        const value = await read(context);
        if (this.current(context)) display(value);
      } catch (error) {
        if (!this.current(context)) return;
        if (error.status === 401 || error.status === 403) this.clear();
        this.error = error.message || '未能读取本店试算单。';
      } finally { if (this.current(context)) this.loading = false; }
    },
    loadList(before) {
      this.perform(context => api.list(context, before), value => {
        this.rows = value.list; this.nextBefore = value.next_before_id; this.record = null;
      });
    },
    loadDetail(id) {
      this.perform(context => api.detail(context, id), value => {
        this.record = value;
        this.$nextTick(() => { if (this.record === value && this.$refs.detailHeading) this.$refs.detailHeading.focus(); });
      });
    },
    statusText(status) { return status === 'ESTIMATED' ? '已试算' : '待核查'; },
    ruleText(revision) { return revision === 0 ? '未配置规则' : '规则第' + revision + '版'; },
    moneyText(value) { return value === null ? '待确认' : value; },
    reasonText(code) { return api.reasonLabels[code] || '另有待核事项'; },
    feeRuleItems: api.feeRuleItems,
    timeText(value) { return new Date(value * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false }); },
  },
};
</script>

<style scoped>
.merchant-statements { min-width: 0; }
.merchant-statements h3 { margin: 24px 0 12px; font-size: 15px; }
.statement-actions, .statement-feedback { margin: 16px 0; }
.statement-reasons { margin: 16px 0; padding: 12px 16px 12px 32px; border-left: 4px solid #e6a23c; background: #f5f7fa; line-height: 1.7; }
.statement-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 20px; margin: 16px 0; }
.statement-values dt { color: #606266; margin-bottom: 6px; }
.statement-values dd { margin: 0; line-height: 1.7; overflow-wrap: anywhere; }
@media (max-width: 760px) {
  .statement-actions .el-button { width: 100%; }
  .statement-values { grid-template-columns: minmax(0, 1fr); }
}
</style>
