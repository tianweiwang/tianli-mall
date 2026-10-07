<template>
  <span class="status-pill" :class="`status-pill--${status}`">
    {{ label }}
  </span>
</template>

<script>
const labels = {
  pending: '待到店',
  verified: '已核销',
  cancelled: '已取消',
  expired: '已过期',
};

export default {
  name: 'StatusPill',
  props: {
    status: {
      type: String,
      required: true,
    },
  },
  computed: {
    label() {
      return labels[this.status] || this.status;
    },
  },
};
</script>

<style scoped>
.status-pill {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  padding: 0 8px;
  color: var(--warning);
  background: #fff7e6;
  border-radius: 12px;
  font-size: 11px;
  white-space: nowrap;
}

.status-pill--verified {
  color: var(--success);
  background: #ecf9f2;
}

.status-pill--cancelled,
.status-pill--expired {
  color: var(--text-muted);
  background: var(--surface-subtle);
}
</style>
