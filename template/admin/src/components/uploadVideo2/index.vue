<template>
  <div class="Modal" :class="{ 'product-video-owned': productId !== null }">
    <el-alert v-if="productId !== null && productMessage" :title="productMessage" :description="productReceiptDescription" type="warning" :closable="false" show-icon />
    <div class="colLeft">
      <div class="Nav">
        <div class="trees-coadd">
          <div v-if="isPage" class="tree_tit" v-db-click @click="addSort">
            <i class="el-icon-circle-plus"></i>
            添加分类
          </div>
          <div class="scollhide">
            <div :class="isPage ? 'tree' : 'isTree'">
              <el-tree
                :data="treeData"
                node-key="id"
                default-expand-all
                highlight-current
                :expand-on-click-node="false"
                @node-click="appendBtn"
                :current-node-key="treeId"
              >
                <span class="custom-tree-node" slot-scope="{ data }">
                  <!-- <span class="file-name">
                    <i class="icon el-icon-folder-remove"></i>
                    {{ data.title }}</span
                  > -->
                  <!-- <span class="file-name">
                    <img v-if="!data.pid" class="icon" src="@/assets/images/file.jpg" />
                    <span class="name line1">{{ data.title }}</span>
                  </span> -->
                  <div class="file-name">
                    <img v-if="!data.pid" class="icon" src="@/assets/images/file.jpg" />
                    <el-tooltip class="item" effect="dark" :content="data.title" placement="top">
                      <div class="text line1">
                        {{ data.title }}
                      </div>
                    </el-tooltip>
                  </div>
                  <span>
                    <el-dropdown @command="(command) => clickMenu(data, command)">
                      <i class="el-icon-more el-icon--right"></i>
                      <template slot="dropdown">
                        <el-dropdown-menu>
                          <el-dropdown-item command="1">新增分类</el-dropdown-item>
                          <el-dropdown-item v-if="data.id" command="2">编辑分类</el-dropdown-item>
                          <el-dropdown-item v-if="data.id" command="3">删除</el-dropdown-item>
                        </el-dropdown-menu>
                      </template>
                    </el-dropdown>
                  </span>
                </span>
              </el-tree>
            </div>
          </div>
        </div>
      </div>
      <div class="conter">
        <div class="bnt acea-row row-middle df-jcsb">
          <div class="">
            <el-button
              class="mr8"
              type="primary"
              :disabled="productId !== null ? ids.length === 0 : checkPicList.length === 0"
              v-db-click
              @click="checkPics"
              size="small"
              v-if="isShow !== 0"
              >使用选中视频</el-button
            >
            <!-- <el-button size="small" type="primary" v-db-click @click="uploadModal">上传视频</el-button> -->
            <el-button
              class="mr8"
              v-if="productId !== null || upload_type !== '1'"
              type="primary"
              size="small"
              v-db-click
              @click="zh_uploadFile"
              >上传视频</el-button
            >
            <el-upload
              v-if="productId === null && upload_type === '1'"
              :show-file-list="false"
              :action="fileUrl"
              :before-upload="videoSaveToUrl"
              :data="uploadData"
              :headers="header"
              :multiple="true"
              style="display: inline-block"
              accept=".mp4"
            >
              <el-button class="mr8" size="small" type="primary">上传视频</el-button>
            </el-upload>
            <!-- 输入链接 -->
            <el-button class="mr8" size="small" type="primary" icon="el-icon-link" @click="openInputModal"></el-button>
            <el-button
              class="mr8"
              size="small"
              :disabled="!checkPicList.length && !ids.length"
              v-db-click
              @click.stop="editPicList()"
              >删除视频</el-button
            >
            <el-cascader
              v-model="pids"
              placeholder="视频移动至"
              style="width: 150px"
              class="treeSel"
              :options="productId !== null ? productMoveTree : treeData2"
              :props="{ checkStrictly: true, emitPath: false, label: 'title', value: 'id' }"
              clearable
              size="small"
              @visible-change="moveImg"
              @change="selectMoveTarget"
            ></el-cascader>
          </div>
          <div>
            <el-input
              class="mr8"
              v-model="fileData.real_name"
              placeholder="请输入视频名"
              size="small"
              style="width: 150px"
              @change="searchFile"
            >
              <i slot="suffix" class="el-icon-search el-input__icon" v-db-click @click="getFileList"></i>
            </el-input>
            <el-radio-group class="mr10" v-if="isPage" v-model="lietStyle" size="small" @input="radioChange">
              <el-radio-button label="list">
                <i class="el-icon-menu"></i>
              </el-radio-button>
              <el-radio-button label="table">
                <!-- <i class="el-icon-files"></i> -->
                <span class="iconfont iconliebiao"></span>
              </el-radio-button>
            </el-radio-group>
          </div>
        </div>
        <div class="pictrueList acea-row" :class="{ 'is-modal': !isPage }">
          <div v-if="lietStyle == 'list'" style="width: 100%">
            <div v-show="isShowPic" class="imagesNo">
              <i class="el-icon-picture" style="color: #dbdbdb; font-size: 60px"></i>
              <span class="imagesNo_sp">视频库为空</span>
            </div>
            <div ref="imgListBox" class="acea-row mb10">
              <div
                class="pictrueList_pic mb10 mt10"
                v-for="(item, index) in pictrueList"
                :key="index"
                :style="{ margin: picmargin }"
                @mouseenter="enterMouse(item)"
                @mouseleave="enterMouse(item)"
              >
                <p class="number" v-if="item.num > 0">
                  <el-badge :value="item.num" type="primary">
                    <a href="#" class="demo-badge"></a>
                  </el-badge>
                </p>
                <div
                  class="img"
                  :class="item.isSelect ? 'on' : ''"
                  v-db-click
                  @click.stop="changImage(item, index, pictrueList)"
                >
                  <video :src="item.satt_dir" />
                </div>

                <div class="operate-item" @mouseenter="enterLeave(item)" @mouseleave="enterLeave(item)">
                  <p v-if="!item.isEdit">
                    {{ item.editName }}
                  </p>
                  <el-input size="small" type="text" v-model="item.real_name" v-else @blur="bindTxt(item)" />
                  <div class="operate-height">
                    <span class="operate mr10" v-db-click @click="editPicList(item.att_id)" v-if="item.isShowEdit"
                      >删除</span
                    >
                    <span class="operate mr10" v-db-click @click="item.isEdit = !item.isEdit" v-if="item.isShowEdit"
                      >改名</span
                    >
                    <span class="operate" v-db-click @click="lookImg(item)" v-if="item.isShowEdit">查看</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <el-table
            v-if="lietStyle == 'table'"
            ref="table"
            :data="pictrueList"
            v-loading="loading"
            highlight-row
            :row-key="getRowKey"
            @selection-change="handleSelectRow"
            no-data-text="暂无数据"
            no-filtered-data-text="暂无筛选结果"
          >
            <el-table-column type="selection" width="60" :reserve-selection="true"> </el-table-column>
            <el-table-column label="视频名称" min-width="190">
              <template slot-scope="scope">
                <div class="df-aic">
                  <div class="tabBox_img mr10">
                    <video :src="scope.row.att_dir" @click="lookImg(scope.row)" />
                  </div>
                  <span v-if="!scope.row.isEdit" class="line2 real-name">{{ scope.row.real_name }}</span>
                  <el-input
                    size="small"
                    type="text"
                    style="width: 90%"
                    v-model="scope.row.real_name"
                    v-else
                    @blur="bindTxt(scope.row)"
                  />
                </div>
              </template>
            </el-table-column>
            <el-table-column label="上传时间" min-width="100">
              <template slot-scope="scope">
                <span>{{ scope.row.time }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" fixed="right" width="170">
              <template slot-scope="scope">
                <a v-db-click @click="editPicList(scope.row)">删除</a>
                <el-divider direction="vertical"></el-divider>
                <a v-db-click @click="scope.row.isEdit = !scope.row.isEdit">{{
                  scope.row.isEdit ? '确定' : '重命名'
                }}</a>
                <el-divider direction="vertical"></el-divider>
                <a v-db-click @click="lookImg(scope.row)">查看</a>
              </template>
            </el-table-column>
          </el-table>
        </div>
        <div class="footer acea-row row-right">
          <pagination
            v-if="total"
            :total="total"
            :pageCount="9"
            layout="total, prev, pager, next"
            :page.sync="fileData.page"
            @pagination="pageChange"
            :limit.sync="fileData.limit"
          ></pagination>
        </div>
      </div>
    </div>
    <uploadImg
      v-if="productId === null"
      ref="upload"
      :isPage="isPage"
      :isIframe="isIframe"
      :categoryId="treeId"
      :categoryList="treeData"
      @uploadSuccess="uploadSuccess"
    ></uploadImg>
    <el-dialog title="查看视频" append-to-body :visible.sync="videoModal" :width="productId !== null ? 'min(1024px, calc(100vw - 32px))' : '1024px'">
      <video :src="imageUrl" :style="productId !== null ? { maxWidth: '100%' } : null" controls />
    </el-dialog>
    <input type="file" ref="refid" style="display: none" @change="zh_uploadFile_change" />
    <el-dialog v-if="productId !== null" :visible.sync="productCategoryVisible" append-to-body title="视频分类" width="min(480px, calc(100vw - 32px))" :close-on-click-modal="false">
      <el-form label-width="80px">
        <el-form-item label="分类名称"><el-input v-model="productCategory.name" maxlength="50" /></el-form-item>
        <el-form-item label="上级分类"><el-cascader v-model="productCategory.pid" style="width: 100%" :options="treeData2" :props="{ checkStrictly: true, emitPath: false, label: 'title', value: 'id' }" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="productCategoryVisible = false">取消</el-button><el-button type="primary" :loading="productCategorySaving" :disabled="productWriteUnknown" @click="saveProductCategory">保存</el-button></span>
    </el-dialog>
    <!-- 输入链接弹窗 -->
    <el-dialog title="输入视频链接" append-to-body :visible.sync="inputModal" :width="productId !== null ? 'min(400px, calc(100vw - 32px))' : '400px'">
      <div class="flex">
        <el-input class="mr-20" v-model="inputUrl" placeholder="请输入视频链接" />
        <el-button type="primary" :loading="productId !== null && productBusy" :disabled="productId !== null && productWriteUnknown" @click="uploadByUrl">{{ productId !== null ? '导入并使用' : '使用' }}</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import {
  getCategoryListApi,
  createApi,
  fileListApi,
  categoryEditApi,
  moveApi,
  fileUpdateApi,
  videoCloudUpload,
} from '@/api/uploadPictures';
import { productGetTempKeysApi, uploadType } from '@/api/product';
import { uploadByPieces } from '@/utils/upload'; //引入uploadByPieces方法

import { captureProductContext, currentProductContext, closeProductContext, requireProductContext, receiptText, mediaId, mediaList, mediaCategories, mediaCreateCategory, mediaUpdateCategory, mediaDeleteCategory, mediaRename, mediaMove, mediaDelete, mediaVideoUpload, mediaVideoImport } from '@/api/product-media';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import Setting from '@/setting';
import { getCookies } from '@/libs/util';
import uploadImg from '@/components/uploadImg';
import { VueTreeList, Tree, TreeNode } from 'vue-tree-list';
import { isVideoUpload } from '@/utils';
export default {
  name: 'uploadPictures',
  components: { uploadImg, VueTreeList },
  props: {
    productId: { default: null },
    isChoice: {
      type: String,
      default: 'one',
    },
    isPage: {
      type: Boolean,
      default: false,
    },
    isIframe: {
      type: Boolean,
      default: false,
    },
    gridBtn: {
      type: Object,
      default: null,
    },
    gridPic: {
      type: Object,
      default: null,
    },
    isShow: {
      type: Number,
      default: 1,
    },
    pageLimit: {
      type: Number,
      default: 0,
    },
  },
  data() {
    return {
      productSession: null,
      productScopeInitialized: false,
      productTreeSequence: 0,
      productListSequence: 0,
      productMoveSequence: 0,
      productMessage: '', productReceipt: null, productReceiptDescription: '',
      productBusy: false, productWriteUnknown: false, productChunkUpload: false, productCloudUpload: false, productPendingVideo: null,
      productCategoryVisible: false, productCategory: { id: 0, pid: 0, name: '' },
      productCategorySaving: false,
      productMoveTree: [{ id: 0, title: '未分组', pid: 0 }],
      productMoveChosen: false,
      spinShow: false,
      fileUrl: Setting.apiBaseURL + '/file/upload',
      modalPic: false,
      treeData: [],
      treeData2: [],
      pictrueList: [],
      uploadData: {}, // 上传参数
      checkPicList: [],
      uploadName: {
        name: '',
        all: 1,
        type: 1,
      },
      formValidate: { id: 0 },
      FromData: null,
      treeId: '',
      isJudge: false,
      buttonProps: {
        type: 'default',
        size: 'small',
      },
      fileData: {
        pid: 0,
        real_name: '',
        page: 1,
        limit: this.pageLimit || 18,
        type: 1,
      },
      total: 0,
      pids: 0,
      list: [],
      modalTitleSs: '',
      isShowPic: false,
      header: {},
      ids: [], // 选中附件的id集合
      lietStyle: 'list',
      imageUrl: '',
      loading: false,
      multipleSelection: [],
      picmargin: '5px', //默认距离右边距离
      videoModal: false,
      upload_type: '',
      upload: {
        videoIng: false, // 是否显示进度条；
      },
      inputModal: false, // 输入链接弹窗
      inputUrl: '', // 输入的视频链接
    };
  },
  created() {
    this.resetProductScope();
    this.productUnsubscribe = subscribePlatformAuthEpoch(() => {
      if (this.productId !== null) this.invalidateProductScope();
    });
  },
  watch: {
    productId(value, previous) {
      this.resetProductScope();
      if (value === null && previous !== null) return;
      this.getList(); this.getFileList();
    },
  },
  beforeDestroy() {
    closeProductContext(this.productSession);
    if (this.productUnsubscribe) this.productUnsubscribe();
  },
  mounted() {
    if (this.isPage) {
      let hang = parseInt((document.body.clientHeight - this.$refs.imgListBox.clientHeight - 325) / 180); //计算行数
      let col = parseInt(this.$refs.imgListBox.clientWidth / 156); //计算列数
      this.fileData.limit = col * hang; //计算分页数量
      this.picmargin = parseInt(this.$refs.imgListBox.clientWidth - col * 146) / (2 * col) + 'px'; //平均分布计算margin距离
    }
    if (this.productId === null) { this.getToken(); this.uploadType(); }
    this.getList();
    this.getFileList();
  },
  methods: {
    resetProductScope() {
      const hadProduct = this.productScopeInitialized;
      this.productScopeInitialized = this.productId !== null;
      closeProductContext(this.productSession); this.productSession = null;
      this.productTreeSequence += 1; this.productListSequence += 1; this.productMoveSequence += 1;
      this.productMessage = ''; this.productReceipt = null; this.productReceiptDescription = '';
      this.productBusy = false; this.productWriteUnknown = false; this.productChunkUpload = false; this.productCloudUpload = false; this.productPendingVideo = null;
      this.productCategoryVisible = false; this.inputModal = false; this.videoModal = false; this.inputUrl = ''; this.imageUrl = '';
      if (this.productId === null && !hadProduct) return;
      this.treeId = this.productId === null ? '' : 0; this.treeData = []; this.treeData2 = [];
      this.pictrueList = []; this.total = 0; this.initData(); this.pids = null; this.productMoveChosen = false;
      this.productMoveTree = [{ id: 0, title: '未分组', pid: 0 }];
      if (this.productId !== null) {
        try { this.productSession = captureProductContext(this.productId); }
        catch (error) { this.productMessage = error.message; }
      }
    },
    invalidateProductScope() {
      closeProductContext(this.productSession); this.productSession = null;
      this.productTreeSequence += 1; this.productListSequence += 1; this.productMoveSequence += 1;
      this.productCategoryVisible = false; this.inputModal = false; this.videoModal = false;
      this.productChunkUpload = false; this.productCloudUpload = false; this.productPendingVideo = null;
      this.inputUrl = ''; this.imageUrl = ''; this.pictrueList = []; this.treeData = []; this.treeData2 = [];
      this.total = 0; this.initData(); this.productMoveTree = [{ id: 0, title: '未分组', pid: 0 }];
      this.productMessage = '登录身份已变化，进行中的提交结果请先核对，再重新打开商品视频';
    },
    async productWork(operation, apply, write = false) {
      const context = this.productSession;
      let ownsBusy = false;
      try {
        requireProductContext(context);
        if (write && this.productWriteUnknown) throw new Error('上一项提交结果未确认，请先核对视频库');
        if (write && this.productBusy) { this.productMessage = '视频正在处理，请稍候'; return; }
        if (write) { this.productBusy = true; ownsBusy = true; }
        const value = await operation(context);
        if (this.productSession !== context || !currentProductContext(context)) return;
        if (write) { this.productMessage = ''; this.productReceipt = null; this.productReceiptDescription = ''; }
        if (apply) apply(value, context);
        return value;
      } catch (error) {
        if (this.productSession === context && currentProductContext(context)) {
          if (write && error.state === 'unknown') this.productWriteUnknown = true;
          this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt);
          this.productMessage = error.message || '商品视频操作未完成';
          if (this.productPendingVideo) this.productMessage += '；本次视频核对编号：' + this.productPendingVideo.upload_reference + '，原文件已保留，请先核对保存结果';
        }
      } finally { if (ownsBusy && this.productSession === context) this.productBusy = false; }
    },
    getProductCategories(search) {
      const sequence = ++this.productTreeSequence;
      return this.productWork(ctx => mediaCategories(ctx, { ...this.uploadName, pid: 0, type: 1 }), value => {
        if (sequence !== this.productTreeSequence) return;
        if (search !== 'search') this.treeData2 = [{ id: 0, title: '未分组', pid: 0 }, ...JSON.parse(JSON.stringify(value.list))];
        this.treeData = [{ id: 0, title: '全部视频', pid: 0 }, ...value.list];
      });
    },
    getProductVideos() {
      const sequence = ++this.productListSequence;
      this.fileData.limit = Math.max(1, Math.min(100, Number(this.fileData.limit) || 18));
      return this.productWork(ctx => mediaList(ctx, { ...this.fileData, pid: this.treeId === '' ? 0 : this.treeId, type: 1 }), value => {
        if (sequence !== this.productListSequence) return;
        this.initData();
        value.list.forEach(item => { item.isSelect = false; item.isEdit = false; item.isShowEdit = false; item.realName = false; item.num = 0; this.editName(item); });
        this.pictrueList = value.list; this.total = value.count; this.isShowPic = value.list.length === 0;
        this.productChunkUpload = value.video_chunk_upload === true;
        this.productCloudUpload = value.video_cloud_upload === true;
      });
    },
    openProductCategory(id, pid, name = '') {
      try {
        requireProductContext(this.productSession);
        if (this.productWriteUnknown || this.productBusy) throw new Error('请先核对当前视频操作结果');
        this.productCategory = { id: mediaId(id, true), pid: mediaId(pid, true), name };
        this.productCategoryVisible = true;
      } catch (error) { this.productMessage = error.message; }
    },
    async saveProductCategory() {
      if (this.productCategorySaving) return;
      const value = { ...this.productCategory };
      if (!value.name.trim()) { this.productMessage = '请填写分类名称'; return; }
      this.productCategorySaving = true;
      try {
        await this.productWork(ctx => value.id ? mediaUpdateCategory(ctx, value.id, { name: value.name, pid: mediaId(value.pid, true), type: 1 }) : mediaCreateCategory(ctx, { name: value.name, pid: mediaId(value.pid, true), type: 1 }), () => {
          this.productCategoryVisible = false; this.getProductCategories(); this.$message.success('视频分类已保存');
        }, true);
      } finally { this.productCategorySaving = false; }
    },
    async removeProductCategory(category) {
      const context = this.productSession;
      try {
        requireProductContext(context);
        await this.$confirm('删除分类“' + category.title + '”？所属视频将按原规则移到父分类或未分组。', '删除视频分类', { type: 'warning' });
        if (this.productSession !== context || !currentProductContext(context)) return;
        await this.productWork(ctx => mediaDeleteCategory(ctx, category.id, 1), () => {
          this.treeId = 0; this.initData(); this.getProductCategories(); this.getProductVideos(); this.$message.success('视频分类已删除');
        }, true);
      } catch (error) { if (error !== 'cancel' && error !== 'close' && this.productSession === context) this.productMessage = error.message || '分类未删除'; }
    },
    selectedProductRows() {
      const selected = [...this.checkPicList, ...this.multipleSelection];
      return this.ids.map(id => {
        const row = selected.find(item => String(item.att_id) === String(id));
        if (!row || ![0, 1, '0', '1'].includes(row.type)) throw new Error('视频原始类型未确认，请刷新当前列表');
        return row;
      });
    },
    getProductMoveTargets() {
      const sequence = ++this.productMoveSequence;
      this.pids = null; this.productMoveChosen = false;
      this.productMoveTree = [{ id: 0, title: '未分组', pid: 0 }];
      let types;
      try { requireProductContext(this.productSession); types = new Set(this.selectedProductRows().map(row => Number(row.type))); }
      catch (error) { this.productMessage = error.message; return; }
      if (types.size !== 1) return;
      const type = [...types][0];
      return this.productWork(ctx => mediaCategories(ctx, { pid: 0, all: 1, type }), value => {
        if (sequence === this.productMoveSequence) this.productMoveTree = [{ id: 0, title: '未分组', pid: 0 }, ...value.list];
      });
    },
    selectMoveTarget(value) {
      if (this.productId !== null) {
        this.productMoveChosen = value !== '' && value !== null && value !== undefined;
        if (this.productMoveChosen) return this.moveProductVideos();
      }
    },
    moveProductVideos() {
      const ids = this.ids.slice(); if (!ids.length || !this.productMoveChosen) return;
      return this.productWork(ctx => mediaMove(ctx, ids, mediaId(this.pids, true), 1), () => {
        this.initData(); this.pids = null; this.productMoveChosen = false; this.getProductVideos(); this.$message.success('视频已移动');
      }, true);
    },
    async deleteProductVideos(ids) {
      if (!ids.length) return;
      const context = this.productSession;
      try {
        requireProductContext(context);
        await this.$confirm('删除选中的视频？', '删除视频', { type: 'warning' });
        if (this.productSession !== context || !currentProductContext(context)) return;
        await this.productWork(ctx => mediaDelete(ctx, ids, 1), () => { this.initData(); this.getProductVideos(); this.$message.success('视频已删除'); }, true);
      } catch (error) { if (error !== 'cancel' && error !== 'close' && this.productSession === context) this.productMessage = error.message || '视频未删除'; }
    },
    renameProductVideo(item) {
      const name = item.real_name;
      if (!name.trim()) { this.productMessage = '请填写视频名称'; return; }
      return this.productWork(ctx => mediaRename(ctx, item.att_id, name, 1), () => { item.real_name = name; this.editName(item); item.isEdit = false; this.getProductVideos(); this.$message.success('视频名称已保存'); }, true);
    },
    uploadProductVideo(event) {
      const input = event.target; const selected = Array.from(input.files || []); input.value = '';
      if (selected.length !== 1) { this.productMessage = '请选择一个视频文件'; return; }
      if (this.productPendingVideo) { this.productMessage = '请先核对本次视频保存结果，编号：' + this.productPendingVideo.upload_reference; return; }
      const pid = this.treeId === '' ? 0 : this.treeId;
      const cloud = this.productCloudUpload && selected[0].size > 3145728;
      return this.productWork(async ctx => {
        const saved = await mediaVideoUpload(ctx, selected[0], pid, this.productChunkUpload, this.productCloudUpload, () => {
          if (this.productSession === ctx && currentProductContext(ctx)) this.productMessage = '视频已传入暂存，正在保存';
        }, pending => {
          if (this.productSession !== ctx || !currentProductContext(ctx)) return false;
          this.productPendingVideo = pending; return true;
        });
        if (cloud) {
          const actual = await mediaList(ctx, { pid: mediaId(pid, true), real_name: selected[0].name, type: 1, page: 1, limit: 100 });
          if (!actual.list.some(row => row.att_dir === saved.src && Number(row.pid) === mediaId(pid, true) && Number(row.type) === 1)) {
            const error = new Error('视频已保存，列表核对尚未完成，请刷新核对本次结果'); error.state = 'unknown'; throw error;
          }
        }
        if (cloud && this.productSession === ctx && currentProductContext(ctx)) this.productPendingVideo = null;
        return saved;
      }, () => {
        this.fileData.page = 1; this.initData(); this.getProductVideos(); this.$message.success('视频已上传');
      }, true);
    },
    importProductVideo() {
      const url = this.inputUrl; const pid = this.treeId === '' ? 0 : this.treeId;
      return this.productWork(ctx => mediaVideoImport(ctx, url, pid), value => {
        this.inputModal = false; this.inputUrl = ''; this.fileData.page = 1; this.initData(); this.getProductVideos();
        if (this.isChoice === 'one') this.$emit('getVideo', value.src);
        else this.$message.success('视频已导入，请在视频库中选择');
      }, true);
    },

    // 打开输入链接弹窗
    openInputModal() {
      if (this.productId !== null) { try { requireProductContext(this.productSession); if (this.productBusy || this.productWriteUnknown) throw new Error('请先核对当前视频操作结果'); } catch (error) { this.productMessage = error.message; return; } }
      this.inputModal = true;
    },
    //获取视频上传类型
    uploadType() {
      uploadType().then((res) => {
        this.upload_type = res.data.upload_type;
      });
    },
    beforeUpload() {
      this.uploadData = {};
      let promise = new Promise((resolve) => {
        this.$nextTick(function () {
          resolve(true);
        });
      });
      return promise;
    },
    zh_uploadFile() {
      if (this.productId !== null) { try { requireProductContext(this.productSession); if (this.productBusy || this.productWriteUnknown) throw new Error('请先核对当前视频操作结果'); } catch (error) { this.productMessage = error.message; return; } }
      this.$refs.refid.click();
    },
    zh_uploadFile_change(evfile) {
      if (this.productId !== null) return this.uploadProductVideo(evfile);
      let that = this;
      if (evfile.target.files[0].type !== 'video/mp4') {
        return that.$message.error('只能上传mp4文件');
      }
      debugger;
      let types = {
        key: evfile.target.files[0].name,
        contentType: evfile.target.files[0].type,
      };
      productGetTempKeysApi(types).then((res) => {
        that.$videoCloud
          .videoUpload({
            type: res.data.type,
            evfile: evfile,
            res: res,
            uploading(status, progress) {
              that.upload.videoIng = status;
            },
          })
          .then((res) => {
            videoCloudUpload({
              pid: this.treeId || 0,
              video_path: res.url,
              video_name: evfile.target.files[0].name,
            }).then((res) => {
              this.getFileList();
            });
          })
          .catch((res) => {
            that.$message.error(res);
          });
      });
    },
    videoSaveToUrl(file) {
      if (this.productId !== null) return false;
      if (isVideoUpload(file))
        uploadByPieces({
          file: file, // 视频实体
          pieceSize: 3, // 分片大小
          success: (data) => {
            this.progress = 100;
            videoCloudUpload({
              pid: this.treeId || 0,
              video_path: data.file_path,
              video_name: file.name,
            }).then((res) => {
              this.getFileList();
              this.$message.success('视频上传成功');
            });
          },
          error: (e) => {
            this.$message.error(e.msg);
          },
          uploading: (chunk, allChunk) => {
            this.videoIng = true;
            let st = Math.floor((chunk / allChunk) * 100);
            this.progress = st;
          },
        });
      return false;
    },
    radioChange() {
      this.initData();
    },
    lookImg(item) {
      if (this.productId !== null) { try { requireProductContext(this.productSession); } catch (error) { this.productMessage = error.message; return; } }
      this.imageUrl = item.att_dir;
      this.videoModal = true;
    },
    onDel(node) {
      if (this.productId !== null) return this.removeProductCategory(node);
      let method = node.cate_id ? routeDel : routeCateDel;
      this.$msgbox({
        title: '提示',
        message: '是否确定删除该菜单',
        showCancelButton: true,
        cancelButtonText: '取消',
        confirmButtonText: '删除',
        iconClass: 'el-icon-warning',
        confirmButtonClass: 'btn-custom-cancel',
      })
        .then(() => {
          method(node.id)
            .then((res) => {
              this.$message.success(res.msg);
              node.remove();
            })
            .catch((err) => {
              this.$message.error(err);
            });
        })
        .catch(() => {});
    },

    onChangeName(params) {
      if (params.eventType == 'blur') {
        let data = {
          name: params.newName,
          id: params.id,
        };
        interfaceEditName(data)
          .then((res) => {
            this.$message.success(res.msg);
          })
          .catch((err) => {
            this.$message.error(err);
          });
      }
    },
    // 添加分类
    addSort() {
      this.append({ id: this.treeId || 0 });
    },
    // 点击菜单
    clickMenu(data, name) {
      if (name == 1) {
        this.append(data);
      } else if (name == 2) {
        this.editPic(data);
      } else if (name == 3) {
        this.remove(data, '分类');
      }
    },
    uploadSuccess() {
      this.fileData.page = 1;
      this.initData();
      this.getFileList();
    },
    uploadModal() {
      this.$refs.upload.uploadModal = true;
    },
    enterMouse(item) {
      item.realName = !item.realName;
    },
    enterLeave(item) {
      item.isShowEdit = !item.isShowEdit;
    },
    // 上传头部token
    getToken() {
      this.header['Authori-zation'] = 'Bearer ' + getCookies('token');
    },
    moveImg(status) {
      if (this.productId !== null) {
        if (status) { if (!this.ids.length) { this.productMessage = '请先选择视频'; return; } this.getProductMoveTargets(); }
        return;
      }
      if (!status) {
        this.getMove();
      } else {
        if (!this.ids.toString()) {
          this.$message.warning('请先选择视频');
          return;
        }
      }
    },
    searchImg() {},
    // 移动分类
    getMove() {
      if (this.productId !== null) return this.moveProductVideos();
      let data = {
        pid: this.pids,
        images: this.ids.toString(),
      };
      if (!data.images) return;
      moveApi(data)
        .then(async (res) => {
          this.$message.success(res.msg);
          this.getFileList();
          this.pids = 0;
          this.checkPicList = [];
          this.ids = [];
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    delImg(id) {
      if (this.productId !== null) return this.deleteProductVideos([typeof id === 'object' ? id.att_id : id]);
      let ids = {
        ids: id,
      };
      let delfromData = {
        title: '删除选中视频',
        url: `file/file/delete`,
        method: 'POST',
        ids: ids,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$message.success(res.msg);
          this.getFileList();
          this.checkPicList = [];
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    // 删除视频
    editPicList(id) {
      if (this.productId !== null) return this.deleteProductVideos(id ? [typeof id === 'object' ? id.att_id : id] : this.ids.slice());
      let ids = {
        ids: id || this.ids.toString(),
      };
      let delfromData = {
        title: '删除选中视频',
        url: `file/file/delete`,
        method: 'POST',
        ids: ids,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$message.success(res.msg);
          this.getFileList();
          this.initData();
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    initData() {
      if (this.productId !== null) { this.productMoveSequence += 1; this.productMoveChosen = false; if (this.$refs.table) this.$refs.table.clearSelection(); }
      this.checkPicList = [];
      this.ids = [];
      this.multipleSelection = [];
    },
    // 鼠标移入 移出
    onMouseOver(root, node, data) {
      event.preventDefault();
      data.flag = !data.flag;
      if (data.flag2) {
        data.flag2 = false;
      }
    },
    // 点击树
    appendBtn(data) {
      this.treeId = data.id;
      this.fileData.page = 1;
      this.getFileList();
    },
    // 点击添加
    append(data) {
      this.treeId = data.id;
      this.getFrom();
    },
    // 删除分类
    remove(data, tit) {
      if (this.productId !== null) return this.removeProductCategory(data);
      this.tits = tit;
      let delfromData = {
        title: '删除 [ ' + data.title + ' ] ' + '分类',
        url: `file/category/${data.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$message.success(res.msg);
          this.getList();
          this.checkPicList = [];
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    // 编辑树表单
    editPic(data) {
      if (this.productId !== null) return this.openProductCategory(data.id, data.pid, data.name || data.title);
      this.$modalForm(categoryEditApi(data.id)).then(() => this.getList());
    },
    // 搜索分类
    changePage() {
      this.getList('search');
    },
    // 分类列表树
    getList(type) {
      if (this.productId !== null) return this.getProductCategories(type);
      let data = {
        title: '全部视频',
        id: '',
        pid: 0,
      };
      getCategoryListApi(this.uploadName)
        .then(async (res) => {
          if (type !== 'search') {
            this.treeData2 = JSON.parse(JSON.stringify([...res.data.list]));
          }
          res.data.list.unshift(data);
          this.treeData = res.data.list;
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    loadData(item, callback) {
      if (this.productId !== null) { const sequence = this.productTreeSequence; return this.productWork(ctx => mediaCategories(ctx, { pid: item.id, all: 0, type: 1 }), value => { if (sequence === this.productTreeSequence) callback(value.list); }); }
      getCategoryListApi({
        pid: item.id,
      })
        .then(async (res) => {
          const data = res.data.list;
          callback(data);
        })
        .catch((res) => {});
    },
    addFlag(treedata) {
      treedata.map((item) => {
        this.$set(item, 'flag', false);
        this.$set(item, 'flag2', false);
        item.children && this.addFlag(item.children);
      });
    },
    // 新建分类
    add() {
      this.treeId = 0;
      this.getFrom();
    },
    searchFile() {
      this.fileData.page = 1;
      this.getFileList();
    },
    // 文件列表
    getFileList() {
      if (this.productId !== null) return this.getProductVideos();
      this.fileData.pid = this.treeId;
      fileListApi(this.fileData)
        .then(async (res) => {
          res.data.list.forEach((el) => {
            el.isSelect = false;
            el.isEdit = false;
            el.isShowEdit = false;
            el.realName = false;
            el.num = 0;
            this.editName(el);
          });
          this.pictrueList = res.data.list;

          if (this.pictrueList.length) {
            this.isShowPic = false;
          } else {
            this.isShowPic = true;
          }
          this.total = res.data.count;
          this.$nextTick(() => {
            //确保dom加载完毕
            // this.showSelectData();
          });
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    showSelectData() {
      if (this.multipleSelection.length > 0) {
        // 判断是否存在勾选过的数据
        this.pictrueList.forEach((row) => {
          // 获取数据列表接口请求到的数据
          this.multipleSelection.forEach((item) => {
            // 勾选到的数据
            if (row.att_id === item.att_id) {
              // this.$refs.table.toggleRowSelection(item, true); // 若有重合，则回显该条数据
            }
          });
        });
      }
    },
    getRowKey(row) {
      return row.att_id;
    },
    //对象数组去重；
    unique(arr) {
      let result = arr.reduce((acc, curr) => {
        const x = acc.find((item) => item.att_id === curr.att_id);
        if (!x) {
          return acc.concat([curr]);
        } else {
          return acc;
        }
      }, []);
      return result;
    },
    //  选中某一行
    handleSelectRow(selection) {
      if (this.productId !== null) { if (selection.length) { try { requireProductContext(this.productSession); } catch (error) { this.productMessage = error.message; return; } } this.productMoveSequence += 1; this.productMoveChosen = false; }
      let arr = this.unique(selection);
      const uniqueArr = [];
      const ids = [];
      for (let i = 0; i < arr.length; i++) {
        const item = arr[i];
        if (!ids.includes(item.att_id)) {
          uniqueArr.push(item);
          ids.push(item.att_id);
        }
      }
      this.ids = ids;
      this.multipleSelection = uniqueArr;
    },
    pageChange(index) {
      if (this.productId !== null) this.initData();
      this.fileData.page = index;
      this.getFileList();
      this.checkPicList = [];
    },
    // 新建分类表单
    getFrom() {
      if (this.productId !== null) return this.openProductCategory(0, this.treeId === '' ? 0 : this.treeId);
      this.$modalForm(createApi({ id: this.treeId, type: 1 })).then((res) => {
        this.getList();
      });
    },
    // 上传之前
    beforeUpload(file) {
      // if (file.size > 2097152) {
      //   this.$message.error(file.name + "大小超过2M!");
      // } else
      if (!/image\/\w+/.test(file.type)) {
        this.$message.error('请上传以jpg、jpeg、png等结尾的视频文件'); //FileExt.toLowerCase()
        return false;
      }
      this.uploadData = {
        pid: this.treeId,
      };
      let promise = new Promise((resolve) => {
        this.$nextTick(function () {
          resolve(true);
        });
      });
      return promise;
    },
    // 上传成功
    handleSuccess(res, file, fileList) {
      if (res.status === 200) {
        this.$message.success(res.msg);
        this.fileData.page = 1;
        this.getFileList();
      } else {
        this.$message.error(res.msg);
      }
    },
    // 关闭
    cancel() {
      this.$emit('changeCancel');
    },
    // 选中视频
    changImage(item, index, row) {
      if (this.productId !== null) { try { requireProductContext(this.productSession); } catch (error) { this.productMessage = error.message; return; } this.productMoveSequence += 1; this.productMoveChosen = false; }
      let activeIndex = 0;
      if (!item.isSelect) {
        item.isSelect = true;
        this.checkPicList.push(item);
      } else {
        item.isSelect = false;
        this.checkPicList.map((el, index) => {
          if (el.att_id == item.att_id) {
            activeIndex = index;
          }
        });
        this.checkPicList.splice(activeIndex, 1);
      }

      this.ids = [];
      this.checkPicList.map((item, i) => {
        this.ids.push(item.att_id);
      });
      this.pictrueList.map((el, i) => {
        if (el.isSelect) {
          this.checkPicList.filter((el2, j) => {
            if (el.att_id == el2.att_id) {
              el.num = j + 1;
            }
          });
        } else {
          el.num = 0;
        }
      });
    },
    // 点击使用选中视频
    checkPics() {
      if (this.productId !== null) {
        try {
          requireProductContext(this.productSession);
          const selected = this.selectedProductRows();
          if (!selected.length) throw new Error('请先选择视频');
          if (this.isChoice === 'one') {
            if (selected.length !== 1) throw new Error('最多只能选择一个视频');
            this.$emit('getVideo', selected[0].att_dir);
          } else {
            const maximum = this.$route.query.maxLength;
            if (maximum !== undefined && selected.length > Number(maximum)) throw new Error('最多只能选' + maximum + '个视频');
            this.$emit('getPicD', selected); this.$emit('getVideo', selected);
          }
        } catch (error) { this.productMessage = error.message; }
        return;
      }
      if (this.isChoice === 'one') {
        if (this.checkPicList.length > 1) return this.$message.warning('最多只能选一张视频');
        this.$emit('getVideo', this.checkPicList[0].att_dir);
      } else {
        let maxLength = this.$route.query.maxLength;
        if (maxLength != undefined && this.checkPicList.length > Number(maxLength))
          return this.$message.warning('最多只能选' + maxLength + '张视频');
        this.$emit('getPicD', this.checkPicList);
        this.$emit('getVideo', this.checkPicList);
      }
    },
    // 上传视频链接
    uploadByUrl() {
      if (this.productId !== null) return this.importProductVideo();
      if (!this.inputUrl) {
        this.$message.error('请输入视频链接');
        return;
      }
      this.$emit('getVideo', this.inputUrl);
      this.$emit('getPicD', this.inputUrl);
      this.inputUrl = '';
    },
    editName(item) {
      let it = item.real_name.split('.');
      let it1 = it[1] == undefined ? [] : it[1];
      let len = it[0].length + it1.length;
      item.editName = len < 10 ? item.real_name : item.real_name.substr(0, 4) + '...' + item.real_name.substr(-5, 5);
    },
    // 修改视频文字上传
    bindTxt(item) {
      if (this.productId !== null) return this.renameProductVideo(item);
      if (item.real_name == '') {
        this.$message.error('请填写内容');
      }
      fileUpdateApi(item.att_id, {
        real_name: item.real_name,
      })
        .then((res) => {
          this.editName(item);
          item.isEdit = false;
          this.$message.success(res.msg);
        })
        .catch((error) => {
          this.$message.error(error.msg);
        });
    },
  },
};
</script>

<style scoped lang="scss">
.nameStyle {
  position: absolute;
  white-space: nowrap;
  z-index: 9;
  background: #eee;
  height: 20px;
  line-height: 20px;
  color: #555;
  border: 1px solid #ebebeb;
  padding: 0 5px;
  left: 56px;
  bottom: -18px;
}

.iconbianji1 {
  font-size: 13px;
}

.selectTreeClass {
  background: #d5e8fc;
}
.tree_tit {
  padding-top: 7px;
}
.treeBox {
  width: 100%;
  height: 100%;
  max-width: 180px;
}
.is-modal .pictrueList_pic {
  width: 100px;
  margin: 10px 5px !important;
  .img {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100px;
    height: 100px;
    background-color: rgb(248, 248, 248);
    padding: 2px;
    img {
      max-width: 96px;
      max-height: 96px;
      // object-fit: cover;
    }
    .operate-height {
      bottom: -8px;
    }
  }
}
.pictrueList_pic {
  position: relative;
  width: 146px;
  cursor: pointer;
  // margin-right: 20px !important;
  .img {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 146px;
    height: 146px;
    background-color: rgb(248, 248, 248);
    padding: 3px;
    video {
      max-width: 100px;
      max-height: 100px;
      // object-fit: cover;
    }
  }

  p {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    height: 20px;
    font-size: 12px;
    color: #515a6d;
    text-align: center;
  }

  .number {
    height: 33px;
  }

  .number {
    position: absolute;
    right: 0;
    top: 0;
  }
  ::v-deep .el-badge__content.is-fixed {
    top: 13px;
    right: 25px;
  }
}
.Nav {
  width: 100%;
  border-right: 1px solid #eee;
  min-width: 220px;
  max-width: max-content;
}
.trees-coadd {
  width: 100%;
  border-radius: 4px;
  overflow: hidden;
  position: relative;

  .scollhide {
    overflow-x: hidden;
    overflow-y: scroll;
    padding: 0px 0 10px 0;
    box-sizing: border-box;

    .isTree {
      min-height: 374px;
      max-height: 550px;
      ::v-deep .file-name {
        display: flex;
        align-items: center;
        .name {
          max-width: 7em;
        }
        .icon {
          width: 12px;
          height: 12px;
          margin-right: 8px;
        }
      }
      ::v-deep .el-tree-node {
        margin-right: 16px;
      }
      ::v-deep .el-tree-node__children .el-tree-node {
        margin-right: 0;
      }
      ::v-deep .el-tree-node__content {
        width: 100%;
        height: 36px;
      }
      ::v-deep .custom-tree-node {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-right: 20px;
        font-size: 13px;
        font-weight: 400;
        color: rgba(0, 0, 0, 0.6);
        line-height: 13px;
      }
      ::v-deep .is-current {
        background: #f1f9ff !important;
        color: var(--prev-color-primary) !important;
      }
      ::v-deep .is-current .custom-tree-node {
        color: var(--prev-color-primary) !important;
      }
    }
  }

  .scollhide::-webkit-scrollbar {
    display: none;
  }
}

.treeSel ::v-deep .ivu-select-dropdown-list {
  padding: 0 5px !important;
  box-sizing: border-box;
  width: 200px;
}
.imagesNo {
  display: flex;
  justify-content: center;
  flex-direction: column;
  align-items: center;
  margin: 65px 0;

  .imagesNo_sp {
    font-size: 13px;
    color: #dbdbdb;
    line-height: 3;
  }
}

.Modal {
  width: 100%;
  height: 100%;
  background: #fff !important;
}
.fill-window {
  height: 100vh;
}
.colLeft {
  padding-right: 0 !important;
  height: 100%;
  display: flex;
  flex-wrap: nowrap;
}

.conter {
  width: 100%;
  height: 100%;
  margin-left: 20px !important;
  .iconliebiao {
    font-size: 12px;
  }
}

.conter .bnt {
  width: 100%;
  padding: 0 0px 20px 0px;
  box-sizing: border-box;
}

.conter .pictrueList {
  // width: 100%;
  overflow-x: hidden;
  overflow-y: auto;
  min-height: 463px;
}
.conter .pictrueList.is-modal {
  max-height: 480px;
}
.right-col {
  // flex: 1;
}
.conter .pictrueList img {
  max-width: 100%;
}
.conter .pictrueList .img.on {
  border: 2px solid var(--prev-color-primary);
}

.conter .footer {
  padding: 0 20px 10px 20px;
}
.tabBox_img {
  display: flex;
  align-items: center;
  video {
    max-width: 90px;
    max-height: 30px;
  }
}
.real-name {
  flex: 1;
}
.df-aic {
  display: flex;
  align-items: center;
}
.demo-badge {
  width: 42px;
  height: 42px;
  background: transparent;
  border-radius: 6px;
  display: inline-block;
}

.bnt ::v-deep .ivu-tree-children {
  padding: 5px 0;
}

.card-tree {
  background: #fff;
  height: 72px;
  box-sizing: border-box;
  overflow-x: scroll; /* 设置溢出滚动 */
  white-space: nowrap;
  overflow-y: hidden;
  /* 隐藏滚动条 */
  border-radius: 4px;
  scrollbar-width: none; /* firefox */
  -ms-overflow-style: none; /* IE 10+ */
}
.card-tree::-webkit-scrollbar {
  display: none; /* Chrome Safari */
}
.tabs {
  background: #fff;
  padding-top: 10px;
  border-radius: 5px 5px 0 0;
}
.operate-item {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  margin: 5px 0;
}
.operate-height {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 16px;
  position: absolute;
  bottom: -10px;
}
.operate {
  color: var(--prev-color-primary);
  font-size: 12px;
  white-space: nowrap;
}

/* R90 owned-only control bounds. Original global rules above remain unchanged. */
.Modal.product-video-owned {
  box-sizing: border-box;
  min-width: 0;
  max-width: 100%;
  .colLeft { min-width: 0; width: 100%; }
  .Nav { flex: 0 0 220px; width: 220px; min-width: 0; max-width: 220px; }
  .conter { flex: 1; width: auto; min-width: 0; }
  .is-modal .pictrueList_pic .img { box-sizing: border-box; max-width: 100%; }
  ::v-deep .custom-tree-node { box-sizing: border-box; min-width: 0; max-width: 100%; }
  ::v-deep .file-name { flex: 1; min-width: 0; }
  ::v-deep .file-name .text { min-width: 0; max-width: 100%; }
  .conter .bnt {
    display: flex; flex-wrap: wrap; gap: 8px;
    > div { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; min-width: 0; max-width: 100%; }
    .el-button { display: inline-flex; align-items: center; justify-content: center; }
  }
  .conter .footer { padding-left: 0; padding-right: 0; min-width: 0; max-width: 100%; }
  ::v-deep .pagination-container { width: 100%; max-width: 100%; min-width: 0; }
  @media (max-width: 767px) {
    .colLeft { flex-direction: column; height: auto; }
    .Nav { flex: 0 0 auto; width: 100%; max-width: 100%; border-right: 0; }
    .trees-coadd .scollhide .isTree { min-height: 0; max-height: 200px; }
    .conter { margin-left: 0 !important; width: 100%; max-width: 100%; height: auto; }
    .conter .pictrueList { min-height: 0; }
    .conter .bnt > div { width: 100%; }
    .conter .bnt .el-input, .conter .bnt .el-cascader { width: 100% !important; max-width: 100%; }
    .conter .footer ::v-deep .el-pagination { display: flex; flex-wrap: wrap; gap: 4px; white-space: normal; padding-left: 0; padding-right: 0; }
    .conter .footer ::v-deep .el-pager { display: flex; flex-wrap: wrap; max-width: 100%; }
  }
}
</style>
