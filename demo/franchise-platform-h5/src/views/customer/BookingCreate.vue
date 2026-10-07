<template>
  <div class="booking-create">
    <section v-if="$route.query.sourceAssessmentId" class="reassessment-context">
      <RefreshCw :size="19" aria-hidden="true" />
      <div><strong>复评预约</strong><span>已按检测报告预选体态健康评估</span></div>
    </section>
    <section>
      <header><Activity :size="19" aria-hidden="true" /><h1>选择服务</h1></header>
      <div class="service-options">
        <button
          v-for="service in services"
          :key="service.id"
          type="button"
          :class="{ 'is-active': selectedServiceId === service.id }"
          @click="selectService(service.id)"
        >
          <strong>{{ service.name }}</strong>
          <span>{{ service.series }} · {{ service.durationMinutes }} 分钟</span>
          <small>{{ service.description }}</small>
          <b>{{ service.priceModel === 'quote' ? formatMoney(service.unitPriceCents) + ' 起' : formatMoney(service.unitPriceCents) }}</b>
        </button>
      </div>
    </section>

    <section>
      <header><CalendarDays :size="19" aria-hidden="true" /><h1>选择日期</h1></header>
      <div v-if="dates.length" class="date-options">
        <button
          v-for="date in dates"
          :key="date"
          type="button"
          :data-testid="`booking-date-${date}`"
          :class="{ 'is-active': selectedDate === date }"
          @click="selectDate(date)"
        >
          <span>{{ displayDate(date) }}</span>
          <strong>{{ weekday(date) }}</strong>
        </button>
      </div>
      <p v-else class="booking-empty">当前服务暂无可预约日期</p>
    </section>

    <section>
      <header><Clock3 :size="19" aria-hidden="true" /><h1>选择时段</h1></header>
      <div v-if="visibleSlots.length" class="slot-options">
        <button
          v-for="slot in visibleSlots"
          :key="slot.id"
          type="button"
          :data-testid="`booking-slot-${slot.id}`"
          :disabled="remaining(slot) === 0"
          :class="{ 'is-active': selectedSlotId === slot.id }"
          @click="selectedSlotId = slot.id"
        >
          <strong>{{ slot.start }}-{{ slot.end }}</strong>
          <span>{{ remaining(slot) ? `剩余 ${remaining(slot)} 位` : '已满' }}</span>
        </button>
      </div>
      <p v-else class="booking-empty">当前日期暂无可预约时段</p>
    </section>

    <section class="booking-contact">
      <header><UserRound :size="19" aria-hidden="true" /><h1>预约联系人</h1></header>
      <label>姓名<input v-model="contactName" type="text" aria-label="预约人姓名"></label>
      <label>手机<input v-model="phone" type="text" aria-label="预约人手机" readonly></label>
    </section>

    <div class="booking-submit">
      <div><span>预约门店</span><strong>{{ store.name }}</strong></div>
      <button type="button" :disabled="!canSubmit" @click="submitBooking">
        <Check :size="17" aria-hidden="true" />确认预约
      </button>
    </div>
  </div>
</template>

<script>
import {
  Activity,
  CalendarDays,
  Check,
  Clock3,
  UserRound,
  RefreshCw,
} from 'lucide-vue';
import { formatMoney } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { servicesForStore } from '../../mock/selectors';
import { createBooking } from '../../mock/transitions';

