<template>
  <div v-if="room" class="live-room-page">
    <section class="live-player" :class="`is-${room.status}`">
      <div class="live-player__top">
        <span :class="['live-player__status', `is-${room.status}`]">
          <Radio v-if="room.status === 'live'" :size="14" aria-hidden="true" />
          <Play v-else-if="room.status === 'ended'" :size="14" aria-hidden="true" />
          <CalendarClock v-else :size="14" aria-hidden="true" />
          {{ statusText }}
        </span>
        <span class="live-player__viewers"><Eye :size="14" aria-hidden="true" />{{ room.viewerCount }} 观看</span>
      </div>
      <div class="live-player__center">
        <Radio v-if="room.status === 'live'" :size="54" aria-hidden="true" />
        <Play v-else :size="54" aria-hidden="true" />
        <strong v-if="room.status === 'live'">模拟直播画面</strong>
        <strong v-else-if="room.status === 'upcoming'">{{ formatTime(room.scheduledAt) }} 开播</strong>
        <strong v-else>直播已结束，可回放</strong>
      </div>
      <div class="live-player__actions" v-if="room.status === 'live'">
        <button type="button" data-testid="live-like" @click="like">
          <Heart :size="17" aria-hidden="true" />点赞 {{ room.likeCount }}
        </button>
        <button type="button" data-testid="live-enter" @click="enter">
          <UserPlus :size="17" aria-hidden="true" />模拟进入
        </button>
      </div>
    </section>

    <section class="live-room-info">
      <div><h1>{{ room.title }}</h1><p><UserRound :size="14" aria-hidden="true" />主播：{{ room.anchor }} · {{ store.name }}</p></div>
    </section>

    <section class="live-room-goods">
      <header><ShoppingBag :size="18" aria-hidden="true" /><h2>直播商品</h2></header>
      <div v-if="room.products.length" class="product-grid">
        <ProductCard
          v-for="product in room.products"
          :key="product.id"
          :product="product"
          :store-id="store.id"
        />
      </div>
      <p v-else class="live-goods-empty">本场次暂未挂载商品</p>
    </section>
  </div>
  <div v-else class="live-room-missing">
    直播间不存在或已下线
  </div>
</template>

<script>
import {
  CalendarClock,
  Eye,
  Heart,
  Play,
  Radio,
  ShoppingBag,
  UserPlus,
  UserRound,
} from 'lucide-vue';
import ProductCard from '../../components/customer/ProductCard.vue';
import { liveRoomById } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { bumpLiveStats } from '../../mock/transitions';

const labels = {
  live: '直播中',
  upcoming: '即将开播',
  ended: '回放',
};

export default {
  name: 'CustomerLiveRoom',
  components: {
    CalendarClock,
    Eye,
    Heart,
    Play,
    ProductCard,
    Radio,
    ShoppingBag,
    UserPlus,
    UserRound,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    store() {
      return this.state.stores.find((item) => item.id === this.$route.params.storeId);
    },
    room() {
      const room = liveRoomById(this.state, this.$route.params.roomId);
      if (!room || room.storeId !== this.store.id) {
        return null;
      }
      return room;
    },
    statusText() {
      return this.room ? (labels[this.room.status] || this.room.status) : '';
    },
  },
  methods: {
    formatTime(value) {
      return value.replace('T', ' ').slice(0, 16);
    },
    like() {
      demoStore.commit((draft) => {
        bumpLiveStats(draft, { roomId: this.room.id, likes: 1 });
      });
      this.$message.success('点赞 +1');
    },
    enter() {
      demoStore.commit((draft) => {
        bumpLiveStats(draft, { roomId: this.room.id, viewers: 10 });
      });
      this.$message.success('模拟进入直播间 +10');
    },
  },
};
</script>

<style scoped>
.live-room-page {
  display: grid;
  gap: 10px;
}

.live-player {
  position: relative;
  display: grid;
  min-height: 280px;
  overflow: hidden;
  place-items: center;
  color: #fff;
  background: linear-gradient(160deg, #17191d, #3a3f4a);
  border-radius: var(--radius-lg);
}

.live-player.is-upcoming {
  background: linear-gradient(160deg, #2d3038, #5b4a2c);
}

.live-player.is-ended {
  background: linear-gradient(160deg, #272a30, #565656);
}

.live-player__top {
  position: absolute;
  top: 12px;
  left: 12px;
  right: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.live-player__status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
}

.live-player__status.is-live {
  background: var(--danger);
}

.live-player__status.is-upcoming {
  color: #ffd9a0;
  background: rgba(0, 0, 0, 0.35);
}

.live-player__status.is-ended {
  color: #d8d8d8;
  background: rgba(0, 0, 0, 0.35);
}

.live-player__viewers {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  color: #fff;
  background: rgba(0, 0, 0, 0.35);
  border-radius: 999px;
  font-size: 11px;
}

.live-player__center {
  display: grid;
  justify-items: center;
  gap: 8px;
}

.live-player__center svg {
  opacity: 0.6;
}

.live-player__center strong {
  font-size: 14px;
  font-weight: 500;
}

.live-player__actions {
  position: absolute;
  bottom: 12px;
  left: 12px;
  right: 12px;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.live-player__actions button {
  display: flex;
  min-height: 40px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #fff;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: var(--radius-md);
  font-size: 12px;
  cursor: pointer;
}

.live-room-info {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.live-room-info h1 {
  margin: 0;
  font-size: 16px;
}

.live-room-info p {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 6px 0 0;
  color: var(--text-muted);
  font-size: 11px;
}

.live-room-goods {
  padding: 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.live-room-goods > header {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 10px;
  color: var(--brand-primary);
}

.live-room-goods h2 {
  margin: 0;
  font-size: 14px;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.live-goods-empty {
  margin: 0;
  padding: 18px;
  color: var(--text-muted);
  background: var(--surface-subtle);
  text-align: center;
  font-size: 12px;
}

.live-room-missing {
  padding: 40px 16px;
  color: var(--text-muted);
  text-align: center;
  font-size: 13px;
}
</style>
