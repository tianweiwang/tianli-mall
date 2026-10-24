<template>
  <section v-loading="loading" class="merchant-media-library">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" />
    <el-alert v-if="notice" :title="notice" role="status" aria-live="polite" aria-atomic="true" type="success" :closable="false" show-icon class="feedback" />
    <el-alert v-if="receipt" title="上次素材操作尚未完整确认" :description="receiptDescription" type="warning" :closable="false" show-icon class="feedback" />
    <div class="toolbar"><el-select v-model="categoryId" :disabled="busy || loading" aria-label="素材分类" @change="firstPage" :id="fieldId('library-category')" v-control-label="'素材分类'"><el-option :value="0" label="全部素材" /><el-option v-for="category in categories" :key="category.id" :value="category.id" :label="category.label" /></el-select><el-button :disabled="busy || loading" @click="reload">刷新</el-button><el-button :disabled="busy || loading" @click="editCategory(null)">新建分类</el-button><el-button v-if="categoryId" :disabled="busy || loading" @click="editCategory(selectedCategory)">编辑分类</el-button><el-button v-if="selectedCategory && selectedCategory.type === 1" :disabled="busy || loading" @click="deleteCategory">删除分类</el-button></div>
    <div class="toolbar"><label :for="fieldId('file-upload')">上传{{ kindLabel }}</label><input :id="fieldId('file-upload')" :aria-label="'上传本店' + kindLabel" type="file" :accept="accept" :disabled="busy || loading || !canUpload" @change="upload"><el-select v-model="uploadCategoryId" :disabled="busy || loading || !canUpload" aria-label="上传到分类" :id="fieldId('upload-category')" v-control-label="'上传到分类'"><el-option :value="0" label="未分类" /><el-option v-for="category in uploadCategories" :key="category.id" :value="category.id" :label="category.label" /></el-select></div>
    <p class="field-note">{{ formatNote }}</p>
    <div v-if="rows.length" class="gallery"><article v-for="row in rows" :key="row.att_id" class="gallery-item">
      <img v-if="rowKind(row) === 'image'" :src="row.thumbnail_url || row.url" :alt="row.real_name">
      <video v-else-if="rowKind(row) === 'video'" :src="row.url" controls preload="none" :aria-label="row.real_name" />
      <audio v-else-if="rowKind(row) === 'audio'" :src="row.url" controls preload="none" :aria-label="row.real_name" />
      <p v-else class="field-note">当前格式暂无预览</p><p class="gallery-name">{{ row.real_name }}</p>
      <p v-if="rowKind(row) === 'audio'" class="field-note">浏览器预览能力可能不同；服务端将在保存时核对实际素材。</p>
      <p v-if="kind === 'image' && rowKind(row) === 'video'" class="field-note">旧本地视频保留原类型，请通过视频选择入口使用。</p>
      <div class="actions"><el-button v-if="selectable && rowKind(row) === kind" type="primary" size="small" :disabled="busy || loading" @click="select(row)">选择{{ kindLabel }}</el-button><el-button size="small" :disabled="busy || loading" @click="remove(row)">删除</el-button></div>
    </article></div><el-empty v-else-if="!loading" description="暂无素材" />
    <div class="pagination"><el-pagination :disabled="busy || loading" :current-page="page" :page-size="limit" :pager-count="5" :total="count" layout="prev, pager, next" @current-change="changePage" /></div>
    <el-dialog title="素材分类" :visible.sync="categoryDialog" append-to-body width="min(92vw, 480px)" :close-on-click-modal="false" :before-close="closeCategory" custom-class="merchant-media-category"><el-alert v-if="categoryError" :title="categoryError" type="error" :closable="false" show-icon /><el-form @submit.native.prevent="saveCategory"><el-form-item label="分类名称" :for="fieldId('category-name')"><el-input v-model="categoryForm.name" maxlength="50" :disabled="busy" :id="fieldId('category-name')" :label="'分类名称'" /></el-form-item><el-form-item label="上级分类" :for="fieldId('category-parent')"><el-select v-model="categoryForm.pid" :disabled="busy" :id="fieldId('category-parent')" v-control-label="'上级分类'"><el-option :value="0" label="无上级" /><el-option v-for="category in parentCategories" :key="category.id" :value="category.id" :label="category.label" /></el-select></el-form-item><el-button type="primary" native-type="submit" :loading="busy" :disabled="busy">保存分类</el-button></el-form></el-dialog>
  </section>
