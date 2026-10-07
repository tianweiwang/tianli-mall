<template>
  <div v-if="booking" class="booking-success">
    <section class="booking-success__heading">
      <div><Check :size="28" aria-hidden="true" /></div>
      <h1>预约成功</h1>
      <p>请按预约时间前往 {{ store.name }}</p>
      <span data-testid="booking-id">{{ booking.id }}</span>
    </section>
    <section class="booking-success__info">
      <div><span>预约服务</span><strong>{{ service.name }}</strong></div>
      <div><span>到店时间</span><strong>{{ booking.date }} {{ booking.start }}-{{ booking.end }}</strong></div>
      <div><span>备用编号</span><strong data-testid="verify-code">{{ booking.verifyCode }}</strong></div>
    </section>
    <section class="booking-qr-panel">
      <QrCode
        :value="qrValue"
        label="预约核销二维码（演示数据）"
        :download-name="`${booking.id}.png`"
      />
      <p>二维码为演示数据，到店后由门店端扫码核销。</p>
    </section>
    <div class="booking-success__actions">
      <router-link :to="detailPath">查看预约</router-link>
      <router-link :to="homePath">返回首页</router-link>
    </div>
  </div>
</template>

<script>
import { Check } from 'lucide-vue';
import QrCode from '../../components/qr/QrCode.vue';
import { demoStore } from '../../mock/store';
import { buildBusinessUrl } from '../../qr/codec';

export default {
  name: 'CustomerBookingSuccess',
  components: { Check, QrCode },
  computed: {
    booking() {
      return demoStore.state.data.bookings.find(
        (item) => item.id === this.$route.params.bookingId,
      );
    },
    store() {
      return demoStore.state.data.stores.find((item) => item.id === this.booking.storeId);
    },
    service() {
      return demoStore.state.data.services.find((item) => item.id === this.booking.serviceId);
    },
    qrValue() {
      return buildBusinessUrl(window.location.href, {
        type: 'booking',
        id: this.booking.id,
        storeId: this.booking.storeId,
        issuedAt: Math.floor(new Date(demoStore.state.data.demoNow).getTime() / 1000),
      });
    },
    detailPath() {
      return `/c/store/${this.$route.params.storeId}/bookings/${this.booking.id}`;
    },
    homePath() {
      return `/c/store/${this.$route.params.storeId}`;
    },
  },
};
</script>

<style scoped>
.booking-success {
  display: grid;
  gap: 10px;
}

.booking-success > section,
.booking-success__actions a {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.booking-success__heading {
  padding: 24px 18px 18px;
  text-align: center;
}

.booking-success__heading > div {
  display: grid;
  width: 52px;
  height: 52px;
  margin: 0 auto;
  place-items: center;
  color: #fff;
  background: var(--success);
  border-radius: 50%;
}

.booking-success__heading h1 {
  margin: 11px 0 3px;
  font-size: 20px;
}

.booking-success__heading p,
.booking-success__heading span {
  margin: 0;
  color: var(--text-muted);
  font-size: 11px;
}

.booking-success__heading span {
  display: block;
  margin-top: 8px;
}

.booking-success__info {
  display: grid;
  gap: 9px;
  padding: 14px;
}

.booking-success__info div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.booking-success__info span,
.booking-success__info strong {
  font-size: 12px;
}

.booking-success__info span {
  color: var(--text-muted);
}

.booking-success__info strong {
  text-align: right;
}

.booking-success__info div:last-child strong {
  color: var(--brand-primary);
  font-size: 18px;
}

.booking-qr-panel {
  padding: 16px;
}

.booking-qr-panel p {
  margin: 10px 0 0;
  color: var(--text-muted);
  text-align: center;
  font-size: 11px;
}

.booking-success__actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.booking-success__actions a {
  display: grid;
  min-height: 42px;
  place-items: center;
  color: var(--brand-primary);
  border-color: var(--brand-primary);
}

.booking-success__actions a:first-child {
  color: #fff;
  background: var(--brand-primary);
}
</style>
