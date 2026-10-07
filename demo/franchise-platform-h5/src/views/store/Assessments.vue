<template>
  <div class="admin-page assessment-workbench">
    <AdminMetricStrip :items="metrics" />

    <section class="admin-panel assessment-workbench__toolbar">
      <div class="assessment-tabs" role="tablist" aria-label="检测中心视图">
        <button :class="{ 'is-active': activeTab === 'tasks' }" type="button" @click="activeTab = 'tasks'">检测任务</button>
        <button :class="{ 'is-active': activeTab === 'reassessments' }" type="button" @click="activeTab = 'reassessments'">复评召回</button>
      </div>
      <el-button type="primary" size="small" @click="walkInVisible = true">
        <Plus :size="15" aria-hidden="true" />现场建档
      </el-button>
    </section>

    <div v-if="lastCreatedTaskId" class="assessment-created">
      已创建检测任务 <strong data-testid="created-assessment-task-id">{{ lastCreatedTaskId }}</strong>
    </div>

    <template v-if="activeTab === 'tasks'">
      <section v-if="pendingBookings.length" class="admin-panel assessment-pending">
        <header class="admin-panel__header">
          <div><h2>待建任务预约</h2><p>仅显示本店已关联顾客的体态评估预约</p></div>
        </header>
        <div class="assessment-pending__list">
          <article v-for="booking in pendingBookings" :key="booking.id">
            <div>
              <strong>{{ booking.contactName }}</strong>
              <span>{{ booking.date }} {{ booking.start }} · {{ booking.phone }}</span>
            </div>
            <el-button
              size="small"
              :data-testid="`create-assessment-${booking.id}`"
              @click="createFromBooking(booking)"
            >创建任务</el-button>
          </article>
        </div>
      </section>

      <section class="admin-panel assessment-task-panel">
        <header class="admin-panel__header">
          <div><h2>检测任务</h2><p>共 {{ filteredTasks.length }} 条本店任务</p></div>
          <div class="assessment-filters">
            <el-input v-model="keyword" size="small" clearable placeholder="顾客姓名或任务号" />
            <el-select v-model="statusFilter" size="small" aria-label="任务状态">
              <el-option label="全部状态" value="all" />
              <el-option label="待检测" value="ready" />
              <el-option label="检测中" value="measuring" />
              <el-option label="待门店确认" value="awaiting-review" />
              <el-option label="已完成" value="completed" />
            </el-select>
          </div>
        </header>
        <div class="assessment-task-list">
          <article v-for="task in filteredTasks" :key="task.id" class="assessment-task-row">
            <div class="assessment-task-row__identity">
              <strong>{{ task.customerName }}</strong>
              <span>{{ task.customerPhone }} · {{ task.customerType === 'member' ? '会员' : '临时顾客' }}</span>
            </div>
            <div><span>任务编号</span><strong>{{ task.id }}</strong></div>
            <div><span>创建时间</span><strong>{{ formatDateTime(task.createdAt) }}</strong></div>
            <AdminStatusTag :status="task.status" :text="taskStatusLabel(task.status)" />
            <router-link
              :to="`/store/assessments/${task.id}`"
              :data-testid="`assessment-task-${task.id}`"
              class="admin-link-button"
            >处理<ArrowRight :size="14" aria-hidden="true" /></router-link>
          </article>
          <div v-if="!filteredTasks.length" class="assessment-empty">当前筛选条件下没有检测任务</div>
        </div>
      </section>
    </template>

    <section v-else class="admin-panel reassessment-panel">
      <header class="admin-panel__header">
        <div><h2>复评召回</h2><p>按计划到期日跟进本店顾客</p></div>
      </header>
      <div class="reassessment-list">
        <article v-for="plan in reassessmentQueue" :key="plan.id">
          <div><strong>{{ plan.customerName }}</strong><span>{{ plan.customerPhone }}</span></div>
          <div><span>计划到期</span><strong>{{ plan.dueDate }}</strong></div>
          <AdminStatusTag :status="plan.status" :text="plan.status === 'due' ? '今日到期' : '已安排'" />
          <router-link to="/store/bookings" class="admin-link-button">查看预约<ArrowRight :size="14" /></router-link>
        </article>
      </div>
    </section>

    <el-dialog title="现场建档" :visible.sync="walkInVisible" width="460px">
      <div class="walk-in-form">
        <label><span>顾客姓名</span><el-input v-model="walkInForm.name" placeholder="请输入姓名" /></label>
        <label><span>脱敏手机号</span><el-input v-model="walkInForm.phone" placeholder="例如 138****2046" /></label>
        <div class="walk-in-form__consent">
          <strong>检测授权</strong>
          <el-checkbox-group v-model="walkInForm.scopes">
            <el-checkbox label="assessment">同意体态记录</el-checkbox>
            <el-checkbox label="image">同意演示影像记录</el-checkbox>
          </el-checkbox-group>
        </div>
      </div>
      <template slot="footer">
        <el-button @click="walkInVisible = false">取消</el-button>
        <el-button type="primary" @click="createWalkIn">创建检测任务</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { Activity, ArrowRight, CalendarSync, ClipboardCheck, Plus, RadioTower } from 'lucide-vue';
