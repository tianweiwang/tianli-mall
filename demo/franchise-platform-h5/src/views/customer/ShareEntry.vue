<template>
  <div class="share-entry-page">
    <section v-if="valid" class="share-entry-card">
      <div class="share-entry-icon"><Gift :size="28" aria-hidden="true" /></div>
      <span>好友推荐</span>
      <h1>由 {{ distributor.name }} 推荐</h1>
      <p>{{ share.content }}，接受后进入门店即可选购商品或预约服务。</p>

      <div class="share-entry-store">
        <Store :size="18" aria-hidden="true" />
        <div><strong>{{ store.name }}</strong><small>推荐门店</small></div>
        <ArrowRight :size="17" aria-hidden="true" />
      </div>

      <div class="share-entry-form">
        <label>好友姓名<input v-model="customerName" type="text" aria-label="好友姓名" placeholder="例如：赵女士"></label>
        <label>好友手机<input v-model="phone" type="text" aria-label="好友手机" placeholder="138****0000"></label>
        <button type="button" :disabled="!canAccept" @click="accept">
          接受推荐并进入商城<ArrowRight :size="16" aria-hidden="true" />
        </button>
      </div>
    </section>

    <section v-else class="share-entry-card is-invalid">
      <UserRound :size="34" aria-hidden="true" />
      <h1>分享链接已失效</h1>
      <p>该推荐链接不存在或门店暂停营业，请返回门店首页继续浏览。</p>
      <router-link to="/c/store/TL1001">返回门店首页</router-link>
    </section>
  </div>
</template>

<script>
import {
  ArrowRight,
  Gift,
  Store,
  UserRound,
} from 'lucide-vue';
import { shareRecordByToken } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { acceptShareAttribution, recordShareVisit } from '../../mock/transitions';

export default {
  name: 'CustomerShareEntry',
  components: { ArrowRight, Gift, Store, UserRound },
  data() {
    return {
      customerName: '',
      phone: '',
    };
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    share() {
      return shareRecordByToken(this.state, this.$route.params.token);
    },
    distributor() {
      return this.share && this.state.distributor?.id === this.share.distributorId
        ? this.state.distributor
        : null;
    },
    store() {
      return this.share
        ? this.state.stores.find((item) => item.id === this.share.storeId) || null
        : null;
    },
    valid() {
      return Boolean(this.share && this.distributor && this.store?.status === 'active');
    },
    canAccept() {
      return Boolean(this.customerName.trim() && this.phone.trim());
    },
  },
  created() {
    if (this.valid) {
      demoStore.commit((draft) => recordShareVisit(draft, this.$route.params.token));
    }
  },
  methods: {
    accept() {
      if (!this.valid || !this.canAccept) {
        return undefined;
      }
      demoStore.commit((draft) => acceptShareAttribution(draft, {
        token: this.$route.params.token,
        customerName: this.customerName.trim(),
        phone: this.phone.trim(),
      }));
      return this.$router.push(`/c/store/${this.share.storeId}`);
    },
  },
};
</script>

<style scoped>
.share-entry-page {
  display: grid;
  min-height: calc(100vh - 164px);
  align-content: center;
}

.share-entry-card {
  padding: 24px 16px 16px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  text-align: center;
}

.share-entry-icon {
  display: grid;
  width: 54px;
  height: 54px;
  margin: 0 auto 10px;
  place-items: center;
  color: #fff;
  background: var(--brand-primary);
  border-radius: 50%;
}

.share-entry-card > span {
  color: var(--brand-primary);
  font-size: 11px;
}

.share-entry-card h1 {
  margin: 5px 0 8px;
  font-size: 20px;
}

.share-entry-card > p {
  margin: 0 auto 16px;
  color: var(--text-regular);
  font-size: 12px;
  line-height: 1.65;
}

.share-entry-store {
  display: grid;
  min-height: 54px;
  grid-template-columns: 22px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  color: var(--brand-primary);
  background: var(--surface-subtle);
  border-radius: var(--radius-md);
  text-align: left;
}

.share-entry-store strong,
.share-entry-store small {
  display: block;
}

.share-entry-store strong {
  color: var(--text-primary);
  font-size: 12px;
}

.share-entry-store small {
  margin-top: 2px;
  color: var(--text-muted);
  font-size: 10px;
}

.share-entry-form {
  display: grid;
  gap: 8px;
  margin-top: 14px;
}

.share-entry-form label {
  display: grid;
  min-height: 42px;
  grid-template-columns: 64px minmax(0, 1fr);
  align-items: center;
  border-bottom: 1px solid var(--border);
  color: var(--text-regular);
  font-size: 12px;
  text-align: left;
}

.share-entry-form input {
  min-width: 0;
  height: 38px;
  background: transparent;
  border: 0;
  outline: 0;
  text-align: right;
}

.share-entry-form button,
.share-entry-card.is-invalid a {
  display: flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  gap: 5px;
  margin-top: 4px;
  color: #fff;
  background: var(--brand-primary);
  border: 0;
  border-radius: var(--radius-md);
  font-size: 12px;
}

.share-entry-form button:disabled {
  background: #c8c9cc;
}

.share-entry-card.is-invalid {
  color: var(--text-muted);
}

.share-entry-card.is-invalid h1 {
  color: var(--text-primary);
}
</style>
