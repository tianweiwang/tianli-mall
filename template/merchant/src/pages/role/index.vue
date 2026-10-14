<template>
  <section class="page-panel">
    <h1 class="page-heading">角色管理</h1>
    <el-alert v-if="notice" class="feedback" :title="notice" type="success" role="status" aria-live="polite" aria-atomic="true" :closable="false" show-icon />
    <el-alert v-if="error" class="feedback" :title="error" type="error" :closable="false" show-icon />
    <div class="toolbar"><el-button type="primary" :disabled="loading || saving" @click="openCreate">新建角色</el-button><el-button :disabled="loading || saving" @click="load">刷新</el-button></div>
    <el-table v-loading="loading" :data="rows" row-key="id" empty-text="暂无角色">
      <el-table-column prop="name" label="角色名称" min-width="180" />
      <el-table-column label="已授权能力" min-width="320"><template slot-scope="scope"><ul v-if="scope.row.permissions.length" class="permission-summary"><li v-for="value in scope.row.permissions" :key="value">{{ permissionLabel(value) }}</li></ul><span v-else>未授予权限</span></template></el-table-column>
      <el-table-column label="更新时间" min-width="170"><template slot-scope="scope">{{ time(scope.row.update_time) }}</template></el-table-column>
      <el-table-column label="操作" width="100"><template slot-scope="scope"><el-button size="small" :disabled="saving || loading" :aria-label="'编辑角色“' + scope.row.name + '”'" @click="openEdit(scope.row)">编辑</el-button></template></el-table-column>
    </el-table>
    <div class="pagination"><el-pagination :current-page="page" :page-size="20" :total="total" :pager-count="5" :disabled="loading || saving" layout="total, prev, pager, next" @current-change="changePage" /></div>
    <el-dialog :title="editingId ? '编辑角色' : '新建角色'" :visible.sync="dialog" width="min(700px, calc(100% - 32px))" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving">
      <el-alert v-if="formError" class="feedback" :title="formError" type="error" :closable="false" show-icon />
      <el-form label-position="top" :disabled="saving" @submit.native.prevent="save">
        <el-form-item for="role-name" label="角色名称" required><el-input id="role-name" v-model="form.name" /><p class="field-note">最多 64 个字符，名称在本店内唯一。</p></el-form-item>
        <el-form-item><template slot="label"><span id="role-permissions-title">动作权限</span></template>
          <el-checkbox-group v-model="form.permissions" class="permission-options" aria-labelledby="role-permissions-title">
            <div v-for="item in catalog" :key="item.value" class="permission-option"><el-checkbox :name="'permission-' + item.value" :label="item.value">{{ item.label }}</el-checkbox><p class="field-note">{{ item.description }}</p></div>
          </el-checkbox-group>
          <p class="field-note">只保存已勾选的权限；允许空权限。本店信息权限用于读取商家后台首页和本人资料。</p>
        </el-form-item>
        <div class="actions"><el-button native-type="submit" type="primary" :loading="saving" :disabled="saving">保存角色</el-button><el-button :disabled="saving" @click="dialog = false">取消</el-button></div>
      </el-form>
    </el-dialog>
  </section>
</template>
<script>
import { peopleApi } from '../../api/people';
import { permissions, roleWrite, roleDto, listDto, formatTime } from '../../domain/people';
const blank = () => ({ name: '', permissions: [] });
export default {
  data: () => ({ rows: [], page: 1, total: 0, loading: false, saving: false, error: '', notice: '', dialog: false, editingId: null, form: blank(), formError: '', catalog: permissions }),
  created() { this.load(); },
  methods: {
    time: formatTime,
    permissionLabel(value) { const item = this.catalog.find((entry) => entry.value === value); return item ? item.label : value; },
    async load() {
      if (this.loading) return; this.loading = true; this.error = '';
      try { const result = listDto(await peopleApi.roles({ page: this.page, limit: 20 }), roleDto); this.rows = result.list; this.total = result.count; }
      catch (error) { this.error = error.message; }
      finally { this.loading = false; }
    },
    changePage(page) { this.page = page; this.load(); },
    openCreate() { this.editingId = null; this.form = blank(); this.formError = ''; this.dialog = true; },
    openEdit(row) { this.editingId = row.id; this.form = { name: row.name, permissions: row.permissions.slice() }; this.formError = ''; this.dialog = true; },
    async save() {
      if (this.saving) return; this.formError = ''; let body;
      try { body = roleWrite(this.form); } catch (error) { this.formError = error.message; return; }
      this.saving = true; this.notice = '';
      try {
        const role = roleDto(await (this.editingId ? peopleApi.updateRole(this.editingId, body) : peopleApi.createRole(body)));
        this.notice = '角色“' + role.name + '”已保存'; this.dialog = false; await this.load();
      } catch (error) { this.formError = error.message; }
      finally { this.saving = false; }
    },
  },
};
</script>
<style scoped>
.permission-summary { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 0; padding: 0; list-style: none; }
.permission-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.permission-option { min-width: 0; padding: 12px; border: 1px solid #dcdfe6; }
.permission-option .el-checkbox { display: flex; align-items: center; margin: 0; white-space: normal; }
.permission-option .field-note { margin-bottom: 0; }
@media (max-width: 760px) { .permission-options { grid-template-columns: minmax(0, 1fr); } }
</style>