import AdminMetricStrip from '../../components/admin/AdminMetricStrip.vue';
import AdminStatusTag from '../../components/admin/AdminStatusTag.vue';
import { demoStore } from '../../mock/store';
import { createAssessmentTask, createWalkInAssessmentTask } from '../../mock/assessmentTransitions';
import { assessmentTasksForStore, reassessmentQueueForStore } from '../../mock/assessmentSelectors';

export default {
  name: 'StoreAssessments',
  components: { AdminMetricStrip, AdminStatusTag, ArrowRight, Plus },
  data() {
    return {
      activeTab: 'tasks',
      keyword: '',
      statusFilter: 'all',
      walkInVisible: false,
      lastCreatedTaskId: '',
      walkInForm: { name: '', phone: '', scopes: ['assessment', 'image'] },
    };
  },
  computed: {
    state() { return demoStore.state.data; },
    storeId() { return this.state.activeStoreId; },
    storeTasks() { return assessmentTasksForStore(this.state, this.storeId); },
    reassessmentQueue() { return reassessmentQueueForStore(this.state, this.storeId); },
    filteredTasks() {
      const query = this.keyword.trim().toLowerCase();
      return this.storeTasks.filter((task) => {
        const matchesKeyword = !query
          || task.customerName.toLowerCase().includes(query)
          || task.id.toLowerCase().includes(query);
        return matchesKeyword && (this.statusFilter === 'all' || task.status === this.statusFilter);
      });
    },
    pendingBookings() {
      const taskBookingIds = new Set(this.storeTasks.map((item) => item.bookingId).filter(Boolean));
      return this.state.bookings.filter((booking) => booking.storeId === this.storeId
        && booking.serviceId === 'SV1001'
        && booking.customerId
        && !taskBookingIds.has(booking.id)
        && ['pending', 'verified'].includes(booking.status));
    },
    metrics() {
      const today = this.state.demoNow.slice(0, 10);
      return [
        { label: '今日任务', value: `${this.storeTasks.filter((item) => item.createdAt.startsWith(today)).length} 项`, help: '本店今日创建', icon: Activity, tone: 'primary' },
        { label: '检测中', value: `${this.storeTasks.filter((item) => ['measuring', 'retrying'].includes(item.status)).length} 项`, help: '设备流程进行中', icon: RadioTower, tone: 'warning' },
        { label: '待确认', value: `${this.storeTasks.filter((item) => item.status === 'awaiting-review').length} 项`, help: '需门店确认结果', icon: ClipboardCheck, tone: 'success' },
        { label: '待复评', value: `${this.reassessmentQueue.length} 人`, help: '计划召回顾客', icon: CalendarSync, tone: 'warning' },
      ];
    },
  },
  methods: {
    createFromBooking(booking) {
      let created;
      try {
        demoStore.commit((draft) => {
          created = createAssessmentTask(draft, {
            bookingId: booking.id,
            customerId: booking.customerId,
            storeId: this.storeId,
          });
        });
        this.lastCreatedTaskId = created.id;
      } catch (error) {
        this.$message?.error(error.message);
      }
    },
    createWalkIn() {
      let created;
      try {
        demoStore.commit((draft) => {
          created = createWalkInAssessmentTask(draft, {
            storeId: this.storeId,
            ...this.walkInForm,
          });
        });
        this.lastCreatedTaskId = created.id;
        this.walkInVisible = false;
        this.walkInForm = { name: '', phone: '', scopes: ['assessment', 'image'] };
        this.$message?.success('现场检测任务已创建');
      } catch (error) {
        this.$message?.error(error.message);
      }
    },
    formatDateTime(value) { return value ? value.replace('T', ' ').slice(0, 16) : '-'; },
    taskStatusLabel(status) {
      return {
        ready: '待检测', measuring: '检测中', retrying: '等待重试',
        'review-required': '数据待补充', 'awaiting-review': '待门店确认', completed: '已完成',
      }[status] || status;
    },
  },
};
</script>

