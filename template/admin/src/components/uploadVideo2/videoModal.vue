<template>
  <el-dialog @closed="resetIfClosed" :visible.sync="visible" :width="productId !== null ? 'min(1024px, calc(100vw - 32px))' : '1024px'" title="选择视频" :close-on-click-modal="false">
    <uploaFile
      v-if="visible"
      :isChoice="isMore"
      :productId="productId"
      @getVideo="getImage"
      :gridBtn="gridBtn"
      :gridPic="gridPic"
    ></uploaFile>
  </el-dialog>
</template>

<script>
import uploaFile from './index.vue';
export default {
  name: '',
  components: { uploaFile },
  data() {
    return {
      isChoice: '',
      visible: false,
      callback: function () {},
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
      more: false,
      productId: null,
      modelName: undefined,
      booleanVal: undefined,
    };
  },
  computed: {
    isMore() {
      return this.more ? 'more' : 'one';
    },
  },
  methods: {
    resetScope() {
      this.productId = null; this.callback = function () {}; this.more = false;
      this.modelName = undefined; this.booleanVal = undefined;
    },
    resetIfClosed() { if (!this.visible) this.resetScope(); },
    handleClose() {
      this.visible = false;
      this.callback(this.visible);
    },
    getImage(img) {
      if (!this.visible) return;
      this.callback(img);
      this.visible = false;
    },
  },
};
</script>
<style lang="scss" scoped></style>
