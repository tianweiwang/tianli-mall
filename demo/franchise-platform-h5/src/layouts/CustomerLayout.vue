<template>
  <div class="customer-stage">
    <div v-if="activeStore" class="customer-app">
      <CustomerHeader :store="activeStore" />
      <main class="customer-main">
        <router-view />
      </main>
      <BottomNav :store-id="activeStore.id" />
    </div>

    <section v-else class="customer-store-unavailable">
      <Store :size="38" aria-hidden="true" />
      <h1>当前门店暂不可用</h1>
      <p>请选择其他营业中的门店。</p>
      <button
        v-for="store in availableStores"
        :key="store.id"
        type="button"
        @click="openStore(store.id)"
      >
        {{ store.name }}
      </button>
    </section>
    <DemoNavigator />
  </div>
</template>

<script>
import { Store } from 'lucide-vue';
import BottomNav from '../components/customer/BottomNav.vue';
import CustomerHeader from '../components/customer/CustomerHeader.vue';
import DemoNavigator from '../components/demo/DemoNavigator.vue';
import { demoStore } from '../mock/store';

export default {
  name: 'CustomerLayout',
  components: {
    BottomNav,
    CustomerHeader,
    DemoNavigator,
    Store,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    activeStore() {
      return this.state.stores.find(
        (item) => item.id === this.$route.params.storeId && item.status === 'active',
      );
    },
    availableStores() {
      return this.state.stores.filter((item) => item.status === 'active');
    },
  },
  watch: {
    '$route.params.storeId': {
      immediate: true,
      handler(storeId) {
        this.syncStore(storeId);
      },
    },
  },
  methods: {
    async syncStore(storeId) {
      const target = this.state.stores.find(
        (item) => item.id === storeId && item.status === 'active',
      );
      if (!target) {
        return;
      }
      if (target.id === this.state.activeStoreId) {
        if (this.state.activeRole !== 'customer') {
          demoStore.commit((draft) => {
            draft.activeRole = 'customer';
          });
        }
        return;
      }
      if (this.state.cart.length) {
        try {
          await this.$confirm(
            '切换门店将清空当前购物车，历史订单和预约不会改变。',
            '确认切换门店',
            {
              confirmButtonText: '清空并切换',
              cancelButtonText: '保留当前门店',
              type: 'warning',
            },
          );
        } catch (_error) {
          await this.$router.replace(`/c/store/${this.state.activeStoreId}`);
          return;
        }
      }
      demoStore.commit((draft) => {
        draft.activeRole = 'customer';
        draft.activeStoreId = target.id;
        draft.cart = [];
      });
    },
    openStore(storeId) {
      this.$router.push(`/c/store/${storeId}`);
    },
  },
};
</script>