</template>
<script>
import { fieldNames } from '../accessibility';
import { api, captureMediaContext, currentMediaContext, mediaKind, mediaRow, mediaReceipt, mediaReceiptText } from '../api';
function flatten(list, type, depth = 0) {
  return list.flatMap((item) => {
    if (!Number.isSafeInteger(item.id) || item.id < 1 || !Number.isSafeInteger(item.pid) || item.pid < 0 || item.type !== type || typeof item.name !== 'string') throw new Error('分类资料返回不完整');
    return [{ ...item, label: '　'.repeat(depth) + item.name }, ...flatten(item.children || [], type, depth + 1)];
  });
}
export default {
  mixins: [fieldNames],
  props: { selectable: Boolean, kind: { type: String, default: 'image' }, productId: { default: null }, productContext: { default: null }, invocation: { type: Number, default: 0 } },
  data: () => ({ rows: [], categories: [], categoryId: 0, uploadCategoryId: 0, page: 1, limit: 20, count: 0, loading: false, busy: false, error: '', notice: '', receipt: null, categoryDialog: false, categoryError: '', categoryRecord: null, categoryForm: { name: '', pid: 0, type: 0 }, context: null, generation: 0, readJob: 0, alive: true, unsubscribe: null }),
  computed: {
    kindLabel() { return { image: '图片', video: '视频', audio: '音频' }[this.kind] || '素材'; },
    categoryType() { return this.kind === 'video' ? 1 : 0; },
    selectedCategory() { return this.categories.find((row) => row.id === this.categoryId) || null; },
    uploadCategories() { return this.categories.filter((row) => row.type === this.categoryType); },
    canUpload() { return this.kind === 'image' || (this.productId !== null && this.$store.getters.can('product.write')); },
    accept() { return { image: 'image/jpeg,image/png,image/gif,image/webp', video: '.mp4,video/mp4,application/mp4', audio: '.mp3,.wav,.wma,.amr,audio/mpeg,audio/wav,audio/x-ms-wma,audio/amr' }[this.kind]; },
    formatNote() { return { image: '支持 JPEG、PNG、GIF、WebP；上传到所选分类。', video: '上传 MP4 到视频分类；旧本地视频使用其原分类，素材原类型保持。', audio: '支持 MP3、WAV、WMA、AMR；大小和格式以服务端当前配置及实际内容核验为准。' }[this.kind]; },
    receiptDescription() { return mediaReceiptText(this.receipt); },
    parentCategories() {
      const rows = this.categories.filter((row) => row.type === this.categoryForm.type);
      if (!this.categoryRecord) return rows;
      const omitted = new Set([this.categoryRecord.id]); let grew;
      do { grew = false; rows.forEach((row) => { if (omitted.has(row.pid) && !omitted.has(row.id)) { omitted.add(row.id); grew = true; } }); } while (grew);
      return rows.filter((row) => !omitted.has(row.id));
    },
  },
  watch: { kind() { this.start(); }, productId() { this.start(); }, productContext() { this.start(); }, invocation() { this.start(); }, busy(value) { this.$emit('busy-change', value); } },
  created() { this.unsubscribe = this.$store.subscribe(() => { if (this.context && (!currentMediaContext(this.context) || !this.$store.getters.can('attachment.manage') || (this.productId !== null && !this.$store.getters.can('product.write')))) { this.clear(); this.error = '登录身份或当前权限已变化，请重新打开素材选择'; } }); this.start(); },
  beforeDestroy() { this.alive = false; this.clear(); if (this.unsubscribe) this.unsubscribe(); },
  methods: {
    rowKind: mediaKind,
    clear() { this.generation += 1; this.readJob += 1; this.context = null; this.rows = []; this.categories = []; this.categoryId = 0; this.uploadCategoryId = 0; this.page = 1; this.count = 0; this.loading = false; this.busy = false; this.categoryDialog = false; this.receipt = null; this.notice = ''; this.error = ''; this.categoryError = ''; },
    start() {
      this.clear();
      try {
        if (!['image', 'video', 'audio'].includes(this.kind) || !Number.isSafeInteger(this.invocation) || this.invocation < 0) throw new Error('素材选择上下文不正确');
        if (this.productContext) { if (!currentMediaContext(this.productContext) || this.productContext.productId !== this.productId) throw new Error('商品上下文已变化，请重新打开'); this.context = this.productContext; }
        else { if (this.productId !== null || this.kind !== 'image') throw new Error('请从真实商品编辑页打开媒体选择'); this.context = captureMediaContext(this.$store, null); }
        this.reload();
      } catch (error) { this.error = error.message; }
    },
    ticket() { return { context: this.context, generation: this.generation, kind: this.kind, productId: this.productId, invocation: this.invocation }; },
    current(ticket) { return this.alive && ticket.context === this.context && ticket.generation === this.generation && ticket.kind === this.kind && ticket.productId === this.productId && ticket.invocation === this.invocation && currentMediaContext(ticket.context) && this.$store.getters.can('attachment.manage'); },
    async reload(propagate = false) {
      if (this.loading || !this.context) { if (propagate === true) throw new Error('素材列表尚未完成读取'); return false; }
      const ticket = this.ticket(); const job = ++this.readJob; this.loading = true; this.error = '';
      try {
        const query = { category_id: this.categoryId, page: this.page, limit: this.limit };
        const calls = [api.attachmentCategories(this.categoryType), this.kind === 'image' ? api.attachments(query) : api.attachmentMedia(this.kind, query)];
        if (this.kind === 'video') calls.push(api.attachmentCategories(0));
        const [categories, data, legacy] = await Promise.all(calls); if (!this.current(ticket) || job !== this.readJob) return false;
        if (!categories || !Array.isArray(categories.list) || !data || !Array.isArray(data.list) || !Number.isInteger(data.count) || data.count < 0 || (this.kind === 'video' && (!legacy || !Array.isArray(legacy.list)))) throw new Error('素材列表返回不完整');
        const primary = flatten(categories.list, this.categoryType); const old = legacy ? flatten(legacy.list, 0).map((row) => ({ ...row, label: '旧本地视频 / ' + row.label })) : [];
        this.categories = primary.concat(old); this.rows = data.list.map((row) => mediaRow(row, this.kind === 'image' ? null : this.kind)); this.count = data.count; return true;
      } catch (error) { if (this.current(ticket) && job === this.readJob) this.error = error.message; if (propagate === true) throw error; return false; }
      finally { if (this.current(ticket) && job === this.readJob) this.loading = false; }
    },
    firstPage() { this.page = 1; this.reload(); }, changePage(page) { if (!this.loading && !this.busy) { this.page = page; this.reload(); } },
    operationError(error) { this.receipt = mediaReceipt(error.data); if (error.status === 0) return '本次素材写入结果尚未确认，请刷新核对，勿直接重复提交'; return error.message + (this.receipt ? '，请保留本次收据并刷新核对素材状态' : ''); },
    async upload(event) {
      const file = event.target.files[0]; event.target.value = ''; if (!file || this.busy || this.loading || !this.canUpload) return;
      const ticket = this.ticket(); if (!this.current(ticket)) return;
      if (this.kind === 'image' && !['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) { this.error = '请选择 JPEG、PNG、GIF 或 WebP 图片'; return; }
      if (this.kind !== 'image' && !(this.kind === 'video' ? /\.mp4$/i : /\.(mp3|wav|wma|amr)$/i).test(file.name)) { this.error = '请选择支持的' + this.kindLabel + '文件'; return; }
      this.busy = true; this.notice = ''; this.error = ''; this.receipt = null; let uploaded = false;
      try {
        const value = this.kind === 'image' ? await api.uploadAttachment(file, this.uploadCategoryId) : await api.uploadProductMedia(this.productId, this.kind, file, this.uploadCategoryId);
        if (!this.current(ticket)) return; const row = mediaRow(value, this.kind); uploaded = true;
        if (row.pid !== this.uploadCategoryId || row.type !== this.categoryType) throw new Error('上传对象的实际分类或类型尚未核对'); this.categoryId = this.uploadCategoryId; this.page = 1;
        const loaded = await this.reload(true); if (!this.current(ticket)) return;
        if (!loaded || !this.rows.some((item) => item.att_id === row.att_id && item.att_dir === row.att_dir)) throw new Error('新增素材尚未从当前列表读回');
        this.notice = this.kindLabel + '已上传并重新读取';
      } catch (error) { if (this.current(ticket)) this.error = (uploaded ? this.kindLabel + '已上传，列表核对未完成：' : '') + this.operationError(error); }
      finally { if (this.current(ticket)) this.busy = false; }
    },
    select(row) { const ticket = this.ticket(); if (!this.current(ticket) || this.busy || this.loading || !this.rows.includes(row)) return; if (this.productId !== null && !this.$store.getters.can('product.write')) return; mediaRow(row, this.kind); this.$emit('select', { row, kind: this.kind, productId: this.productId, invocation: this.invocation, context: this.context }); },
    async remove(row) {
      const ticket = this.ticket(); if (!this.current(ticket) || this.busy || this.loading || !this.rows.includes(row)) return;
      try { await this.$confirm('删除“' + row.real_name + '”？已被商品使用的素材不能删除。', '删除素材', { type: 'warning' }); } catch (action) { return; }
      if (!this.current(ticket) || !this.rows.includes(row)) return; this.busy = true; this.error = ''; this.notice = ''; this.receipt = null; let deleted = false;
      try { await api.deleteAttachments([row.att_id]); if (!this.current(ticket)) return; deleted = true; await this.reload(true); if (this.current(ticket)) this.notice = '素材已删除并重新读取'; }
      catch (error) { if (this.current(ticket)) this.error = (deleted ? '素材已删除，列表刷新未完成：' : '') + this.operationError(error); } finally { if (this.current(ticket)) this.busy = false; }
    },
    editCategory(row) { if (this.busy || this.loading || !this.current(this.ticket())) return; this.categoryRecord = row; this.categoryForm = { name: row ? row.name : '', pid: row ? row.pid : 0, type: row ? row.type : this.categoryType }; this.categoryError = ''; this.categoryDialog = true; },
    closeCategory() { if (!this.busy) this.categoryDialog = false; },
    async saveCategory() {
      if (this.busy || !this.categoryDialog) return; const ticket = this.ticket(); if (!this.current(ticket)) return;
      this.categoryError = ''; if (!this.categoryForm.name.trim()) { this.categoryError = '请填写分类名称'; return; }
      const body = { name: this.categoryForm.name.trim(), pid: this.categoryForm.pid }; if (this.categoryForm.type === 1 || this.kind === 'video') body.type = this.categoryForm.type;
      this.busy = true; let saved = false;
      try { await api.saveAttachmentCategory(this.categoryRecord ? this.categoryRecord.id : null, body); if (!this.current(ticket)) return; saved = true; await this.reload(true); if (!this.current(ticket)) return; this.categoryDialog = false; this.notice = '分类已保存'; }
      catch (error) { if (this.current(ticket)) this.categoryError = (saved ? '分类已保存，列表刷新未完成：' : '') + this.operationError(error); } finally { if (this.current(ticket)) this.busy = false; }
    },
    async deleteCategory() {
      const row = this.selectedCategory; const ticket = this.ticket(); if (!row || row.type !== 1 || this.busy || this.loading || !this.current(ticket)) return;
      try { await this.$confirm('删除视频分类“' + row.name + '”？素材会保留并移到有效上级或未分类。', '删除分类', { type: 'warning' }); } catch (action) { return; }
      if (!this.current(ticket) || this.selectedCategory !== row) return; this.busy = true; this.error = ''; this.notice = ''; let deleted = false;
      try { await api.deleteVideoCategory(row.id); if (!this.current(ticket)) return; deleted = true; this.categoryId = 0; this.uploadCategoryId = 0; this.page = 1; await this.reload(true); if (this.current(ticket)) this.notice = '视频分类已删除，素材已重新读取'; }
      catch (error) { if (this.current(ticket)) this.error = (deleted ? '分类已删除，列表核对未完成：' : '') + this.operationError(error); } finally { if (this.current(ticket)) this.busy = false; }
    },
  },
};
</script>
