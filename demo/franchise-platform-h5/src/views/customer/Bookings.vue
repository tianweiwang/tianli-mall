<template>
  <div class="customer-bookings">
    <nav aria-label="预约状态筛选">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        type="button"
        :class="{ 'is-active': activeStatus === tab.value }"
        @click="activeStatus = tab.value"
      >{{ tab.label }}</button>
    </nav>
    <section v-if="visibleBookings.length" class="booking-list">
      <article v-for="booking in visibleBookings" :key="booking.id" :data-testid="`booking-row-${booking.id}`">
        <header>
          <span>{{ booking.id }}</span>
          <StatusPill :status="booking.status" />
        </header>
        <div class="booking-list__body">
          <CalendarClock :size="23" aria-hidden="true" />
          <div>
            <h1>{{ serviceName(booking.serviceId) }}</h1>
            <p>{{ booking.date }}　{{ booking.start }}-{{ booking.end }}</p>
            <span>{{ storeName(booking.storeId) }}</span>
          </div>
        </div>
        <footer>
          <button v-if="booking.status === 'pending'" type="button" @click="cancel(booking)">取消预约</button>
          <router-link :to="detailPath(booking)">查看详情</router-link>
        </footer>
      </article>
    </section>
    <section v-else class="bookings-empty">
      <CalendarX :size="36" aria-hidden="true" />
      <span>暂无该状态预约</span>
    </section>
  </div>
</template>

<script>
import { CalendarClock, CalendarX } from 'lucide-vue';
import StatusPill from '../../components/customer/StatusPill.vue';
import { demoStore } from '../../mock/store';
import { cancelBooking } from '../../mock/transitions';

export default {
  name: 'CustomerBookings',
  components: { CalendarClock, CalendarX, StatusPill },
  data() {
    return {
      activeStatus: 'pending',
      tabs: [
        { label: '待到店', value: 'pending' },
        { label: '已核销', value: 'verified' },
        { label: '已取消', value: 'cancelled' },
        { label: '已过期', value: 'expired' },
      ],
    };
  },
  computed: {
    visibleBookings() {
      return demoStore.state.data.bookings.filter((item) => item.status === this.activeStatus);
    },
  },
  methods: {
    serviceName(serviceId) {
      return demoStore.state.data.services.find((item) => item.id === serviceId)?.name;
    },
    storeName(storeId) {
      return demoStore.state.data.stores.find((item) => item.id === storeId)?.name;
    },
    detailPath(booking) {
      return `/c/store/${this.$route.params.storeId}/bookings/${booking.id}`;
    },
    async cancel(booking) {
      try {
        await this.$confirm('取消后将释放当前预约时段。', '取消预约', {
          confirmButtonText: '确认取消',
          cancelButtonText: '保留预约',
          type: 'warning',
        });
        demoStore.commit((draft) => {
          cancelBooking(draft, booking.id);
        });
        this.$message.success('预约已取消');
      } catch (_error) {
        // Cancel keeps the booking unchanged.
      }
    },
  },
};
</script>

<style scoped>
.customer-bookings {
  display: grid;
  gap: 10px;
}

.customer-bookings > nav {
  display: grid;
  height: 42px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.customer-bookings nav button {
  min-width: 0;
  color: var(--text-regular);
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  font-size: 11px;
  cursor: pointer;
}

.customer-bookings nav button.is-active {
  color: var(--brand-primary);
  border-bottom-color: var(--brand-primary);
  font-weight: 600;
}

.booking-list {
  display: grid;
  gap: 10px;
}

.booking-list article,
.bookings-empty {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.booking-list header,
.booking-list footer {
  display: flex;
  min-height: 42px;
  align-items: center;
  justify-content: space-between;
  padding: 0 13px;
}

.booking-list header {
  border-bottom: 1px solid var(--border);
}

.booking-list header > span {
  color: var(--text-muted);
  font-size: 11px;
}

.booking-list__body {
  display: grid;
  grid-template-columns: 30px minmax(0, 1fr);
  gap: 9px;
  padding: 14px 13px;
  color: var(--brand-primary);
}

.booking-list__body h1,
.booking-list__body p {
  margin: 0;
}

.booking-list__body h1 {
  color: var(--text-primary);
  font-size: 14px;
}

.booking-list__body p,
.booking-list__body span {
  color: var(--text-regular);
  font-size: 11px;
}

.booking-list__body span {
  color: var(--text-muted);
}

.booking-list footer {
  justify-content: flex-end;
  gap: 8px;
  border-top: 1px solid var(--border);
}

.booking-list footer button,
.booking-list footer a {
  display: grid;
  min-width: 78px;
  min-height: 31px;
  padding: 0 9px;
  place-items: center;
  color: var(--text-regular);
  background: transparent;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  font-size: 11px;
  cursor: pointer;
}

.booking-list footer a {
  color: var(--brand-primary);
  border-color: var(--brand-primary);
}

.bookings-empty {
  display: grid;
  min-height: 260px;
  align-content: center;
  justify-items: center;
  gap: 8px;
  color: var(--text-muted);
}
</style>
