<template>
  <section class="marketplace-console finance-transactions">
    <el-card shadow="never">
      <h1 class="marketplace-heading">测试交易</h1>
      <p class="finance-note">查看已保存的测试根单与全部付款尝试。每笔根单只列一次，按根单编号从新到旧分页。</p>
      <el-alert
        title="测试交易与平台费估算，不是真实入账"
        description="只有明确选择已保存版本后才由服务端估算平台费。此页不会启用收款、退款、结算或提现，也不会修改交易和资金草稿。"
        type="info"
        :closable="false"
        show-icon
      />
      <el-alert v-if="error" class="finance-feedback" :title="error" type="error" :closable="false" show-icon />
      <el-form label-position="top" class="finance-filter" @submit.native.prevent="refresh">
        <el-form-item label="用于平台费估算的保存版本" for="finance-transactions-revision">
          <el-select
            id="finance-transactions-revision"
            v-model="selectedRevision"
            class="finance-revision"
            :disabled="loading || !can('read')"
            placeholder="不选择版本，只查看测试交易"
            @change="revisionChanged"
          >
            <el-option :value="null" label="不选择版本，只查看测试交易" />
            <el-option
              v-for="row in revisions"
              :key="row.revision"
              :value="row.revision"
              :label="'第 ' + row.revision + ' 版 · ' + timeText(row.created_at)"
            />
            <el-option
              v-if="selectedOutsideRecent"
              :value="selectedRevision"
              :label="'已选择第 ' + selectedRevision + ' 版（最近20版之外）'"
              disabled
            />
          </el-select>
        </el-form-item>
        <div class="marketplace-actions finance-actions">
          <el-button native-type="submit" :disabled="loading || !can('read')" :loading="loading">刷新第一页</el-button>
          <span class="finance-note" role="status">{{
            loading ? '正在读取测试交易…' : '版本列表显示最近20版；不会自动选择最新版本。'
          }}</span>
        </div>
      </el-form>
      <template v-if="view">
        <section class="finance-rule" aria-labelledby="finance-transactions-rule">
          <h2 id="finance-transactions-rule">本页平台费规则</h2>
          <p v-if="view.selected_revision === null" class="finance-note">
            尚未选择保存版本，交易保留查看，平台费估算为空。
          </p>
          <template v-else>
            <p class="finance-note">使用已保存的第 {{ view.selected_revision }} 版，仅用于本次查看，不回填旧交易。</p>
            <dl class="finance-values">
              <div>
                <dt>平台费开关</dt>
                <dd>
                  {{
                    view.rule.platform_fee_enabled === null
                      ? '未配置'
                      : view.rule.platform_fee_enabled
                      ? '收取'
                      : '不收取'
                  }}
                </dd>
              </div>
              <div>
                <dt>计费基数</dt>
                <dd>
                  {{
                    view.rule.platform_fee_basis === 'goods_paid'
                      ? '商品实付（不含运费）'
                      : view.rule.platform_fee_basis === 'order_paid'
                      ? '订单实付（含运费）'
                      : '未配置'
                  }}
                </dd>
              </div>
              <div>
                <dt>平台费率</dt>
                <dd>{{ view.rule.platform_fee_rate === null ? '未配置' : view.rule.platform_fee_rate + '%' }}</dd>
              </div>
              <div>
                <dt>平台费承担方</dt>
                <dd>{{ payerText(view.rule.platform_fee_payer) }}</dd>
              </div>
            </dl>
          </template>
        </section>
        <dl class="finance-values finance-unknown">
          <div>
            <dt>通道费</dt>
            <dd>{{ moneyText(view.channel_fee_amount) }}</dd>
          </div>
          <div>
            <dt>商家实际到账</dt>
            <dd>{{ moneyText(view.merchant_receivable) }}</dd>
          </div>
          <div>
            <dt>实际结算</dt>
            <dd>{{ moneyText(view.settled_amount) }}</dd>
          </div>
        </dl>
        <p class="finance-note">
          下列金额是根单保存金额，不按售后批准金额冲减。展开行可查看该根单全部付款尝试；估算值不能作为账单、收入统计或结算依据。
        </p>
        <p v-if="!view.list.length" class="finance-empty" role="status">当前页没有符合条件的测试交易。</p>
        <el-table v-else :data="view.list" row-key="root_order_id" class="finance-table" border>
          <el-table-column type="expand" width="48">
            <template slot-scope="scope">
              <section class="finance-attempts" :aria-label="'根单 ' + scope.row.root_order_id + ' 的全部付款尝试'">
                <h2>全部付款尝试（{{ scope.row.attempts.length }}）</h2>
                <el-table :data="scope.row.attempts" row-key="attempt_id" border>
                  <el-table-column prop="attempt_id" label="尝试编号" min-width="170" />
                  <el-table-column prop="admitted_generation" label="登记代次" min-width="100" />
                  <el-table-column label="保存状态" min-width="180"
                    ><template slot-scope="attempt">{{
                      attemptStateText(attempt.row.state)
                    }}</template></el-table-column
                  >
                  <el-table-column label="创建时间" min-width="170"
                    ><template slot-scope="attempt">{{ timeText(attempt.row.created_at) }}</template></el-table-column
                  >
                  <el-table-column label="应用时间" min-width="170"
                    ><template slot-scope="attempt">{{
                      attempt.row.applied_at === null ? '尚未应用' : timeText(attempt.row.applied_at)
                    }}</template></el-table-column
                  >
                </el-table>
              </section>
            </template>
          </el-table-column>
          <el-table-column label="根单" min-width="210"
            ><template slot-scope="scope"
              ><div class="finance-order">{{ scope.row.order_no }}</div>
              <div class="finance-note">
                根单编号 {{ scope.row.root_order_id }} · 商家 {{ scope.row.mer_id }}
              </div></template
            ></el-table-column
          >
          <el-table-column label="商品实付" min-width="150"
            ><template slot-scope="scope">{{ moneyText(scope.row.goods_paid) }}</template></el-table-column
          >
          <el-table-column label="运费" min-width="140"
            ><template slot-scope="scope">{{ moneyText(scope.row.shipping_paid) }}</template></el-table-column
          >
          <el-table-column label="订单金额" min-width="150"
            ><template slot-scope="scope">{{ moneyText(scope.row.order_amount) }}</template></el-table-column
          >
          <el-table-column label="付款与履约" min-width="160"
            ><template slot-scope="scope"
              ><div>{{ scope.row.attempts.length }} 次付款尝试</div>
              <div class="finance-note">{{ scope.row.fulfillment_hold ? '履约暂缓' : '未标记履约暂缓' }}</div></template
            ></el-table-column
          >
          <el-table-column label="平台费估算" min-width="230"
            ><template slot-scope="scope"
              ><div>{{ estimateText(scope.row.estimate_status) }}</div>
              <template v-if="scope.row.fee_estimate"
                ><strong>{{ moneyText(scope.row.fee_estimate.platform_fee_amount) }}</strong>
                <div v-if="scope.row.fee_estimate.basis_amount !== null" class="finance-note">
                  基数 {{ moneyText(scope.row.fee_estimate.basis_amount) }}
                </div>
                <div class="finance-note">{{ payerText(scope.row.fee_estimate.payer) }}</div></template
              ></template
            ></el-table-column
          >
        </el-table>
        <div class="marketplace-actions finance-pagination">
          <span class="finance-note" role="status"
            >第 {{ pageNumber }} 页 · 本页 {{ view.list.length }} 笔根单{{
              view.next_before_id === null ? ' · 已到末页' : ''
            }}</span
          >
          <el-button :disabled="loading || view.next_before_id === null || !can('read')" @click="nextPage"
            >下一页</el-button
          >
        </div>
      </template>
    </el-card>
  </section>
