<template>
  <div class="service-detail">
    <div v-if="service">
      <section class="service-detail__hero">
        <span class="service-detail__series">{{ service.series }}</span>
        <h1>{{ service.name }}</h1>
        <p>{{ service.description }}</p>
        <div class="service-detail__facts">
          <span><Clock3 :size="14" aria-hidden="true" />{{ service.durationMinutes }} 分钟</span>
          <span><MapPin :size="14" aria-hidden="true" />{{ store.name }}</span>
        </div>
      </section>

      <section class="service-detail__prices" aria-label="服务价格">
        <header><Tag :size="16" aria-hidden="true" /><h2>价格方案</h2></header>
        <div class="price-cards">
          <div v-if="service.priceModel === 'quote'" class="price-card price-card--quote">
            <span>定制起价</span>
            <strong>{{ formatMoney(service.unitPriceCents) }} 起</strong>
            <small>到店评估后按方案报价</small>
          </div>
          <template v-else>
            <div class="price-card">
              <span>单次体验</span>
              <strong>{{ formatMoney(service.unitPriceCents) }}</strong>
              <small>{{ service.durationMinutes }} 分钟 / 次</small>
            </div>
            <div v-if="memberPricing.discountRate < 100" class="price-card price-card--member">
              <span>会员本金价<b>6.6折</b></span>
              <strong>{{ formatMoney(memberPricing.principalCents) }}</strong>
              <small>到店核销后使用储值本金结算</small>
            </div>
            <div v-if="service.packagePriceCents" class="price-card price-card--package">
              <span>疗程方案<b>更划算</b></span>
              <strong>{{ formatMoney(service.packagePriceCents) }}</strong>
              <small>{{ service.packageCount }}{{ service.packageUnit }} · 到店分次使用</small>
            </div>
          </template>
        </div>
      </section>

      <section class="service-detail__booking" aria-label="预约状态">
        <header><CalendarDays :size="16" aria-hidden="true" /><h2>预约到店</h2></header>
        <div v-if="availability.hasOnlineBooking">
          <strong class="service-detail__available">当前可在线预约</strong>
          <span>本店剩余可约 {{ availability.remainingTotal }} 个名额</span>
        </div>
        <div v-else>
          <strong>暂未开放线上预约</strong>
          <span>可到店或致电门店咨询</span>
        </div>
      </section>

      <div class="service-detail__actions">
        <router-link
          :to="bookingPath"
          :class="{ 'is-disabled': !availability.hasOnlineBooking }"
          class="primary-shop-button"
          :aria-disabled="!availability.hasOnlineBooking"
        >
          <Check :size="17" aria-hidden="true" />{{ bookingLabel }}
        </router-link>
      </div>
    </div>
    <div v-else class="service-detail__missing">服务项目不存在或已下架</div>
  </div>
</template>

<script>
import { CalendarDays, Check, Clock3, MapPin, Tag } from 'lucide-vue';
import { formatMoney, serviceAvailability, serviceForStoreById } from '../../mock/selectors';
import { calculateServiceSettlement } from '../../mock/memberSelectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerServiceDetail',
  components: { CalendarDays, Check, Clock3, MapPin, Tag },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    service() {
      return serviceForStoreById(
        this.state,
        this.$route.params.storeId,
        this.$route.params.serviceId,
      );
    },
    availability() {
      if (!this.service) {
        return { hasOnlineBooking: false, remainingTotal: 0 };
      }
      return serviceAvailability(this.state, this.store.id, this.service.id);
    },
    memberPricing() {
      if (!this.service) return { discountRate: 100, principalCents: 0 };
      return calculateServiceSettlement(this.state, {
        customerId: this.state.activeCustomerId,
        originalCents: this.service.unitPriceCents,
        paymentSource: 'principal',
      });
    },
    bookingPath() {
      return `/c/store/${this.store.id}/booking?serviceId=${this.service.id}`;
    },
    bookingLabel() {
      if (this.service.priceModel === 'quote') {
        return this.availability.hasOnlineBooking ? '预约到店定制' : '到店咨询';
      }
      return this.availability.hasOnlineBooking ? '立即预约' : '暂不可预约';
    },
  },
  methods: { formatMoney },
};
</script>

<style scoped>
.service-detail {
  display: grid;
  gap: 12px;
}

.service-detail__hero,
.service-detail__prices,
.service-detail__booking {
  padding: 15px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.service-detail__series {
  display: inline-block;
  margin-bottom: 8px;
  padding: 2px 9px;
  color: var(--brand-primary);
  background: #fff5f4;
  border: 1px solid #f3cbc7;
  border-radius: 999px;
  font-size: 11px;
}

.service-detail__hero h1 {
  margin: 0 0 7px;
  font-size: 19px;
}

.service-detail__hero p {
  margin: 0 0 12px;
  color: var(--text-regular);
  font-size: 13px;
  line-height: 1.6;
}

.service-detail__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  color: var(--text-muted);
  font-size: 12px;
}

.service-detail__facts span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.service-detail__prices header,
.service-detail__booking header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 12px;
  color: var(--brand-primary);
}

.service-detail__prices h2,
.service-detail__booking h2 {
  margin: 0;
  color: var(--text-primary);
  font-size: 14px;
}

.price-cards {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.price-card {
  padding: 13px;
  background: var(--surface-subtle);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.price-card span {
  display: block;
  margin-bottom: 7px;
  color: var(--text-muted);
  font-size: 11px;
}

.price-card span b {
  margin-left: 6px;
  padding: 1px 5px;
  color: #b7791f;
  background: #fdf3e0;
  border-radius: 999px;
  font-size: 9px;
}

.price-card strong {
  display: block;
  color: var(--brand-primary);
  font-size: 20px;
}

.price-card small {
  display: block;
  margin-top: 4px;
  color: var(--text-muted);
  font-size: 10px;
}

.price-card--package {
  border-color: #e8c68a;
  background: #fffaf0;
}

.price-card--member {
  border-color: #b9d9c7;
  background: #f3faf6;
}

.price-card--quote {
  grid-column: 1 / -1;
  text-align: center;
}

.service-detail__booking strong,
.service-detail__booking span {
  display: block;
}

.service-detail__booking strong {
  font-size: 13px;
}

.service-detail__available {
  color: #2f7d4f;
}

.service-detail__booking span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 11px;
}

.service-detail__actions {
  position: sticky;
  bottom: 0;
  padding: 10px 0 2px;
}

.service-detail__actions .primary-shop-button {
  display: flex;
  width: 100%;
  min-height: 46px;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.service-detail__actions a.is-disabled {
  pointer-events: none;
  opacity: 0.55;
}

.service-detail__missing {
  padding: 30px;
  color: var(--text-muted);
  text-align: center;
}
</style>
