<template>
  <figure class="demo-qr-code">
    <div class="demo-qr-code__canvas">
      <img
        v-if="dataUrl"
        :src="dataUrl"
        :alt="label"
        width="320"
        height="320"
      >
      <span v-else>二维码生成中</span>
    </div>
    <figcaption>{{ label }}</figcaption>
    <a
      v-if="dataUrl"
      class="demo-qr-code__download"
      :href="dataUrl"
      :download="downloadName"
    >
      下载二维码
    </a>
  </figure>
</template>

<script>
import QRCode from 'qrcode';

export default {
  name: 'QrCode',
  props: {
    value: {
      type: String,
      required: true,
    },
    label: {
      type: String,
      default: '演示二维码',
    },
    downloadName: {
      type: String,
      default: 'demo-qr.png',
    },
  },
  data() {
    return {
      dataUrl: '',
    };
  },
  watch: {
    value: {
      immediate: true,
      handler() {
        this.renderCode();
      },
    },
  },
  methods: {
    async renderCode() {
      this.dataUrl = await QRCode.toDataURL(this.value, {
        width: 320,
        margin: 2,
        errorCorrectionLevel: 'M',
      });
    },
  },
};
</script>

<style scoped>
.demo-qr-code {
  margin: 0;
  text-align: center;
}

.demo-qr-code__canvas {
  display: grid;
  width: min(320px, 100%);
  aspect-ratio: 1;
  margin: 0 auto 12px;
  place-items: center;
  color: #909399;
  background: #fff;
}

.demo-qr-code__canvas img {
  width: 100%;
  height: 100%;
}

.demo-qr-code figcaption {
  color: #606266;
  font-size: 13px;
}

.demo-qr-code__download {
  display: inline-block;
  margin-top: 10px;
  color: #e93323;
  font-size: 13px;
}
</style>
