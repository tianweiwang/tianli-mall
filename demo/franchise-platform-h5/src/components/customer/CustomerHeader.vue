<template>
  <header class="customer-header">
    <div class="customer-header__store-mark">
      <Store :size="20" aria-hidden="true" />
    </div>
    <div class="customer-header__identity">
      <strong>{{ store.name }}</strong>
      <span>{{ store.address }}</span>
    </div>
    <span class="customer-header__status">
      {{ store.status === 'active' ? '营业中' : '暂停营业' }}
    </span>
  </header>
</template>

<script>
import { Store } from 'lucide-vue';

export default {
  name: 'CustomerHeader',
  components: { Store },
  props: {
    store: {
      type: Object,
      required: true,
    },
  },
};
</script>
