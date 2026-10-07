<template>
  <div class="admin-page member-operations">
    <section class="member-metrics" aria-label="会员运营概览">
      <div><span>会员客户</span><strong>{{ memberCount }}</strong><small>含会员卡与储值客户</small></div>
      <div><span>储值本金余额</span><strong>{{ formatMoney(totalPrincipalCents) }}</strong><small>客户账户合计</small></div>
      <div><span>补贴计划</span><strong>{{ activePlanCount }}</strong><small>执行中的100期计划</small></div>
      <div><span>累计补贴</span><strong>{{ formatMoney(totalSubsidyCents) }}</strong><small>已发放金额</small></div>
    </section>

    <section class="admin-panel">
      <header class="admin-panel__header">
        <div><h2>会员运营</h2><p>客户资产、消费与集团补贴统一视图</p></div>
        <el-button type="primary" @click="executeWeeklySubsidy">执行本周补贴</el-button>
      </header>
      <nav class="member-tabs" aria-label="会员运营视图">
        <button v-for="tab in tabs" :key="tab.key" type="button" :class="{ 'is-active': activeTab === tab.key }" @click="activeTab = tab.key">{{ tab.label }}<span>{{ tabCount(tab.key) }}</span></button>
      </nav>

      <el-table v-if="activeTab === 'members'" key="members" :data="overview.customers" empty-text="暂无会员客户">
        <el-table-column label="客户" min-width="140"><template slot-scope="{ row }"><strong>{{ row.customer.name }}</strong><br><span class="member-muted">{{ row.customer.phone }}</span></template></el-table-column>
        <el-table-column label="会员身份" min-width="155"><template slot-scope="{ row }">{{ profileTitle(row) }}</template></el-table-column>
        <el-table-column label="储值本金" width="120"><template slot-scope="{ row }">{{ formatMoney(row.account.principalBalanceCents) }}</template></el-table-column>
        <el-table-column label="赠送积分" width="110"><template slot-scope="{ row }">{{ row.account.pointsBalance }}</template></el-table-column>
        <el-table-column label="补贴余额" width="120"><template slot-scope="{ row }">{{ formatMoney(row.account.subsidyBalanceCents) }}</template></el-table-column>
        <el-table-column label="操作" width="90"><template slot-scope="{ row }"><el-button type="text" @click="openCustomer(row.customer.id)">详情</el-button></template></el-table-column>
      </el-table>

      <el-table v-else-if="activeTab === 'memberships'" key="memberships" :data="overview.memberships" empty-text="暂无开卡储值记录">
        <el-table-column prop="id" label="业务编号" min-width="170" />
        <el-table-column label="客户" width="120"><template slot-scope="{ row }">{{ customerName(row.customerId) }}</template></el-table-column>
        <el-table-column prop="planName" label="方案" min-width="150" />
        <el-table-column label="支付金额" width="120"><template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template></el-table-column>
        <el-table-column label="开通时间" min-width="155"><template slot-scope="{ row }">{{ formatTime(row.activatedAt) }}</template></el-table-column>
      </el-table>

      <el-table v-else-if="activeTab === 'consumptions'" key="consumptions" :data="overview.consumptions" empty-text="暂无会员消费">
        <el-table-column prop="id" label="消费编号" min-width="170" />
        <el-table-column label="客户" width="120"><template slot-scope="{ row }">{{ customerName(row.customerId) }}</template></el-table-column>
        <el-table-column label="业务来源" min-width="150"><template slot-scope="{ row }">{{ row.orderId || row.bookingId || '历史消费' }}</template></el-table-column>
        <el-table-column label="支付来源" width="110"><template slot-scope="{ row }">{{ sourceLabel(row.paymentSource) }}</template></el-table-column>
        <el-table-column label="实际扣除" width="130"><template slot-scope="{ row }">{{ consumptionAmount(row) }}</template></el-table-column>
        <el-table-column label="计入补贴" width="120"><template slot-scope="{ row }">{{ formatMoney(row.subsidyEligiblePrincipalCents) }}</template></el-table-column>
      </el-table>

      <el-table v-else-if="activeTab === 'plans'" key="plans" :data="overview.subsidyPlans" empty-text="暂无补贴计划">
        <el-table-column prop="id" label="计划编号" min-width="175" />
        <el-table-column label="客户" width="120"><template slot-scope="{ row }">{{ customerName(row.customerId) }}</template></el-table-column>
        <el-table-column label="消费进度" min-width="155"><template slot-scope="{ row }">{{ formatMoney(account(row.customerId).eligiblePrincipalSpentCents) }} / ¥5,100.00</template></el-table-column>
        <el-table-column label="发放进度" min-width="145"><template slot-scope="{ row }">{{ row.paidPeriods }}/{{ row.totalPeriods }}期</template></el-table-column>
        <el-table-column label="已发金额" width="120"><template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template></el-table-column>
        <el-table-column label="状态" width="100"><template slot-scope="{ row }">{{ row.status === 'active' ? '执行中' : '已完成' }}</template></el-table-column>
      </el-table>

      <el-table v-else key="runs" :data="overview.subsidyRuns" empty-text="暂无发放记录">
        <el-table-column prop="id" label="发放编号" min-width="175" />
        <el-table-column prop="periodKey" label="补贴周期" width="120" />
        <el-table-column label="客户" width="120"><template slot-scope="{ row }">{{ customerName(row.customerId) }}</template></el-table-column>
        <el-table-column label="发放金额" width="120"><template slot-scope="{ row }">{{ formatMoney(row.amountCents) }}</template></el-table-column>
        <el-table-column label="执行时间" min-width="155"><template slot-scope="{ row }">{{ formatTime(row.executedAt) }}</template></el-table-column>
      </el-table>
    </section>

    <el-drawer title="会员客户详情" :visible.sync="drawerOpen" size="520px">
      <div v-if="selectedProfile" class="member-detail">
        <header><div><span>{{ selectedProfile.customer.id }}</span><h3>{{ selectedProfile.customer.name }}</h3><p>{{ profileTitle(selectedProfile) }}</p></div><strong>{{ formatMoney(selectedProfile.account.principalBalanceCents) }}</strong></header>
        <section><h4>资产账户</h4><dl><div><dt>储值本金</dt><dd>{{ formatMoney(selectedProfile.account.principalBalanceCents) }}</dd></div><div><dt>赠送积分</dt><dd>{{ selectedProfile.account.pointsBalance }}分</dd></div><div><dt>补贴余额</dt><dd>{{ formatMoney(selectedProfile.account.subsidyBalanceCents) }}</dd></div><div><dt>补贴消费</dt><dd>{{ formatMoney(selectedProfile.account.eligiblePrincipalSpentCents) }}</dd></div></dl></section>
        <section><h4>会员权益</h4><ul><li v-for="item in selectedProfile.benefits" :key="item.id"><span>{{ item.title }}</span><strong>{{ item.status === 'available' ? '可使用' : '已使用' }}</strong></li></ul><p v-if="!selectedProfile.benefits.length">暂无会员卡权益</p></section>
        <section><h4>最近流水</h4><ul><li v-for="item in selectedProfile.ledger.slice(0, 8)" :key="item.id"><span>{{ item.title }}</span><strong>{{ ledgerAmount(item) }}</strong></li></ul><p v-if="!selectedProfile.ledger.length">暂无资产流水</p></section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { memberOperationsOverview, memberProfileForCustomer } from '../../mock/memberSelectors';
