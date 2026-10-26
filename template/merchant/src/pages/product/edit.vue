<template>
  <section class="page-panel" v-loading="loading"><h1 class="page-heading">{{ productId ? (editable ? '编辑商品' : '查看商品') : '新建商品' }}</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" /><el-alert v-if="notice" :title="notice" role="status" aria-live="polite" aria-atomic="true" type="success" :closable="false" show-icon class="feedback" /><p v-if="actual" class="field-note">当前状态：{{ status(actual.review_status) }} · {{ actual.is_show ? '已上架' : '未上架' }}<span v-if="actual.review_reason"> · {{ actual.review_reason }}</span></p>
    <el-form v-if="loaded" label-position="top" @submit.native.prevent="save">
      <div class="form-grid"><el-form-item label="商品名称" :for="fieldId('product-name')"><el-input v-model="form.store_name" :disabled="!editable || busy" maxlength="128" :id="fieldId('product-name')" :label="'商品名称'" /></el-form-item><el-form-item label="商品单位" :for="fieldId('product-unit')"><el-input v-model="form.unit_name" :disabled="!editable || busy" maxlength="32" :id="fieldId('product-unit')" :label="'单位'" /></el-form-item><el-form-item label="商品简介" :for="fieldId('product-info')"><el-input v-model="form.store_info" :disabled="!editable || busy" maxlength="256" :id="fieldId('product-info')" :label="'商品简介'" /></el-form-item><el-form-item label="关键词" :for="fieldId('product-keyword')"><el-input v-model="form.keyword" :disabled="!editable || busy" maxlength="256" :id="fieldId('product-keyword')" :label="'关键词'" /></el-form-item><el-form-item class="full-row" label="商品分类" :for="fieldId('product-category')"><el-select v-model="form.cate_id" multiple filterable :disabled="!editable || busy" :id="fieldId('product-category')" v-control-label="'商品分类'"><el-option v-for="category in categories" :key="category.id" :value="category.id" :label="category.label" /></el-select></el-form-item></div>
      <h2 class="section-heading">商品图片</h2><div class="image-strip"><div v-for="(image, index) in form.slider_image" :key="image" class="image-item"><img :src="preview(image)" alt="商品轮播图"><el-button v-if="editable" size="small" :disabled="busy" @click="form.slider_image.splice(index, 1)">移除图片</el-button></div></div><el-button v-if="editable && can('attachment.manage')" :disabled="busy || form.slider_image.length >= 12" @click="pick('slider')">选择本店素材</el-button><p class="field-note">第一张图片作为主图，最多 12 张。</p>
      <div class="toolbar"><el-button v-if="mediaAllowed" :disabled="busy || loading" @click="pick('video')">选择商品视频</el-button></div><div v-if="form.video_link"><video :src="preview(form.video_link)" controls class="product-video" /><el-button v-if="editable" :disabled="busy" @click="form.video_link = ''">移除视频</el-button></div>
      <h2 class="section-heading">规格与价格</h2><el-radio-group aria-label="规格类型" v-model="form.spec_type" :disabled="!editable || busy" @change="changeSpec"><el-radio :label="0">单规格</el-radio><el-radio :label="1">多规格</el-radio></el-radio-group>
      <div v-if="form.spec_type" class="definition-list"><div v-for="(item, index) in form.items" :key="index" class="rule-block"><div class="toolbar"><el-input :id="fieldId('dimension-' + index)" :label="'第' + (index + 1) + '项规格名称'" :value="item.value" @input="renameDimension(item, $event)" @change="renameDimension(item, item.value.trim())" :disabled="!editable || busy" placeholder="规格名称，如颜色" maxlength="32" /><el-button v-if="editable" size="small" :disabled="busy" @click="form.items.splice(index, 1)">移除此规格</el-button></div><div class="toolbar"><div v-for="(option, optionIndex) in item.detail" :key="optionIndex" class="option-field"><el-input :id="fieldId('dimension-' + index + '-option-' + optionIndex)" :label="'第' + (index + 1) + '项规格 ' + item.value + ' 第' + (optionIndex + 1) + '个选项'" :value="option.value" @input="renameOption(item, option, $event)" @change="renameOption(item, option, option.value.trim())" :disabled="!editable || busy" placeholder="规格选项" maxlength="64" /><el-button v-if="editable" size="mini" :disabled="busy" @click="item.detail.splice(optionIndex, 1)">移除</el-button></div><el-button v-if="editable" size="small" :disabled="busy || item.detail.length >= 100" @click="item.detail.push({ value: '' })">添加选项</el-button></div></div><div v-if="editable" class="toolbar"><el-button :disabled="busy || form.items.length >= 8" @click="form.items.push({ value: '', detail: [{ value: '' }] })">添加规格</el-button><el-button :disabled="busy" @click="generate">生成规格组合</el-button></div></div>
      <spec-stock :rows="form.attrs" :editable="editable && !busy" :media-allowed="can('attachment.manage')" :stock-allowed="Boolean(productId) && can('stock.adjust') && !busy" :preview="preview" @picture="pick('sku', $event)" @stock="adjust" /><p class="field-note">已有规格保留 SKU 身份。库存单独调整；新规格保存后可增加库存。</p>
      <h2 class="section-heading">运费</h2><div class="form-grid"><el-form-item label="运费方式" :for="fieldId('product-freight')"><el-select v-model="form.freight" :disabled="!editable || busy" :id="fieldId('product-freight')" v-control-label="'运费方式'"><el-option :value="1" label="包邮" /><el-option :value="2" label="固定运费" /><el-option :value="3" label="运费模板" /></el-select></el-form-item><el-form-item v-if="form.freight === 2" label="固定运费（元）" :for="fieldId('product-postage')"><el-input v-model="form.postage" inputmode="decimal" :disabled="!editable || busy" :id="fieldId('product-postage')" :label="'固定运费（元）'" /></el-form-item><el-form-item v-if="form.freight === 3" label="本店运费模板" :for="fieldId('product-template')"><el-select v-model="form.temp_id" :disabled="!editable || busy || !can('freight.manage')" :id="fieldId('product-template')" v-control-label="'本店运费模板'"><el-option v-for="template in freights" :key="template.id" :value="template.id" :label="template.name" /><el-option v-if="form.temp_id && !freights.some((item) => item.id === form.temp_id)" :value="form.temp_id" :label="'当前模板 ' + form.temp_id" /></el-select><p v-if="!can('freight.manage')" class="field-note">当前账号无运费模板管理权限，保留已选择的模板。</p></el-form-item></div>
      <h2 class="section-heading">商品详情</h2><div v-if="mediaAllowed" class="toolbar"><el-button :disabled="busy || loading" @click="pick('description-image')">追加详情图片</el-button><el-button :disabled="busy || loading" @click="pick('description-video')">追加详情视频</el-button><el-button :disabled="busy || loading" @click="pick('description-audio')">追加详情音频</el-button></div><el-form-item label="详情内容" :for="fieldId('product-description')"><el-input v-model="form.description" type="textarea" :rows="10" :disabled="!editable || busy" maxlength="60000" :id="fieldId('product-description')" :label="'详情内容'" /></el-form-item>
      <p v-if="dirty" class="field-note">有未保存修改。保存后商品将重新进入待审核并下架，审核通过后再上架。</p><div class="actions"><el-button v-if="editable" type="primary" native-type="submit" :loading="busy" :disabled="busy">保存商品</el-button><el-button v-if="productId && can('product.submit_review') && actual && [0,3].includes(actual.review_status)" :disabled="busy || dirty" @click="submitReview">提交审核</el-button><el-button :disabled="busy" @click="$router.push('/products')">返回列表</el-button></div>
    </el-form>
    <attachment-picker :visible="pickerVisible" :kind="pickerKind" :product-id="pickerContext ? pickerContext.productId : null" :product-context="pickerContext" :invocation="pickerInvocation" @close="closePicker" @select="selected" /><stock-adjustment :visible="stockVisible" :product-id="productId" :sku="stockSku" @close="stockVisible = false" @adjusted="stockAdjusted" />
  </section>
