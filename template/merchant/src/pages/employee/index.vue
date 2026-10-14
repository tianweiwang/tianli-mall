<template>
  <section class="page-panel">
    <h1 class="page-heading">员工管理</h1>
    <el-alert v-if="notice" class="feedback" :title="notice" type="success" role="status" aria-live="polite" aria-atomic="true" :closable="false" show-icon />
    <el-alert v-if="error" class="feedback" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-if="roleError" class="feedback" :title="roleError" type="warning" :closable="false" show-icon />
    <section v-if="delivery" class="setup-delivery" aria-labelledby="setup-delivery-title">
      <h2 id="setup-delivery-title">新员工的一次性设置链接</h2>
      <p>员工 {{ deliveryAccount }} 已建立。请将此链接交给该员工设置密码。</p>
      <p>服务器返回的到期时间：{{ time(delivery.expires_at) }}</p>
      <label class="delivery-label" for="employee-setup-link">设置链接</label>
      <textarea id="employee-setup-link" :value="delivery.url" readonly rows="3" class="delivery-link" />
      <p class="field-note">链接只保留在当前页面；关闭提示或离开页面后不再显示。</p>
      <div class="actions"><el-button size="small" @click="copyDelivery">复制链接</el-button><el-button size="small" @click="clearDelivery">关闭链接提示</el-button></div>
      <p v-if="copyNotice" role="status" aria-live="polite">{{ copyNotice }}</p>
    </section>
    <div class="toolbar">
      <div class="filter-field"><label for="employee-status-filter">员工状态</label><el-select id="employee-status-filter" v-model="statusFilter" clearable placeholder="全部状态" :disabled="loading || saving || changing !== null"><el-option label="启用" :value="1" /><el-option label="停用" :value="0" /></el-select></div>
      <div class="filter-field"><label for="employee-role-filter">员工角色</label><el-select id="employee-role-filter" v-model="roleFilter" clearable filterable placeholder="全部角色" :loading="loadingRoles" :disabled="loading || saving || changing !== null"><el-option v-for="role in roleOptions" :key="role.id" :label="role.name" :value="role.id" /></el-select></div>
      <el-button :disabled="loading || saving || changing !== null" @click="filter">查询</el-button>
      <el-button type="primary" :disabled="loading || saving || changing !== null" @click="openCreate">新建员工</el-button>
    </div>
    <p v-if="!canRoles" class="field-note">可选角色来自本人资料和已读取的员工。其他角色需有角色管理权限的同事维护。</p>
    <el-table v-loading="loading" :data="rows" row-key="id" empty-text="暂无员工">
      <el-table-column prop="account" label="账号" min-width="160" />
      <el-table-column label="姓名" min-width="120"><template slot-scope="scope">{{ scope.row.name || '—' }}</template></el-table-column>
      <el-table-column label="角色" min-width="220"><template slot-scope="scope">{{ roleLabel(scope.row) }}</template></el-table-column>
      <el-table-column label="状态" width="90"><template slot-scope="scope"><span>{{ scope.row.status === 1 ? '启用' : '停用' }}</span></template></el-table-column>
      <el-table-column label="更新时间" min-width="170"><template slot-scope="scope">{{ time(scope.row.update_time) }}</template></el-table-column>
      <el-table-column label="操作" min-width="170"><template slot-scope="scope"><div class="table-actions"><el-button size="small" :disabled="saving || changing !== null || loading" :aria-label="'编辑员工“' + (scope.row.name || scope.row.account) + '”'" @click="openEdit(scope.row)">编辑</el-button><el-button size="small" :loading="changing === scope.row.id" :disabled="saving || changing !== null || loading" :aria-label="(scope.row.status === 1 ? '停用' : '启用') + '员工“' + (scope.row.name || scope.row.account) + '”'" @click="changeStatus(scope.row)">{{ scope.row.status === 1 ? '停用' : '启用' }}</el-button></div></template></el-table-column>
    </el-table>
    <div class="pagination"><el-pagination :current-page="page" :page-size="20" :total="total" :pager-count="5" :disabled="loading || saving || changing !== null" layout="total, prev, pager, next" @current-change="changePage" /></div>
    <el-dialog :title="editingId ? '编辑员工' : '新建员工'" :visible.sync="dialog" width="min(640px, calc(100% - 32px))" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving">
      <el-alert v-if="formError" class="feedback" :title="formError" type="error" :closable="false" show-icon />
      <el-form label-position="top" :disabled="saving" @submit.native.prevent="save">
        <div class="form-grid">
          <el-form-item for="employee-account" label="员工账号" required><el-input id="employee-account" v-model="form.account" autocomplete="off" /><p class="field-note">最多 191 个字符；服务端统一去除首尾空白、转换英文字母大小写。</p></el-form-item>
          <el-form-item for="employee-role" label="角色" required><el-select id="employee-role" v-model="form.role_id" filterable :loading="loadingRoles" placeholder="选择本店角色"><el-option v-for="role in roleOptions" :key="role.id" :value="role.id" :label="role.name" /></el-select></el-form-item>
          <el-form-item for="employee-name" label="姓名"><el-input id="employee-name" v-model="form.name" /><p class="field-note">选填，最多 64 个字符。</p></el-form-item>
          <el-form-item for="employee-avatar" label="头像地址"><el-input id="employee-avatar" v-model="form.avatar" /><p class="field-note">选填，保留实际地址，最多 512 个字符。</p></el-form-item>
        </div>
        <p v-if="!editingId" class="field-note">新员工先创建账号，再使用服务器返回的一次性链接自行设置密码。</p>
        <div class="actions"><el-button native-type="submit" type="primary" :loading="saving" :disabled="saving || loadingRoles || !form.role_id">{{ editingId ? '保存员工' : '创建员工' }}</el-button><el-button :disabled="saving" @click="dialog = false">取消</el-button></div>
      </el-form>
    </el-dialog>
  </section>
