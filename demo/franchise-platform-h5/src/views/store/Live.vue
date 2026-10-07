<template>
  <div class="admin-page store-live">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-panel live-create-panel">
      <header class="admin-panel__header">
        <div><h2>创建演示场次</h2><p>新建场次默认为“即将开播”，仅写入本地演示数据</p></div>
      </header>
      <div class="live-create-grid">
        <el-input v-model.trim="title" aria-label="直播标题" placeholder="直播标题（演示）" clearable />
        <el-input v-model.trim="anchor" aria-label="主播姓名" placeholder="主播姓名" clearable />
        <el-select v-model="productIds" multiple collapse-tags aria-label="挂载商品" placeholder="挂载商品">
          <el-option v-for="product in products" :key="product.id" :label="product.name" :value="product.id" />
        </el-select>
        <el-button type="primary" :disabled="!canCreate" @click="createRoom">创建场次</el-button>
      </div>
    </section>

    <section class="admin-panel admin-table-panel">
      <header class="admin-panel__header">
        <div><h2>本店直播场次</h2><p>仅显示 {{ store.name }} 的场次</p></div>
      </header>
      <el-table :data="rooms" style="width: 100%" empty-text="暂无直播场次">
        <el-table-column prop="id" label="场次编号" min-width="140" />
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
            <div><dt>直播标题</dt><dd>{{ selectedRecord.title }}</dd></div>
            <div><dt>主播</dt><dd>{{ selectedRecord.anchor }}</dd></div>
            <div><dt>当前状态</dt><dd><AdminStatusTag :status="selectedRecord.status" :text="statusText(selectedRecord.status)" /></dd></div>
            <div><dt>观看 / 点赞</dt><dd>{{ selectedRecord.viewerCount }} / {{ selectedRecord.likeCount }}</dd></div>
            <div><dt>挂载商品</dt><dd>{{ productNames(selectedRecord.productIds) }}</dd></div>
          </dl>
        </section>
        <div class="admin-drawer-actions">
          <el-button @click="drawerOpen = false">关闭</el-button>
          <el-button v-if="selectedRecord.status === 'upcoming'" type="primary" @click="changeStatus('live')">开始直播</el-button>
          <el-button v-else-if="selectedRecord.status === 'live'" type="danger" @click="changeStatus('ended')">结束直播</el-button>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { Eye, Heart, Radio, CalendarClock } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import {
  activeStore,
  liveRoomsForStore,
  productsForStore,
  sumBy,
} from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { createLiveRoom, transitionLiveRoom } from '../../mock/transitions';

const statusLabels = {
  live: '直播中',
  upcoming: '预告',
  ended: '已结束',
};

export default {
  name: 'StoreLive',
  components: { AdminMetricStrip, AdminStatusTag },
  data() {
    return {
      title: '',
      anchor: '',
      drawerOpen: false,
      productIds: [],
      selectedRecord: null,
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return activeStore(this.state);
    },
    rooms() {
      return liveRoomsForStore(this.state, this.store.id);
    },
    products() {
      return productsForStore(this.state, this.store.id).filter((item) => item.stock > 0);
    },
    metrics() {
      const liveRooms = this.rooms.filter((item) => item.status === 'live');
      const upcomingRooms = this.rooms.filter((item) => item.status === 'upcoming');
      return [
        { label: '直播中场次', value: `${liveRooms.length} 场`, help: `${this.rooms.length} 个总场次`, icon: Radio, tone: 'danger' },
        { label: '累计观看', value: `${sumBy(this.rooms, 'viewerCount')} 人`, help: '含回放观看', icon: Eye, tone: 'primary' },
        { label: '累计点赞', value: `${sumBy(this.rooms, 'likeCount')} 次`, help: '直播间互动', icon: Heart, tone: 'success' },
        { label: '预告场次', value: `${upcomingRooms.length} 场`, help: '等待开播', icon: CalendarClock, tone: 'warning' },
      ];
    },
    canCreate() {
      return Boolean(this.title.trim() && this.anchor.trim() && this.productIds.length);
    },
  },
  methods: {
    changeStatus(targetStatus) {
      try {
        const roomId = this.selectedRecord.id;
        demoStore.commit((draft) => transitionLiveRoom(draft, { roomId, targetStatus }));
        this.selectedRecord = this.rooms.find((item) => item.id === roomId);
        this.$message.success(targetStatus === 'live' ? '直播已开始（演示）' : '直播已结束（演示）');
      } catch (error) {
        this.$message.error(error.message);
      }
    },
    statusText(status) {
      return statusLabels[status] || status;
    },
    productNames(productIds) {
      return productIds
        .map((id) => this.state.products.find((item) => item.id === id)?.name || id)
        .join('、');
    },
    createRoom() {
      demoStore.commit((draft) => {
        createLiveRoom(draft, {
          storeId: draft.activeStoreId,
          title: this.title.trim(),
          anchor: this.anchor.trim(),
          productIds: [...this.productIds],
        });
      });
      this.$message.success('直播场次已创建（演示）');
      this.title = '';
      this.anchor = '';
      this.productIds = [];
    },
    openDetail(room) {
      this.selectedRecord = room;
      this.drawerOpen = true;
    },
  },
};
</script>

<style scoped>
.live-create-panel {
  margin-bottom: 16px;
}

.live-create-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) 140px minmax(0, 1.2fr) 110px;
  gap: 12px;
  padding: 4px 18px 18px;
}

.admin-drawer-title {
  margin: 0;
  font-size: 18px;
}

.admin-drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
}
</style>
