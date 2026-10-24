<template>
  <el-dialog :title="title" :visible="visible" width="min(92vw, 960px)" append-to-body :close-on-click-modal="false" :before-close="close" custom-class="merchant-media-picker">
    <attachment-library v-if="visible" selectable :kind="kind" :product-id="productId" :product-context="productContext" :invocation="invocation" @select="$emit('select', $event)" @busy-change="busy = $event" />
  </el-dialog>
</template>
<script>
import AttachmentLibrary from './AttachmentLibrary.vue';
export default {
  components: { AttachmentLibrary },
  props: { visible: Boolean, kind: { type: String, default: 'image' }, productId: { default: null }, productContext: { default: null }, invocation: { type: Number, default: 0 } },
  data: () => ({ busy: false }),
  computed: { title() { return '选择本店' + ({ image: '图片', video: '视频', audio: '音频' }[this.kind] || '素材'); } },
  watch: { visible() { this.busy = false; }, invocation() { this.busy = false; } },
  methods: { close() { if (!this.busy) this.$emit('close'); } },
};
</script>
