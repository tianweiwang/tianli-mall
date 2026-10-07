<template>
  <div class="admin-page brand-devices">
    <AdminMetricStrip :items="metrics" />

    <section v-if="overdueCount" class="device-warning">
      <AlertTriangle :size="18" aria-hidden="true" />
      <div><strong>{{ overdueCount }} 台设备校准已超过 30 天</strong><span>当前为演示提醒，不提供远程校准操作。</span></div>
    </section>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>设备台账</h2><p>查看设备归属、在线状态和最近校准记录</p></div>
        <div class="device-filters">
          <el-select v-model="storeFilter" size="small" aria-label="设备门店筛选">
            <el-option label="全部门店" value="all" />
            <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
          </el-select>
          <el-select v-model="statusFilter" size="small" aria-label="设备状态筛选">
            <el-option label="全部状态" value="all" />
            <el-option label="在线" value="online" />
            <el-option label="离线" value="offline" />
            <el-option label="维护中" value="maintenance" />
          </el-select>
        </div>
      </header>
      <el-table :data="filteredDevices" :row-class-name="rowClassName" style="width: 100%">
        <el-table-column prop="name" label="设备名称" min-width="180" />
        <el-table-column prop="model" label="型号" width="120" />
        <el-table-column prop="storeName" label="归属门店" min-width="150" />
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusLabel(row.status)" /></template>
        </el-table-column>
        <el-table-column prop="softwareVersion" label="软件版本" width="110" />
        <el-table-column label="最近校准" width="130">
          <template slot-scope="{ row }"><span :class="{ 'is-overdue': isCalibrationOverdue(row) }">{{ row.calibratedAt }}</span></template>
        </el-table-column>
        <el-table-column prop="todayCount" label="今日检测" width="100" />
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看设备 ${row.id}`" @click="openDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :visible.sync="drawerOpen" size="430px">
      <template slot="title"><h2 class="admin-drawer-title">设备详情</h2></template>
      <div v-if="selectedRecord" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>设备信息</h3>
          <dl class="admin-detail-list">
            <div><dt>设备编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>设备名称</dt><dd>{{ selectedRecord.name }}</dd></div>
            <div><dt>型号</dt><dd>{{ selectedRecord.model }}</dd></div>
            <div><dt>归属门店</dt><dd>{{ selectedRecord.storeName || '暂未分配' }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedRecord.status" :text="statusLabel(selectedRecord.status)" /></dd></div>
            <div><dt>软件版本</dt><dd>{{ selectedRecord.softwareVersion }}</dd></div>
            <div><dt>最近校准</dt><dd>{{ selectedRecord.calibratedAt }}{{ isCalibrationOverdue(selectedRecord) ? '（已逾期）' : '' }}</dd></div>
            <div><dt>今日检测</dt><dd>{{ selectedRecord.todayCount }} 次</dd></div>
          </dl>
        </section>
        <div class="admin-drawer-actions"><el-button @click="drawerOpen = false">关闭</el-button></div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { Activity, AlertTriangle, MonitorCog, WifiOff } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { assessmentOperationsForBrand } from '../../mock/assessmentSelectors';

export default {
  name: 'BrandDevices',
  components: { AdminMetricStrip, AdminStatusTag, AlertTriangle },
  data() {
    return {
      drawerOpen: false,
      selectedRecord: null,
      storeFilter: 'all',
      statusFilter: 'all',
    };
  },
  computed: {
    state() { return demoStore.state.data; },
    operations() { return assessmentOperationsForBrand(this.state); },
    devices() { return this.operations.devices; },
    filteredDevices() {
      return this.devices.filter((device) => (this.storeFilter === 'all' || device.storeId === this.storeFilter)
        && (this.statusFilter === 'all' || device.status === this.statusFilter));
    },
    overdueCount() { return this.devices.filter(this.isCalibrationOverdue).length; },
    metrics() {
      return [
        { label: '设备总数', value: `${this.devices.length} 台`, help: `${this.devices.filter((item) => item.storeId).length} 台已分配`, icon: MonitorCog, tone: 'primary' },
        { label: '在线设备', value: `${this.operations.onlineDeviceCount} 台`, help: '可领取检测任务', icon: Activity, tone: 'success' },
        { label: '离线设备', value: `${this.devices.filter((item) => item.status === 'offline').length} 台`, help: '需门店检查网络', icon: WifiOff, tone: 'warning' },
        { label: '校准提醒', value: `${this.overdueCount} 台`, help: '超过 30 天未校准', icon: AlertTriangle, tone: 'danger' },
      ];
    },
  },
  methods: {
    isCalibrationOverdue(device) {
      const calibrated = new Date(`${device.calibratedAt}T00:00:00+08:00`);
      const now = new Date(this.state.demoNow);
      return (now - calibrated) / 86400000 > 30;
    },
    openDetail(device) {
      this.selectedRecord = device;
      this.drawerOpen = true;
    },
    rowClassName({ row }) { return row.status === 'offline' || this.isCalibrationOverdue(row) ? 'device-row--warning' : ''; },
    statusLabel(status) { return { online: '在线', offline: '离线', maintenance: '维护中' }[status] || status; },
  },
};
</script>

<style scoped>
.device-warning { display: grid; grid-template-columns: 24px minmax(0, 1fr); align-items: center; gap: 8px; padding: 12px 14px; color: #995f0d; background: #fff7e9; border: 1px solid #f4ddb7; border-radius: var(--radius-md); }
.device-warning strong, .device-warning span { display: block; }
.device-warning strong { font-size: 13px; }
.device-warning span { margin-top: 2px; font-size: 11px; }
.device-filters { display: grid; width: 320px; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.is-overdue { color: var(--danger); font-weight: 600; }
.brand-devices :deep(.device-row--warning td) { background: #fffaf2; }
.admin-drawer-title { margin: 0; font-size: 18px; }
.admin-drawer-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
