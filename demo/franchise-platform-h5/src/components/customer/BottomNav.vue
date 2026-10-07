<template>
  <nav class="customer-bottom-nav" aria-label="商城底部导航">
    <router-link
      v-for="item in items"
      :key="item.name"
      :to="item.to"
      :class="{ 'is-active': isActive(item.name) }"
    >
      <component :is="item.icon" :size="21" aria-hidden="true" />
      <span>{{ item.label }}</span>
    </router-link>
  </nav>
</template>

<script>
import {
  Grid2X2,
  Home,
  ShoppingCart,
  User,
} from 'lucide-vue';

export default {
  name: 'BottomNav',
  props: {
    storeId: {
      type: String,
      required: true,
    },
  },
  computed: {
    items() {
      const base = `/c/store/${this.storeId}`;
      return [
        { name: 'customer-home', label: '首页', to: base, icon: Home },
        { name: 'customer-category', label: '分类', to: `${base}/category`, icon: Grid2X2 },
        { name: 'customer-cart', label: '购物车', to: `${base}/cart`, icon: ShoppingCart },
        { name: 'customer-profile', label: '我的', to: `${base}/profile`, icon: User },
      ];
    },
  },
  methods: {
    isActive(name) {
      if (name === 'customer-home') {
        return ['customer-home', 'customer-live', 'customer-live-room'].includes(this.$route.name);
      }
      if (name === 'customer-profile') {
        return [
          'customer-profile',
          'customer-orders',
          'customer-order-detail',
          'customer-bookings',
          'customer-booking-detail',
          'customer-assessments',
          'customer-assessment-detail',
          'customer-points',
          'customer-membership',
          'customer-assets',
          'customer-referral',
          'customer-share',
          'customer-distribution',
        ].includes(this.$route.name);
      }
      return this.$route.name === name;
    },
  },
};
</script>
