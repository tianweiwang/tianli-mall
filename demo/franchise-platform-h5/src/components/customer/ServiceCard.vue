<template>
  <article class="service-card" :data-testid="`service-${service.id}`">
    <router-link :to="servicePath" class="service-card__link">
      <span class="service-card__series">{{ service.series }}</span>
      <h3>{{ service.name }}</h3>
      <p class="service-card__meta"><Clock3 :size="13" aria-hidden="true" />{{ service.durationMinutes }} 分钟</p>
    </router-link>
    <div class="service-card__footer">
      <div class="service-card__prices">
        <strong v-if="service.priceModel === 'quote'">{{ formatMoney(service.unitPriceCents) }} 起</strong>
        <template v-else>
          <strong>{{ formatMoney(service.unitPriceCents) }}</strong>
          <span v-if="service.packagePriceCents" class="service-card__package">
            疗程 {{ formatMoney(service.packagePriceCents) }}/{{ service.packageCount }}{{ service.packageUnit }}
          </span>
        </template>
      </div>
      <router-link :to="bookingPath" class="service-card__book">预约</router-link>
    </div>
  </article>
</template>

<script>
import { Clock3 } from 'lucide-vue';
import { formatMoney } from '../../mock/selectors';

export default {
  name: 'ServiceCard',
  components: { Clock3 },
  props: {
    service: { type: Object, required: true },
    storeId: { type: String, required: true },
  },
  computed: {
    servicePath() {
      return `/c/store/${this.storeId}/services/${this.service.id}`;
    },
    bookingPath() {
      return `/c/store/${this.storeId}/booking?serviceId=${this.service.id}`;
    },
  },
  methods: { formatMoney },
};
</script>

<style scoped>
.service-card {
  min-width: 0;
  padding: 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.service-card__series {
  display: inline-block;
  margin-bottom: 7px;
  padding: 2px 7px;
  color: var(--brand-primary);
  background: #fff5f4;
  border: 1px solid #f3cbc7;
  border-radius: 999px;
  font-size: 10px;
}

.service-card__link {
  display: block;
}

.service-card h3 {
  margin: 0 0 5px;
  font-size: 14px;
  font-weight: 600;
}

.service-card__meta {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0 0 10px;
  color: var(--text-muted);
  font-size: 11px;
}

.service-card__footer {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
}

.service-card__prices strong {
  display: block;
  color: var(--brand-primary);
  font-size: 16px;
}

.service-card__package {
  display: block;
  margin-top: 2px;
  color: var(--price-accent, #b7791f);
  font-size: 11px;
}

.service-card__book {
  flex: 0 0 auto;
  padding: 6px 12px;
  color: #fff;
  background: var(--brand-primary);
  border-radius: 999px;
  font-size: 12px;
  text-decoration: none;
  white-space: nowrap;
}
</style>
