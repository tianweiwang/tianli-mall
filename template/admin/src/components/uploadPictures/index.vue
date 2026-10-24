<template>
  <div class="Modal" :class="{ 'product-media-picker': productId !== null }">
    <div v-if="productId !== null" class="product-source-switch">
      <el-radio-group v-model="productSource" :disabled="platformWriting || !productSession" size="small" @change="changeProductSource">
        <el-radio-button label="commercial">商品图库</el-radio-button>
        <el-radio-button label="platform">平台图片</el-radio-button>
      </el-radio-group>
      <span class="product-source-hint">平台图片需先导入商品图库，再用于商品</span>
    </div>
    <el-alert v-if="productMessage" :title="productMessage" :description="productReceiptDescription" type="warning" :closable="false" show-icon />
    <el-alert v-if="platformNotice" :title="platformNotice" type="info" :closable="false" show-icon />
    <div v-show="productSource !== 'platform'" class="colLeft">
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
              type="primary"
              :disabled="checkPicList.length === 0"
              v-db-click
              @click="checkPics"
              size="small"
              v-if="isShow !== 0"
              >使用选中图片</el-button
            >
            <el-button size="small" type="primary" v-db-click @click="uploadModal">上传图片</el-button>
            <el-button
              class="mr14"
              size="small"
              :disabled="!checkPicList.length && !ids.length"
              v-db-click
              @click.stop="editPicList()"
              >删除图片</el-button
            >
            <el-cascader
              v-model="pids"
              placeholder="图片移动至"
              style="width: 150px"
              class="treeSel"
              :options="treeData2"
              :props="{ checkStrictly: true, emitPath: false, label: 'title', value: 'id' }"
              clearable
              size="small"
              @visible-change="moveImg"
            ></el-cascader>
          </div>
          <div>
            <el-input
              class="mr10"
              v-model="fileData.real_name"
              placeholder="请输入图片名"
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
              <span class="imagesNo_sp">图片库为空</span>
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
                  <img v-lazy="item.satt_dir" />
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
            <el-table-column label="图片名称" min-width="190">
              <template slot-scope="scope">
                <div class="df-aic">
                  <div class="tabBox_img mr10" v-viewer>
                    <img v-lazy="scope.row.att_dir" />
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
                <a v-db-click @click="editPicList(scope.row.att_id)">删除</a>
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
    <section v-if="productId !== null" v-show="productSource === 'platform'" class="platform-source" aria-label="平台图片来源">
      <div class="platform-source-nav" v-loading="platformTreeLoading">
        <h3 class="platform-source-title">平台分类</h3>
        <el-tree ref="platformTree" :data="platformCategories" node-key="id" highlight-current :current-node-key="platformQuery.category_id" @node-click="choosePlatformCategory">
          <span slot-scope="{ data }" class="platform-category-name">{{ data.title }}<small v-if="data.type === 1">（目录）</small></span>
        </el-tree>
      </div>
      <div class="platform-source-content">
        <div class="platform-source-tools">
          <el-input v-model="platformQuery.real_name" class="platform-source-search" size="small" placeholder="搜索平台图片名" maxlength="100" clearable :disabled="platformWriting" @keyup.enter.native="searchPlatformAssets">
            <el-button slot="append" icon="el-icon-search" aria-label="搜索平台图片" :disabled="platformWriting || platformLoading" @click="searchPlatformAssets" />
          </el-input>
          <el-button class="platform-action" size="small" :loading="platformWriting" :disabled="platformWriting || productWriteUnknown || platformTreeLoading" @click="choosePlatformFile">上传到平台库</el-button>
          <input ref="platformFile" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.ico,image/jpeg,image/png,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon" class="platform-file-input" tabindex="-1" @change="uploadPlatformFile" />
        </div>
        <p class="platform-source-description">当前分类：{{ platformCategoryName }}。上传将保存到{{ platformQuery.category_id === 0 ? '平台未分组' : '此平台分类' }}；商品导入分类在下方单独选择。</p>
        <div v-loading="platformLoading" class="platform-source-gallery" aria-live="polite">
          <p v-if="!platformLoading && platformReadReady && !platformAssets.length" class="platform-source-empty">当前分类暂无平台图片，可上传到平台库后再导入。</p>
          <p v-if="!platformLoading && !platformReadReady" class="platform-source-empty">平台图片尚未读取完成，请点击搜索重新读取。</p>
          <div v-if="platformAssets.length" class="platform-source-grid">
            <button v-for="item in platformAssets" :key="item.platform_asset_id" type="button" class="platform-image-choice" :class="{ 'is-selected': platformSelectedId === item.platform_asset_id }" :aria-pressed="platformSelectedId === item.platform_asset_id" :disabled="platformWriting || platformLoading || productWriteUnknown" @click="choosePlatformAsset(item)">
              <img :src="item.satt_dir || item.src" alt="" />
              <span class="platform-image-name">{{ item.real_name || item.name }}</span>
              <span v-if="platformSelectedId === item.platform_asset_id" class="platform-image-selection">待导入</span>
            </button>
          </div>
        </div>
        <div class="platform-source-pagination">
          <el-pagination v-if="platformTotal" small background layout="total, prev, pager, next" :total="platformTotal" :page-size="platformQuery.limit" :current-page="platformQuery.page" :pager-count="5" :disabled="platformWriting || platformLoading" @current-change="changePlatformPage" />
        </div>
        <div class="platform-import-actions">
          <label class="platform-import-label">导入至商品分类
            <el-cascader v-model="platformDestinationPid" :options="treeData2" :props="{ checkStrictly: true, emitPath: false, label: 'title', value: 'id' }" :disabled="platformWriting || productWriteUnknown" size="small" placeholder="未分组" />
          </label>
          <el-button class="platform-action" type="primary" size="small" :loading="platformWriting" :disabled="!platformSelectedId || platformLoading || platformWriting || productWriteUnknown" @click="importSelectedPlatformImage">导入到商品图库</el-button>
        </div>
      </div>
    </section>
    <uploadImg
      ref="upload"
      :isPage="isPage"
      :productId="productId"
      :productScope="productSession"
      :isIframe="isIframe"
      :categoryId="treeId"
      :categoryList="productId !== null ? treeData2 : treeData"
      @uploadSuccess="uploadSuccess"
      @mediaCloseResult="acceptProductClose"
    ></uploadImg>
    <el-dialog :visible.sync="productCategoryVisible" :append-to-body="true" title="图片分类" :width="isIframe ? '95%' : '480px'" :close-on-click-modal="false">
      <el-form label-width="80px">
        <el-form-item label="分类名称"><el-input v-model="productCategory.name" maxlength="50" /></el-form-item>
        <el-form-item label="上级分类">
          <el-cascader v-model="productCategory.pid" :options="treeData2" :props="{ checkStrictly: true, emitPath: false, label: 'title', value: 'id' }" />
        </el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="productCategoryVisible = false">取消</el-button><el-button type="primary" :loading="productSaving" :disabled="productWriteUnknown" @click="saveProductCategory">保存</el-button></span>
    </el-dialog>
    <div class="images" v-show="false" v-viewer="{ movable: false }">
      <img v-for="src in pictrueList" :src="src.att_dir" :key="src.att_id" />
    </div>
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
} from '@/api/uploadPictures';
import Setting from '@/setting';
import { captureProductContext, currentProductContext, closeProductContext, receiptText, requireProductContext, mediaId, mediaList, mediaCategories, mediaCreateCategory, mediaUpdateCategory, mediaDeleteCategory, mediaMove, mediaDelete, mediaRename, platformMediaList, platformMediaCategories, platformMediaUpload, importPlatformImage } from '@/api/product-media';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
import { getCookies } from '@/libs/util';
import uploadImg from '@/components/uploadImg';
import { VueTreeList, Tree, TreeNode } from 'vue-tree-list';
export default {
  name: 'uploadPictures',
  components: { uploadImg, VueTreeList },
  props: {
    productId: { default: null },
    isChoice: {
      type: String,
      default: '',
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
      productSource: 'commercial',
      platformGeneration: 0,
      platformListSequence: 0,
      platformTreeSequence: 0,
      platformAssets: [],
      platformCategories: [],
      platformTotal: 0,
      platformSelectedId: '',
      platformCategoryName: '全部图片',
      platformDestinationPid: 0,
      platformQuery: { page: 1, limit: 18, category_id: 0, real_name: '' },
      platformLoading: false,
      platformReadReady: false,
      platformTreeLoading: false,
      platformWriting: false,
      platformFileContext: null,
      platformNotice: '',
      productScopeInitialized: false,
      productListSequence: 0,
      productTreeSequence: 0,
      productMessage: '',
      productReceipt: null,
      productCloseResult: null,
      productReceiptDescription: '',
      productWriteUnknown: false,
      productSaving: false,
      productCategoryVisible: false,
      productCategory: { id: 0, pid: 0, name: '' },
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
      this.getList();
      this.getFileList();
    },
  },
  beforeDestroy() {
    this.resetPlatformSource();
    closeProductContext(this.productSession);
    this.productSession = null;
    if (this.productUnsubscribe) this.productUnsubscribe();
  },
  mounted() {
    if (this.isPage) {
      let hang = parseInt((document.body.clientHeight - this.$refs.imgListBox.clientHeight - 325) / 180); //计算行数
      let col = parseInt(this.$refs.imgListBox.clientWidth / 156); //计算列数
      this.fileData.limit = col * hang; //计算分页数量
      this.picmargin = parseInt(this.$refs.imgListBox.clientWidth - col * 146) / (2 * col) + 'px'; //平均分布计算margin距离
    }
    this.getToken();
    this.getList();
    this.getFileList();
  },
  methods: {
    receiptTextValue(value) { return receiptText(value); },
    acceptProductClose(event) {
      const child = this.$refs.upload;
      if (!event || this.productId === null || event.scope !== this.productSession || !currentProductContext(this.productSession) || !child || event.context !== child.productSession || event.generation !== child.productGeneration) return;
      this.productCloseResult = { lease_id: event.lease_id, state: event.state, cleanup_complete: event.cleanup_complete === true };
      if (event.message) this.productMessage = event.message;
      if (event.receipt) { this.productReceipt = event.receipt; this.productReceiptDescription = receiptText(event.receipt); }
    },
    resetProductScope() {
      this.resetPlatformSource();
      const hadProduct = this.productScopeInitialized === true;
      this.productScopeInitialized = this.productId !== null;
      closeProductContext(this.productSession);
      this.productSession = null;
      this.productMessage = ''; this.productReceipt = null; this.productReceiptDescription = '';
      this.productWriteUnknown = false;
      this.productCategoryVisible = false;
      if (this.productId === null && !hadProduct) return;
      this.treeId = this.productId === null ? '' : 0;
      this.treeData = [];
      this.treeData2 = [];
      this.pictrueList = [];
      this.total = 0;
      this.initData();
      if (this.productId !== null) {
        try { this.productSession = captureProductContext(this.productId); } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; }
      }
    },
    invalidateProductScope() {
      this.resetPlatformSource();
      closeProductContext(this.productSession);
      this.productSession = null;
      this.productMessage = '登录身份已变化，进行中的提交结果请先核对，再重新打开商品图片';
      this.productCategoryVisible = false;
      this.pictrueList = [];
      this.treeData = [];
      this.treeData2 = [];
      this.total = 0;
      this.initData();
    },
    async productWork(operation, apply, write = false, active = null) {
      const context = this.productSession;
      try {
        requireProductContext(context);
        if (write && this.productWriteUnknown) throw new Error('上一项提交结果未确认，请先核对图库');
        const value = await operation(context);
        if (this.productSession !== context || (active && !active())) return;
        requireProductContext(context);
        if (apply) apply(value);
        return value;
      } catch (error) {
        if (this.productSession === context && (!active || active())) {
          if (error.state === 'unknown') this.productWriteUnknown = true;
          this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message || '商品图片操作未完成';
        }
      }
    },
    resetPlatformSource() {
      this.platformGeneration += 1;
      this.platformListSequence += 1; this.platformTreeSequence += 1;
      this.productSource = 'commercial';
      this.platformAssets = []; this.platformCategories = []; this.platformTotal = 0;
      this.platformSelectedId = ''; this.platformDestinationPid = 0;
      this.platformQuery = { page: 1, limit: 18, category_id: 0, real_name: '' };
      this.platformCategoryName = '全部图片';
      this.platformLoading = false; this.platformReadReady = false; this.platformTreeLoading = false; this.platformWriting = false;
      this.platformFileContext = null; this.platformNotice = '';
    },
    platformScopeCurrent(context, generation) {
      return this.productSession === context && this.platformGeneration === generation && currentProductContext(context);
    },
    changeProductSource(value) {
      if (value !== 'platform') return;
      if (!currentProductContext(this.productSession)) { this.productSource = 'commercial'; return; }
      this.getPlatformCategories(); this.getPlatformAssets();
    },
    async getPlatformCategories() {
      const context = this.productSession; const generation = this.platformGeneration;
      if (!this.platformScopeCurrent(context, generation)) return;
      const sequence = ++this.platformTreeSequence;
      const active = () => this.platformScopeCurrent(context, generation) && sequence === this.platformTreeSequence;
      this.platformTreeLoading = true;
      try {
        await this.productWork(ctx => platformMediaCategories(ctx), value => {
          this.platformCategories = [{ id: 0, pid: 0, type: 0, title: '全部图片' }, ...value.list];
        }, false, active);
      } finally { if (active()) this.platformTreeLoading = false; }
    },
    async getPlatformAssets(selectedId = '') {
      const context = this.productSession; const generation = this.platformGeneration;
      if (!this.platformScopeCurrent(context, generation)) return;
      const sequence = ++this.platformListSequence;
      const active = () => this.platformScopeCurrent(context, generation) && sequence === this.platformListSequence;
      const query = { ...this.platformQuery };
      this.platformLoading = true; this.platformReadReady = false; this.platformSelectedId = '';
      this.platformAssets = []; this.platformTotal = 0;
      try {
        await this.productWork(ctx => platformMediaList(ctx, query), value => {
          this.platformAssets = value.list; this.platformTotal = value.count; this.platformReadReady = true;
          this.platformSelectedId = selectedId && value.list.some(item => item.platform_asset_id === selectedId) ? selectedId : '';
        }, false, active);
      } finally { if (active()) this.platformLoading = false; }
    },
    choosePlatformCategory(category) {
      if (this.platformWriting || !currentProductContext(this.productSession)) return;
      if (category.type !== 0) {
        this.platformNotice = '此目录仅用于导航，请选择其下的图片分类。';
        if (this.$refs.platformTree) this.$refs.platformTree.setCurrentKey(this.platformQuery.category_id);
        return;
      }
      this.platformQuery.category_id = category.id; this.platformQuery.page = 1;
      this.platformCategoryName = category.title;
      this.getPlatformAssets();
    },
    searchPlatformAssets() {
      if (this.platformWriting || !currentProductContext(this.productSession)) return;
      this.platformQuery.page = 1; this.getPlatformAssets();
    },
    changePlatformPage(page) {
      if (this.platformWriting || page === this.platformQuery.page || !currentProductContext(this.productSession)) return;
      this.platformQuery.page = page; this.getPlatformAssets();
    },
    choosePlatformAsset(item) {
      if (this.platformWriting || this.platformLoading || this.productWriteUnknown || !currentProductContext(this.productSession)) return;
      if (!this.platformAssets.includes(item)) return;
      this.platformSelectedId = item.platform_asset_id;
    },
    choosePlatformFile() {
      if (this.platformWriting || this.productWriteUnknown || !currentProductContext(this.productSession)) return;
      this.platformFileContext = { context: this.productSession, generation: this.platformGeneration, categoryId: this.platformQuery.category_id };
      this.$refs.platformFile.value = ''; this.$refs.platformFile.click();
    },
    async uploadPlatformFile(event) {
      const file = event.target.files && event.target.files[0];
      event.target.value = '';
      const entry = this.platformFileContext; this.platformFileContext = null;
      if (!file || !entry || this.platformWriting || !this.platformScopeCurrent(entry.context, entry.generation)) return;
      const active = () => this.platformScopeCurrent(entry.context, entry.generation);
      this.platformWriting = true; this.platformNotice = '';
      let uploaded = null;
      try {
        await this.productWork(ctx => platformMediaUpload(ctx, file, entry.categoryId), value => { uploaded = value; }, true, active);
        if (!active() || !uploaded) return;
        this.platformQuery.page = 1; this.platformQuery.real_name = '';
        this.platformNotice = '图片已保存到平台库；点击导入后才能用于当前商品。';
        await this.getPlatformAssets(uploaded.platform_asset_id);
      } finally { if (active()) this.platformWriting = false; }
    },
    async importSelectedPlatformImage() {
      if (this.platformWriting || this.platformLoading || this.productWriteUnknown || !this.platformSelectedId) return;
      if (!this.platformAssets.some(item => item.platform_asset_id === this.platformSelectedId)) return;
      const context = this.productSession; const generation = this.platformGeneration;
      const active = () => this.platformScopeCurrent(context, generation);
      if (!active()) return;
      const sourceId = this.platformSelectedId;
      const destinationPid = this.platformDestinationPid === null || this.platformDestinationPid === '' ? 0 : this.platformDestinationPid;
      this.platformWriting = true; this.platformNotice = '';
      let imported = null;
      try {
        await this.productWork(ctx => importPlatformImage(ctx, sourceId, destinationPid), value => { imported = value.list[0]; }, true, active);
        if (!active() || !imported) return;
        this.platformSelectedId = ''; this.productSource = 'commercial';
        this.treeId = destinationPid; this.fileData.page = 1; this.fileData.real_name = '';
        this.initData(); this.pictrueList = []; this.total = 0; this.isShowPic = false;
        const sequence = this.productListSequence + 1;
        const refreshed = await this.getProductImages();
        if (!active() || sequence !== this.productListSequence) return;
        if (!refreshed) { this.platformNotice = '平台图片已导入；商品图库刷新未完成，请刷新后核对，请勿重复导入。'; return; }
        const index = this.pictrueList.findIndex(item => item.att_id === imported.att_id && item.att_dir === imported.src);
        if (index >= 0) {
          this.changImage(this.pictrueList[index], index, this.pictrueList);
          this.platformNotice = '平台图片已导入并选中，点击“使用选中图片”应用到商品。';
        } else this.platformNotice = '平台图片已导入，当前页未显示新图片；请在商品图库核对，请勿重复导入。';
      } finally { if (active()) this.platformWriting = false; }
    },
    getProductCategories(type) {
      const sequence = ++this.productTreeSequence;
      return this.productWork(ctx => mediaCategories(ctx, { ...this.uploadName, type: 0 }), value => {
        if (sequence !== this.productTreeSequence) return;
        if (type !== 'search') this.treeData2 = [{ id: 0, title: '未分组', pid: 0 }, ...JSON.parse(JSON.stringify(value.list))];
        this.treeData = [{ id: 0, title: '全部图片', pid: 0 }, ...value.list];
      });
    },
    getProductImages() {
      const sequence = ++this.productListSequence;
      this.fileData.limit = Math.max(1, Math.min(100, Number(this.fileData.limit) || 18));
      const query = { ...this.fileData, pid: this.treeId === '' ? 0 : this.treeId };
      return this.productWork(ctx => mediaList(ctx, query), value => {
        if (sequence !== this.productListSequence) return;
        value.list.forEach(item => {
          item.isSelect = false; item.isEdit = false; item.isShowEdit = false; item.realName = false; item.num = 0;
          this.editName(item);
        });
        this.pictrueList = value.list; this.total = value.count; this.isShowPic = value.list.length === 0;
      });
    },
    openProductCategory(id, pid, name = '') {
      try {
        requireProductContext(this.productSession);
        if (this.productWriteUnknown) throw new Error('上一项结果未确认，请先核对图库');
        this.productCategory = { id: mediaId(id, true), pid: mediaId(pid, true), name };
        this.productCategoryVisible = true;
      } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; }
    },
    async saveProductCategory() {
      if (this.productSaving) return;
      const category = { ...this.productCategory };
      if (!category.name.trim()) { this.productMessage = '请填写分类名称'; return; }
      this.productSaving = true;
      try {
        await this.productWork(ctx => category.id ? mediaUpdateCategory(ctx, category.id, { name: category.name, pid: mediaId(category.pid, true), type: 0 }) : mediaCreateCategory(ctx, { name: category.name, pid: mediaId(category.pid, true), type: 0 }), () => {
          this.productCategoryVisible = false;
          this.$message.success('分类已保存'); this.getProductCategories();
        }, true);
      } finally { this.productSaving = false; }
    },
    async removeProductCategory(category) {
      const context = this.productSession;
      try {
        requireProductContext(context);
        await this.$confirm('删除分类“' + category.title + '”？所属图片将移到父分类或未分组。', '删除分类', { type: 'warning' });
        if (this.productSession !== context) return;
        await this.productWork(ctx => mediaDeleteCategory(ctx, category.id), () => {
          this.treeId = 0; this.initData(); this.getProductCategories(); this.getProductImages(); this.$message.success('分类已删除');
        }, true);
      } catch (error) { if (error !== 'cancel' && error !== 'close' && this.productSession === context) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message || '分类未删除'; } }
    },
    moveProductImages() {
      const ids = this.ids.slice(); if (!ids.length) return;
      return this.productWork(ctx => mediaMove(ctx, ids, this.pids === '' || this.pids === null ? 0 : this.pids), () => {
        this.initData(); this.pids = 0; this.getProductImages(); this.$message.success('图片已移动');
      }, true);
    },
    async deleteProductImages(ids) {
      if (!ids.length) return;
      const context = this.productSession;
      try {
        requireProductContext(context);
        await this.$confirm('删除选中的图片？', '删除图片', { type: 'warning' });
        if (this.productSession !== context) return;
        await this.productWork(ctx => mediaDelete(ctx, ids), () => { this.initData(); this.getProductImages(); this.$message.success('图片已删除'); }, true);
      } catch (error) { if (error !== 'cancel' && error !== 'close' && this.productSession === context) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message || '图片未删除'; } }
    },
    renameProductImage(item) {
      if (!item.real_name.trim()) { this.productMessage = '请填写图片名称'; return; }
      return this.productWork(ctx => mediaRename(ctx, item.att_id, item.real_name), () => { this.editName(item); item.isEdit = false; this.$message.success('图片名称已保存'); }, true);
    },
    radioChange() {
      this.initData();
    },
    lookImg(item) {
      this.imageUrl = item.att_dir;
      const viewer = this.$el.querySelector('.images').$viewer;
      viewer.show();
      this.$nextTick(() => {
        let i = this.pictrueList.findIndex((e) => e.att_dir === item.att_dir);
        viewer.update().view(i);
      });
    },
    onDel(node) {
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
      if (this.productId !== null) {
        try { requireProductContext(this.productSession); if (this.productWriteUnknown) throw new Error("上一项结果未确认，请先核对图库"); } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; return; }
      }
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
      if (!status) {
        this.getMove();
      } else {
        if (!this.ids.toString()) {
          this.$message.warning('请先选择图片');
          return;
        }
      }
    },
    searchImg() {},
    // 移动分类
    getMove() {
      if (this.productId !== null) return this.moveProductImages();
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
      if (this.productId !== null) return this.deleteProductImages([id]);
      let ids = {
        ids: id,
      };
      let delfromData = {
        title: '删除选中图片',
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
    // 删除图片
    editPicList(id) {
      if (this.productId !== null) return this.deleteProductImages(id ? [id] : this.ids.slice());
      let ids = {
        ids: id || this.ids.toString(),
      };
      let delfromData = {
        title: '删除选中图片',
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
        title: '全部图片',
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
      if (this.productId !== null) return this.productWork(ctx => mediaCategories(ctx, { pid: item.id, all: 0, type: 0 }), value => callback(value.list));
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
      if (this.productId !== null) return this.getProductImages();
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
      this.fileData.page = index;
      this.getFileList();
      this.checkPicList = [];
    },
    // 新建分类表单
    getFrom() {
      if (this.productId !== null) return this.openProductCategory(0, this.treeId === "" ? 0 : this.treeId, "");
      this.$modalForm(createApi({ id: this.treeId })).then((res) => {
        this.getList();
      });
    },
    // 上传之前
    beforeUpload(file) {
      // if (file.size > 2097152) {
      //   this.$message.error(file.name + "大小超过2M!");
      // } else
      if (!/image\/\w+/.test(file.type)) {
        this.$message.error('请上传以jpg、jpeg、png等结尾的图片文件'); //FileExt.toLowerCase()
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
    // 选中图片
    changImage(item, index, row) {
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
    // 点击使用选中图片
    checkPics() {
      if (this.productId !== null) { try { requireProductContext(this.productSession); } catch (error) { this.productReceipt = error.receipt || null; this.productReceiptDescription = receiptText(this.productReceipt); this.productMessage = error.message; return; } }
      if (this.isChoice === '单选') {
        if (this.checkPicList.length > 1) return this.$message.warning('最多只能选一张图片');
        this.$emit('getPic', this.checkPicList[0]);
      } else {
        let maxLength = this.$route.query.maxLength;
        if (maxLength != undefined && this.checkPicList.length > Number(maxLength))
          return this.$message.warning('最多只能选' + maxLength + '张图片');
        this.$emit('getPicD', this.checkPicList);
        this.$emit('getPic', this.checkPicList);
      }
    },
    editName(item) {
      let it = item.real_name.split('.');
      let it1 = it[1] == undefined ? [] : it[1];
      let len = it[0].length + it1.length;
      item.editName = len < 10 ? item.real_name : item.real_name.substr(0, 4) + '...' + item.real_name.substr(-5, 5);
    },
    // 修改图片文字上传
    bindTxt(item) {
      if (this.productId !== null) return this.renameProductImage(item);
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
    img {
      max-width: 140px;
      max-height: 140px;
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

/* Product source panel only; the legacy global picker keeps its original layout. */
.product-source-switch {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.product-source-hint, .platform-source-description {
  color: #909399;
  font-size: 12px;
  line-height: 20px;
}
.product-media-picker > .el-alert { margin-bottom: 12px; }
.platform-source {
  display: flex;
  gap: 20px;
  width: 100%;
  box-sizing: border-box;
}
.platform-source-nav {
  flex: 0 0 180px;
  min-width: 0;
  max-width: 180px;
  border-right: 1px solid #ebeef5;
  padding-right: 12px;
  box-sizing: border-box;
}
.platform-source-title { margin: 0 0 12px; color: #515a6d; font-size: 14px; }
.platform-category-name {
  flex: 1;
  min-width: 0;
  white-space: normal;
  overflow-wrap: break-word;
}
.platform-category-name small { color: #909399; }
.platform-source-nav ::v-deep .el-tree-node__content { height: auto; min-height: 32px; align-items: center; }
.platform-source-content { flex: 1; min-width: 0; }
.platform-source-tools, .platform-import-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.platform-source-search { flex: 1 1 200px; width: auto; min-width: 0; }
.platform-file-input { display: none; }
.platform-source-description { margin: 12px 0; }
.platform-source-gallery { min-height: 220px; }
.platform-source-empty { margin: 48px 0; color: #909399; text-align: center; }
.platform-source-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(128px, 1fr));
  gap: 12px;
}
.platform-image-choice {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  min-width: 0;
  min-height: 168px;
  padding: 10px;
  border: 2px solid #ebeef5;
  border-radius: 4px;
  box-sizing: border-box;
  color: #515a6d;
  background: #fff;
  font: inherit;
  text-align: center;
  cursor: pointer;
}
.platform-image-choice img { width: 100%; height: 104px; object-fit: contain; }
.platform-image-name { width: 100%; font-size: 12px; line-height: 18px; overflow-wrap: break-word; }
.platform-image-choice.is-selected { border-color: var(--prev-color-primary); }
.platform-image-choice:focus-visible { outline: 2px solid var(--prev-color-primary); outline-offset: 2px; }
.platform-image-choice:disabled { cursor: default; opacity: .65; }
.platform-image-selection { color: var(--prev-color-primary); font-size: 12px; }
.platform-source-pagination { display: flex; justify-content: flex-end; margin: 16px 0; }
.platform-import-actions { padding-top: 12px; border-top: 1px solid #ebeef5; }
.platform-import-label { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; min-width: 0; }
.platform-import-label .el-cascader { width: 180px; max-width: 100%; }
.platform-action.el-button { display: inline-flex; align-items: center; justify-content: center; gap: 4px; margin: 0; text-align: center; }
.product-source-switch ::v-deep .el-radio-button__inner { display: inline-flex; align-items: center; justify-content: center; }
.platform-action ::v-deep span { display: inline-flex; align-items: center; justify-content: center; text-align: center; }
@media (max-width: 700px) {
  .platform-source { flex-direction: column; gap: 12px; }
  .platform-source-nav { flex-basis: auto; width: 100%; max-width: none; max-height: 180px; overflow: auto; border-right: 0; border-bottom: 1px solid #ebeef5; padding: 0 0 12px; }
  .platform-source-pagination { justify-content: center; }
  .platform-import-label, .platform-import-label .el-cascader { width: 100%; }
  .platform-source-tools > .platform-action, .platform-import-actions > .platform-action { width: 100%; box-sizing: border-box; }
  .platform-source-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
