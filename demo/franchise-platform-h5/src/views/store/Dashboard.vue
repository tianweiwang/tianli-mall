<template>
  <div class="admin-page store-dashboard">
    <AdminMetricStrip :items="metrics" />

    <div class="store-dashboard__grid">
      <section class="admin-panel store-task-panel">
        <header class="admin-panel__header">
          <div><h2>待处理事项</h2><p>按当前门店状态实时汇总</p></div>
        </header>
        <div class="store-task-list">
          <router-link to="/store/bookings">
            <CalendarClock :size="20" aria-hidden="true" />
            <div><strong>{{ overview.pendingBookingCount }} 条待到店预约</strong><span>查看预约人和到店时段</span></div>
            <ChevronRight :size="17" aria-hidden="true" />
          </router-link>
          <router-link to="/store/assessments">
            <Activity :size="20" aria-hidden="true" />
            <div><strong>{{ assessmentTodoCount }} 项检测待办</strong><span>开始检测、确认结果与跟进复评</span></div>
            <ChevronRight :size="17" aria-hidden="true" />
          </router-link>
          <router-link to="/store/settlements">
            <BadgeDollarSign :size="20" aria-hidden="true" />
            <div><strong>{{ overview.openSettlementCount }} 笔结算处理中</strong><span>{{ formatMoney(overview.openSettlementIncomeCents) }} 门店收入待结算</span></div>
            <ChevronRight :size="17" aria-hidden="true" />
          </router-link>
          <router-link to="/store/orders">
            <Truck :size="20" aria-hidden="true" />
            <div><strong>{{ shippingCount }} 笔订单配送中</strong><span>配送由品牌方统一处理</span></div>
            <ChevronRight :size="17" aria-hidden="true" />
          </router-link>
        </div>
      </section>

      <section class="admin-panel admin-table-panel">
        <header class="admin-panel__header">
          <div><h2>最近订单</h2><p>仅显示 {{ store.name }} 归属订单</p></div>
          <router-link to="/store/orders" class="admin-link-button">全部订单<ArrowRight :size="15" /></router-link>
        </header>
        <el-table :data="recentOrders" style="width: 100%">
          <el-table-column prop="id" label="订单号" min-width="160" />
          <el-table-column label="商品" min-width="190">
            <template slot-scope="{ row }">{{ orderSummary(row) }}</template>
          </el-table-column>
          <el-table-column label="实付金额" width="120">
            <template slot-scope="{ row }">{{ formatMoney(row.paidCents) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template slot-scope="{ row }"><AdminStatusTag :status="row.status" /></template>
          </el-table-column>
          <el-table-column label="操作" width="72" fixed="right">
            <template slot-scope="{ row }">
              <router-link :to="orderDetailPath(row)" :aria-label="`查看订单 ${row.id}`" class="admin-link-button">查看</router-link>
            </template>
          </el-table-column>
        </el-table>
      </section>
    </div>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>待到店预约</h2><p>建议提前确认服务人员与时段</p></div>
        <router-link to="/store/bookings" class="admin-link-button">预约管理<ArrowRight :size="15" /></router-link>
      </header>
      <el-table :data="pendingBookings" style="width: 100%">
        <el-table-column prop="id" label="预约编号" min-width="170" />
        <el-table-column label="预约人" width="150">
          <template slot-scope="{ row }">
            <strong class="dashboard-booking-contact">{{ row.contactName }}</strong>
            <span class="dashboard-booking-phone">{{ row.phone }}</span>
          </template>
        </el-table-column>
        <el-table-column label="服务项目" min-width="170">
          <template slot-scope="{ row }">{{ serviceName(row.serviceId) }}</template>
        </el-table-column>
        <el-table-column label="到店时间" min-width="190">
          <template slot-scope="{ row }">{{ row.date }} {{ row.start }}-{{ row.end }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" text="待到店" /></template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <router-link :to="bookingDetailPath(row)" :aria-label="`查看预约 ${row.id}`" class="admin-link-button">查看</router-link>
          </template>
        </el-table-column>
      </el-table>
    </section>
  </div>
</template>

<script>
import {
  Activity,
  ArrowRight,
  BadgeDollarSign,
  CalendarCheck2,
  CalendarClock,
  ChevronRight,
  ClipboardList,
  TrendingUp,
  Truck,
} from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { assessmentTasksForStore, reassessmentQueueForStore } from '../../mock/assessmentSelectors';
import {
  activeStore,
  bookingsForStore,
  formatMoney,
  ordersForStore,
  storeOverview,
} from '../../mock/selectors';

export default {
  name: 'StoreDashboard',
  components: {
    AdminMetricStrip,
    AdminStatusTag,
    Activity,
    ArrowRight,
    BadgeDollarSign,
    CalendarClock,
    ChevronRight,
    Truck,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    storeOrders() {
      return ordersForStore(this.state, this.store.id);
    },
    storeBookings() {
      return bookingsForStore(this.state, this.store.id);
    },
    overview() {
      return storeOverview(this.state, this.store.id);
    },
    metrics() {
      return [
        { label: '本店成交额', value: formatMoney(this.overview.grossSalesCents), help: '已支付及已完成订单', icon: TrendingUp, tone: 'primary' },
        { label: '归属订单', value: `${this.overview.orderCount} 笔`, help: `${this.overview.revenueOrderCount} 笔计入成交`, icon: ClipboardList, tone: 'success' },
        { label: '待到店预约', value: `${this.overview.pendingBookingCount} 人`, help: `到店率 ${this.overview.arrivalRate}%`, icon: CalendarCheck2, tone: 'warning' },
        { label: '预计门店收入', value: formatMoney(this.overview.expectedIncomeCents), help: '扣除品牌抽成后', icon: BadgeDollarSign, tone: 'success' },
      ];
    },
    recentOrders() {
      return this.storeOrders.slice(0, 4);
    },
    pendingBookings() {
      return this.storeBookings.filter((item) => item.status === 'pending').slice(0, 5);
    },
    shippingCount() {
      return this.storeOrders.filter((item) => item.status === 'shipping').length;
    },
    assessmentTodoCount() {
      const activeTasks = assessmentTasksForStore(this.state, this.store.id)
        .filter((item) => item.status !== 'completed').length;
      return activeTasks + reassessmentQueueForStore(this.state, this.store.id).length;
    },
  },
  methods: {
    bookingDetailPath(booking) {
      return `/store/bookings?recordId=${encodeURIComponent(booking.id)}`;
    },
    formatMoney,
    orderDetailPath(order) {
      return `/store/orders?recordId=${encodeURIComponent(order.id)}`;
    },
    orderSummary(order) {
      return order.items.map((item) => `${item.name} ×${item.quantity}`).join('、');
    },
    serviceName(serviceId) {
      return this.state.services.find((item) => item.id === serviceId)?.name || serviceId;
    },
  },
};
</script>

<style scoped>
.store-dashboard__grid {
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr);
  gap: 16px;
}

.store-task-list {
  padding: 4px 18px;
}

.store-task-list a {
  display: grid;
  min-height: 74px;
  grid-template-columns: 28px minmax(0, 1fr) 18px;
  align-items: center;
  gap: 9px;
  color: var(--admin-primary);
  border-bottom: 1px solid var(--border);
}

.store-task-list a:last-child {
  border-bottom: 0;
}

.store-task-list strong,
.store-task-list span {
  display: block;
}

.store-task-list strong {
  color: var(--text-primary);
  font-size: 13px;
}

.store-task-list span {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 11px;
}

.dashboard-booking-contact,
.dashboard-booking-phone {
  display: block;
  white-space: nowrap;
}

.dashboard-booking-phone {
  color: var(--text-muted);
  font-size: 11px;
}
</style>
