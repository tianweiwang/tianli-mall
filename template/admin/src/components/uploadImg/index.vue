<template>
  <div>
    <el-dialog
      title="上传图片"
      :visible.sync="uploadModal"
      :append-to-body="true"
      :width="isIframe ? '100%' : '1024px'"
      :fullscreen="isIframe"
      @close="closed"
      :close-on-click-modal="productId === null"
      :close-on-press-escape="productId === null || !loading"
      :show-close="productId === null || !loading"
    >
      <div class="main" v-loading="loading">
        <el-alert v-if="productMessage" :title="productMessage" :description="productReceiptDescription" type="warning" :closable="false" show-icon />
        <el-form :model="ruleForm" :rules="rules" ref="ruleForm" label-width="100px" class="demo-ruleForm">
          <el-form-item label="上传方式：" prop="type">
            <el-radio-group :disabled="productId !== null && loading" v-model="ruleForm.type" @input="radioChange(ruleForm.type)">
              <el-radio :label="0">本地上传</el-radio>
              <el-radio :label="1">网络上传</el-radio>
              <el-radio :label="2">扫码上传</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="上传至分组：" prop="region" v-show="ruleForm.type == 0 || ruleForm.type == 1">
            <el-cascader
              class="form-width"
              v-model="ruleForm.region"
              :props="props"
              :options="categoryList"
              @change="handleChange"
            ></el-cascader>
          </el-form-item>
          <el-form-item label="网络图片：" prop="region" v-if="ruleForm.type == 1">
            <el-input class="form-width" v-model="webImgUrl" placeholder="请网络图片地址"></el-input>
            <span class="tq-text" v-db-click @click="getImg">提取照片</span>
          </el-form-item>
          <el-form-item label="上传图片：" prop="region" v-if="ruleForm.type == 0">
            <div class="acea-row">
              <div class="uploadCont">
                <el-upload
                  ref="upload"
                  :action="fileUrl"
                  list-type="picture-card"
                  :on-change="fileChange"
                  :file-list="ruleForm.imgList"
                  :auto-upload="false"
                  :data="uploadData"
                  :headers="header"
                  :multiple="true"
                  :limit="limit"
                >
                  <i slot="default" class="el-icon-plus"></i>
                  <div
                    slot="file"
                    slot-scope="{ file }"
                    draggable="false"
                    @dragstart="handleDragStart($event, file)"
                    @dragover="handleDragOver($event, file)"
                    @dragenter="handleDragEnter($event, file)"
                    @dragend="handleDragEnd($event, file)"
                  >
                    <img class="el-upload-list__item-thumbnail" :src="file.url" alt="" />
                    <i class="el-icon-error btndel" v-db-click @click="handleWebRemove(file)" />
                  </div>
                </el-upload>
                <div class="tips">
                  {{ productId !== null ? "支持JPEG、PNG、GIF、WebP；每张最大50MiB且以服务器配置为准，可拖动调整顺序" : "建议上传图片最大宽度750px，不超过3MB；仅支持jpeg、jpg、png格式，可拖拽调整上传顺序" }}
                </div>
              </div>
            </div>
          </el-form-item>
          <template v-if="ruleForm.type == 1">
            <div class="img-box pl100">
              <div
                v-for="(item, index) in ruleForm.imgList"
                :key="index"
                class="pictrue"
                draggable="false"
                @dragstart="handleDragStart($event, item)"
                @dragover.prevent="handleDragOver($event, item)"
                @dragenter="handleDragEnter($event, item)"
                @dragend="handleDragEnd($event, item)"
              >
                <img :src="item.url" />
                <i class="el-icon-error btndel" v-db-click @click="handleRemove(index)" />
              </div>
            </div>
          </template>
          <div class="code-image" v-if="ruleForm.type == 2">
            <div class="left">
              <el-form-item label="上传至分组：" prop="region">
                <el-cascader
                  class="form-width"
                  v-model="ruleForm.region"
                  :props="props"
                  :options="categoryList"
                  @change="handleChange"
                ></el-cascader>
              </el-form-item>
              <el-form-item label="二维码：" prop="region">
                <div class="code" ref="qrCodeUrl"></div>
                <div class="trip">扫描二维码，快速上传手机图片</div>
                <div class="trip-small">建议使用手机浏览器</div>
                <div class="trip-small" v-if="productId !== null && productExpires">二维码有效10分钟，有效至 {{ new Date(productExpires).toLocaleTimeString() }}</div>
              </el-form-item>
            </div>
            <div class="right">
              <el-button size="small" v-db-click @click="scanUploadGet">刷新图库</el-button>
              <div class="tip">刷新图库按钮，可显示移动端上传成功的图片</div>
              <div class="img-box">
                <div
                  v-for="(item, index) in ruleForm.imgList"
                  :key="index"
                  class="pictrue"
                  draggable="false"
                  @dragstart="handleDragStart($event, item)"
                  @dragover.prevent="handleDragOver($event, item)"
                  @dragenter="handleDragEnter($event, item)"
                  @dragend="handleDragEnd($event, item)"
                >
                  <img :src="productId !== null ? item.previewUrl : item.att_dir" />
                  <i class="el-icon-error btndel" v-db-click @click="handleWebRemove(item)" />
                </div>
              </div>
            </div>
          </div>
        </el-form>
      </div>

      <span slot="footer" class="dialog-footer">
        <el-button :disabled="productId !== null && loading" v-db-click @click="clear">取 消</el-button>
        <el-button type="primary" :disabled="!ruleForm.imgList.length || (productId !== null && (loading || productHold || productInvalidFiles || productClaimState === 'processing'))" v-db-click @click="submitUpload"
          >确 定</el-button
        >
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { getCategoryListApi, moveApi, onlineUpload, scanUploadCode } from '@/api/uploadPictures';
import Setting from '@/setting';
import { getCookies } from '@/libs/util';
import { captureProductContext, currentProductContext, requireProductContext, closeProductContext, receiptText, cancelProductRequests, releaseProductPreview, mediaId, mediaUpload, mediaOnline, mediaCategories, issueScan, pollScan, scanPreview, claimScan, closeScan } from '@/api/product-media';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import { fileUpload, scanUploadQrcode, scanUploadGet } from '@/api/setting';
import QRCode from 'qrcodejs2';
import compressImg from '@/utils/compressImg.js';
import { isPicUpload } from '@/utils/index';
export default {
  name: '',
  props: {
    productId: { default: null },
    productScope: { default: null },
    categoryList: {
      default: () => {
        return [];
      },
    },
    categoryId: {
      default: '',
    },
    isPage: {
      default: false,
    },
    isIframe: {
      default: false,
    },
  },
  watch: {
    productId() {
      this.stopProductScan(); closeProductContext(this.productSession); this.productSession = null;
      this.uploadModal = false; this.ruleForm.imgList = [];
      this.productMessage = "商品范围已变化，请重新打开上传";
    },
    uploadModal: {
      handler(newVal) {
        if (newVal) {
          this.ruleForm.region = this.productId !== null && this.categoryId === '' ? 0 : this.categoryId;
          if (this.productId !== null) this.openProductUpload();
        }
      },
      immediate: true,
    },
  },
  data() {
    return {
      productSession: null,
      productWasOpened: false,
      productMessage: '',
      productReceipt: null,
      productReceiptDescription: '',
      productHold: false,
      productInvalidFiles: false,
      productLease: '',
      productExpires: 0,
      productGeneration: 0,
      productPolling: false,
      productClaimState: '',
      productDismissed: new Set(),
      productLocalUrls: new Set(),
      webImgUrl: '',
      uploadModal: false,
      fileUrl: Setting.apiBaseURL + '/file/upload',
      header: {
        'Authori-zation': 'Bearer ' + getCookies('token'),
      },
      uploadData: {},
      props: { checkStrictly: true, emitPath: false, label: 'title', value: 'id' },
      disabled: false,
      ruleForm: {
        type: 0,
        region: '',
        imgList: [],
      },
      rules: { type: [{ required: true, message: '请选择活动资源', trigger: 'change' }] },
      qrcode: '',
      scanToken: '',
      limit: 20,
      loading: false,
      time: undefined,
    };
  },
  created() {
    this.productUnsubscribe = subscribePlatformAuthEpoch(() => {
      if (this.productId !== null) {
        this.stopProductScan(); closeProductContext(this.productSession); this.productSession = null;
        this.ruleForm.imgList = []; this.productMessage = '登录身份已变化，进行中的提交结果请先核对，再重新打开上传';
      }
    });
  },
  mounted() {},
  beforeDestroy() {
    if (this.productId !== null || this.productWasOpened) { this.uploadModal = false; this.disposeProductScan(true); }
    if (this.productUnsubscribe) this.productUnsubscribe();
    clearInterval(this.time);
    this.time = undefined;
  },
  methods: {
    openProductUpload() {
      this.productWasOpened = true;
      this.stopProductScan(); closeProductContext(this.productSession); this.productSession = null;
      this.loading = false;
      this.productMessage = ''; this.productReceipt = null; this.productReceiptDescription = ''; this.productHold = false; this.productInvalidFiles = false; this.productClaimState = ''; this.productLease = ''; this.productExpires = 0;
      this.productDismissed = new Set();
      try { this.productSession = captureProductContext(this.productId); } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; }
    },
    stopProductScan() {
      this.productGeneration += 1; window.clearTimeout(this.time); this.time = undefined; this.productPolling = false;
      if (this.productSession) {
        cancelProductRequests(this.productSession);
        Array.from(this.productSession.life.urls).forEach(url => releaseProductPreview(this.productSession, url));
      }
      this.productLocalUrls.forEach(url => window.URL.revokeObjectURL(url)); this.productLocalUrls.clear();
      if (this.$refs.qrCodeUrl) this.$refs.qrCodeUrl.innerHTML = '';
    },
    async disposeProductScan(remote) {
      const context = this.productSession; const scope = this.productScope; const lease = this.productLease;
      this.stopProductScan(); const generation = this.productGeneration;
      this.productLease = ''; this.productExpires = 0; this.productDismissed = new Set();
      this.ruleForm.imgList = [];
      const sameClose = () => !this._isDestroyed && !this._isBeingDestroyed && this.productSession === context && this.productScope === scope && this.productGeneration === generation && currentProductContext(context) && currentProductContext(scope);
      if (remote && lease && currentProductContext(context)) {
        try {
          const value = await closeScan(context, lease);
          if (sameClose()) this.$emit('mediaCloseResult', { scope, context, generation, lease_id: lease, state: value.state, cleanup_complete: value.cleanup_complete === true, message: value.state === 'closed' ? '' : value.state === 'pending' ? '扫码关闭仍待处理，请保留本次结果' : '扫码关闭结果未确认，请核对图片库', receipt: null });
        } catch (error) {
          if (sameClose()) this.$emit('mediaCloseResult', { scope, context, generation, lease_id: lease, state: 'unknown', cleanup_complete: false, message: error.message || '扫码关闭结果未确认', receipt: error.receipt || null });
        }
      }
      if (!this.uploadModal || this.productSession !== context) closeProductContext(context);
    },
    async productRadioChange(type) {
      if (this.loading) return;
      await this.disposeProductScan(true);
      if (!this.uploadModal || this.ruleForm.type !== type) return;
      this.productClaimState = ''; this.productInvalidFiles = false; this.productDismissed = new Set();
      if (type === 2) await this.issueProductScan();
    },
    async issueProductScan() {
      const context = this.productSession; const generation = this.productGeneration;
      if (this.productLease || this.loading || this.productHold) return;
      try {
        requireProductContext(context); this.loading = true;
        const value = await issueScan(context, mediaId(this.ruleForm.region, true));
        if (this.productSession !== context || this.productGeneration !== generation || !this.uploadModal) return;
        requireProductContext(context);
        this.productLease = value.lease_id; this.productExpires = value.expires_at_ms;
        this.$nextTick(() => { if (this.productSession === context && this.productGeneration === generation && this.uploadModal) this.creatQrCode(value.url); });
        this.productMessage = '手机图片发送后在此预览，点击确定才保存到商品图片库';
      } catch (error) {
        if (this.productSession === context && this.productGeneration === generation) { if (error.state === 'unknown') this.productHold = true; this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; }
      } finally {
        if (this.productSession === context && this.productGeneration === generation) { this.loading = false; if (this.productLease) this.pollProductScan(); }
      }
    },
    async pollProductScan() {
      const context = this.productSession; const lease = this.productLease; const generation = this.productGeneration;
      if (!lease || this.productPolling || !this.uploadModal || this.ruleForm.type !== 2 || !currentProductContext(context)) return;
      window.clearTimeout(this.time); this.productPolling = true;
      try {
        const value = await pollScan(context, lease);
        if (this.productSession !== context || this.productGeneration !== generation || this.productLease !== lease) return;
        requireProductContext(context); this.productExpires = value.expires_at_ms;
        const state = value.state.toLowerCase();
        if (['closing', 'closed', 'gone', 'expired'].includes(state)) { this.productMessage = '二维码已失效，请重新打开扫码上传'; this.productHold = true; return; }
        if (state === 'unknown') { this.productHold = true; this.productMessage = '本次扫码结果未确认，请保留记录并核对图库'; return; }
        if (state === 'claiming') { this.productClaimState = 'processing'; this.productMessage = '图片正在认领处理中，请保留本次结果'; }
        if (state === 'claimed') { this.productHold = true; this.productMessage = '本次图片已认领，请返回商品图片库核对'; return; }
        if (state !== 'open' && state !== 'claiming') throw new Error('扫码状态未确认，请刷新图库');
        for (const item of value.list) {
          if (this.productDismissed.has(item.stage_id) || this.ruleForm.imgList.some(existing => existing.stage_id === item.stage_id)) continue;
          const url = await scanPreview(context, lease, item);
          if (this.productSession !== context || this.productGeneration !== generation || this.productLease !== lease || !this.uploadModal) { releaseProductPreview(context, url); return; }
          this.ruleForm.imgList.push({ ...item, previewUrl: url });
        }
      } catch (error) {
        if (this.productSession === context && this.productGeneration === generation) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message || '扫码图库读取未完成'; }
      } finally {
        if (this.productSession === context && this.productGeneration === generation) {
          this.productPolling = false;
          if (currentProductContext(context) && this.uploadModal && this.ruleForm.type === 2 && this.productLease === lease && !this.productHold && this.productClaimState !== 'processing') this.time = window.setTimeout(() => this.pollProductScan(), 2000);
        }
      }
    },
    dismissProductStage(item) {
      if (this.loading || this.productHold || this.productClaimState === 'processing') return;
      this.productDismissed.add(item.stage_id);
      releaseProductPreview(this.productSession, item.previewUrl);
      this.ruleForm.imgList = this.ruleForm.imgList.filter(row => row.stage_id !== item.stage_id);
    },
    async loadProductCategories(item, callback) {
      const context = this.productSession;
      try { const value = await mediaCategories(context, { pid: mediaId(item.value, true), all: 0, type: 0 }); if (this.productSession === context && currentProductContext(context)) callback(value.list); }
      catch (error) { if (this.productSession === context) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; } }
    },
    async submitProductUpload() {
      if (this.loading || this.productHold || this.productInvalidFiles || this.productClaimState === 'processing' || !this.ruleForm.imgList.length) return;
      const context = this.productSession;
      this.productGeneration += 1; cancelProductRequests(context); this.productPolling = false;
      const generation = this.productGeneration; const type = this.ruleForm.type;
      const selected = this.ruleForm.imgList.slice(); const destination = this.ruleForm.region;
      try {
        requireProductContext(context); this.loading = true; window.clearTimeout(this.time);
        if (type === 0) {
          for (const item of selected) {
            await mediaUpload(context, item.raw, mediaId(destination, true));
            if (this.productSession !== context || this.productGeneration !== generation || !this.uploadModal) return;
            requireProductContext(context);
            this.ruleForm.imgList = this.ruleForm.imgList.filter(remaining => remaining !== item);
            if (item.url && item.url.startsWith('blob:')) { window.URL.revokeObjectURL(item.url); this.productLocalUrls.delete(item.url); }
          }
        } else if (type === 1) {
          await mediaOnline(context, selected.map(item => item.url), mediaId(destination, true));
        } else if (type === 2) {
          const result = await claimScan(context, this.productLease, selected.map(item => item.stage_id), mediaId(destination, true));
          if (this.productSession !== context || this.productGeneration !== generation) return;
          if (result.state === 'processing') { this.productClaimState = 'processing'; this.productMessage = '图片正在认领处理中，请保留本次结果并核对图片库'; return; }
        }
        if (this.productSession !== context || this.productGeneration !== generation || !this.uploadModal) return;
        requireProductContext(context); this.$message.success('上传成功'); this.$emit('uploadSuccess'); this.uploadModal = false;
      } catch (error) {
        if (this.productSession === context && this.productGeneration === generation) { if (error.state === 'unknown') this.productHold = true; this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message || '上传未完成，请核对图片库'; }
      } finally { if (this.productSession === context && this.productGeneration === generation) this.loading = false; }
    },
    radioChange(type) {
      if (this.productId !== null) return this.productRadioChange(type);
      this.ruleForm.type = type;
      this.ruleForm.imgList = [];
      clearInterval(this.time);
      this.time = undefined;
      if (type == 2) {
        this.scanUploadQrcode();
        this.time = setInterval((e) => {
          this.scanUploadGet();
        }, 2000);
      }
    },
    scanUploadQrcode() {
      if (this.productId !== null) return this.issueProductScan();
      scanUploadQrcode(this.ruleForm.region).then((res) => {
        this.creatQrCode(res.data.url);
        this.scanToken = res.data.url;
      });
    },
    scanUploadGet() {
      if (this.productId !== null) return this.pollProductScan();
      let token = this.scanToken.split('token=')[1];
      scanUploadGet(token).then((res) => {
        this.ruleForm.imgList = res.data;
      });
    },

    getImg() {
      if (this.productId !== null) {
        try {
          requireProductContext(this.productSession);
          if (this.ruleForm.imgList.length >= 50 || typeof this.webImgUrl !== 'string' || this.webImgUrl.length > 2048 || !/^https?:\/\//i.test(this.webImgUrl)) throw new Error('请填写有效图片地址，一次最多50张');
          const url = new URL(this.webImgUrl);
          if (url.username || url.password || url.hash) throw new Error('图片地址不能包含身份信息或锚点');
          this.ruleForm.imgList.push({ url: this.webImgUrl });
        } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; }
        return;
      }
      if (!this.webImgUrl) {
        this.$message.error('请先输入图片地址');
        return;
      }
      if (this.webImgUrl.indexOf('.php') != -1) {
        this.$message.error('请先输入其他图片地址');
        return;
      }
      this.ruleForm.imgList.push({
        url: this.webImgUrl,
      });
    },
    async submitUpload() {
      if (this.productId !== null) return this.submitProductUpload();
      if (!this.ruleForm.imgList.length) return this.$message.warning('请先选择图片');
      if (this.ruleForm.type == 0) {
        this.uploadData = {
          pid: this.ruleForm.region,
        };
        if (this.ruleForm.imgList.length) {
          if (this.loading) return;
          this.loading = true;
          for (let i = 0; i < this.ruleForm.imgList.length; i++) {
            const file = this.ruleForm.imgList[i].raw;
            await this.uploadItem(file);
            if (i == this.ruleForm.imgList.length - 1) {
              this.$message.success('上传成功');
              this.$emit('uploadSuccess');
              this.uploadModal = false;
              this.loading = false;
              this.initData();
            }
          }
        }
      } else if (this.ruleForm.type == 1) {
        let urls = this.ruleForm.imgList.map((e) => {
          return e.url;
        });
        if (urls.length) {
          if (this.loading) return;
          this.loading = true;
          onlineUpload({ pid: this.ruleForm.region, images: urls })
            .then((res) => {
              this.$message.success('上传成功');
              this.$emit('uploadSuccess');
              this.uploadModal = false;
              this.loading = false;
              this.initData();
            })
            .catch((err) => {
              this.loading = false;
              this.$message.error(err.msg);
            });
        }
      } else if (this.ruleForm.type == 2) {
        let attId = this.ruleForm.imgList.map((e) => {
          return e.att_id;
        });
        moveApi({ pid: this.ruleForm.region, images: attId }).then((res) => {
          this.$message.success('上传成功');
          this.$emit('uploadSuccess');
          this.uploadModal = false;
          this.initData();
        });
      }
    },
    uploadItem(file) {
      if (this.productId !== null) return mediaUpload(this.productSession, file, this.ruleForm.region);
      return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('pid', this.ruleForm.region);
        fileUpload(formData)
          .then((res) => {
            if (res.status == 200) {
              resolve();
              // this.$emit('uploadImgSuccess', res.data);
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
    beforeUpload(file) {
      console.log(file);
    },
    creatQrCode(url) {
      this.$refs.qrCodeUrl.innerHTML = '';
      var qrcode = new QRCode(this.$refs.qrCodeUrl, {
        text: url, // 需要转换为二维码的内容
        width: 160,
        height: 160,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
      });
    },
    handleWebRemove(file) {
      if (this.productId !== null && this.ruleForm.type === 2) return this.dismissProductStage(file);
      if (this.productId !== null && file.url && file.url.startsWith('blob:')) { window.URL.revokeObjectURL(file.url); this.productLocalUrls.delete(file.url); }
      let index = this.ruleForm.imgList.findIndex((e) => {
        return e.url == file.url;
      });
      this.ruleForm.imgList.splice(index, 1);
      if (this.productId !== null) this.productInvalidFiles = !this.ruleForm.imgList.every(item => item.raw instanceof Blob && item.raw.size > 0 && item.raw.size <= 52428800);
    },
    handleRemove(index) {
      this.ruleForm.imgList.splice(index, 1);
    },
    handlePictureCardPreview(file) {
      this.dialogImageUrl = file.url;
      this.dialogVisible = true;
    },
    handleDownload(file) {
      console.log(file);
    },
    async fileChange(file, fileList) {
      if (this.productId !== null) {
        if (this.loading || this.productHold) return;
        fileList.forEach(item => { if (item.url && item.url.startsWith('blob:')) this.productLocalUrls.add(item.url); });
        this.ruleForm.imgList = fileList;
        this.productInvalidFiles = !fileList.every(item => item.raw instanceof Blob && item.raw.size > 0 && item.raw.size <= 52428800);
        if (this.productInvalidFiles) this.productMessage = '存在超过50MiB或不可读取的图片，请移除后再发送；实际限制以服务器配置为准';
        return;
      }
      if (isPicUpload(file)) {
        if (file.size >= 2097152) {
          await this.comImg(file.raw).then((res) => {
            fileList.map((e) => {
              if (e.uid === file.uid) {
                e.raw = res;
              }
            });
            this.ruleForm.imgList = fileList;
          });
        } else {
          this.ruleForm.imgList = fileList;
        }
      } else {
        // 从ruleForm对象的imgList数组中删除最后一个元素
        this.ruleForm.imgList.splice(this.ruleForm.imgList.length, 1);
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
      if (this.productId !== null) return this.loadProductCategories(item, callback);
      getCategoryListApi({
        pid: item.value,
      })
        .then(async (res) => {
          const data = res.data.list;
          callback(data);
        })
        .catch((res) => {});
    },
    handleChange(e) {
      if (this.productId !== null) return;
      if (this.ruleForm.type == 2) this.scanUploadQrcode();
    },
    // 移动
    handleDragStart(e, item) {
      this.dragging = item;
    },
    handleDragEnd(e, item) {
      this.dragging = null;
    },
    handleDragOver(e) {
      e.dataTransfer.dropEffect = 'move';
    },
    handleDragEnter(e, item) {
      if (this.productId !== null && (this.loading || this.productHold || this.productClaimState === "processing")) return;
      e.dataTransfer.effectAllowed = 'move';
      if (item === this.dragging) {
        return;
      }
      const newItems = [...this.ruleForm.imgList];
      const src = newItems.indexOf(this.dragging);
      const dst = newItems.indexOf(item);
      newItems.splice(dst, 0, ...newItems.splice(src, 1));
      this.ruleForm.imgList = newItems;
    },
    closed() {
      if (this.productId !== null || this.productWasOpened) { this.productWasOpened = false; this.disposeProductScan(true); this.initData(); return; }
      this.initData();
      scanUploadCode().then((res) => {});
    },
    clear() {
      if (this.productId !== null) { if (!this.loading) this.uploadModal = false; return; }
      this.uploadModal = false;
      this.initData();
    },
    initData() {
      this.ruleForm.type = 0;
      this.ruleForm.region = 0;
      this.scanToken = '';
      this.webImgUrl = '';
      this.ruleForm.imgList = [];
      clearInterval(this.time);
      this.time = undefined;
    },
  },
};
</script>
<style lang="scss" scoped>
::v-deep .el-dialog__title {
  font-size: 16px;
}
.main {
  min-height: 410px;
}
.pictrue {
  width: 60px !important;
  height: 60px !important;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  margin-right: 10px;
  position: relative;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}
.btndel {
  position: absolute;
  z-index: 1;
  font-size: 18px;
  right: -5px;
  top: -5px;
  color: #999;
}
.form-width {
  width: 280px;
}
.tq-text {
  margin-left: 14px;
  font-size: 12px;
  font-weight: 400;
  color: var(--prev-color-primary);
  cursor: pointer;
}
.uploadCont ::v-deep .el-upload--picture-card,
::v-deep .el-upload-list--picture-card .el-upload-list__item {
  width: 64px;
  height: 64px;
  line-height: 72px;
  overflow: inherit;
}
.uploadCont ::v-deep .el-upload--picture-card,
::v-deep .el-upload-list--picture-card .el-upload-list__item img {
  width: 64px !important;
  height: 64px !important;
  border-radius: 6px;
  object-fit: cover;
}
.pl100 {
  padding-left: 100px;
}
.img-box {
  display: flex;
  flex-wrap: wrap;
}
.tips {
  font-size: 12px;
  color: #bbbbbb;
}
.code-image {
  display: flex;
  margin-top: 12px;
  .left {
    display: flex;
    flex-direction: column;
    margin-right: 20px;
    align-items: center;
    .code {
      border: 1px solid #dddddd;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 200px;
      height: 200px;
      border-radius: 4px;
      .code-img {
        width: 160px;
        height: 160px;
      }
    }
    .form-width {
      width: 200px;
    }
    .code {
      margin-bottom: 14px;
    }
    .trip {
      color: #333333;
      text-align: center;
      line-height: 18px;
    }
    .trip-small {
      font-size: 12px;
      font-weight: 400;
      color: #bbbbbb;
      text-align: center;
      line-height: 16px;
    }
  }
  .right {
    margin-top: 62px;
    .tip {
      font-size: 12px;
      font-weight: 400;
      color: #bbbbbb;
      margin: 10px 0;
    }
  }
}
</style>