</template>
<script>
import {
  captureContext,
  currentContext,
  closeContext,
  can,
  refreshPermissions,
  read,
} from '@/api/marketplace-finance-transactions';
import { moneyText, payerText, attemptStateText, estimateText } from '@/libs/marketplace-finance-transactions';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import '../style.css';
export default {
  name: 'marketplaceFinanceTransactions',
  data: () => ({
    context: null,
    contextRoute: '',
    alive: true,
    generation: 0,
    requestJob: 0,
    loading: false,
    view: null,
    revisions: [],
    selectedRevision: null,
    pageNumber: 0,
    error: '',
    unsubscribe: null,
    unsubscribeEpoch: null,
  }),
  computed: {
    selectedOutsideRecent() {
      return this.selectedRevision !== null && !this.revisions.some((row) => row.revision === this.selectedRevision);
    },
  },
  created() {
    this.unsubscribeEpoch = subscribePlatformAuthEpoch(() => this.syncIdentity());
    this.unsubscribe = this.$store.subscribe(() => this.syncIdentity());
    window.addEventListener('focus', this.syncIdentity);
    window.addEventListener('storage', this.syncIdentity);
    this.reset();
  },
  watch: {
    '$route.fullPath'() {
      this.reset();
    },
  },
  activated() {
    if (this.alive && this.activeRoute() && !this.context) this.reset();
  },
  deactivated() {
    this.clear();
  },
  beforeRouteLeave(to, from, next) {
    this.clear();
    next();
  },
  beforeDestroy() {
    this.alive = false;
    if (this.unsubscribe) this.unsubscribe();
    if (this.unsubscribeEpoch) this.unsubscribeEpoch();
    window.removeEventListener('focus', this.syncIdentity);
    window.removeEventListener('storage', this.syncIdentity);
    this.clear();
  },
  methods: {
    moneyText,
    payerText,
    attemptStateText,
    estimateText,
    timeText(value) {
      return new Date(value * 1000).toLocaleString('zh-CN', { hour12: false });
    },
    activeRoute() {
      return this.$route.name === 'marketplace_finance_transactions';
    },
    can(action) {
      return can(this.$store, action);
    },
    current(context, generation, job) {
      return (
        this.alive &&
        this.activeRoute() &&
        context === this.context &&
        generation === this.generation &&
        job === this.requestJob &&
        this.contextRoute === this.$route.fullPath &&
        currentContext(context)
      );
    },
    clear() {
      closeContext(this.context);
      this.context = null;
      this.contextRoute = '';
      this.generation += 1;
      this.requestJob += 1;
      this.loading = false;
      this.view = null;
      this.revisions = [];
      this.selectedRevision = null;
      this.pageNumber = 0;
      this.error = '';
    },
    reset() {
      this.clear();
      if (!this.alive || !this.activeRoute()) return;
      try {
        this.context = captureContext(this.$store);
        this.contextRoute = this.$route.fullPath;
        this.load();
      } catch (error) {
        this.error = '当前登录身份未确认，请重新进入测试交易';
      }
    },
    syncIdentity() {
      if (this.context && !currentContext(this.context)) {
        this.clear();
        this.error = '身份已变化，请重新进入测试交易';
        return;
      }
      if (this.context && !this.can('read')) {
        this.clear();
        this.error = '当前测试交易查看权限已变化';
      }
    },
    refresh() {
      if (this.loading) return;
      if (!this.context) this.reset();
      else this.load();
    },
    revisionChanged() {
      this.refresh();
    },
    nextPage() {
      if (!this.loading && this.view && this.view.next_before_id !== null) this.load(this.view.next_before_id);
    },
    async load(before = null) {
      if (this.loading || !this.context) return;
      const context = this.context;
      const generation = this.generation;
      const job = ++this.requestJob;
      const query = { limit: 20, before_id: before, revision: this.selectedRevision };
      const page = before === null ? 1 : this.pageNumber + 1;
      this.loading = true;
      this.view = null;
      this.pageNumber = 0;
      this.error = '';
      try {
        await refreshPermissions(
          context,
          () => this.current(context, generation, job) && !this.$router.history.pending,
        );
        if (!this.current(context, generation, job)) return;
        const value = await read(context, query);
        if (!this.current(context, generation, job)) return;
        this.view = value;
        this.revisions = value.available_revisions;
        this.pageNumber = page;
      } catch (error) {
        if (this.current(context, generation, job)) {
          this.view = null;
          this.revisions = [];
          this.error = error.message || '测试交易读取失败';
        }
      } finally {
        if (this.current(context, generation, job)) this.loading = false;
        else if (
          context === this.context &&
          generation === this.generation &&
          job === this.requestJob &&
          !currentContext(context)
        ) {
          this.syncIdentity();
        }
      }
    },
  },
};
</script>
<style scoped>
.finance-transactions {
  min-width: 0;
  color: #303133;
}
.finance-transactions h2 {
  margin: 0 0 12px;
  font-size: 17px;
}
.finance-transactions >>> .el-alert--info .el-alert__title,
.finance-transactions >>> .el-alert--info .el-alert__icon {
  color: #606266;
}
.finance-transactions >>> .el-alert--info .el-alert__description {
  color: #606266 !important;
}
.finance-note {
  color: #606266;
  margin: 8px 0 14px;
  line-height: 1.7;
  overflow-wrap: anywhere;
}
.finance-feedback {
  margin-top: 16px;
}
.finance-filter {
  margin-top: 24px;
}
.finance-revision {
  width: 440px;
  max-width: 100%;
}
.finance-actions {
  align-items: center;
  margin: 18px 0;
}
.finance-actions .finance-note {
  flex: 1 1 240px;
  margin: 0;
}
.finance-rule {
  margin-top: 24px;
  padding-top: 24px;
  border-top: 1px solid #ebeef5;
}
.finance-values {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 24px;
  margin: 20px 0;
}
.finance-values dt {
  color: #606266;
  margin-bottom: 6px;
}
.finance-values dd {
  margin: 0;
  line-height: 1.7;
  overflow-wrap: anywhere;
}
.finance-unknown {
  padding: 18px;
  background: #f5f7fa;
}
.finance-empty {
  margin: 24px 0;
  padding: 18px;
  color: #606266;
  background: #f5f7fa;
  line-height: 1.7;
}
.finance-table {
  width: 100%;
  max-width: 100%;
  min-width: 0;
}
.finance-table .finance-note {
  margin: 6px 0 0;
}
.finance-order {
  overflow-wrap: anywhere;
}
.finance-attempts {
  padding: 8px 16px;
}
.finance-pagination {
  align-items: center;
  justify-content: space-between;
  margin-top: 20px;
}
.finance-pagination .finance-note {
  margin: 0;
}
@media (max-width: 760px) {
  .finance-values {
    grid-template-columns: minmax(0, 1fr);
  }
  .finance-unknown,
  .finance-empty {
    padding: 14px;
  }
  .finance-actions .el-button,
  .finance-pagination .el-button {
    width: 100%;
  }
  .finance-actions .finance-note,
  .finance-pagination .finance-note {
    flex-basis: 100%;
  }
}
</style>
