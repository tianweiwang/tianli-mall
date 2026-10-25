<template>
  <div>
    <el-card :bordered="false" shadow="never" class="ivu-mt" :body-style="{ padding: 0 }">
      <div class="padding-add">
        <el-form
          ref="formValidate"
          :model="formValidate"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
          inline
        >
          <el-form-item label="搜索：">
            <el-input
              clearable
              placeholder="请输入姓名、UID"
              v-model="formValidate.keyword"
              class="form_content_width"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" v-db-click @click="userSearchs">查询</el-button>
          </el-form-item>
        </el-form>
      </div>
    </el-card>
    <el-card :bordered="false" shadow="never" class="ivu-mt mt16" :body-style="{ padding: '0 20px 20px' }">
      <el-row class="ivu-mt box-wrapper">
        <el-col :xs="24" :sm="24" ref="rightBox">
          <el-tabs v-model="formValidate.status" @tab-click="userSearchs">
            <el-tab-pane name="all" label="全部"></el-tab-pane>
            <el-tab-pane
              v-for="(item, index) in statusList"
              :key="index"
              :label="item.status_name"
              :name="item.id"
            ></el-tab-pane>
          </el-tabs>
          <p v-if="licenceMessage" class="licence-notice">{{ licenceMessage }}</p>
          <el-table
            :data="userLists"
            ref="table"
            v-loading="loading"
            highlight-current-row
            no-formValidate-text="暂无数据"
            no-filtered-formValidate-text="暂无筛选结果"
          >
            <el-table-column label="用户UID" width="100">
              <template slot-scope="scope">
                <span>{{ scope.row.uid }}</span>
              </template>
            </el-table-column>
            <el-table-column label="代理商名称" min-width="150">
              <template slot-scope="scope">
                <span>{{ scope.row.agent_name }}</span>
              </template>
            </el-table-column>
            <el-table-column label="代理商电话" min-width="150">
              <template slot-scope="scope">
                <span>{{ scope.row.phone }}</span>
              </template>
            </el-table-column>
            <el-table-column label="事业部名称" min-width="150">
              <template slot-scope="scope">
                <span>{{ scope.row.division_name }}</span>
              </template>
            </el-table-column>
            <el-table-column label="申请图片" min-width="150">
              <template slot-scope="scope">
                <div class="pictrue-box" v-if="licenceImagesFor(scope.row).length">
                  <div v-for="(image, index) in licenceImagesFor(scope.row)" :key="image.ref + ':' + index">
                    <div v-viewer v-if="image.url">
                      <img class="pictrue mr10" :src="image.url" alt="申请资质图片" @error="licenceImageFailed(image)" />
                    </div>
                    <span v-else class="licence-image-info">{{ image.loading ? '读取中' : '图片未取得' }}</span>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="申请时间" min-width="150">
              <template slot-scope="scope">
                <span>{{ scope.row.add_time }}</span>
              </template>
            </el-table-column>
            <el-table-column label="申请状态" min-width="150">
              <template slot-scope="scope">
                <el-tag>{{ scope.row.status == 0 ? '申请中' : scope.row.status == 1 ? '已同意' : '已拒绝' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="邀请码" min-width="150">
              <template slot-scope="scope">
                <el-tag>{{ scope.row.division_invite }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" fixed="right" width="170">
              <template slot-scope="scope">
                <a v-if="scope.row.status == 0" v-db-click @click="groupAdd(scope.row.id, 1)">同意</a>
                <el-divider v-if="scope.row.status == 0" direction="vertical" />
                <a v-if="scope.row.status == 0" v-db-click @click="groupAdd(scope.row.id, 0)">拒绝</a>
                <el-divider direction="vertical" v-if="scope.row.status == 0" />
                <a v-db-click @click="del(scope.row, '删除申请', scope.$index)">删除</a>
              </template>
            </el-table-column>
          </el-table>
          <div class="acea-row row-right page">
            <pagination
              v-if="total"
              :total="total"
              :page.sync="formValidate.page"
              :limit.sync="formValidate.limit"
              @pagination="getList"
            />
          </div>
        </el-col>
      </el-row>
    </el-card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import { divisionList, divisionFrom, isShowApi, clerkList } from '@/api/agent';
import { formatDate } from '@/utils/validate';
import { captureContext, currentContext, closeContext, isPrivateAgentLicence, privateAgentLicenceImage } from '@/api/agent-licence';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
export default {
  name: 'agent_extra',
  data() {
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      total: 0,
      total2: 0,
      userLists: [],
      formInline: {
        uid: 0,
        proportion: 0,
        image: '',
      },
      statusList: [
        {
          status_name: '申请中',
          id: '0',
        },
        {
          status_name: '已同意',
          id: '1',
        },
        {
          status_name: '已拒绝',
          id: '2',
        },
      ],
      FromData: null,
      loading: false,
      current: 0,
      formValidate: {
        page: 1,
        limit: 15,
        keyword: '',
        status: 'all',
      },
      staffModal: false,
      clerkReqData: {
        uid: 0,
        page: 1,
        limit: 15,
      },
      clerkLists: [],
      licenceImages: {},
      licenceContext: null,
      licenceGeneration: 0,
      licenceActive: true,
      licenceLoaded: false,
      licenceMessage: '',
      licenceUnsubscribe: null,
      licenceEpochUnsubscribe: null,
    };
  },
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, 'yyyy-MM-dd hh:mm');
      }
    },
  },
  computed: {
    ...mapState('media', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : '50px';
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  mounted() {
    this.getList();
  },
  created() {
    this.licenceUnsubscribe = this.$store.subscribe(() => this.syncLicenceIdentity());
    this.licenceEpochUnsubscribe = subscribePlatformAuthEpoch(this.syncLicenceIdentity);
    window.addEventListener('focus', this.syncLicenceIdentity);
    window.addEventListener('storage', this.syncLicenceIdentity);
  },
  activated() {
    if (!this.licenceActive) { this.licenceActive = true; this.getList(); }
  },
  deactivated() {
    this.licenceActive = false;
    this.clearLicenceImages();
  },
  beforeDestroy() {
    this.licenceActive = false;
    this.clearLicenceImages();
    if (this.licenceUnsubscribe) this.licenceUnsubscribe();
    if (this.licenceEpochUnsubscribe) this.licenceEpochUnsubscribe();
    window.removeEventListener('focus', this.syncLicenceIdentity);
    window.removeEventListener('storage', this.syncLicenceIdentity);
  },
  watch: {
    '$route.fullPath'() { this.clearLicenceImages(); }
  },
  methods: {
    licenceImagesFor(application) {
      return this.licenceActive && currentContext(this.licenceContext) ? this.licenceImages[String(application.id)] || [] : [];
    },
    clearLicenceImages() {
      closeContext(this.licenceContext);
      this.licenceContext = null;
      this.licenceGeneration += 1;
      Object.keys(this.licenceImages).forEach(key => this.licenceImages[key].forEach(image => {
        if (image.owned && image.url) window.URL.revokeObjectURL(image.url);
        image.url = '';
        image.owned = false;
      }));
      this.licenceImages = {};
    },
    syncLicenceIdentity() {
      if (!this.licenceContext || currentContext(this.licenceContext)) return;
      this.clearLicenceImages();
      this.userLists = [];
      this.total = 0;
      this.loading = false;
      this.licenceMessage = '登录身份或权限已变化，请重新查询申请';
    },
    licenceImageFailed(image) {
      if (image.owned && image.url) window.URL.revokeObjectURL(image.url);
      image.url = '';
      image.owned = false;
      image.loading = false;
      image.error = '图片未能显示，请重新查询申请';
      this.licenceMessage = image.error;
    },
    async loadLicenceImages(context, generation, applications) {
      const current = () => this.licenceActive && this.licenceContext === context && currentContext(context) && generation === this.licenceGeneration && applications === this.userLists;
      const queue = [];
      applications.forEach(application => {
        const rows = application.images.map(ref => ({ ref, url: isPrivateAgentLicence(ref) ? '' : ref, owned: false, loading: isPrivateAgentLicence(ref), error: '' }));
        this.$set(this.licenceImages, String(application.id), rows);
        rows.forEach(image => { if (image.loading) queue.push({ application, image }); });
      });
      const worker = async () => {
        while (queue.length && current()) {
          const { application, image } = queue.shift();
          try {
            const url = await privateAgentLicenceImage(context, application, image.ref);
            if (!current() || !this.licenceImagesFor(application).includes(image)) { window.URL.revokeObjectURL(url); return; }
            image.url = url;
            image.owned = true;
          } catch (error) {
            if (current()) { image.error = error.message || '图片读取未完成'; this.licenceMessage = image.error; }
          } finally { if (current()) image.loading = false; }
        }
      };
      await Promise.all([worker(), worker(), worker()]);
    },
    userSearchs() {
      this.formValidate.page = 1;
      this.getList();
    },
    jump(uid) {
      this.clerkReqData.uid = uid;
      this.getClerkList();
    },
    getClerkList() {
      this.clerkReqData.division_type = 3;
      clerkList(this.clerkReqData).then((res) => {
        this.clerkLists = res.data.list;
        this.total2 = res.data.count;
        this.staffModal = true;
      });
    },
    // 列表
    getList() {
      this.clearLicenceImages();
      this.userLists = [];
      if (!this.licenceActive) return;
      let context;
      try { context = captureContext(this.$store); this.licenceContext = context; }
      catch (error) { this.loading = false; this.licenceMessage = '登录身份未确认，请重新查询申请'; return; }
      const generation = this.licenceGeneration, route = this.$route.fullPath;
      const query = { ...this.formValidate };
      const current = () => this.licenceActive && this.licenceContext === context && currentContext(context) && generation === this.licenceGeneration && route === this.$route.fullPath;
      this.loading = true;
      this.licenceMessage = '';
      divisionList(query)
        .then(async (res) => {
          if (!current()) return;
          const data = res.data;
          if (!data || !Array.isArray(data.list) || data.list.some(application => !application || !Array.isArray(application.images) || application.images.some(ref => typeof ref !== 'string' || !ref))) throw new Error('申请列表未完整取得');
          this.userLists = data.list;
          this.total = data.count;
          this.loading = false;
          this.licenceLoaded = true;
          await this.loadLicenceImages(context, generation, data.list);
        })
        .catch((res) => {
          if (!current()) return;
          this.loading = false;
          this.licenceMessage = res.msg || res.message || '申请读取未完成，请重新查询';
        });
    },
    pageChange(index) {
      this.formValidate.page = index;
      this.getList();
    },
    clerkPageChange() {
      this.clerkReqData.page = index;
      this.getClerkList();
    },
    // 添加表单
    groupAdd(id, type) {
      this.$modalForm(divisionFrom(id, type))
        .then((res) => {
          this.getList();
        })
        .catch((err) => {});
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.uid,
        status: row.division_status,
      };
      isShowApi(data)
        .then(async (res) => {
          this.$message.success(res.msg);
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
    // 编辑
    edit(row) {},
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        method: 'DELETE',
        uid: row.id,
        url: `agent/division/del_apply/${row.id}`,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$message.success(res.msg);
          this.getList();
        })
        .catch((res) => {
          this.$message.error(res.msg);
        });
    },
  },
};
</script>