export default {
  name: 'CustomerBookingCreate',
  components: {
    Activity,
    CalendarDays,
    Check,
    Clock3,
    UserRound,
    RefreshCw,
  },
  data() {
    return {
      contactName: '陈女士',
      phone: '138****2046',
      selectedDate: '',
      selectedServiceId: 'SV1001',
      selectedSlotId: '',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    services() {
      return servicesForStore(this.state, this.store.id);
    },
    serviceSlots() {
      return this.state.slotInventory.filter(
        (item) => item.storeId === this.store.id && item.serviceId === this.selectedServiceId,
      );
    },
    dates() {
      return [...new Set(this.serviceSlots.map((item) => item.date))];
    },
    visibleSlots() {
      return this.serviceSlots.filter((item) => item.date === this.selectedDate);
    },
    selectedSlot() {
      return this.visibleSlots.find((item) => item.id === this.selectedSlotId);
    },
    canSubmit() {
      return Boolean(
        this.selectedSlot
        && this.remaining(this.selectedSlot) > 0
        && this.contactName.trim(),
      );
    },
  },
  created() {
    const customer = this.state.customers.find((item) => item.id === this.state.activeCustomerId);
    if (customer) {
      this.contactName = customer.name;
      this.phone = customer.phone;
    }
    const requestedService = this.$route.query.serviceId;
    if (this.services.some((item) => item.id === requestedService)) {
      this.selectedServiceId = requestedService;
    } else if (!this.services.some((item) => item.id === this.selectedServiceId)) {
      this.selectedServiceId = this.services[0]?.id || '';
    }
    this.resetSelection();
  },
  methods: {
    formatMoney,
    remaining(slot) {
      return Math.max(0, slot.capacity - slot.booked);
    },
    displayDate(date) {
      return date.slice(5).replace('-', '月') + '日';
    },
    weekday(date) {
      return ['周日', '周一', '周二', '周三', '周四', '周五', '周六'][
        new Date(`${date}T12:00:00+08:00`).getDay()
      ];
    },
    resetSelection() {
      this.selectedDate = this.dates[0] || '';
      this.selectedSlotId = this.visibleSlots.find((item) => this.remaining(item) > 0)?.id || '';
    },
    selectService(serviceId) {
      this.selectedServiceId = serviceId;
      this.$nextTick(this.resetSelection);
    },
    selectDate(date) {
      this.selectedDate = date;
      this.selectedSlotId = this.visibleSlots.find((item) => this.remaining(item) > 0)?.id || '';
    },
    submitBooking() {
      if (!this.canSubmit) {
        return;
      }
      try {
        let booking;
        demoStore.commit((draft) => {
          booking = createBooking(draft, {
            slotId: this.selectedSlotId,
            contactName: this.contactName.trim(),
            phone: this.phone,
            sourceAssessmentId: this.$route.query.sourceAssessmentId || null,
          });
        });
        this.$router.push(
          `/c/store/${booking.storeId}/booking-success/${booking.id}`,
        );
      } catch (error) {
        this.$message.error(error.message);
      }
    },
  },
};
</script>

<style scoped>
.booking-create {
  display: grid;
  gap: 10px;
}

.booking-create > section,
.booking-submit {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.reassessment-context {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  color: var(--brand-primary);
  background: #fff5f4 !important;
  border-color: #f3cbc7 !important;
}

.reassessment-context strong,
.reassessment-context span { display: block; }
.reassessment-context strong { font-size: 13px; }
.reassessment-context span { margin-top: 2px; color: var(--text-muted); font-size: 10px; }

.booking-create section > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 11px;
  color: var(--brand-primary);
}

.booking-create h1 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.service-options,
.slot-options,
.date-options {
  display: grid;
  gap: 8px;
}

.service-options {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.service-options button,
.slot-options button,
.date-options button {
  min-width: 0;
  padding: 10px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.service-options button.is-active,
.slot-options button.is-active,
.date-options button.is-active {
  color: var(--brand-primary);
  background: #fff5f4;
  border-color: var(--brand-primary);
}

.service-options b,
.service-options strong,
.service-options span,
.service-options small,
.slot-options strong,
.slot-options span,
.date-options span,
.date-options strong {
  display: block;
}

.service-options b {
  margin-top: 5px;
  color: var(--brand-primary);
  font-size: 13px;
}

.service-options strong {
  font-size: 12px;
}

.service-options span,
.service-options small {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 10px;
}

.service-options small {
  display: none;
}

.date-options {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.date-options span {
  font-size: 12px;
}

.date-options strong {
  font-size: 11px;
}

.slot-options {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.slot-options strong {
  font-size: 13px;
}

.slot-options span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 10px;
}

.slot-options button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.booking-empty {
  margin: 0;
  padding: 14px;
  color: var(--text-muted);
  background: var(--surface-subtle);
  text-align: center;
  font-size: 12px;
}

.booking-contact label {
  display: grid;
  min-height: 42px;
  grid-template-columns: 50px minmax(0, 1fr);
  align-items: center;
  color: var(--text-regular);
  border-top: 1px solid var(--border);
  font-size: 12px;
}

.booking-contact input {
  width: 100%;
  min-width: 0;
  height: 36px;
  text-align: right;
  background: transparent;
  border: 0;
  outline: 0;
}

.booking-submit {
  position: sticky;
  z-index: 20;
  bottom: calc(60px + env(safe-area-inset-bottom));
  display: grid;
  grid-template-columns: minmax(0, 1fr) 132px;
  align-items: center;
  padding: 10px 10px 10px 14px;
}

.booking-submit span,
.booking-submit strong {
  display: block;
}

.booking-submit span {
  color: var(--text-muted);
  font-size: 10px;
}

.booking-submit strong {
  font-size: 13px;
}

.booking-submit button {
  display: flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  cursor: pointer;
}

.booking-submit button:disabled {
  background: #c8c9cc;
  cursor: not-allowed;
}
</style>
