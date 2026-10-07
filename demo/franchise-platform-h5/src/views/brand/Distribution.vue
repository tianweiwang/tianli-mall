<template>
  <div class="admin-page brand-distribution">
    <AdminMetricStrip :items="metrics" />
    <p class="distribution-budget-notice">个人分享佣金由集团推广预算承担，不进入区域代理分账或客户补贴资金池。</p>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>分销员档案</h2><p>当前演示账号的分销档案</p></div>
      </header>
      <el-table :data="distributorRows" style="width: 100%">
        <el-table-column prop="id" label="分销编号" min-width="140" />
        <el-table-column prop="name" label="姓名" min-width="100" />
        <el-table-column prop="level" label="等级" min-width="110" />
        <el-table-column label="佣金比例" width="100">
          <template slot-scope="{ row }">{{ row.commissionRate }}%</template>
        </el-table-column>
        <el-table-column label="累计佣金" width="120">
          <template slot-scope="{ row }"><strong>{{ formatMoney(row.totalCommissionCents) }}</strong></template>
        </el-table-column>
        <el-table-column label="已结算" width="110">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.settledCents) }}</span></template>
        </el-table-column>
        <el-table-column label="待结算" width="110">
          <template slot-scope="{ row }">{{ formatMoney(row.pendingCents) }}</template>
        </el-table-column>
        <el-table-column label="可提现" width="110">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.withdrawableCents) }}</span></template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看分销员 ${row.id}`" @click="openDistributor(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>佣金明细</h2><p>佣金按订单实付与分销协议比例生成</p></div>
      </header>
      <el-table :data="state.commissionRecords" style="width: 100%" empty-text="暂无佣金记录">
        <el-table-column prop="id" label="佣金编号" min-width="170" />
        <el-table-column prop="orderId" label="关联订单" min-width="170" />
        <el-table-column prop="customerName" label="成交顾客" min-width="110" />
        <el-table-column label="佣金金额" width="120">
          <template slot-scope="{ row }"><span class="admin-value-positive">{{ formatMoney(row.amountCents) }}</span></template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="row.status === 'settled' ? '已结算' : '待结算'" /></template>
        </el-table-column>
        <el-table-column label="时间" min-width="160">
          <template slot-scope="{ row }">{{ formatTime(row.at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看佣金 ${row.id}`" @click="openCommission(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section class="admin-panel">
      <header class="admin-panel__header">
        <div><h2>分享回流</h2><p>消费者分享带来的访问与转化（演示）</p></div>
      </header>
      <div class="brand-share-grid">
        <div v-for="record in state.shareRecords" :key="record.id" class="brand-share-item">
          <strong>{{ record.content }}</strong>
          <span>{{ record.channel }} · {{ formatTime(record.at) }}</span>
          <small>访问 {{ record.visits }} · 绑定 {{ record.bindings || 0 }} · 归因 {{ record.orders }} 单</small>
          <button type="button" :aria-label="`查看分享 ${record.id}`" @click="openShare(record)">查看详情</button>
        </div>
      </div>
    </section>

    <el-drawer :visible.sync="drawerOpen" size="440px">
      <template slot="title"><h2 class="admin-drawer-title">{{ detailTitle }}</h2></template>
      <div v-if="selectedRecord" class="admin-detail-body">
        <section v-if="detailType === 'distributor'" class="admin-detail-section">
          <h3>分销档案</h3>
          <dl class="admin-detail-list">
            <div><dt>分销编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>姓名</dt><dd>{{ selectedRecord.name }}</dd></div>
            <div><dt>等级</dt><dd>{{ selectedRecord.level }}</dd></div>
            <div><dt>佣金比例</dt><dd>{{ selectedRecord.commissionRate }}%</dd></div>
            <div><dt>累计佣金</dt><dd>{{ formatMoney(selectedRecord.totalCommissionCents) }}</dd></div>
            <div><dt>已结算</dt><dd>{{ formatMoney(selectedRecord.settledCents) }}</dd></div>
            <div><dt>待结算</dt><dd>{{ formatMoney(selectedRecord.pendingCents) }}</dd></div>
            <div><dt>可提现</dt><dd class="admin-value-positive">{{ formatMoney(selectedRecord.withdrawableCents) }}</dd></div>
          </dl>
        </section>
        <section v-else-if="detailType === 'commission'" class="admin-detail-section">
          <h3>佣金记录</h3>
          <dl class="admin-detail-list">
            <div><dt>佣金编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>关联订单</dt><dd>{{ selectedRecord.orderId }}</dd></div>
            <div><dt>成交顾客</dt><dd>{{ selectedRecord.customerName }}</dd></div>
            <div><dt>佣金金额</dt><dd class="admin-value-positive">{{ formatMoney(selectedRecord.amountCents) }}</dd></div>
            <div><dt>状态</dt><dd>{{ selectedRecord.status === 'settled' ? '已结算' : '待结算' }}</dd></div>
            <div><dt>发生时间</dt><dd>{{ formatTime(selectedRecord.at) }}</dd></div>
          </dl>
        </section>
        <section v-else class="admin-detail-section">
          <h3>分享转化</h3>
          <dl class="admin-detail-list">
            <div><dt>分享编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>分享内容</dt><dd>{{ selectedRecord.content }}</dd></div>
            <div><dt>分享渠道</dt><dd>{{ selectedRecord.channel }}</dd></div>
            <div><dt>分享时间</dt><dd>{{ formatTime(selectedRecord.at) }}</dd></div>
            <div><dt>访问次数</dt><dd>{{ selectedRecord.visits }}</dd></div>
            <div><dt>推荐绑定</dt><dd>{{ selectedRecord.bindings || 0 }}</dd></div>
            <div><dt>归因订单</dt><dd>{{ selectedRecord.orders }}</dd></div>
          </dl>
        </section>
        <div class="admin-drawer-actions"><el-button @click="drawerOpen = false">关闭</el-button></div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { HandCoins, Link2, ShoppingBag, TrendingUp } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { brandGrowthOverview, distributorOverview, formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'BrandDistribution',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      detailType: '',
      drawerOpen: false,
      selectedRecord: null,
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    overview() {
      return brandGrowthOverview(this.state);
    },
    distributorRows() {
      return [distributorOverview(this.state).account];
    },
    detailTitle() {
      return { distributor: '分销员详情', commission: '佣金详情', share: '分享详情' }[this.detailType] || '详情';
    },
    metrics() {
      return [
        { label: '分享绑定', value: `${this.overview.shareBindings} 人`, help: `${this.overview.shareVisits} 次分享访问`, icon: Link2, tone: 'primary' },
        { label: '归因订单', value: `${this.overview.attributedOrders} 单`, help: `分享记录累计 ${this.overview.shareOrders} 单`, icon: ShoppingBag, tone: 'success' },
        { label: '待结算佣金', value: formatMoney(this.overview.pendingCommissionCents), help: '分账完成后可提现', icon: TrendingUp, tone: 'warning' },
        { label: '已结算佣金', value: formatMoney(this.overview.settledCommissionCents), help: `累计佣金 ${formatMoney(this.overview.totalCommissionCents)}`, icon: HandCoins, tone: 'success' },
      ];
    },
  },
  methods: {
    formatMoney,
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    openCommission(record) {
      this.openDetail('commission', record);
    },
    openDetail(type, record) {
      this.detailType = type;
      this.selectedRecord = record;
      this.drawerOpen = true;
    },
    openDistributor(record) {
      this.openDetail('distributor', record);
    },
    openShare(record) {
      this.openDetail('share', record);
    },
  },
};
</script>

<style scoped>
.brand-share-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 4px 18px 18px;
}

.brand-share-item {
  padding: 12px;
  background: var(--surface-subtle, #fafbfc);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.brand-share-item strong,
.brand-share-item span,
.brand-share-item small {
  display: block;
}

.brand-share-item strong {
  font-size: 13px;
}

.brand-share-item span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.brand-share-item small {
  margin-top: 6px;
  color: var(--text-regular);
  font-size: 11px;
}

.brand-share-item button {
  margin-top: 10px;
  padding: 0;
  color: var(--admin-primary);
  background: transparent;
  border: 0;
  font-size: 12px;
  cursor: pointer;
}

.admin-drawer-title { margin: 0; font-size: 18px; }
.admin-drawer-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
.distribution-budget-notice { margin: 0; padding: 12px 14px; color: #7a5813; background: #fff8e8; border: 1px solid #f0d99d; border-radius: var(--radius-md); font-size: 11px; }
</style>