</template>
<script>
import { peopleApi } from '../../api/people';
import { employeeWrite, employeeDto, roleDto, listDto, knownRoleOptions, passwordDelivery, formatTime } from '../../domain/people';
const blank = () => ({ account: '', role_id: null, name: '', avatar: '' });
export default {
  data: () => ({ rows: [], seen: [], roles: [], page: 1, total: 0, statusFilter: null, roleFilter: null, loading: false, loadingRoles: false, saving: false, changing: null, error: '', roleError: '', notice: '', dialog: false, editingId: null, form: blank(), formError: '', delivery: null, deliveryAccount: '', copyNotice: '' }),
  computed: {
    canRoles() { return this.$store.getters.can('merchant.role.manage'); },
    roleOptions() {
      const observed = knownRoleOptions(this.$store.state.profile, this.seen);
      const named = new Map(this.roles.map((role) => [role.id, { id: role.id, name: role.name }]));
      observed.forEach((role) => { if (!named.has(role.id)) named.set(role.id, role); });
      return Array.from(named.values());
    },
  },
  async created() { await Promise.all([this.load(), this.loadRoles()]); },
  beforeDestroy() { this.clearDelivery(); },
  methods: {
    time: formatTime,
    remember(rows) { const seen = new Map(this.seen.map((row) => [row.id, row])); rows.forEach((row) => seen.set(row.id, row)); this.seen = Array.from(seen.values()); },
    roleLabel(row) { const role = this.roleOptions.find((item) => item.id === row.role_id); return role ? role.name : '当前角色（未返回角色名）'; },
    async load() {
      if (this.loading) return; this.loading = true; this.error = '';
      const query = { page: this.page, limit: 20 };
      if (this.statusFilter === 0 || this.statusFilter === 1) query.status = this.statusFilter;
      if (Number.isInteger(this.roleFilter) && this.roleFilter > 0) query.role_id = this.roleFilter;
      try { const result = listDto(await peopleApi.employees(query), employeeDto); this.rows = result.list; this.total = result.count; this.remember(result.list); }
      catch (error) { this.error = error.message; }
      finally { this.loading = false; }
    },
    async loadRoles() {
      if (!this.canRoles) return; this.loadingRoles = true; this.roleError = '';
      try {
        const first = listDto(await peopleApi.roles({ page: 1, limit: 100 }), roleDto); const list = first.list.slice();
        const pages = Math.ceil(first.count / 100);
        for (let page = 2; page <= pages; page += 1) { const result = listDto(await peopleApi.roles({ page, limit: 100 }), roleDto); list.push(...result.list); }
        this.roles = list;
      } catch (error) { this.roleError = '角色目录未能读取：' + error.message; }
      finally { this.loadingRoles = false; }
    },
    filter() { this.page = 1; this.load(); },
    changePage(page) { this.page = page; this.load(); },
    openCreate() { this.clearDelivery(); this.editingId = null; this.form = blank(); this.formError = ''; this.dialog = true; },
    openEdit(row) { this.editingId = row.id; this.form = { account: row.account, role_id: row.role_id, name: row.name, avatar: row.avatar }; this.formError = ''; this.dialog = true; },
    async save() {
      if (this.saving) return; this.formError = ''; let body;
      try { body = employeeWrite(this.form); } catch (error) { this.formError = error.message; return; }
      this.saving = true; this.notice = '';
      try {
        if (this.editingId) {
          const employee = employeeDto(await peopleApi.updateEmployee(this.editingId, body)); this.remember([employee]); this.notice = '员工“' + (employee.name || employee.account) + '”已保存';
        } else {
          const result = await peopleApi.createEmployee(body); const employee = employeeDto(result && result.employee);
          this.delivery = passwordDelivery(result.password_setup, window.location.origin); this.deliveryAccount = employee.account;
          this.remember([employee]); this.notice = '员工“' + (employee.name || employee.account) + '”已创建';
        }
        this.dialog = false; await this.load();
      } catch (error) { this.formError = error.message; }
      finally { this.saving = false; }
    },
    async changeStatus(row) {
      if (this.changing !== null || this.saving) return;
      const status = row.status === 1 ? 0 : 1; this.changing = row.id;
      try { await this.$confirm('确认' + (status === 1 ? '启用' : '停用') + '员工“' + (row.name || row.account) + '”？', '员工状态', { confirmButtonText: '确认', cancelButtonText: '取消', type: 'warning' }); }
      catch (action) { this.changing = null; return; }
      this.error = ''; this.notice = '';
      try { const employee = employeeDto(await peopleApi.setEmployeeStatus(row.id, status)); this.remember([employee]); this.notice = '员工状态已按服务器结果更新'; await this.load(); }
      catch (error) { this.error = error.message; }
      finally { this.changing = null; }
    },
    clearDelivery() { this.delivery = null; this.deliveryAccount = ''; this.copyNotice = ''; },
    async copyDelivery() {
      this.copyNotice = '';
      if (!this.delivery) return;
      try {
        if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('请选中上方完整链接手动复制');
        await navigator.clipboard.writeText(this.delivery.url); this.copyNotice = '链接已复制';
      } catch (error) { this.copyNotice = error.message; }
    },
  },
};
</script>
<style scoped>
.filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; max-width: 100%; }
.setup-delivery { margin: 0 0 24px; padding: 18px; border: 1px solid #dcdfe6; background: #f5f7f9; min-width: 0; }
.setup-delivery h2 { margin: 0 0 12px; font-size: 17px; }
.setup-delivery p { line-height: 1.7; overflow-wrap: anywhere; }
.delivery-label { display: block; margin: 12px 0 6px; }
.delivery-link { display: block; width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; padding: 10px; border: 1px solid #dcdfe6; background: #fff; color: #303133; font: inherit; line-height: 1.5; resize: vertical; overflow-wrap: anywhere; }
</style>
