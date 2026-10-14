<template>
  <view v-if="store" class="marketplace-store-link">
    <view class="store-identity">
      <image v-if="store.logo" class="store-logo" :src="store.logo" mode="aspectFit" :aria-label="store.name + '店铺标识'" />
      <view class="store-text"><text class="store-caption">{{ $t('商品所属店铺') }}</text><text class="store-name">{{ store.name }}</text></view>
    </view>
    <button class="store-entry" :aria-label="'进入店铺 ' + store.name" @tap="visit">{{ $t('进入店铺') }}</button>
  </view>
</template>
<script>
export default {
  props: { store: { type: Object, default: null } },
  methods: { visit() { uni.navigateTo({ url: '/pages/store/index?mer_id=' + this.store.mer_id }); } },
};
</script>
<style scoped>
.marketplace-store-link { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; margin: 20rpx 24rpx; padding: 24rpx; box-sizing: border-box; max-width: 100%; background: #fff; border-radius: 16rpx; }
.store-identity { display: flex; align-items: center; flex: 1; min-width: 0; gap: 20rpx; }
.store-logo { width: 80rpx; height: 80rpx; flex-shrink: 0; }
.store-text { min-width: 0; display: flex; flex-direction: column; gap: 8rpx; }
.store-caption { color: #606266; font-size: 24rpx; }
.store-name { color: #282828; font-size: 30rpx; overflow-wrap: anywhere; }
.store-entry { display: flex; align-items: center; justify-content: center; flex-shrink: 0; max-width: 42%; margin: 0; padding: 12rpx 20rpx; box-sizing: border-box; text-align: center; line-height: 1.4; font-size: 26rpx; color: var(--view-theme, #E93323); background: #fff; border: 1rpx solid var(--view-theme, #E93323); border-radius: 28rpx; }
.store-entry::after { border: 0; }
</style>