</template>
<script>
import { fieldNames } from '../../accessibility';
import { api, captureMediaContext, currentMediaContext, mediaRow } from '../../api';
import { blankProduct, fromDetail, productWrite, regenerateSkus, recordId, reviewLabel } from '../../domain/product';
import SpecStock from '../../components/SpecStock.vue';
import AttachmentPicker from '../../components/AttachmentPicker.vue';
import StockAdjustment from '../../components/StockAdjustment.vue';
const flattenCategories = (list, depth = 0) => list.flatMap((row) => [{ ...row, label: '　'.repeat(depth) + row.cate_name }, ...flattenCategories(row.children || [], depth + 1)]).filter((row) => row.selectable);
export default {
  mixins: [fieldNames],
  components: { SpecStock, AttachmentPicker, StockAdjustment },
  data: () => ({ productId: null, form: blankProduct(), actual: null, savedDefinition: '', loaded: false, loading: false, busy: false, error: '', notice: '', categories: [], freights: [], previews: {}, pickerVisible: false, pickerTarget: '', pickerSku: null, pickerKind: 'image', pickerInvocation: 0, pickerContext: null, stockVisible: false, stockSku: null, pageContext: null, pageGeneration: 0, pageRoute: '', alive: true, active: true, unsubscribe: null }),
  computed: {
    editable() { return this.can('product.write'); },
    mediaAllowed() { return this.editable && this.can('attachment.manage'); },
    dirty() { if (!this.productId || !this.loaded) return false; try { return JSON.stringify(productWrite(this.form)) !== this.savedDefinition; } catch (error) { return true; } },
  },
  created() { this.unsubscribe = this.$store.subscribe(() => { if (this.pageContext && !currentMediaContext(this.pageContext)) { this.invalidatePage(); this.form = blankProduct(); this.actual = null; this.error = '登录身份已变化，请重新打开商品'; } else if (this.pickerVisible && !this.mediaAllowed) this.closePicker(); }); this.load(); },
  watch: { '$route.params.id'() { this.load(); } },
  beforeRouteLeave(to, from, next) { this.invalidatePage(); next(); },
  deactivated() { this.active = false; this.invalidatePage(); },
  activated() { if (!this.active) { this.active = true; this.load(); } },
  beforeDestroy() { this.alive = false; this.invalidatePage(); if (this.unsubscribe) this.unsubscribe(); },
  methods: {
    can(permission) { return this.$store.getters.can(permission); }, status: reviewLabel,
    preview(path) { return this.previews[path] || path; },
    routeKey() { return this.$route.name + ':' + (this.$route.params.id || ''); },
    current(context, generation) { return this.alive && this.active && context === this.pageContext && generation === this.pageGeneration && this.pageRoute === this.routeKey() && currentMediaContext(context); },
    closePicker() { this.pickerInvocation += 1; this.pickerVisible = false; this.pickerContext = null; this.pickerTarget = ''; this.pickerSku = null; },
    invalidatePage() { this.pageGeneration += 1; this.closePicker(); this.pageContext = null; this.loaded = false; this.loading = false; this.busy = false; this.stockVisible = false; this.stockSku = null; this.previews = {}; },
    checkDetail(data, id, context) { if (!data || !data.product || recordId(data.product.id) !== id || Number(data.product.mer_id) !== context.merchant) throw new Error('商品详情与当前本店上下文不一致'); return fromDetail(data); },
    async load() {
      this.invalidatePage(); this.loading = true; this.error = ''; this.notice = ''; this.actual = null; this.form = blankProduct();
      let context; const generation = this.pageGeneration;
      try {
        const isNew = this.$route.name === 'product-new'; if (!isNew && this.$route.name !== 'product-edit') throw new Error('商品编辑入口不正确');
        const target = isNew ? 0 : recordId(this.$route.params.id); context = captureMediaContext(this.$store, target); this.pageContext = context; this.pageRoute = this.routeKey(); this.productId = target || null;
        const categories = await api.categories(); if (!this.current(context, generation)) return; if (!Array.isArray(categories.list)) throw new Error('分类返回不完整');
        const freights = [];
        if (this.can('freight.manage')) { let page = 1, count; do { const data = await api.freightTemplates({ page, limit: 100 }); if (!this.current(context, generation)) return; if (!Array.isArray(data.list) || !Number.isInteger(data.count)) throw new Error('运费模板返回不完整'); freights.push(...data.list); count = data.count; if (!data.list.length && freights.length < count) throw new Error('运费模板列表不完整'); page += 1; } while (freights.length < count); }
        if (target) { const data = await api.product(target); if (!this.current(context, generation)) return; this.form = this.checkDetail(data, target, context); this.actual = data.product; this.savedDefinition = JSON.stringify(productWrite(this.form)); }
        else { this.form = blankProduct(); this.actual = null; this.savedDefinition = ''; }
        this.categories = flattenCategories(categories.list); this.freights = freights; this.loaded = true;
      } catch (error) { if (!context || this.current(context, generation)) this.error = error.message; }
      finally { if (!context || this.current(context, generation)) this.loading = false; }
    },
    changeSpec() { try { if (this.form.spec_type && !this.form.items.length) this.form.items = [{ value: '', detail: [{ value: '' }] }]; if (!this.form.spec_type) regenerateSkus(this.form); } catch (error) { this.error = error.message; } },
    renameDimension(item, next) {
      const previous = item.value; if (previous === next) return;
      if (this.form.items.some((other) => other !== item && other.value === next)) { this.error = '规格名称不能重复'; return; }
      this.form.attrs.forEach((sku) => { if (Object.prototype.hasOwnProperty.call(sku.detail, previous)) { const value = sku.detail[previous]; this.$delete(sku.detail, previous); this.$set(sku.detail, next, value); } });
      item.value = next; this.error = '';
    },
    renameOption(item, option, next) {
      const previous = option.value; if (previous === next) return;
      if (item.detail.some((other) => other !== option && other.value === next)) { this.error = '规格选项不能重复'; return; }
      this.form.attrs.forEach((sku) => { if (sku.detail[item.value] === previous) this.$set(sku.detail, item.value, next); });
      option.value = next; this.error = '';
    },
    generate() { this.error = ''; try { regenerateSkus(this.form); } catch (error) { this.error = error.message; } },
    pick(target, sku = null) {
      this.error = ''; const context = this.pageContext;
      if (!this.loaded || this.loading || this.busy || !this.mediaAllowed || !this.current(context, this.pageGeneration)) return;
      if (context.productId === 0 ? (this.$route.name !== 'product-new' || this.productId !== null || this.actual !== null) : (!this.actual || recordId(this.actual.id) !== context.productId || this.productId !== context.productId || Number(this.actual.mer_id) !== context.merchant)) { this.error = '商品媒体上下文未确认，请重新读取'; return; }
      if (!['slider', 'sku', 'video', 'description-image', 'description-video', 'description-audio'].includes(target) || (target === 'sku' && !this.form.attrs.includes(sku))) return;
      this.closePicker(); this.pickerTarget = target; this.pickerSku = sku; this.pickerKind = target === 'video' || target === 'description-video' ? 'video' : (target === 'description-audio' ? 'audio' : 'image'); this.pickerContext = context; this.pickerVisible = true;
    },
    selected(selection) {
      const context = this.pickerContext;
      if (!selection || !this.pickerVisible || !this.loaded || this.busy || !this.mediaAllowed || !this.current(context, this.pageGeneration)
        || selection.context !== context || selection.productId !== context.productId || selection.invocation !== this.pickerInvocation || selection.kind !== this.pickerKind) return;
      try {
        const row = mediaRow(selection.row, this.pickerKind); this.$set(this.previews, row.att_dir, row.url); if (row.satt_dir) this.$set(this.previews, row.satt_dir, row.thumbnail_url);
        if (this.pickerTarget === 'slider' && !this.form.slider_image.includes(row.att_dir) && this.form.slider_image.length < 12) { this.form.slider_image.push(row.att_dir); if (this.form.slider_image.length === 1) this.form.attrs.forEach((sku) => { if (!sku.pic) sku.pic = row.att_dir; }); }
        else if (this.pickerTarget === 'sku' && this.form.attrs.includes(this.pickerSku)) this.pickerSku.pic = row.att_dir;
        else if (this.pickerTarget === 'video') this.form.video_link = row.att_dir;
        else if (this.pickerTarget.startsWith('description-')) { const element = document.createElement({ image: 'img', video: 'video', audio: 'audio' }[this.pickerKind]); element.setAttribute('src', row.att_dir); if (this.pickerKind !== 'image') { element.setAttribute('controls', ''); element.setAttribute('preload', 'none'); } this.form.description += element.outerHTML; }
        this.closePicker();
      } catch (error) { this.error = error.message; }
    },
    adjust(sku) { this.stockSku = sku; this.stockVisible = true; },
    async stockAdjusted() {
      const context = this.pageContext; const generation = this.pageGeneration; if (!this.current(context, generation) || !this.productId) return; const id = this.productId;
      this.error = ''; this.notice = '';
      try { const data = await api.product(id); if (!this.current(context, generation)) return; const latest = this.checkDetail(data, id, context); this.form.attrs.forEach((sku) => { const actual = latest.attrs.find((row) => row.id === sku.id && row.unique === sku.unique); if (actual) sku.stock = actual.stock; }); this.actual = data.product; this.notice = '库存已调整，当前库存已更新'; }
      catch (error) { if (this.current(context, generation)) this.error = '库存调整已提交，当前库存读取失败：' + error.message; }
    },
    async save() {
      const context = this.pageContext; const generation = this.pageGeneration;
      if (this.busy || !this.loaded || !this.editable || !this.current(context, generation)) return; this.closePicker(); this.error = ''; this.notice = ''; this.busy = true; let saved = false;
      try {
        const result = await api.saveProduct(this.productId, productWrite(this.form)); if (!this.current(context, generation)) return; const id = recordId(result.id); if (context.productId && id !== context.productId) throw new Error('保存返回了不同商品编号'); saved = true;
        if (context.productId === 0) { this.productId = id; this.loaded = false; await this.$router.replace('/products/' + id + '/edit'); return; }
        const detail = await api.product(id); if (!this.current(context, generation)) return; this.form = this.checkDetail(detail, id, context); this.actual = detail.product; this.savedDefinition = JSON.stringify(productWrite(this.form)); this.productId = id; this.notice = '商品已保存，当前状态：' + reviewLabel(this.actual.review_status);
      } catch (error) { if (this.current(context, generation)) this.error = (saved ? '商品已保存，详情重新读取未完成：' : '') + error.message; }
      finally { if (this.current(context, generation)) this.busy = false; }
    },
    async submitReview() {
      const context = this.pageContext; const generation = this.pageGeneration;
      if (this.busy || !this.current(context, generation)) return; this.error = ''; this.notice = ''; if (this.dirty) { this.error = '请先保存当前修改，再提交审核'; return; } this.closePicker(); this.busy = true; let submitted = false;
      try { await api.submitReview(this.productId); if (!this.current(context, generation)) return; submitted = true; const detail = await api.product(this.productId); if (!this.current(context, generation)) return; this.checkDetail(detail, this.productId, context); this.actual = detail.product; this.notice = '审核提交已完成，当前状态：' + reviewLabel(this.actual.review_status); }
      catch (error) { if (this.current(context, generation)) this.error = (submitted ? '已提交审核，状态读取未完成：' : '') + error.message; } finally { if (this.current(context, generation)) this.busy = false; }
    },
  },
};
</script>
