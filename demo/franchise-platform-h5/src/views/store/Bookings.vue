<template>
  <div class="admin-page">
    <section class="admin-filter-bar" aria-label="预约筛选">
      <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="预约编号、姓名或手机号" prefix-icon="el-icon-search" />
      <el-select v-model="status" aria-label="预约状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-date-picker v-model="date" type="date" value-format="yyyy-MM-dd" placeholder="预约日期" clearable />
      <span class="admin-filter-bar__count">共 {{ visibleBookings.length }} 条</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleBookings" style="width: 100%" empty-text="暂无符合条件的预约">
        <el-table-column prop="id" label="预约编号" min-width="165" />
        <el-table-column label="预约人" width="145">
          <template slot-scope="{ row }"><strong>{{ row.contactName }}</strong><br><span class="booking-muted">{{ row.phone }}</span></template>
        </el-table-column>
        <el-table-column label="服务项目" min-width="165">
          <template slot-scope="{ row }">{{ serviceName(row.serviceId) }}</template>
        </el-table-column>
        <el-table-column label="预约日期" width="120" prop="date" />
        <el-table-column label="预约时段" width="130">
          <template slot-scope="{ row }">{{ row.start }}-{{ row.end }}</template>
        </el-table-column>
        <el-table-column label="备用编号" width="105" prop="verifyCode" />
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button></template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer title="预约详情" :visible.sync="drawerOpen" size="440px">
      <div v-if="selectedBooking" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>预约信息</h3>
          <dl class="admin-detail-list">
            <div><dt>预约编号</dt><dd>{{ selectedBooking.id }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedBooking.status" :text="statusText(selectedBooking.status)" /></dd></div>
            <div><dt>服务项目</dt><dd>{{ serviceName(selectedBooking.serviceId) }}</dd></div>
            <div><dt>到店时间</dt><dd>{{ selectedBooking.date }} {{ selectedBooking.start }}-{{ selectedBooking.end }}</dd></div>
            <div><dt>所属门店</dt><dd>{{ store.name }}</dd></div>
          </dl>
        </section>
        <section class="admin-detail-section">
          <h3>联系信息</h3>
          <dl class="admin-detail-list">
            <div><dt>预约人</dt><dd>{{ selectedBooking.contactName }}</dd></div>
            <div><dt>手机</dt><dd>{{ selectedBooking.phone }}</dd></div>
            <div><dt>备用核销编号</dt><dd class="booking-code">{{ selectedBooking.verifyCode }}</dd></div>
          </dl>
        </section>
        <section v-if="selectedBooking.verifiedAt" class="admin-detail-section">
          <h3>核销记录</h3>
          <dl class="admin-detail-list"><div><dt>核销时间</dt><dd>{{ formatTime(selectedBooking.verifiedAt) }}</dd></div></dl>
        </section>
        <MemberSettlementPanel
          v-if="selectedBooking.customerId && (selectedBooking.status === 'verified' || selectedBooking.memberSettlement)"
          :booking-id="selectedBooking.id"
          :store-id="store.id"
        />
      </div>
    </el-drawer>
  </div>
</template>

<script>
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import MemberSettlementPanel from '../../components/store/MemberSettlementPanel.vue';
import { demoStore } from '../../mock/store';
import { activeStore, bookingsForStore } from '../../mock/selectors';

const statusLabels = {
  pending: '待到店',
  verified: '已核销',
  cancelled: '已取消',
  expired: '已过期',
};

export default {
  name: 'StoreBookings',
  components: { AdminStatusTag, MemberSettlementPanel },
  data() {
    return {
      date: '',
      drawerOpen: false,
      keyword: '',
      selectedBooking: null,
      status: 'all',
      statusOptions: [
        { label: '待到店', value: 'pending' },
        { label: '已核销', value: 'verified' },
        { label: '已取消', value: 'cancelled' },
        { label: '已过期', value: 'expired' },
      ],
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    storeBookings() {
      return bookingsForStore(this.state, this.store.id);
    },
    visibleBookings() {
      const keyword = this.keyword.toLowerCase();
      return this.storeBookings.filter((booking) => {
        const matchesStatus = this.status === 'all' || booking.status === this.status;
        const matchesDate = !this.date || booking.date === this.date;
        const searchable = `${booking.id} ${booking.contactName} ${booking.phone}`.toLowerCase();
        return matchesStatus && matchesDate && (!keyword || searchable.includes(keyword));
      });
    },
  },
  created() {
    this.openRequestedRecord();
  },
  watch: {
    '$route.query.recordId'() {
      this.openRequestedRecord();
    },
  },
  methods: {
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    openDetail(booking) {
      this.selectedBooking = booking;
      this.drawerOpen = true;
    },
    openRequestedRecord() {
      const recordId = this.$route?.query?.recordId;
      const booking = recordId && this.storeBookings.find((item) => item.id === recordId);
      if (booking) this.openDetail(booking);
    },
    serviceName(serviceId) {
      return this.state.services.find((item) => item.id === serviceId)?.name || serviceId;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
  },
};
</script>

<style scoped>
.booking-muted {
  color: var(--text-muted);
  font-size: 11px;
}

.booking-code {
  color: var(--admin-primary);
  font-size: 17px !important;
  font-weight: 600;
}
</style>
