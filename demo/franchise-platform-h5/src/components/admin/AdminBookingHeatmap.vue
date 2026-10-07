<template>
  <section class="admin-panel admin-booking-heatmap" aria-label="门店预约时段热力">
    <header class="admin-panel__header">
      <div>
        <h2>门店时段热力</h2>
        <p>格内显示已预约 / 容量，横轴按实际开放时段合并</p>
      </div>
      <div class="admin-booking-heatmap__legend" aria-label="热力状态图例">
        <span><i class="is-available" />充足</span>
        <span><i class="is-busy" />繁忙</span>
        <span><i class="is-full" />已满</span>
        <span><i class="is-off" />未开放</span>
      </div>
    </header>

    <div v-if="columns.length" class="admin-booking-heatmap__scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">门店</th>
            <th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th>
            <th scope="col">门店合计</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.storeId">
            <th scope="row">
              <strong>{{ row.storeName }}</strong>
              <span>{{ row.storeStatus === 'active' ? `营业中 · ${row.openHours}` : '已停用' }}</span>
            </th>
            <td v-for="cell in row.cells" :key="cell.key">
              <button
                v-if="cell.selectable"
                type="button"
                :class="[
                  'admin-booking-heatmap__cell',
                  `is-${cell.tone}`,
                  { 'is-selected': selectedKey === cell.key },
                ]"
                :data-testid="cell.testId"
                :aria-pressed="String(selectedKey === cell.key)"
                :aria-label="`${cell.storeName} ${cell.label} ${cell.booked}/${cell.capacity} ${cell.serviceLabel}`"
                @click="$emit('select', cell)"
              >
                <strong>{{ cell.booked }} / {{ cell.capacity }}</strong>
                <span>{{ cell.serviceLabel }}</span>
              </button>
              <div v-else class="admin-booking-heatmap__cell is-off">
                <strong>—</strong>
                <span>{{ row.storeStatus === 'active' ? '未开放' : '门店停约' }}</span>
              </div>
            </td>
            <td class="admin-booking-heatmap__summary">
              <strong>{{ row.bookingCount }} 条</strong>
              <span>到店率 {{ row.arrivalRate }}%</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="admin-empty">所选日期暂无开放时段</div>
  </section>
</template>

<script>
export default {
  name: 'AdminBookingHeatmap',
  props: {
    columns: {
      type: Array,
      required: true,
    },
    rows: {
      type: Array,
      required: true,
    },
    selectedKey: {
      type: String,
      default: '',
    },
  },
};
</script>

<style scoped>
.admin-booking-heatmap {
  overflow: hidden;
}

.admin-booking-heatmap__legend {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-muted);
  font-size: 11px;
}

.admin-booking-heatmap__legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.admin-booking-heatmap__legend i {
  width: 10px;
  height: 10px;
  border: 1px solid var(--border-strong);
  border-radius: 2px;
}

.admin-booking-heatmap__legend .is-available {
  background: #edf5ff;
  border-color: #bfdcff;
}

.admin-booking-heatmap__legend .is-busy {
  background: #cfe4ff;
  border-color: #8fc1ff;
}

.admin-booking-heatmap__legend .is-full {
  background: #fff3df;
  border-color: #efc27f;
}

.admin-booking-heatmap__legend .is-off {
  background: var(--surface-subtle);
}

.admin-booking-heatmap__scroll {
  overflow-x: auto;
}

table {
  width: 100%;
  min-width: 900px;
  border-spacing: 0;
  border-collapse: separate;
  table-layout: fixed;
}

th,
td {
  padding: 8px;
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  text-align: center;
}

thead th {
  height: 44px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  font-size: 11px;
  font-weight: 600;
}

thead th:first-child,
tbody th {
  position: sticky;
  z-index: 2;
  left: 0;
  width: 160px;
  background: var(--surface);
  text-align: left;
}

thead th:first-child {
  z-index: 3;
  background: var(--surface-subtle);
}

tbody th strong,
tbody th span,
.admin-booking-heatmap__cell strong,
.admin-booking-heatmap__cell span,
.admin-booking-heatmap__summary strong,
.admin-booking-heatmap__summary span {
  display: block;
}

tbody th strong {
  font-size: 12px;
}

tbody th span,
.admin-booking-heatmap__summary span {
  margin-top: 3px;
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 400;
}

.admin-booking-heatmap__cell {
  width: 100%;
  min-height: 58px;
  padding: 7px;
  color: var(--text-regular);
  background: var(--surface-subtle);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

button.admin-booking-heatmap__cell {
  cursor: pointer;
}

button.admin-booking-heatmap__cell:focus-visible {
  outline: 2px solid var(--admin-primary);
  outline-offset: 2px;
}

.admin-booking-heatmap__cell strong {
  font-size: 14px;
}

.admin-booking-heatmap__cell span {
  margin-top: 4px;
  overflow: hidden;
  font-size: 10px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-booking-heatmap__cell.is-available {
  color: #2367a7;
  background: #edf5ff;
  border-color: #bfdcff;
}

.admin-booking-heatmap__cell.is-busy {
  color: #174f88;
  background: #cfe4ff;
  border-color: #8fc1ff;
}

.admin-booking-heatmap__cell.is-full {
  color: #945d0c;
  background: #fff3df;
  border-color: #efc27f;
}

.admin-booking-heatmap__cell.is-off {
  color: var(--text-muted);
  background: var(--surface-subtle);
  border-style: dashed;
}

.admin-booking-heatmap__cell.is-selected {
  outline: 2px solid var(--admin-primary);
  outline-offset: 2px;
}

.admin-booking-heatmap__summary {
  width: 110px;
}

tr:last-child th,
tr:last-child td {
  border-bottom: 0;
}

th:last-child,
td:last-child {
  border-right: 0;
}
</style>
