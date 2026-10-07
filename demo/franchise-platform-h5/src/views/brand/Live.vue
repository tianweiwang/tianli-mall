<template>
  <div class="admin-page brand-live">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-filter-bar" aria-label="直播运营筛选">
      <el-select v-model="storeId" aria-label="所属门店">
        <el-option label="全部门店" value="all" />
        <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
      </el-select>
      <el-select v-model="status" aria-label="场次状态">
        <el-option label="全部状态" value="all" />
        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <span class="admin-filter-bar__count">共 {{ visibleRooms.length }} 场</span>
    </section>

    <section class="admin-panel admin-table-panel">
      <el-table :data="visibleRooms" style="width: 100%" empty-text="暂无符合条件的直播场次">
        <el-table-column prop="id" label="场次编号" min-width="130" />
        <el-table-column label="所属门店" min-width="140">
          <template slot-scope="{ row }">{{ storeName(row.storeId) }}</template>
        </el-table-column>
        <el-table-column prop="title" label="直播标题" min-width="220" />
        <el-table-column prop="anchor" label="主播" min-width="100" />
        <el-table-column label="状态" width="100">
          <template slot-scope="{ row }"><AdminStatusTag :status="row.status" :text="statusText(row.status)" /></template>
        </el-table-column>
        <el-table-column label="观看" width="90">
          <template slot-scope="{ row }">{{ row.viewerCount }}</template>
        </el-table-column>
        <el-table-column label="点赞" width="90">
          <template slot-scope="{ row }">{{ row.likeCount }}</template>
        </el-table-column>
        <el-table-column label="挂载商品" min-width="180">
          <template slot-scope="{ row }">{{ productNames(row.productIds) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="72" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" :aria-label="`查看直播 ${row.id}`" @click="openDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer :visible.sync="drawerOpen" size="440px">
      <template slot="title"><h2 class="admin-drawer-title">直播详情</h2></template>
      <div v-if="selectedRecord" class="admin-detail-body">
        <section class="admin-detail-section">
          <h3>场次信息</h3>
          <dl class="admin-detail-list">
            <div><dt>场次编号</dt><dd>{{ selectedRecord.id }}</dd></div>
            <div><dt>所属门店</dt><dd>{{ storeName(selectedRecord.storeId) }}</dd></div>
            <div><dt>直播标题</dt><dd>{{ selectedRecord.title }}</dd></div>
            <div><dt>主播</dt><dd>{{ selectedRecord.anchor }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedRecord.status" :text="statusText(selectedRecord.status)" /></dd></div>
            <div><dt>观看 / 点赞</dt><dd>{{ selectedRecord.viewerCount }} / {{ selectedRecord.likeCount }}</dd></div>
            <div><dt>挂载商品</dt><dd>{{ productNames(selectedRecord.productIds) }}</dd></div>
          </dl>
        </section>
        <div class="admin-drawer-actions"><el-button @click="drawerOpen = false">关闭</el-button></div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { CalendarClock, Eye, Heart, Radio } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { liveBrandOverview, sumBy } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

const statusLabels = {
  live: '直播中',
  upcoming: '预告',
  ended: '已结束',
};

export default {
  name: 'BrandLive',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      drawerOpen: false,
      selectedRecord: null,
      storeId: 'all',
      status: 'all',
      statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    rooms() {
      return this.state.liveRooms || [];
    },
    overview() {
      return liveBrandOverview(this.state);
    },
    visibleRooms() {
      return this.rooms.filter((item) => {
        const matchesStore = this.storeId === 'all' || item.storeId === this.storeId;
        const matchesStatus = this.status === 'all' || item.status === this.status;
        return matchesStore && matchesStatus;
      });
    },
    metrics() {
      return [
        { label: '直播总场次', value: `${this.overview.roomCount} 场`, help: `${this.overview.liveCount} 场直播中`, icon: Radio, tone: 'danger' },
        { label: '累计观看', value: `${this.overview.viewerCount} 人`, help: '含回放观看', icon: Eye, tone: 'primary' },
        { label: '累计点赞', value: `${this.overview.likeCount} 次`, help: '直播间互动', icon: Heart, tone: 'success' },
        { label: '预告场次', value: `${this.overview.upcomingCount} 场`, help: `${this.overview.endedCount} 场已结束`, icon: CalendarClock, tone: 'warning' },
      ];
    },
  },
  methods: {
    openDetail(room) {
      this.selectedRecord = room;
      this.drawerOpen = true;
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    storeName(storeId) {
      return this.state.stores.find((item) => item.id === storeId)?.name || storeId;
    },
    productNames(productIds) {
      return productIds
        .map((id) => this.state.products.find((item) => item.id === id)?.name || id)
        .join('、');
    },
  },
};
</script>

<style scoped>
.admin-drawer-title { margin: 0; font-size: 18px; }
.admin-drawer-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