<style lang="scss" scoped>
.ivu-form-item {
  margin-bottom: 0;
}
.picBox {
  display: inline-block;
  cursor: pointer;
  .upLoad {
    width: 58px;
    height: 58px;
    line-height: 58px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.02);
  }
  .pictrue {
    width: 60px;
    height: 60px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    margin-right: 10px;

    img {
      width: 100%;
      height: 100%;
    }
  }
}
::v-deep .ivu-menu-vertical .ivu-menu-item-group-title {
  display: none;
}
::v-deep .ivu-menu-vertical.ivu-menu-light:after {
  display: none;
}
.left-wrapper {
  height: 904px;
  background: #fff;
  border-right: 1px solid #f2f2f2;
}
.menu-item {
  z-index: 50;
  position: relative;
  display: flex;
  justify-content: space-between;
  word-break: break-all;
}
::v-deep .el-tabs__item {
  height: 54px;
  line-height: 54px;
}
.icon-box {
  z-index: 3;
  position: absolute;
  right: 20px;
  top: 50%;
  transform: translateY(-50%);
  display: none;
}

.right-menu {
  z-index: 10;
  position: absolute;
  right: -106px;
  top: -11px;
  width: auto;
  min-width: 121px;
}
.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;
  img {
    width: 100%;
    height: 100%;
  }
}
.pictrue-box {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.licence-notice {
  color: #606266;
  line-height: 1.6;
  margin: 12px 0;
  word-break: break-word;
}
.licence-image-info {
  display: inline-block;
  font-size: 12px;
  color: #909399;
}
.pictrue {
  width: 25px;
  height: 25px;
}
</style>
