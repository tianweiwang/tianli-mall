<template>
  <el-dialog @closed="resetIfClosed" :visible.sync="visible" width="1024px" title="选择图片" :close-on-click-modal="false">
    <uploadPictures
      v-if="visible"
      :isChoice="isMore"
      :productId="productId"
      @getPic="getImage"
      :gridBtn="gridBtn"
      :gridPic="gridPic"
    ></uploadPictures>
  </el-dialog>
</template>

<script>
import uploadPictures from './index.vue';
export default {
  name: '',
  components: { uploadPictures },
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
      return this.more ? '多选' : '单选';
    },
  },
  methods: {
    resetScope() {
      this.productId = null;
      this.callback = function () {};
      this.more = false;
      this.modelName = undefined;
      this.booleanVal = undefined;
    },
    resetIfClosed() { if (!this.visible) this.resetScope(); },
    handleClose() {
      this.visible = false;
      this.callback(this.visible);
    },
    getImage(img) {
      if (!this.visible) return;
      const callback = this.callback;
      callback(img);
      this.visible = false;
    },
  },
};
</script>
<style lang="scss" scoped></style>
