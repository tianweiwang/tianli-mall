<template>
  <view class="storefront" :style="colorStyle">
    <view class="store-header" v-if="store">
      <text class="marketplace-name">{{ marketplaceName }}</text>
      <view class="store-heading"><image v-if="store.logo" class="store-logo" :src="store.logo" mode="aspectFit" :aria-label="store.name + '店铺标识'" /><text class="store-name">{{ store.name }}</text></view>
      <view class="store-note" v-if="store.shipping_note"><text class="note-label">{{ $t('发货说明') }}</text><text>{{ store.shipping_note }}</text></view>
      <view class="store-note" v-if="store.after_sale_contact"><text class="note-label">{{ $t('售后联系方式') }}</text><text>{{ store.after_sale_contact }}</text></view>
    </view>
    <view v-if="storeError" class="feedback"><text role="status" aria-live="polite">{{ storeError }}</text><button class="store-action" :disabled="storeLoading" @tap="initialize">{{ $t('重新加载店铺') }}</button></view>
    <view v-if="storeLoading" class="feedback" role="status" aria-live="polite">{{ $t('店铺资料加载中') }}</view>
    <view v-if="store" class="store-products">
      <view class="section-title">{{ $t('本店商品') }}</view>
      <view class="product-row" v-for="item in products" :key="item.id" @tap="visitProduct(item)" @keydown.enter.prevent="visitProduct(item)" tabindex="0" role="link" :aria-label="'查看商品 ' + item.store_name">
        <image class="product-image" :src="item.image" mode="aspectFit" :aria-label="item.store_name" />
        <view class="product-text"><text class="product-name">{{ item.store_name }}</text><view class="product-price font-color">￥{{ item.price }}</view><view class="product-sales">{{ $t('已售') }} {{ item.sales }}{{ item.unit_name || $t('件') }}</view></view>
        <text class="iconfont icon-xiangyou" aria-hidden="true"></text>
      </view>
      <Loading :loading="productsLoading" :loaded="ended" />
      <view v-if="productsError" class="feedback"><text role="status" aria-live="polite">{{ productsError }}</text><button class="store-action" :disabled="productsLoading" @tap="loadProducts">{{ $t('重新加载商品') }}</button></view>
      <view v-if="!productsLoading && !productsError && !products.length" class="feedback" role="status">{{ $t('本店暂无已发布商品') }}</view>
      <button v-if="!productsLoading && !productsError && products.length && !ended" class="store-action more" @tap="loadProducts">{{ $t('加载更多商品') }}</button>
      <view v-if="ended && products.length" class="feedback" role="status">{{ $t('本店商品已加载完') }}</view>
    </view>
  </view>
</template>
<script>
import { getMarketplaceStore, getProductslist } from '@/api/store';
import { merchantIdentity, publicStore, storefrontMessage } from '@/utils/public-store';
import marketplace from '@/config/marketplace';
import colors from '@/mixins/color';
import Loading from '@/components/Loading/index.vue';
export default {
  components: { Loading }, mixins: [colors],
  data() { return { marketplaceName: marketplace.name, merId: null, store: null, storeLoading: false, storeError: '', products: [], productsLoading: false, productsError: '', page: 1, limit: 20, ended: false }; },
  onLoad(options) { try { this.merId = merchantIdentity(options.mer_id); this.initialize(); } catch (error) { this.storeError = storefrontMessage(error); } },
  onReachBottom() { this.loadProducts(); },
  methods: {
    async initialize() {
      if (this.storeLoading || this.productsLoading) return;
      this.storeLoading = true; this.storeError = ''; this.store = null; this.products = []; this.productsError = ''; this.page = 1; this.ended = false;
      try { const response = await getMarketplaceStore(merchantIdentity(this.merId)); this.store = publicStore(response.data, this.merId); uni.setNavigationBarTitle({ title: this.store.name }); }
      catch (error) { this.storeError = storefrontMessage(error); }
      finally { this.storeLoading = false; }
      if (this.store) this.loadProducts();
    },
    async loadProducts() {
      if (!this.store || this.storeLoading || this.productsLoading || this.ended) return;
      this.productsLoading = true; this.productsError = '';
      try {
        const response = await getProductslist({ mer_id: this.merId, page: this.page, limit: this.limit });
        if (!Array.isArray(response.data)) throw new Error('本店商品列表暂未完整取得');
        response.data.forEach((item) => { if (!Number.isSafeInteger(item.id) || item.id < 1 || item.id > 8388607 || item.mer_id !== this.merId || typeof item.store_name !== 'string' || typeof item.image !== 'string') throw new Error('本店商品列表暂未完整取得'); });
        const seen = new Set(this.products.map((item) => item.id));
        response.data.forEach((item) => { if (!seen.has(item.id)) { this.products.push(item); seen.add(item.id); } });
        this.ended = response.data.length < this.limit; this.page += 1;
      } catch (error) { this.productsError = storefrontMessage(error); }
      finally { this.productsLoading = false; }
    },
    visitProduct(item) { uni.navigateTo({ url: '/pages/goods_details/index?id=' + item.id }); },
  },
};
</script>
<style scoped>
.storefront { min-height: 100vh; background: #f5f5f5; color: #282828; padding-bottom: 30rpx; }
.store-header { padding: 30rpx; background: #fff; }
.marketplace-name { color: #606266; font-size: 24rpx; }
.store-heading { display: flex; align-items: center; gap: 20rpx; margin: 20rpx 0; min-width: 0; }
.store-logo { width: 100rpx; height: 100rpx; flex-shrink: 0; }
.store-name { flex: 1; min-width: 0; font-size: 36rpx; font-weight: bold; overflow-wrap: anywhere; }
.store-note { display: flex; flex-direction: column; gap: 8rpx; margin-top: 20rpx; font-size: 26rpx; line-height: 1.6; overflow-wrap: anywhere; white-space: pre-wrap; }
.note-label { color: #606266; }
.store-products { margin-top: 20rpx; background: #fff; }
.section-title { padding: 26rpx 30rpx; font-size: 32rpx; font-weight: bold; }
.product-row { display: flex; align-items: center; gap: 20rpx; padding: 24rpx 30rpx; border-top: 1rpx solid #f5f5f5; box-sizing: border-box; width: 100%; }
.product-image { width: 180rpx; height: 180rpx; flex-shrink: 0; border-radius: 16rpx; }
.product-text { flex: 1; min-width: 0; }
.product-name { font-size: 28rpx; overflow-wrap: anywhere; }
.product-price { font-size: 32rpx; margin-top: 16rpx; }
.product-sales { color: #606266; font-size: 24rpx; margin-top: 12rpx; }
.feedback { padding: 26rpx 30rpx; color: #606266; font-size: 26rpx; line-height: 1.6; overflow-wrap: anywhere; }
.store-action { display: flex; align-items: center; justify-content: center; box-sizing: border-box; max-width: 100%; margin: 20rpx 0 0; padding: 14rpx 26rpx; text-align: center; line-height: 1.4; font-size: 28rpx; color: var(--view-theme, #E93323); background: #fff; border: 1rpx solid var(--view-theme, #E93323); border-radius: 30rpx; }
.store-action::after { border: 0; }
.more { margin: 24rpx 30rpx; max-width: calc(100% - 60rpx); }
</style>
