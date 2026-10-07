<template>
  <section class="route-placeholder" :aria-label="$route.meta.title">
    <h1>{{ $route.meta.title }}</h1>
    <div class="route-placeholder__line route-placeholder__line--wide" />
    <div class="route-placeholder__line" />
    <div class="route-placeholder__line route-placeholder__line--short" />
  </section>
</template>

<script>
export default {
  name: 'PlaceholderView',
};
</script>
