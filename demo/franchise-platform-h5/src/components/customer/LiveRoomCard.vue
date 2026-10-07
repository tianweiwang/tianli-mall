<template>
  <article class="live-room-card" :data-testid="`live-room-${room.id}`">
    <router-link :to="roomPath" class="live-room-card__link">
      <div class="live-room-card__media">
        <Radio v-if="room.status === 'live'" :size="26" aria-hidden="true" />
        <CalendarClock v-else-if="room.status === 'upcoming'" :size="26" aria-hidden="true" />
        <Play v-else :size="26" aria-hidden="true" />
        <span :class="['live-room-card__status', `is-${room.status}`]">{{ statusText }}</span>
      </div>
      <div class="live-room-card__body">
        <h3>{{ room.title }}</h3>
        <p><UserRound :size="13" aria-hidden="true" />{{ room.anchor }} · {{ store.name }}</p>
        <div class="live-room-card__stats">
          <span><Eye :size="13" aria-hidden="true" />{{ room.viewerCount }} 观看</span>
          <span><Heart :size="13" aria-hidden="true" />{{ room.likeCount }} 点赞</span>
          <small v-if="room.status === 'upcoming'">{{ formatTime(room.scheduledAt) }} 开播</small>
          <small v-else-if="room.status === 'ended'">可看回放</small>
        </div>
      </div>
    </router-link>
  </article>
</template>

<script>
import { CalendarClock, Eye, Heart, Play, Radio, UserRound } from 'lucide-vue';

const labels = {
  live: '直播中',
  upcoming: '预告',
  ended: '回放',
};

export default {
  name: 'LiveRoomCard',
  components: { CalendarClock, Eye, Heart, Play, Radio, UserRound },
  props: {
    room: {
      type: Object,
      required: true,
    },
    store: {
      type: Object,
      required: true,
    },
  },
  computed: {
    roomPath() {
      return `/c/store/${this.store.id}/live/${this.room.id}`;
    },
    statusText() {
      return labels[this.room.status] || this.room.status;
    },
  },
  methods: {
    formatTime(value) {
      return value.replace('T', ' ').slice(5, 16);
    },
  },
};
</script>

<style scoped>
.live-room-card {
  overflow: hidden;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.live-room-card__link {
  display: grid;
  grid-template-columns: 112px minmax(0, 1fr);
  min-height: 112px;
}

.live-room-card__media {
  position: relative;
  display: grid;
  place-items: center;
  color: #fff;
  background: linear-gradient(135deg, #454a55, #2d3038);
}

.live-room-card__media svg {
  opacity: 0.55;
}

.live-room-card__status {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 10px;
}

.live-room-card__status.is-live {
  color: #fff;
  background: var(--danger);
}

.live-room-card__status.is-upcoming {
  color: #9d6413;
  background: #fff7e9;
}

.live-room-card__status.is-ended {
  color: var(--text-muted);
  background: #f2f4f7;
}

.live-room-card__body {
  display: grid;
  align-content: center;
  gap: 5px;
  padding: 10px 12px;
}

.live-room-card h3 {
  margin: 0;
  font-size: 14px;
  line-height: 1.4;
}

.live-room-card p {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0;
  color: var(--text-muted);
  font-size: 11px;
}

.live-room-card__stats {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--text-muted);
  font-size: 10px;
}

.live-room-card__stats span {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.live-room-card__stats small {
  color: var(--brand-primary);
}
</style>
