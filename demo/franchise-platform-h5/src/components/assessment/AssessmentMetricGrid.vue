<template>
  <section :class="['assessment-metrics', { 'is-compact': compact }]" aria-label="体态记录指标">
    <article v-for="metric in metrics" :key="metric.key" class="assessment-metric">
      <span class="assessment-metric__label">{{ metric.label }}</span>
      <strong>{{ metric.current }} <small>{{ metric.unit }}</small></strong>
      <div :class="['assessment-metric__status', `is-${metric.status}`]">
        {{ statusLabel(metric.status) }}
      </div>
      <dl>
        <div>
          <dt>较上次</dt>
          <dd>{{ formatDelta(metric.previousDelta) }} {{ metric.unit }}</dd>
        </div>
        <div>
          <dt>较首次</dt>
          <dd>{{ formatDelta(metric.firstDelta) }} {{ metric.unit }}</dd>
        </div>
      </dl>
    </article>
  </section>
</template>

<script>
export default {
  name: 'AssessmentMetricGrid',
  props: {
    metrics: {
      type: Array,
      required: true,
    },
    compact: {
      type: Boolean,
      default: false,
    },
  },
  methods: {
    formatDelta(value) {
      return Number(value) > 0 ? `+${value}` : String(value);
    },
    statusLabel(status) {
      return {
        improvement: '改善',
        stable: '持平',
        fluctuation: '波动',
      }[status] || '记录';
    },
  },
};
</script>

<style scoped>
.assessment-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.assessment-metric {
  min-width: 0;
  padding: 16px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.assessment-metric__label {
  display: block;
  color: var(--text-muted);
  font-size: 13px;
}

.assessment-metric strong {
  display: block;
  margin-top: 8px;
  font-size: 24px;
  font-weight: 650;
  letter-spacing: 0;
}

.assessment-metric strong small {
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 500;
}

.assessment-metric__status {
  width: fit-content;
  margin-top: 8px;
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 600;
}

.assessment-metric__status.is-improvement { color: var(--success); }
.assessment-metric__status.is-fluctuation { color: #b66a08; }

.assessment-metric dl {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin: 14px 0 0;
}

.assessment-metric dl div {
  min-width: 0;
  padding-top: 8px;
  border-top: 1px solid var(--border);
}

.assessment-metric dt,
.assessment-metric dd {
  margin: 0;
  font-size: 11px;
}

.assessment-metric dt { color: var(--text-muted); }
.assessment-metric dd { margin-top: 3px; font-weight: 600; }

.assessment-metrics.is-compact .assessment-metric { padding: 12px; }
.assessment-metrics.is-compact .assessment-metric strong { font-size: 20px; }

@media (max-width: 900px) {
  .assessment-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 520px) {
  .assessment-metrics { grid-template-columns: minmax(0, 1fr); }
}
</style>
