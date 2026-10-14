<template>
  <section v-loading="loading">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" /><el-alert v-if="notice" :title="notice" role="status" aria-live="polite" aria-atomic="true" type="success" :closable="false" show-icon class="feedback" />
    <div class="toolbar"><el-select v-model="categoryId" :disabled="busy || loading" aria-label="素材分类" @change="firstPage" :id="fieldId('library-category')" v-control-label="'素材分类'"><el-option :value="0" label="全部素材" /><el-option v-for="category in categories" :key="category.id" :value="category.id" :label="category.label" /></el-select><el-button :disabled="busy || loading" @click="reload">刷新</el-button><el-button :disabled="busy" @click="editCategory(null)">新建分类</el-button><el-button v-if="categoryId" :disabled="busy" @click="editCategory(categories.find((item) => item.id === categoryId))">编辑分类</el-button></div>
    <div class="toolbar"><label :for="fieldId('file-upload')">上传图片</label><input :id="fieldId('file-upload')" aria-label="上传本店图片" type="file" accept="image/jpeg,image/png,image/gif,image/webp" :disabled="busy || loading" @change="upload"><el-select v-model="uploadCategoryId" :disabled="busy || loading" aria-label="上传到分类" :id="fieldId('upload-category')" v-control-label="'上传到分类'"><el-option :value="0" label="未分类" /><el-option v-for="category in categories" :key="category.id" :value="category.id" :label="category.label" /></el-select></div>
    <p class="field-note">支持 JPEG、PNG、GIF、WebP；上传到所选分类。</p>
    <div v-if="rows.length" class="gallery"><article v-for="row in rows" :key="row.att_id" class="gallery-item"><img :src="row.thumbnail_url || row.url" :alt="row.real_name"><p class="gallery-name">{{ row.real_name }}</p><div class="actions"><el-button v-if="selectable" type="primary" size="small" :disabled="busy" @click="$emit('select', row)">选择图片</el-button><el-button size="small" :disabled="busy" @click="remove(row)">删除</el-button></div></article></div><el-empty v-else-if="!loading" description="暂无素材" />
    <div class="pagination"><el-pagination :current-page="page" :page-size="limit" :pager-count="5" :total="count" layout="prev, pager, next" @current-change="changePage" /></div>
    <el-dialog title="素材分类" :visible.sync="categoryDialog" append-to-body width="min(92vw, 480px)" :close-on-click-modal="false"><el-alert v-if="categoryError" :title="categoryError" type="error" :closable="false" show-icon /><el-form @submit.native.prevent="saveCategory"><el-form-item label="分类名称" :for="fieldId('category-name')"><el-input v-model="categoryForm.name" maxlength="50" :id="fieldId('category-name')" :label="'分类名称'" /></el-form-item><el-form-item label="上级分类" :for="fieldId('category-parent')"><el-select v-model="categoryForm.pid" :id="fieldId('category-parent')" v-control-label="'上级分类'"><el-option :value="0" label="无上级" /><el-option v-for="category in parentCategories" :key="category.id" :value="category.id" :label="category.label" /></el-select></el-form-item><el-button type="primary" native-type="submit" :loading="busy" :disabled="busy">保存分类</el-button></el-form></el-dialog>
  </section>
</template>
<script>
import { fieldNames } from '../accessibility';
import { api } from '../api';
function flatten(list, depth = 0) { return list.flatMap((item) => [{ ...item, label: '　'.repeat(depth) + item.name }, ...flatten(item.children || [], depth + 1)]); }
export default {
  mixins: [fieldNames],
  props: { selectable: Boolean },
  data: () => ({ rows: [], categories: [], categoryId: 0, uploadCategoryId: 0, page: 1, limit: 20, count: 0, loading: false, busy: false, error: '', notice: '', categoryDialog: false, categoryError: '', categoryRecord: null, categoryForm: { name: '', pid: 0 } }),
  computed: { parentCategories() {
    if (!this.categoryRecord) return this.categories;
    const omitted = new Set([this.categoryRecord.id]); let grew;
    do { grew = false; this.categories.forEach((row) => { if (omitted.has(row.pid) && !omitted.has(row.id)) { omitted.add(row.id); grew = true; } }); } while (grew);
    return this.categories.filter((row) => !omitted.has(row.id));
  } },
  created() { this.reload(); },
  methods: {
    async reload(propagate = false) { if (this.loading) { if (propagate === true) throw new Error('素材列表仍在读取，请稍后刷新核对'); return; } this.loading = true; this.error = ''; try { const [categories, data] = await Promise.all([api.attachmentCategories(), api.attachments({ category_id: this.categoryId, page: this.page, limit: this.limit })]); if (!Array.isArray(data.list) || !Array.isArray(categories.list) || !Number.isInteger(data.count)) throw new Error('素材列表返回不完整'); this.categories = flatten(categories.list); this.rows = data.list; this.count = data.count; } catch (error) { this.error = error.message; if (propagate === true) throw error; } finally { this.loading = false; } },
    firstPage() { this.page = 1; this.reload(); }, changePage(page) { if (!this.loading) { this.page = page; this.reload(); } },
    async upload(event) {
      const file = event.target.files[0]; event.target.value = ''; if (!file || this.busy || this.loading) return; this.notice = ''; this.error = '';
      if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) { this.error = '请选择 JPEG、PNG、GIF 或 WebP 图片'; return; }
      this.busy = true; let uploaded = false;
      try { const row = await api.uploadAttachment(file, this.uploadCategoryId); if (!row || !row.att_id || !row.att_dir || !row.url) throw new Error('上传结果不完整，请刷新素材库核对'); uploaded = true; await this.reload(true); this.notice = '图片已上传'; }
      catch (error) { this.error = (uploaded ? '图片已上传，列表刷新未完成：' : '') + this.operationError(error); }
      finally { this.busy = false; }
    },
    operationError(error) { return error.message + (error.data && error.data.complete === false ? '，操作未完整完成，请刷新后核对当前素材状态' : ''); },
    async remove(row) {
      try { await this.$confirm('删除“' + row.real_name + '”？已被商品使用的素材不能删除。', '删除素材', { type: 'warning' }); } catch (action) { return; }
      this.busy = true; this.error = ''; this.notice = ''; let deleted = false;
      try { await api.deleteAttachments([row.att_id]); deleted = true; await this.reload(true); this.notice = '素材已删除'; } catch (error) { this.error = (deleted ? '素材已删除，列表刷新未完成：' : '') + this.operationError(error); } finally { this.busy = false; }
    },
    editCategory(row) { this.categoryRecord = row; this.categoryForm = { name: row ? row.name : '', pid: row ? row.pid : 0 }; this.categoryError = ''; this.categoryDialog = true; },
    async saveCategory() {
      if (this.busy) return; this.categoryError = ''; if (!this.categoryForm.name.trim()) { this.categoryError = '请填写分类名称'; return; }
      this.busy = true; let saved = false;
      try { await api.saveAttachmentCategory(this.categoryRecord ? this.categoryRecord.id : null, { name: this.categoryForm.name.trim(), pid: this.categoryForm.pid }); saved = true; await this.reload(true); this.categoryDialog = false; this.notice = '分类已保存'; }
      catch (error) { this.categoryError = (saved ? '分类已保存，列表刷新未完成：' : '') + error.message; } finally { this.busy = false; }
    },
  },
};
</script>
