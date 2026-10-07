<template>
  <div class="admin-page brand-bookings">
    <section class="admin-filter-bar" aria-label="全平台预约筛选">
      <el-date-picker v-model="date" type="date" value-format="yyyy-MM-dd" :clearable="false" aria-label="预约日期" />
      <el-select v-model="storeId" aria-label="所属门店">
        <el-option label="全部门店" value="all" />
        <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
      </el-select>
      <el-select v-model="serviceId" aria-label="服务项目">
        <el-option label="全部服务" value="all" />
        <el-option v-for="service in state.services" :key="service.id" :label="service.name" :value="service.id" />
      </el-select>
      <el-select v-model="status" aria-label="预约状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-button class="brand-bookings__reset" @click="resetFilters">
        <RotateCcw :size="14" aria-hidden="true" />
        重置筛选
      </el-button>
    </section>

    <AdminMetricStrip :items="metricItems" />

    <AdminBookingHeatmap
      :columns="operations.columns"
      :rows="operations.rows"
      :selected-key="selectedCell ? selectedCell.key : ''"
      @select="selectHeatCell"
    />

    <section v-if="operations.attention.length" class="admin-panel brand-bookings__attention" aria-label="预约关注事项">
      <div class="brand-bookings__attention-title">
        <TriangleAlert :size="18" aria-hidden="true" />
        <strong>需关注</strong>
      </div>
      <div class="brand-bookings__attention-actions">
        <button
          v-for="item in operations.attention"
          :key="item.key"
          type="button"
          class="brand-bookings__attention-item"
          @click="selectAttention(item)"
        >
          {{ item.label }}
        </button>
      </div>
    </section>

    <section class="admin-filter-bar brand-bookings__detail-filter" aria-label="预约明细筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="预约编号、姓名或手机号" prefix-icon="el-icon-search" />
      <span v-if="selectedCell" class="brand-bookings__selection">
        {{ selectionSummary }}
        <el-button type="text" @click="clearHeatSelection">清除时段筛选</el-button>
      </span>
      <span class="admin-filter-bar__count">共 {{ visibleBookings.length }} 条</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleBookings" style="width: 100%" empty-text="暂无符合条件的预约">
        <el-table-column prop="id" label="预约编号" min-width="165" />
        <el-table-column label="所属门店" min-width="145">
          <template slot-scope="{ row }">{{ storeName(row.storeId) }}</template>
        </el-table-column>
        <el-table-column label="预约人" width="140">
          <template slot-scope="{ row }"><strong>{{ row.contactName }}</strong><br><span class="brand-booking-muted">{{ row.phone }}</span></template>
        </el-table-column>
        <el-table-column label="服务项目" min-width="160">
          <template slot-scope="{ row }">{{ serviceName(row.serviceId) }}</template>
        </el-table-column>
        <el-table-column prop="date" label="预约日期" width="115" />
        <el-table-column label="预约时段" width="125">
          <template slot-scope="{ row }">{{ row.start }}-{{ row.end }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer title="预约详情" :visible.sync="drawerOpen" size="450px">
      <div v-if="selectedBooking" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>预约信息</h3>
          <dl class="admin-detail-list">
            <div><dt>预约编号</dt><dd>{{ selectedBooking.id }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedBooking.status" :text="statusText(selectedBooking.status)" /></dd></div>
            <div><dt>所属门店</dt><dd>{{ storeName(selectedBooking.storeId) }}</dd></div>
            <div><dt>服务项目</dt><dd>{{ serviceName(selectedBooking.serviceId) }}</dd></div>
            <div><dt>到店时间</dt><dd>{{ selectedBooking.date }} {{ selectedBooking.start }}-{{ selectedBooking.end }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>联系与核销</h3>
          <dl class="admin-detail-list">
            <div><dt>预约人</dt><dd>{{ selectedBooking.contactName }}</dd></div>
            <div><dt>手机</dt><dd>{{ selectedBooking.phone }}</dd></div>
            <div><dt>备用核销编号</dt><dd class="brand-booking-code">{{ selectedBooking.verifyCode }}</dd></div>
            <div v-if="selectedBooking.verifiedAt"><dt>核销时间</dt><dd>{{ formatTime(selectedBooking.verifiedAt) }}</dd></div>
          </dl>
        </section>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import {
  CalendarDays,
  Gauge,
  RotateCcw,
  TriangleAlert,
  UserRoundCheck,
} from 'lucide-vue';
import AdminBookingHeatmap from '../../components/admin/AdminBookingHeatmap.vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { brandBookingOperations } from '../../mock/selectors';

const statusLabels = {
  pending: '待到店',
  verified: '已核销',
  cancelled: '已取消',
  expired: '已过期',
};

export default {
  name: 'BrandBookings',
  components: {
    AdminBookingHeatmap,
    AdminMetricStrip,
    AdminStatusTag,
    CalendarDays,
    Gauge,
    RotateCcw,
    TriangleAlert,
    UserRoundCheck,
  },
  data() {
    return {
      date: demoStore.state.data.demoNow.slice(0, 10),
      drawerOpen: false,
      keyword: '',
      selectedBooking: null,
      selectedCell: null,
      serviceId: 'all',
      status: 'all',
      storeId: 'all',
      statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    operations() {
      return brandBookingOperations(this.state, {
        date: this.date,
        storeId: this.storeId,
        serviceId: this.serviceId,
      });
    },
    metricItems() {
      const { metrics } = this.operations;
      return [
        {
          label: '当日预约',
          value: String(metrics.bookingCount),
          help: `覆盖 ${metrics.activeStoreCount} 家营业门店`,
          icon: CalendarDays,
          tone: 'primary',
        },
        {
          label: '预约容量',
          value: `${metrics.booked} / ${metrics.capacity}`,
          help: `整体占用率 ${metrics.occupancyRate}%`,
          icon: Gauge,
          tone: 'success',
        },
        {
          label: '待到店',
          value: String(metrics.pendingCount),
          help: metrics.nextPendingTime ? `最近一场 ${metrics.nextPendingTime}` : '当前无待到店预约',
          icon: UserRoundCheck,
          tone: 'warning',
        },
        {
          label: '需关注',
          value: String(metrics.attentionCount),
          help: `${metrics.fullSlotCount} 个已满时段 · ${metrics.expiredCount} 条已过期`,
          icon: TriangleAlert,
          tone: 'danger',
        },
      ];
    },
    selectionSummary() {
      if (!this.selectedCell) return '';
      return `${this.selectedCell.storeName} · ${this.selectedCell.label} · ${this.selectedCell.booked}/${this.selectedCell.capacity} · ${this.date}`;
    },
    visibleBookings() {
      const keyword = this.keyword.toLowerCase();
      return this.state.bookings.filter((booking) => {
        const matchesDate = !this.date || booking.date === this.date;
        const matchesStore = this.storeId === 'all' || booking.storeId === this.storeId;
        const matchesService = this.serviceId === 'all' || booking.serviceId === this.serviceId;
        const matchesStatus = this.status === 'all' || booking.status === this.status;
        const matchesCell = !this.selectedCell || (
          booking.storeId === this.selectedCell.storeId
          && booking.start === this.selectedCell.start
          && booking.end === this.selectedCell.end
        );
        const searchable = `${booking.id} ${booking.contactName} ${booking.phone}`.toLowerCase();
        return matchesDate && matchesStore && matchesService && matchesStatus && matchesCell
          && (!keyword || searchable.includes(keyword));
      });
    },
  },
  watch: {
    date: 'clearHeatSelection',
    serviceId: 'clearHeatSelection',
    storeId: 'clearHeatSelection',
  },
  methods: {
    clearHeatSelection() {
      this.selectedCell = null;
    },
    resetFilters() {
      this.date = this.state.demoNow.slice(0, 10);
      this.keyword = '';
      this.selectedCell = null;
      this.serviceId = 'all';
      this.status = 'all';
      this.storeId = 'all';
    },
    selectAttention(item) {
      if (item.type === 'full-slot') {
        this.selectHeatCell(item.cell);
        return;
      }
      if (item.type === 'expired') {
        this.clearHeatSelection();
        this.status = 'expired';
      }
    },
    selectHeatCell(cell) {
      this.selectedCell = this.selectedCell?.key === cell.key ? null : cell;
    },
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    openDetail(booking) {
      this.selectedBooking = booking;
      this.drawerOpen = true;
    },
    serviceName(serviceId) {
      return this.state.services.find((item) => item.id === serviceId)?.name || serviceId;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
  },
};
</script>

<style scoped>
.brand-bookings__reset,
.brand-bookings__attention-title,
.brand-bookings__attention-item,
.brand-bookings__selection {
  display: inline-flex;
  align-items: center;
}

.brand-bookings__reset,
.brand-bookings__attention-title,
.brand-bookings__selection {
  gap: 6px;
}

.brand-bookings__attention {
  display: flex;
  min-height: 64px;
  align-items: center;
  gap: 16px;
  padding: 12px 16px;
}

.brand-bookings__attention-title,
.brand-bookings__attention-item {
  color: #a35e00;
}

.brand-bookings__attention-actions {
  display: flex;
  min-width: 0;
  gap: 8px;
  overflow-x: auto;
}

.brand-bookings__attention-item {
  min-height: 32px;
  padding: 6px 10px;
  white-space: nowrap;
  background: #fff7e9;
  border: 1px solid #efc27f;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.brand-bookings__attention-item:focus-visible {
  outline: 2px solid var(--admin-primary);
  outline-offset: 2px;
}

.brand-bookings__detail-filter {
  min-height: 64px;
}

.brand-bookings__selection {
  color: var(--text-regular);
  font-size: 12px;
  white-space: nowrap;
}

.brand-booking-muted {
  color: var(--text-muted);
  font-size: 11px;
}

.brand-booking-code {
  color: var(--admin-primary);
  font-size: 17px !important;
  font-weight: 600;
}
</style>
