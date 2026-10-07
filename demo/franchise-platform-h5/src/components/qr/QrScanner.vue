<template>
  <section class="qr-scanner">
    <div :id="elementId" class="qr-scanner__reader" />

    <div class="qr-scanner__actions">
      <button
        type="button"
        class="qr-scanner__action"
        :disabled="busy || cameraActive"
        @click="startCamera"
      >
        <Camera :size="18" aria-hidden="true" />
        打开摄像头
      </button>

      <label class="qr-scanner__action">
        <ImageIcon :size="18" aria-hidden="true" />
        识别二维码图片
        <input
          class="qr-scanner__file"
          type="file"
          accept="image/*"
          aria-label="识别二维码图片"
          :disabled="busy"
          @change="scanImage"
        >
      </label>
    </div>

    <form class="qr-scanner__backup" @submit.prevent="submitBackup">
      <label for="backup-verify-code">备用核销编号</label>
      <div class="qr-scanner__backup-row">
        <Keyboard :size="18" aria-hidden="true" />
        <input
          id="backup-verify-code"
          v-model.trim="backupCode"
          inputmode="numeric"
          maxlength="24"
          placeholder="请输入核销编号"
        >
        <button type="submit" :disabled="!backupCode || busy">查询预约</button>
      </div>
    </form>
  </section>
</template>

<script>
import { Camera, Image as ImageIcon, Keyboard } from 'lucide-vue';
import { createScanner } from '../../qr/scanner';

export default {
  name: 'QrScanner',
  components: {
    Camera,
    ImageIcon,
    Keyboard,
  },
  props: {
    busy: {
      type: Boolean,
      default: false,
    },
    elementId: {
      type: String,
      default: 'demo-qr-reader',
    },
  },
  data() {
    return {
      scanner: null,
      cameraActive: false,
      backupCode: '',
    };
  },
  beforeDestroy() {
    this.stopScanner();
  },
  methods: {
    ensureScanner() {
      if (!this.scanner) {
        this.scanner = createScanner(this.elementId);
      }
      return this.scanner;
    },
    async startCamera() {
      try {
        await this.ensureScanner().start(
          (value) => this.$emit('decoded', value),
          () => {},
        );
        this.cameraActive = true;
      } catch (_error) {
        this.$emit(
          'error',
          '无法使用摄像头，请检查浏览器权限，或改用二维码图片。',
        );
      }
    },
    async scanImage(event) {
      const [file] = event.target.files;
      if (!file) {
        return;
      }
      try {
        const value = await this.ensureScanner().scanFile(file);
        this.$emit('decoded', value);
      } catch (_error) {
        this.$emit('error', '未能识别二维码图片，请更换清晰图片。');
      } finally {
        event.target.value = '';
      }
    },
    submitBackup() {
      this.$emit('backup', this.backupCode);
    },
    async stopScanner() {
      if (!this.scanner) {
        return;
      }
      try {
        await this.scanner.stop();
      } catch (_error) {
        // The scanner may already be released by the browser.
      }
      this.scanner = null;
      this.cameraActive = false;
    },
  },
};
</script>

<style scoped>
.qr-scanner__reader {
  overflow: hidden;
  width: 100%;
  min-height: 280px;
  background: #111;
  border-radius: 8px;
}

.qr-scanner__actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 16px;
}

.qr-scanner__action {
  position: relative;
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #303133;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
}

.qr-scanner__file {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
}

.qr-scanner__backup {
  margin-top: 20px;
}

.qr-scanner__backup > label {
  display: block;
  margin-bottom: 8px;
  color: #606266;
  font-size: 13px;
}

.qr-scanner__backup-row {
  display: grid;
  grid-template-columns: 20px minmax(0, 1fr) auto;
  min-height: 42px;
  align-items: center;
  gap: 8px;
  padding-left: 12px;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 6px;
}

.qr-scanner__backup-row input {
  min-width: 0;
  border: 0;
  outline: 0;
}

.qr-scanner__backup-row button {
  align-self: stretch;
  padding: 0 16px;
  color: #fff;
  background: #e93323;
  border: 0;
}

@media (max-width: 430px) {
  .qr-scanner__actions {
    grid-template-columns: 1fr;
  }
}
</style>