<style scoped>
.assessment-workbench__toolbar {
  display: flex;
  min-height: 62px;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 16px;
}

.assessment-tabs { display: inline-grid; grid-template-columns: repeat(2, 112px); padding: 3px; background: #f2f4f7; border-radius: var(--radius-md); }
.assessment-tabs button { min-height: 34px; color: var(--text-muted); background: transparent; border: 0; border-radius: var(--radius-sm); cursor: pointer; }
.assessment-tabs button.is-active { color: var(--admin-primary); background: var(--surface); box-shadow: 0 1px 3px rgb(34 48 64 / 12%); font-weight: 600; }
.assessment-workbench__toolbar .el-button { display: inline-flex; align-items: center; gap: 5px; }

.assessment-created { padding: 10px 14px; color: #176b48; background: #edf9f2; border: 1px solid #ccebd9; border-radius: var(--radius-md); font-size: 13px; }
.assessment-pending__list, .assessment-task-list, .reassessment-list { padding: 0 18px 8px; }
.assessment-pending__list article, .reassessment-list article { display: grid; min-height: 68px; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 16px; border-top: 1px solid var(--border); }
.assessment-pending__list strong, .assessment-pending__list span, .reassessment-list strong, .reassessment-list span { display: block; }
.assessment-pending__list span, .reassessment-list span { margin-top: 3px; color: var(--text-muted); font-size: 11px; }

.assessment-filters { display: grid; width: min(440px, 100%); grid-template-columns: minmax(180px, 1fr) 150px; gap: 8px; }
.assessment-task-row { display: grid; min-height: 76px; grid-template-columns: minmax(150px, 1.1fr) minmax(170px, 1fr) minmax(130px, .8fr) 100px 72px; align-items: center; gap: 16px; border-top: 1px solid var(--border); }
.assessment-task-row > div strong, .assessment-task-row > div span { display: block; }
.assessment-task-row > div span { color: var(--text-muted); font-size: 11px; }
.assessment-task-row > div strong { margin-top: 3px; overflow: hidden; font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }
.assessment-task-row__identity strong { font-size: 14px !important; }
.assessment-empty { padding: 32px 0; color: var(--text-muted); text-align: center; }
.reassessment-list article { grid-template-columns: minmax(160px, 1fr) minmax(130px, .7fr) 100px 96px; }

.walk-in-form { display: grid; gap: 18px; }
.walk-in-form label > span, .walk-in-form__consent > strong { display: block; margin-bottom: 7px; font-size: 13px; }
.walk-in-form__consent { padding: 12px; background: #f7f8fa; border-radius: var(--radius-md); }

@media (max-width: 900px) {
  .assessment-task-row { grid-template-columns: minmax(140px, 1fr) minmax(160px, 1fr) 96px 70px; }
  .assessment-task-row > div:nth-child(3) { display: none; }
}
</style>
