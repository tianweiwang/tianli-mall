<template>
  <div class="main" v-loading="loading">
    <el-alert v-if="productPhoneMessage" :title="productPhoneMessage" type="warning" :closable="false" show-icon />
    <div v-if="uploading">
      <div class="img-list" :class="{ 'none-card': imgList.length }">
        <el-upload
          ref="upload"
          :action="fileUrl"
          list-type="picture-card"
          :on-change="fileChange"
          :on-error="fileError"
          :before-upload="beforeUpload"
          :file-list="imgList"
          :auto-upload="false"
          :multiple="true"
          :limit="limit"
          accept="image/*"
          :disabled="productScene && (!productPhoneReady || productPhoneHold || loading)"
        >
          <div slot="default" class="upload-card" v-if="!imgList.length">
            <i class="el-icon-plus"></i>
            <p class="text">点击选择图片</p>
          </div>
          <div slot="file" slot-scope="{ file }">
            <img class="el-upload-list__item-thumbnail" :src="file.url" alt="" />
            <i class="el-icon-error btndel" v-db-click @click="handleRemove(file)" />
          </div>
        </el-upload>
      </div>

      <div class="footer">
        <div v-if="imgList.length">共{{ imgList.length }}/{{ limit }}张，{{ (allSize / 1000000).toFixed(2) }} M</div>
        <div v-else></div>
        <div class="upload-btn">
          <div v-if="!productScene && imgList.length < limit" class="btn" v-db-click @click="selectImgs">
            {{ imgList.length ? '继续选择' : '选择图片' }}
          </div>
          <div v-if="!productScene" class="btn upload" :class="{ 'no-pic': !imgList.length }" v-db-click @click="submitUpload">确认上传</div>
          <el-button v-if="productScene && imgList.length < limit" class="btn" :disabled="!productPhoneReady || productPhoneHold || loading" @click="selectImgs">{{ imgList.length ? '继续选择' : '选择图片' }}</el-button>
          <el-button v-if="productScene" class="btn upload" type="primary" :loading="loading" :disabled="!productPhoneReady || productPhoneHold || productPhoneInvalidFiles || !imgList.length" @click="submitUpload">确认发送</el-button>
        </div>
      </div>
    </div>
    <div v-else class="upload-success">
      <div class="success">
        <img class="image" src="@/assets/images/success.jpg" alt="" />
      </div>
      <div class="text">{{ productScene ? "图片已发送，请回电脑确认" : "图片上传成功" }}</div>
      <div v-if="!productScene" class="again" v-db-click @click="again">继续上传</div>
      <el-button v-else :disabled="productPhoneHold || !productPhoneReady || productPhoneSentCount >= 50 || productPhoneSentBytes >= 67108864" @click="again">继续上传</el-button>
    </div>
  </div>
</template>

<script>
import Setting from '@/setting';
import { scanUpload } from '@/api/setting';
import { takePhoneScanFragment, stageProductScan } from '@/api/product-media';
import compressImg from '@/utils/compressImg.js';
import { isPicUpload } from '@/utils';

