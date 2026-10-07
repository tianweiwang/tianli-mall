<template>
  <div class="admin-shell">
    <aside class="admin-sidebar">
      <router-link :to="homePath" class="admin-brand">
        <span class="admin-brand__mark">天</span>
        <span>
          <strong>天俪商城</strong>
          <small>{{ roleLabel }}</small>
        </span>
      </router-link>

      <nav class="admin-menu" :aria-label="`${roleLabel}菜单`">
        <router-link
          v-for="item in menuItems"
          :key="item.path"
          :to="item.path"
        >
          <component :is="item.icon" :size="18" aria-hidden="true" />
          <span>{{ item.label }}</span>
        </router-link>
      </nav>
    </aside>

    <section class="admin-workspace">
      <header class="admin-topbar">
        <div>
          <span class="admin-topbar__role">{{ roleLabel }}</span>
          <strong>{{ $route.meta.title }}</strong>
        </div>
        <div class="admin-topbar__actions">
          <span class="admin-topbar__user">{{ userLabel }}</span>
          <DemoNavigator />
        </div>
      </header>
      <main class="admin-main">
        <router-view />
      </main>
    </section>
  </div>
</template>

<script>
import DemoNavigator from '../demo/DemoNavigator.vue';

export default {
  name: 'AdminShell',
  components: { DemoNavigator },
  props: {
    roleLabel: {
      type: String,
      required: true,
    },
    userLabel: {
      type: String,
      required: true,
    },
    homePath: {
      type: String,
      required: true,
    },
    menuItems: {
      type: Array,
      required: true,
    },
  },
};
</script>
