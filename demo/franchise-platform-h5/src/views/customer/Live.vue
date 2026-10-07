<template>
  <div class="live-page">
    <section v-if="liveRooms.length" class="live-hero">
      <div>
        <span class="live-hero__badge"><Radio :size="13" aria-hidden="true" />直播中</span>
        <h1>{{ store.name }} 直播</h1>
        <p>{{ liveRooms.length }} 个直播间正在开播，进店好物同步上架</p>
      </div>
      <router-link :to="`/c/store/${store.id}/live/${liveRooms[0].id}`">
        进入直播间<ChevronRight :size="16" aria-hidden="true" />
      </router-link>
    </section>
    <section v-else class="live-empty">
      <Radio :size="30" aria-hidden="true" />
      <strong>当前门店暂无直播</strong>
      <span>可前往门店端创建演示场次</span>
    </section>

    <section v-if="liveRooms.length" class="live-section">
      <header><Radio :size="17" aria-hidden="true" /><h2>正在直播</h2></header>
      <div class="live-room-list">
        <LiveRoomCard
          v-for="room in liveRooms"
          :key="room.id"
          :room="room"
          :store="store"
          live
        />
      </div>
    </section>

    <section v-if="upcomingRooms.length" class="live-section">
      <header><CalendarClock :size="17" aria-hidden="true" /><h2>即将开播</h2></header>
      <div class="live-room-list">
        <LiveRoomCard v-for="room in upcomingRooms" :key="room.id" :room="room" :store="store" />
      </div>
    </section>

    <section v-if="endedRooms.length" class="live-section">
      <header><History :size="17" aria-hidden="true" /><h2>往期回放</h2></header>
      <div class="live-room-list">
        <LiveRoomCard v-for="room in endedRooms" :key="room.id" :room="room" :store="store" />
      </div>
    </section>
  </div>
</template>

<script>
import { CalendarClock, ChevronRight, History, Radio } from 'lucide-vue';
import LiveRoomCard from '../../components/customer/LiveRoomCard.vue';
import { liveRoomsForStore } from '../../mock/selectors';
import { demoStore } from '../../mock/store';

export default {
  name: 'CustomerLive',
  components: { CalendarClock, ChevronRight, History, LiveRoomCard, Radio },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    rooms() {
      return liveRoomsForStore(this.state, this.store.id);
    },
    liveRooms() {
      return this.rooms.filter((item) => item.status === 'live');
    },
    upcomingRooms() {
      return this.rooms.filter((item) => item.status === 'upcoming');
    },
    endedRooms() {
      return this.rooms.filter((item) => item.status === 'ended');
    },
  },
};
</script>

<style scoped>
.live-page {
  display: grid;
  gap: 12px;
}

.live-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 16px;
  color: #fff;
  background: linear-gradient(135deg, #b93a28, #e06a3f);
  border-radius: var(--radius-lg);
}

.live-hero__badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #ffe6e0;
  font-size: 11px;
}

.live-hero h1 {
  margin: 4px 0 2px;
  font-size: 18px;
}

.live-hero p {
  margin: 0;
  color: #ffe0d8;
  font-size: 11px;
}

.live-hero > a {
  display: flex;
  min-height: 38px;
  align-items: center;
  gap: 3px;
  padding: 0 12px;
  color: #fff;
  background: rgba(255, 255, 255, 0.16);
  border-radius: var(--radius-md);
  font-size: 12px;
}

.live-empty {
  display: grid;
  min-height: 150px;
  place-items: center;
  align-content: center;
  gap: 6px;
  color: var(--text-muted);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.live-empty strong {
  color: var(--text-regular);
  font-size: 14px;
}

.live-empty span {
  font-size: 11px;
}

.live-section > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 4px 2px 9px;
  color: var(--brand-primary);
}

.live-section h2 {
  margin: 0;
  font-size: 16px;
}

.live-room-list {
  display: grid;
  gap: 10px;
}
</style>