export default {
  name: 'app_upload_file',
  data() {
    return {
      productScene: false,
      productPhoneEntry: null,
      productPhoneReady: false,
      productPhoneHold: false,
      productPhoneInvalidFiles: false,
      productPhoneMessage: '',
      productPhoneGeneration: 0,
      productPhoneSentCount: 0,
      productPhoneSentBytes: 0,
      productPhoneControllers: new Set(),
      productPhoneUrls: new Set(),
      fileUrl: Setting.apiBaseURL + '/image/scan_upload',
      imgList: [],
      allSize: 0,
      token: '',
      uploading: true,
      limit: 20,
      loading: false,
      pid: 0,
    };
  },
  async created() {
    this.productScene = this.$route.query.scene === 'product_media' || window.location.hash.includes('scan_cap=');
    if (this.productScene) {
      let entry = null;
      try { entry = takePhoneScanFragment(this.$route); } catch (error) { this.productPhoneMessage = error.message; }
      try {
        await this.$router.replace({ path: this.$route.path, query: this.$route.query, hash: '' });
        if (this.$route.hash || window.location.hash) throw new Error('扫码地址尚未清理');
        this.productPhoneEntry = entry; this.productPhoneReady = entry !== null;
        if (entry) this.productPhoneMessage = '图片发送后还需在电脑确认；二维码有效10分钟，以电脑生成时间为准';
      } catch (error) { this.productPhoneEntry = null; this.productPhoneMessage = '扫码页面尚未就绪，请重新扫码'; }
      document.title = '手机端扫码上传';
      return;
    }
    this.token = this.$route.query.token;
    this.pid = this.$route.query.pid;
    document.title = '手机端扫码上传';
  },
  watch: {
    '$route.query.lease_id'(value) { if (this.productScene && this.productPhoneEntry && value !== this.productPhoneEntry.lease) this.invalidateProductPhone(); },
    '$route.query.scene'(value) { if (this.productScene && value !== 'product_media') this.invalidateProductPhone(); },
  },
  beforeDestroy() { this.invalidateProductPhone(false); },
  methods: {
    phoneCurrent(entry, generation) {
      return this.productPhoneReady && this.productPhoneEntry === entry && this.productPhoneGeneration === generation && this.$route.query.scene === 'product_media' && this.$route.query.lease_id === entry.lease;
    },
    releasePhoneUrls() {
      this.productPhoneUrls.forEach(url => window.URL.revokeObjectURL(url)); this.productPhoneUrls.clear();
    },
    invalidateProductPhone(show = true) {
      this.productPhoneGeneration += 1; this.productPhoneControllers.forEach(controller => controller.abort()); this.productPhoneControllers.clear();
      this.productPhoneEntry = null; this.productPhoneReady = false; this.loading = false; this.releasePhoneUrls();
      if (show) this.productPhoneMessage = '扫码页面已变化，请回电脑重新扫码';
    },
    async submitProductPhone() {
      if (this.loading || !this.productPhoneReady || this.productPhoneHold || this.productPhoneInvalidFiles || !this.imgList.length) return;
      const entry = this.productPhoneEntry; const generation = this.productPhoneGeneration; const selected = this.imgList.slice();
      const total = selected.reduce((sum, item) => sum + item.raw.size, 0);
      if (this.productPhoneSentCount + selected.length > 50 || this.productPhoneSentBytes + total > 67108864) { this.productPhoneMessage = '本次扫码最多50张、总计64MiB，请回电脑确认或重新生成二维码'; return; }
      this.loading = true;
      try {
        for (const item of selected) {
          if (!this.phoneCurrent(entry, generation)) return;
          const controller = new AbortController(); this.productPhoneControllers.add(controller);
          const timer = window.setTimeout(() => controller.abort(), 100000);
          try { await stageProductScan(entry, item.raw, controller.signal); }
          finally { window.clearTimeout(timer); this.productPhoneControllers.delete(controller); }
          if (!this.phoneCurrent(entry, generation)) return;
          this.productPhoneSentCount += 1; this.productPhoneSentBytes += item.raw.size;
        }
        if (!this.phoneCurrent(entry, generation)) return;
        this.uploading = false; this.productPhoneMessage = '图片已发送，请回电脑确认保存'; this.releasePhoneUrls();
      } catch (error) {
        if (this.productPhoneEntry === entry && this.productPhoneGeneration === generation) { this.productPhoneHold = true; this.productPhoneMessage = error.message || '发送结果未确认，请查看电脑图库'; }
      } finally { if (this.productPhoneEntry === entry && this.productPhoneGeneration === generation) this.loading = false; }
    },
    selectImgs() {
      if (this.loading) return;
      this.$refs['upload'].$refs['upload-inner'].handleClick();
    },
    again() {
      if (this.productScene) { if (!this.productPhoneReady || this.productPhoneHold || this.productPhoneSentCount >= 50 || this.productPhoneSentBytes >= 67108864) return; this.releasePhoneUrls(); this.limit = Math.min(20, 50 - this.productPhoneSentCount); }
      this.uploading = true;
      this.productPhoneInvalidFiles = false;
      this.imgList = [];
      this.allSize = 0;
    },
    async submitUpload() {
      if (this.productScene) return this.submitProductPhone();
      if (this.imgList.length) {
        if (this.loading) return;
        this.loading = true;
        for (let i = 0; i < this.imgList.length; i++) {
          const file = this.imgList[i].raw;
          await this.uploadItem(file);
          if (i == this.imgList.length - 1) {
            this.uploading = false;
            this.loading = false;
          }
        }
      } else {
        this.$message.warning('请先选择图片');
      }
    },
    handleRemove(file) {
      if (this.productScene && file.url && file.url.startsWith("blob:")) { window.URL.revokeObjectURL(file.url); this.productPhoneUrls.delete(file.url); }
      let index = this.imgList.findIndex((e) => {
        return e.url == file.url;
      });
      this.imgList.splice(index, 1);
      if (this.productScene) this.productPhoneInvalidFiles = !this.imgList.every(item => item.raw instanceof Blob && item.raw.size > 0 && item.raw.size <= 52428800);
      this.$nextTick((e) => {
        let s = 0;
        if (this.imgList.length) {
          this.imgList.map((e) => {
            s += e.raw.size;
          });
          this.allSize = s;
        } else {
          this.allSize = 0;
        }
      });
    },

    uploadItem(file) {
      if (this.productScene) return Promise.reject(new Error("请使用本次商品扫码发送入口"));
      return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('uploadToken', this.token);
        formData.append('pid', this.pid);
        scanUpload(formData)
          .then((res) => {
            if (res.status == 200) {
              resolve();
            } else {
              this.loading = false;
              this.$message({
                message: '上传失败',
                type: 'error',
                duration: 1000,
              });
            }
          })
          .catch((err) => {
            this.loading = false;
            this.$message.error(err.msg);
          });
      });
    },
    fileError(err, file, fileList) {
      console.log(err, file, fileList);
    },
    beforeUpload(file) {
      return isPicUpload(file);
    },
    async fileChange(file, fileList) {
      if (this.productScene) {
        if (!this.productPhoneReady || this.productPhoneHold || this.loading) return;
        fileList.forEach(item => { if (item.url && item.url.startsWith('blob:')) this.productPhoneUrls.add(item.url); });
        this.imgList = fileList; this.allSize = fileList.reduce((sum, item) => sum + item.raw.size, 0);
        this.productPhoneInvalidFiles = !fileList.every(item => item.raw instanceof Blob && item.raw.size > 0 && item.raw.size <= 52428800);
        if (this.productPhoneInvalidFiles) this.productPhoneMessage = '存在超过50MiB或不可读取的图片，请移除后再发送；实际限制以服务器配置为准';
        return;
      }
      if (file.size >= 2097152) {
        await this.comImg(file.raw).then((res) => {
          fileList.map((e) => {
            if (e.uid === file.uid) {
              this.allSize += res.size;
              e.raw = res;
            }
          });
          this.imgList = fileList;
        });
      } else {
        this.imgList = fileList;
        let s = 0;
        if (this.imgList.length) {
          this.imgList.map((e) => {
            s += e.raw.size;
          });
          this.allSize = s;
        } else {
          this.allSize = 0;
        }
      }
    },
    comImg(file) {
      return new Promise((resolve, reject) => {
        compressImg(file).then((res) => {
          resolve(res);
        });
      });
    },
    loadData(item, callback) {
      getCategoryListApi({
        pid: item.value,
      })
        .then(async (res) => {
          const data = res.data.list;
          callback(data);
        })
        .catch((res) => {});
    },
  },
};
</script>
<style lang="scss" scoped>
.upload-btn {
  display: flex;
  align-items: center;
}
.img-list {
  padding: 10px;
  overflow: scroll;
  height: calc(100vh - 50px);
  background-color: #fff;
}

