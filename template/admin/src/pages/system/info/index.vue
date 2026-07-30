<template>
  <div>
    <el-card shadow="never" class="ivu-mt mb16">
      <div class="head acea-row row-between-wrapper">
        <span>本地版权信息</span>
        <el-button type="primary" size="small" v-db-click @click="editCopyright">编辑</el-button>
      </div>
      <div class="copyright-preview">
        <img v-if="authorizedPicture" :src="authorizedPicture" alt="" class="copyright-image" />
        <span v-if="copyrightText">{{ copyrightText }}</span>
        <span v-if="!authorizedPicture && !copyrightText" class="empty-text">暂未配置</span>
      </div>
    </el-card>

    <el-card v-for="(value, key) in tableList" :key="key" shadow="never" class="ivu-mt mb16">
      <div class="head acea-row row-between-wrapper">{{ key | headText }}</div>
      <el-table ref="table" :data="tableList[key]" empty-text="暂无数据">
        <el-table-column :label="key == 'permissions' ? '文件/目录' : '环境'" min-width="180">
          <template slot-scope="scope">{{ scope.row.name }}</template>
        </el-table-column>
        <el-table-column label="要求" min-width="180">
          <template slot-scope="scope">
            <span>{{ scope.row.require }}</span>
            <el-tooltip placement="top" v-if="key == 'process' && !scope.row.value">
              <div slot="content" v-html="trips[scope.$index].message"></div>
              <i class="el-icon-warning-outline"></i>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="180">
          <template slot-scope="scope">
            <span v-if="typeof scope.row.value === 'boolean'">
              <i v-if="scope.row.value === true" class="el-icon-check"></i>
              <i v-else class="el-icon-close"></i>
            </span>
            <span v-else>{{ scope.row.value }}</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog :visible.sync="modalCopyright" title="编辑版权信息" width="550px">
      <div class="copyright-field">
        <div class="field-label">版权文字:</div>
        <el-input v-model="copyrightText" />
      </div>
      <div class="copyright-field">
        <div class="field-label">版权图片:</div>
        <div>
          <div class="upload-picture" v-if="authorizedPicture" v-db-click @click="modalPicTap">
            <img v-lazy="authorizedPicture" alt="" />
            <i class="el-icon-error" @click.stop="authorizedPicture = ''"></i>
          </div>
          <div class="upload" v-else v-db-click @click="modalPicTap">
            <div class="iconfont">+</div>
          </div>
          <div class="tips-info">建议尺寸：宽290px*高100px</div>
        </div>
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button v-db-click @click="modalCopyright = false">取 消</el-button>
        <el-button type="primary" v-db-click @click="saveCopyRight">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="modalPic" width="1024px" title="上传版权图片" :close-on-click-modal="false">
      <uploadPictures
        v-if="modalPic"
        :isChoice="isChoice"
        :gridBtn="gridBtn"
        :gridPic="gridPic"
        @getPic="getPic"
      ></uploadPictures>
    </el-dialog>
  </div>
</template>

<script>
import uploadPictures from '@/components/uploadPictures';
import { saveCrmebCopyRight, getCrmebCopyRight, copyrightList } from '@/api/system';

export default {
  name: 'system_info',
  components: {
    uploadPictures,
  },
  data() {
    return {
      modalCopyright: false,
      modalPic: false,
      copyrightText: '',
      authorizedPicture: '',
      isChoice: '单选',
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      tableList: {},
      trips: [
        {
          message:
            '您的【长连接】未开启，没有开启会导致系统默认客服无法使用,后台订单通知无法收到。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
        },
        {
          message:
            '您的【定时任务】未开启，没有开启会导致自动收货、未支付自动取消订单、订单自动好评、拼团到期退款等任务无法正常执行。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
        },
        {
          message:
            '您的【消息队列】未开启，没有开启会导致异步任务无法执行。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
        },
      ],
    };
  },
  filters: {
    headText(key) {
      const labels = {
        server: '服务器信息',
        environment: '系统环境要求',
        permissions: '权限状态',
        process: '启动进程',
      };
      return labels[key] || key;
    },
  },
  mounted() {
    this.getCopyRight();
    copyrightList().then((res) => {
      this.tableList = res.data;
    });
  },
  methods: {
    editCopyright() {
      this.modalCopyright = true;
    },
    saveCopyRight() {
      saveCrmebCopyRight({
        copyright: this.copyrightText,
        copyright_img: this.authorizedPicture,
      }).then((res) => {
        this.getCopyRight();
        this.modalCopyright = false;
        this.$message.success(res.msg);
      });
    },
    modalPicTap() {
      this.modalPic = true;
    },
    getPic(picture) {
      this.authorizedPicture = picture.att_dir;
      this.modalPic = false;
    },
    getCopyRight() {
      getCrmebCopyRight().then((res) => {
        const { copyrightContext, copyrightImage } = res.data;
        this.copyrightText = copyrightContext || '';
        this.authorizedPicture = copyrightImage || '';
      });
    },
  },
};
</script>

<style scoped lang="scss">
.head {
  margin-bottom: 20px;
  color: #303133;
  font-size: 14px;
  font-weight: 400;
}

.copyright-preview {
  display: flex;
  min-height: 60px;
  align-items: center;
  gap: 16px;
  color: #606266;
}

.copyright-image {
  max-width: 290px;
  max-height: 100px;
  object-fit: contain;
}

.empty-text,
.tips-info {
  color: #909399;
  font-size: 12px;
}

.copyright-field {
  display: flex;
  padding: 9px 16px 9px 10px;
}

.field-label {
  flex: 0 0 76px;
  padding-right: 12px;
  color: rgba(0, 0, 0, 0.85);
  font-size: 13px;
  line-height: 32px;
}

.upload,
.upload-picture {
  position: relative;
  display: flex;
  width: 60px;
  height: 60px;
  align-items: center;
  justify-content: center;
  border: 1px solid #dddddd;
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
}

.upload-picture img {
  width: 100%;
  height: 100%;
  border-radius: 3px;
  object-fit: contain;
}

.upload-picture .el-icon-error {
  position: absolute;
  top: -3px;
  right: -3px;
  color: #999999;
}

.el-icon-check {
  color: var(--prev-color-primary);
  font-size: 22px;
  font-weight: 600;
}

.el-icon-close {
  color: #f5222d;
  font-size: 22px;
  font-weight: 600;
}

.el-icon-warning-outline {
  font-size: 13px;
}
</style>
