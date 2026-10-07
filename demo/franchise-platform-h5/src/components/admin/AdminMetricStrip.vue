<template>
  <section class="admin-metric-strip" aria-label="经营指标">
    <div v-for="item in items" :key="item.label" class="admin-metric-strip__item">
      <div :class="['admin-metric-strip__icon', `is-${item.tone || 'primary'}`]">
        <component :is="item.icon" :size="20" aria-hidden="true" />
      </div>
      <div>
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
        <small v-if="item.help">{{ item.help }}</small>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  name: 'AdminMetricStrip',
  props: {
    items: {
      type: Array,
      required: true,
    },
  },
};
</script>

<style scoped>
.admin-metric-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.admin-metric-strip__item {
  display: grid;
  min-width: 0;
  min-height: 112px;
  grid-template-columns: 42px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  padding: 18px 20px;
  border-right: 1px solid var(--border);
}

.admin-metric-strip__item:last-child {
  border-right: 0;
}

.admin-metric-strip__icon {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  color: var(--admin-primary);
  background: #edf5ff;
  border-radius: var(--radius-md);
}

.admin-metric-strip__icon.is-success {
  color: var(--success);
  background: #edf9f2;
}

.admin-metric-strip__icon.is-warning {
  color: #bf7410;
  background: #fff7e9;
}

.admin-metric-strip__icon.is-danger {
  color: var(--danger);
  background: #fff0ef;
}

.admin-metric-strip span,
.admin-metric-strip strong,
.admin-metric-strip small {
  display: block;
}

.admin-metric-strip span {
  color: var(--text-muted);
  font-size: 12px;
}

.admin-metric-strip strong {
  margin-top: 2px;
  overflow: hidden;
  font-size: 23px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-metric-strip small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 11px;
}
</style>