::v-deep .el-upload-list--picture-card .el-upload-list__item {
  // width: 113px;
  // height: 113px;
  // line-height: 113px;
  // overflow: inherit;
  margin: 1% 1% 0px 1%;
  width: 31.3%;
  height: 31.3%;
  padding-top: 31.3%;
  aspect-ratio: 1 / 1;
}
::v-deep .el-upload-list--picture-card .el-upload-list__item > div {
  // position: relative;
  width: 100%;
  height: 100%;
}
::v-deep .el-upload--picture-card {
  width: 100%;
  height: 100%;
  display: flex;
  height: 146px;
  justify-content: center;
  align-items: center;
  background: #f9f9f9;
}
::v-deep .el-upload-list--picture-card .el-upload-list__item img {
  width: 100%;
  height: 100%;
  border-radius: 6px;
  object-fit: cover;
  position: absolute;
  left: 0;
  top: 0;
}
.btndel {
  position: absolute;
  z-index: 1;
  font-size: 18px;
  right: -1px;
  top: -1px;
  color: #282828;
  opacity: 0.5;
}
::v-deep .el-upload--picture-card:hover,
.el-upload:focus {
  border-color: #c0ccda;
}
.img-box {
  display: flex;
  padding-left: 100px;
  flex-wrap: wrap;
}
.none-card ::v-deep .el-upload--picture-card {
  display: none !important;
}
.footer {
  padding: 0 10px 0 15px;
  position: fixed;
  bottom: 0;
  width: 100%;
  box-sizing: border-box;
  background-color: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(10px);
  z-index: 277;
  border-top: 1px solid #f0f0f0;
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  .btn {
    border: 1px solid #cccccc;
    width: 88px;
    height: 30px;
    border-radius: 15px;
    color: #000;
    font-size: 14px;
    font-family: PingFang SC-Regular, PingFang SC;
    font-weight: 400;
    color: #666666;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .upload {
    background-color: #e93323;
    color: #fff;
    margin-left: 10px;
  }
  .upload.no-pic {
    background: #e93323;
    opacity: 0.3;
  }
}
.upload-card {
  display: flex;
  flex-direction: column;
  line-height: 16px;
  .el-icon-plus {
    font-size: 28px;
    font-weight: bold;
    color: #bbbbbb;
  }
  .text {
    font-size: 13px;
    font-weight: 400;
    color: #999999;
    margin-top: 18px;
  }
}
.upload-success {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  height: 80vh;
  .success {
    width: 50px;
    height: 50px;
    background: #4bbc12;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    .image {
      width: 60%;
    }
  }
  .text {
    font-size: 16px;
    font-family: PingFang SC-Medium, PingFang SC;
    font-weight: 500;
    color: #282828;
    margin-bottom: 40px;
  }
  .again {
    width: 150px;
    height: 43px;
    border-radius: 21px;
    text-align: center;
    line-height: 41px;
    font-size: 15px;
    font-weight: 400;
    color: #333333;
    border: 1px solid #cccccc;
  }
}
</style>
