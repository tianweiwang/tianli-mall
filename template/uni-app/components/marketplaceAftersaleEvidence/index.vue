<template>
  <view class="private-evidence">
    <view v-if="!files.length" class="evidence-note">此记录没有凭证图片。</view>
    <view v-for="row in rows" :key="row.id" class="evidence-card">
      <image v-if="row.url" class="evidence-image" :src="row.url" mode="widthFix" :aria-label="'私有凭证 ' + row.position" @error="imageFailed(row)" />
      <text v-else class="evidence-note">{{ row.loading ? '正在读取私有凭证' : row.error || '私有凭证尚未读取' }}</text>
      <text class="evidence-note">凭证 {{ row.position }}</text>
      <button v-if="!row.loading && !row.url" class="evidence-button" @tap="load(row)">重新读取此凭证</button>
    </view>
  </view>
</template>
<script>
import { errorText, u64 } from '@/utils/marketplace-aftersale';
export default {
  props: { files: { type: Array, required: true }, reader: { type: Function, required: true } },
  data: () => ({ rows: [], generation: 0 }),
  watch: { files: { immediate: true, handler(value) { this.reset(value); } } },
  beforeDestroy() { this.generation += 1; this.clear(); },
  methods: {
    clear() { this.rows.forEach(row => { if (row.release) row.release(); row.url = ''; row.release = null; }); },
    reset(files) {
      this.generation += 1; this.clear();
      this.rows = files.map((id, index) => ({ id: u64(id), position: index + 1, url: '', loading: false, error: '', release: null }));
      this.rows.forEach(row => this.load(row));
    },
    async load(row) {
      if (row.loading) return; const generation = this.generation; row.loading = true; row.error = '';
      try {
        // MP receives the page method before Vue binds it to its owning page.
        // #ifdef MP
        const image = await this.reader.call(this.$parent, row.id);
        // #endif
        // #ifndef MP
        const image = await this.reader(row.id);
        // #endif
        if (generation !== this.generation || !this.rows.includes(row)) { image.release(); return; }
        if (row.release) row.release(); row.url = image.url; row.release = image.release;
      } catch (error) { if (generation === this.generation && this.rows.includes(row)) row.error = errorText(error); }
      finally { if (generation === this.generation && this.rows.includes(row)) row.loading = false; }
    },
    imageFailed(row) { if (row.release) row.release(); row.release = null; row.url = ''; row.error = '凭证图片未能显示，请重新读取'; }
  }
};
</script>
<style scoped>
.private-evidence { box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 20rpx; min-width: 0; width: 100%; }
.evidence-card { box-sizing: border-box; width: 100%; max-width: 480rpx; min-width: 0; padding: 16rpx; background: #f7f8fa; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12rpx; }
.evidence-image { display: block; width: 100%; }
.evidence-note { font-size: 24rpx; color: #606266; overflow-wrap: anywhere; }
.evidence-button { box-sizing: border-box; display: flex; align-items: center; justify-content: center; min-height: 44px; height: auto; max-width: 100%; min-width: 0; margin: 0; padding: 10px 14px; border: 1px solid #ddd; background: #fff; color: #333; font-size: 26rpx; line-height: 1.4; white-space: normal; text-align: center; }
.evidence-button::after { border: 0; }
</style>
