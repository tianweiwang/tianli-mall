export function recordId(value) {
  if (typeof value === 'string' && /^[1-9][0-9]*$/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < 1 || value > 2147483647) throw new Error('记录编号不正确');
  return value;
}
export function decimal(value) {
  if (typeof value !== 'string' || !/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/.test(value)) throw new Error('金额、重量和体积请填写最多两位小数的非负数字');
  const [whole, fraction = ''] = value.split('.'); return whole + '.' + fraction.padEnd(2, '0');
}
const amounts = ['price', 'cost', 'ot_price', 'vip_price', 'weight', 'volume'];
export const customFormTypes = [
  { value: 'text', label: '文本框' }, { value: 'number', label: '数字' },
  { value: 'email', label: '邮件' }, { value: 'data', label: '日期' },
  { value: 'time', label: '时间' }, { value: 'id', label: '身份证' },
  { value: 'phone', label: '手机号' }, { value: 'img', label: '图片' },
];
function formText(value) {
  return typeof value === 'string' && !value.includes('\0')
    && !Array.from(value).some((character) => character.length === 1 && /[\uD800-\uDFFF]/.test(character));
}
export function customFormDefinition(fields, validateTitles = true) {
  if (!Array.isArray(fields) || fields.length > 10) throw new Error('下单填写资料最多可设置 10 项');
  return Array.from(fields, (field) => {
    if (!field || typeof field !== 'object' || Array.isArray(field)
      || Object.keys(field).length !== 4 || !['title', 'label', 'value', 'status'].every((key) => Object.prototype.hasOwnProperty.call(field, key))) throw new Error('下单填写资料定义不完整，请重新读取商品');
    if (!formText(field.title)) throw new Error('资料标题返回不完整，请重新读取商品');
    if (validateTitles && (!field.title.trim() || Array.from(field.title).length > 128)) throw new Error('请填写每项资料的标题，最多 128 个字');
    if (!customFormTypes.some((type) => type.value === field.label) || typeof field.status !== 'boolean') throw new Error('资料类型或必填设置不正确');
    let value = field.value;
    if (field.label === 'img') {
      if (value !== '' && (!Array.isArray(value) || value.length > 8 || !Array.from(value).every(formText))) throw new Error('图片资料定义不完整，请重新读取商品');
      if (Array.isArray(value)) value = value.slice();
    } else if (!formText(value)) throw new Error('资料默认内容不完整，请重新读取商品');
    return { title: field.title, label: field.label, value, status: field.status };
  });
}
export function newSku(detail = { '规格': '默认' }) {
  return { detail, price: '0.00', cost: '0.00', ot_price: '0.00', vip_price: '0.00', weight: '0.00', volume: '0.00', pic: '', bar_code: '', bar_code_number: '', is_default_select: 0, stock: 0 };
}
export function blankProduct() {
  return { store_name: '', store_info: '', keyword: '', unit_name: '件', slider_image: [], video_link: '', description: '', custom_form: [], cate_id: [], spec_type: 0, items: [], attrs: [newSku()], rule_id: 0, freight: 1, postage: '0.00', temp_id: 0 };
}
export function fromDetail(data) {
  if (!data || !data.product || !Array.isArray(data.attrs) || !Array.isArray(data.items)) throw new Error('商品详情返回不完整');
  const form = blankProduct();
  ['store_name', 'store_info', 'keyword', 'unit_name', 'slider_image', 'video_link', 'cate_id', 'spec_type', 'freight', 'postage', 'temp_id'].forEach((key) => { form[key] = data.product[key]; });
  form.description = data.description; form.items = JSON.parse(JSON.stringify(data.items));
  form.custom_form = customFormDefinition(data.product.custom_form, false);
  form.originalCustomForm = customFormDefinition(form.custom_form, false);
  form.attrs = data.attrs.map((row) => {
    const sku = newSku({ ...row.detail });
    ['id', 'unique', 'pic', 'stock', 'bar_code', 'bar_code_number', 'is_default_select', ...amounts].forEach((key) => { if (row[key] !== undefined) sku[key] = row[key]; });
    return sku;
  });
  return form;
}
function normalizedItems(items) {
  if (!Array.isArray(items) || items.length < 1 || items.length > 8) throw new Error('请设置 1–8 项规格');
  const names = new Set();
  const normalized = items.map((item) => {
    const name = item.value.trim();
    if (!name || name.length > 32 || /[,=;]/.test(name) || names.has(name)) throw new Error('规格名称不能为空、重复或包含逗号、等号、分号'); names.add(name);
    if (!Array.isArray(item.detail) || !item.detail.length || item.detail.length > 100) throw new Error('每项规格需有 1–100 个选项');
    const seen = new Set(); const detail = item.detail.map((option) => {
      const value = (typeof option === 'string' ? option : option.value).trim();
      if (!value || value.length > 64 || /[,=;]/.test(value) || seen.has(value)) throw new Error('规格选项不能为空、重复或包含逗号、等号、分号'); seen.add(value); return { value, pic: typeof option === 'object' ? (option.pic || '') : '' };
    });
    return { value: name, detail };
  }); return normalized;
}
// Adapted from template/admin/src/pages/product/productAdd/index.vue generateCombinations (1816–1822).
export function generateCombinations(arr, prefix = []) {
  if (arr.length === 0) return [prefix];
  const [first, ...rest] = arr;
  return first.detail.flatMap((detail) => generateCombinations(rest, [...prefix, detail.value]));
}
function sameDetail(a, b) { const keys = Object.keys(a); return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key]); }
export function regenerateSkus(form) {
  if (form.spec_type === 0) {
    const row = form.attrs.find((sku) => sameDetail(sku.detail, { '规格': '默认' })); form.items = []; form.attrs = [row || newSku()]; return;
  }
  const items = normalizedItems(form.items); if (items.reduce((count, item) => count * item.detail.length, 1) > 256) throw new Error('生成规格组合最多 256 项');
  const rows = generateCombinations(items).map((values) => {
    const detail = {}; items.forEach((item, index) => { detail[item.value] = values[index]; });
    return form.attrs.find((row) => sameDetail(row.detail, detail)) || newSku(detail);
  }); form.items = items; form.attrs = rows;
}
export function productWrite(form) {
  if (!form.store_name.trim() || !form.unit_name.trim() || !form.slider_image.length || !form.cate_id.length) throw new Error('请填写商品名称、单位、图片和分类');
  const items = form.spec_type ? normalizedItems(form.items) : [];
  if (form.spec_type) {
    const seen = new Set();
    form.attrs.forEach((row) => {
      if (Object.keys(row.detail).length !== items.length || items.some((item) => !item.detail.some((option) => option.value === row.detail[item.value]))) throw new Error('规格选项已修改，请先更新规格组合');
      const combination = JSON.stringify(items.map((item) => row.detail[item.value])); if (seen.has(combination)) throw new Error('规格组合不能重复'); seen.add(combination);
    });
  }
  if (!form.attrs.length || form.attrs.length > 256) throw new Error('商品需有 1–256 项实际规格');
  const attrs = form.attrs.map((row) => {
    if (!row.pic) throw new Error('请为每项规格选择商品图片');
    const sku = { detail: { ...row.detail }, pic: row.pic, bar_code: row.bar_code || '', bar_code_number: row.bar_code_number || '', is_default_select: row.is_default_select ? 1 : 0 };
    amounts.forEach((key) => { sku[key] = decimal(row[key]); });
    if (row.id !== undefined) { sku.id = recordId(row.id); if (!/^[A-Za-z0-9]{8}$/.test(row.unique)) throw new Error('已有规格身份不完整，请重新读取商品'); sku.unique = row.unique; }
    return sku;
  });
  if (attrs.filter((row) => row.is_default_select).length > 1) throw new Error('只能设置一项默认规格');
  const body = {};
  ['store_name', 'store_info', 'keyword', 'unit_name', 'slider_image', 'video_link', 'description', 'spec_type', 'freight'].forEach((key) => { body[key] = form[key]; });
  body.cate_id = form.cate_id.map(recordId); body.items = items; body.attrs = attrs; body.rule_id = 0; body.postage = decimal(form.postage); body.temp_id = form.freight === 3 ? recordId(form.temp_id) : 0;
  // Unchanged legacy definitions must not block unrelated edits or rewrite their saved bytes.
  const fields = customFormDefinition(form.custom_form, false);
  if (!Object.prototype.hasOwnProperty.call(form, 'originalCustomForm') || JSON.stringify(fields) !== JSON.stringify(form.originalCustomForm)) body.custom_form = customFormDefinition(fields);
  return body; // Absolute stock is never included in product definition writes.
}
export const reviewLabel = (status) => ['草稿', '待审核', '审核通过', '已驳回'][status] || '状态未知';