import { runWeeklySubsidy } from '../../mock/memberTransitions';
import { formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'BrandMembers',
  data() {
    return {
      activeTab: 'members',
      currentPeriodKey: '2026-W31',
      drawerOpen: false,
      selectedCustomerId: null,
      tabs: [
        { key: 'members', label: '会员客户' },
        { key: 'memberships', label: '开卡储值' },
        { key: 'consumptions', label: '会员消费' },
        { key: 'plans', label: '补贴计划' },
        { key: 'runs', label: '发放记录' },
      ],
    };
  },
  computed: {
    state() { return demoStore.state.data; },
    overview() { return memberOperationsOverview(this.state); },
    selectedProfile() { return this.selectedCustomerId ? memberProfileForCustomer(this.state, this.selectedCustomerId) : null; },
    memberCount() { return this.overview.customers.filter((item) => item.plans.length).length; },
    activePlanCount() { return this.overview.subsidyPlans.filter((item) => item.status === 'active').length; },
    totalPrincipalCents() { return this.overview.customers.reduce((sum, item) => sum + item.account.principalBalanceCents, 0); },
    totalSubsidyCents() { return this.overview.subsidyRuns.reduce((sum, item) => sum + item.amountCents, 0); },
  },
  methods: {
    formatMoney,
    account(customerId) { return this.overview.customers.find((item) => item.customer.id === customerId)?.account || {}; },
    consumptionAmount(row) { return row.paymentSource === 'points' ? `${row.points}分` : formatMoney(row.principalCents || row.originalCents); },
    customerName(customerId) { return this.state.customers.find((item) => item.id === customerId)?.name || customerId; },
    formatTime(value) { return value ? value.replace('T', ' ').slice(0, 16) : '-'; },
    ledgerAmount(item) { const amount = Math.abs(item.amount); const prefix = item.amount > 0 ? '+' : '-'; return item.assetType === 'points' ? `${prefix}${amount}分` : `${prefix}${formatMoney(amount)}`; },
    openCustomer(customerId) { this.selectedCustomerId = customerId; this.drawerOpen = true; },
    profileTitle(profile) { const benefits = profile.effectiveBenefits; if (benefits.hasMembership && benefits.hasStoredValue) return '双重权益会员'; if (benefits.hasMembership) return '510会员'; if (benefits.hasStoredValue) return '储值会员'; return '普通客户'; },
    sourceLabel(source) { if (source === 'principal') return '储值本金'; if (source === 'points') return '赠送积分'; return '微信支付'; },
    tabCount(key) { const map = { members: this.overview.customers.length, memberships: this.overview.memberships.length, consumptions: this.overview.consumptions.length, plans: this.overview.subsidyPlans.length, runs: this.overview.subsidyRuns.length }; return map[key]; },
    async executeWeeklySubsidy() {
      try {
        await this.$confirm(`确认执行 ${this.currentPeriodKey} 补贴批次？同一周期不可重复发放。`, '执行本周补贴', { confirmButtonText: '确认执行', cancelButtonText: '取消', type: 'warning' });
        let result;
        demoStore.commit((draft) => { result = runWeeklySubsidy(draft, { periodKey: this.currentPeriodKey }); });
        if (result.alreadyExecuted) this.$message.info('本周补贴已执行，请勿重复发放');
        else this.$message.success(`已向${result.recordCount}位客户发放${formatMoney(result.totalAmountCents)}`);
        this.activeTab = 'runs';
      } catch (error) {
        if (error?.message) this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.member-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.member-metrics > div { padding: 17px 18px; border-right: 1px solid var(--border); }
.member-metrics > div:last-child { border-right: 0; }
.member-metrics span, .member-metrics strong, .member-metrics small { display: block; }
.member-metrics span, .member-metrics small, .member-muted { color: var(--text-muted); font-size: 11px; }
.member-metrics strong { margin: 4px 0 2px; font-size: 23px; }
.member-tabs { display: flex; gap: 3px; padding: 10px 14px; border-bottom: 1px solid var(--border); }
.member-tabs button { display: flex; min-height: 36px; align-items: center; gap: 7px; padding: 0 13px; color: var(--text-regular); background: transparent; border: 0; border-radius: var(--radius-sm); cursor: pointer; }
.member-tabs button.is-active { color: var(--admin-primary); background: var(--admin-primary-soft); font-weight: 600; }
.member-tabs span { min-width: 18px; padding: 1px 5px; background: var(--surface-subtle); border-radius: 9px; font-size: 10px; }
.member-detail { display: grid; gap: 14px; padding: 0 20px 24px; }
.member-detail > header { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 17px; color: #fff; background: #263a33; border-radius: var(--radius-md); }
.member-detail header span, .member-detail header p { font-size: 10px; opacity: .75; }
.member-detail header h3, .member-detail header p { margin: 2px 0 0; }
.member-detail header > strong { font-size: 21px; }
.member-detail section { padding: 15px; border: 1px solid var(--border); border-radius: var(--radius-md); }
.member-detail h4 { margin: 0 0 11px; font-size: 13px; }
.member-detail dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0; }
.member-detail dt { color: var(--text-muted); font-size: 10px; }
.member-detail dd { margin: 2px 0 0; font-weight: 600; }
.member-detail ul { margin: 0; padding: 0; list-style: none; }
.member-detail li { display: flex; min-height: 34px; align-items: center; justify-content: space-between; gap: 9px; border-top: 1px solid var(--border); font-size: 11px; }
.member-detail li:first-child { border-top: 0; }
.member-detail li strong { color: var(--success); }
.member-detail section > p { color: var(--text-muted); font-size: 11px; }
@media (max-width: 900px) { .member-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .member-metrics > div:nth-child(2) { border-right: 0; } }
</style>
