<template>
  <div v-if="booking" class="booking-detail">
    <section class="booking-detail__status">
      <div><StatusPill :status="booking.status" /><h1>{{ service.name }}</h1></div>
      <span>{{ booking.id }}</span>
    </section>
    <section class="booking-detail__info">
      <div><MapPin :size="18" aria-hidden="true" /><span>预约门店</span><strong>{{ store.name }}</strong></div>
      <div><CalendarDays :size="18" aria-hidden="true" /><span>预约日期</span><strong>{{ booking.date }}</strong></div>
      <div><Clock3 :size="18" aria-hidden="true" /><span>预约时段</span><strong>{{ booking.start }}-{{ booking.end }}</strong></div>
      <div><UserRound :size="18" aria-hidden="true" /><span>预约人</span><strong>{{ booking.contactName }}　{{ booking.phone }}</strong></div>
    </section>
    <section class="booking-detail__settlement">
      <header><WalletCards :size="18" aria-hidden="true" /><h2>到店结算</h2></header>
      <div v-if="booking.memberSettlement" class="booking-detail__settled">
        <strong>项目已结算</strong>
        <span>{{ booking.memberSettlement.paymentSource === 'principal' ? '储值本金' : '赠送积分' }} · {{ settlementAmount }}</span>
      </div>
      <div v-else class="booking-detail__settlement-options">
        <p><strong>储值本金享6.6折</strong><span>门店核销后按会员价扣除本金</span></p>
        <p><strong>赠送积分按原价</strong><span>1元原价对应1积分，不享折扣</span></p>
        <small>到店服务完成后，由门店工作人员选择支付来源并确认结算。</small>
      </div>
    </section>
    <section v-if="showQr" class="booking-detail__qr">
      <QrCode
        :value="qrValue"
        label="预约核销二维码（演示数据）"
        :download-name="`${booking.id}.png`"
      />
      <div class="verify-code"><span>备用核销编号</span><strong data-testid="verify-code">{{ booking.verifyCode }}</strong></div>
      <p>二维码为演示数据；无摄像头时可向门店提供备用编号。</p>
    </section>
    <section v-else class="booking-detail__closed">
      当前预约状态为“{{ statusText }}”，核销码已停止使用。
    </section>
    <button v-if="booking.status === 'pending'" type="button" class="cancel-booking" @click="cancel">
      取消预约
    </button>
  </div>
</template>

<script>
import {
  CalendarDays,
  Clock3,
  MapPin,
  UserRound,
  WalletCards,
} from 'lucide-vue';
import StatusPill from '../../components/customer/StatusPill.vue';
import QrCode from '../../components/qr/QrCode.vue';
import { demoStore } from '../../mock/store';
import { cancelBooking } from '../../mock/transitions';
import { buildBusinessUrl } from '../../qr/codec';
import { formatMoney } from '../../mock/selectors';

const labels = {
  pending: '待到店',
  verified: '已核销',
  cancelled: '已取消',
  expired: '已过期',
};

export default {
  name: 'CustomerBookingDetail',
  components: {
    CalendarDays,
    Clock3,
    MapPin,
    QrCode,
    StatusPill,
    UserRound,
    WalletCards,
  },
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
    statusText() {
      return labels[this.booking.status] || this.booking.status;
    },
    showQr() {
      return ['pending', 'verified'].includes(this.booking.status);
    },
    qrValue() {
      return buildBusinessUrl(window.location.href, {
        type: 'booking',
        id: this.booking.id,
        storeId: this.booking.storeId,
        issuedAt: Math.floor(new Date(demoStore.state.data.demoNow).getTime() / 1000),
      });
    },
    settlementAmount() {
      const result = this.booking.memberSettlement;
      return result.paymentSource === 'principal' ? formatMoney(result.principalCents) : `${result.points}分`;
    },
  },
  methods: {
    async cancel() {
      try {
        await this.$confirm('取消后将释放当前预约时段。', '取消预约', {
          confirmButtonText: '确认取消',
          cancelButtonText: '保留预约',
          type: 'warning',
        });
        demoStore.commit((draft) => {
          cancelBooking(draft, this.booking.id);
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
.booking-detail {
  display: grid;
  gap: 10px;
}

.booking-detail > section,
.cancel-booking {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.booking-detail__status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 15px;
}

.booking-detail__status div {
  display: flex;
  align-items: center;
  gap: 9px;
}

.booking-detail__status h1 {
  margin: 0;
  font-size: 16px;
}

.booking-detail__status > span {
  color: var(--text-muted);
  font-size: 10px;
}

.booking-detail__info {
  display: grid;
  gap: 10px;
  padding: 14px;
}

.booking-detail__info div {
  display: grid;
  grid-template-columns: 22px 70px minmax(0, 1fr);
  align-items: center;
  gap: 6px;
}

.booking-detail__info svg {
  color: var(--brand-primary);
}

.booking-detail__info span,
.booking-detail__info strong {
  font-size: 12px;
}

.booking-detail__info span {
  color: var(--text-muted);
}

.booking-detail__info strong {
  text-align: right;
}

.booking-detail__qr {
  padding: 16px;
}

.booking-detail__settlement { padding: 14px; }
.booking-detail__settlement header { display: flex; align-items: center; gap: 7px; margin-bottom: 11px; color: var(--brand-primary); }
.booking-detail__settlement h2 { margin: 0; color: var(--text-primary); font-size: 14px; }
.booking-detail__settlement-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.booking-detail__settlement-options p { margin: 0; padding: 10px; background: var(--surface-subtle); border-radius: var(--radius-sm); }
.booking-detail__settlement-options strong, .booking-detail__settlement-options span { display: block; }
.booking-detail__settlement-options strong { font-size: 11px; }
.booking-detail__settlement-options span, .booking-detail__settlement-options small { color: var(--text-muted); font-size: 9px; }
.booking-detail__settlement-options > small { grid-column: 1 / -1; }
.booking-detail__settled { padding: 12px; color: var(--success); background: #f2faf5; border-radius: var(--radius-sm); }
.booking-detail__settled strong, .booking-detail__settled span { display: block; }
.booking-detail__settled span { margin-top: 2px; color: var(--text-regular); font-size: 11px; }

.verify-code {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-top: 12px;
}

.verify-code span {
  color: var(--text-muted);
  font-size: 11px;
}

.verify-code strong {
  color: var(--brand-primary);
  font-size: 20px;
}

.booking-detail__qr p,
.booking-detail__closed {
  color: var(--text-muted);
  text-align: center;
  font-size: 11px;
}

.booking-detail__closed {
  padding: 28px 14px;
}

.cancel-booking {
  min-height: 42px;
  color: var(--danger);
  cursor: pointer;
}
</style>
