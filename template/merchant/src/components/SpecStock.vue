<template>
  <el-table :data="rows" border style="width:100%">
    <el-table-column label="规格" min-width="150"><template slot-scope="scope">{{ Object.values(scope.row.detail).join(' / ') }}<p v-if="scope.row.id" class="field-note">SKU {{ scope.row.id }}</p></template></el-table-column>
    <el-table-column label="图片" width="110"><template slot-scope="scope"><img v-if="scope.row.pic" :src="preview(scope.row.pic)" class="table-image" alt="规格图片"><el-button v-if="editable && mediaAllowed" size="mini"  :aria-label="skuLabel(scope.row, scope.$index) + ' 选择图片'" @click="$emit('picture', scope.row)">选择图片</el-button></template></el-table-column>
    <el-table-column v-for="field in fields" :key="field.key" :label="field.label" min-width="115"><template slot-scope="scope"><el-input :id="fieldId('sku-' + scope.$index + '-' + field.key)" :label="skuLabel(scope.row, scope.$index) + ' ' + field.label" v-model="scope.row[field.key]" :disabled="!editable" inputmode="decimal" size="small" /></template></el-table-column>
    <el-table-column label="条码" min-width="145"><template slot-scope="scope"><el-input :id="fieldId('sku-' + scope.$index + '-barcode')" :label="skuLabel(scope.row, scope.$index) + ' 条码'" v-model="scope.row.bar_code" :disabled="!editable" maxlength="50" size="small" /></template></el-table-column>
    <el-table-column label="库存" min-width="110"><template slot-scope="scope"><span>{{ scope.row.stock }}</span><el-button v-if="stockAllowed && scope.row.id && scope.row.unique" size="mini"  :aria-label="skuLabel(scope.row, scope.$index) + ' 调整库存'" @click="$emit('stock', scope.row)">调整库存</el-button></template></el-table-column>
    <el-table-column label="默认规格" width="95"><template slot-scope="scope"><el-checkbox v-control-label="skuLabel(scope.row, scope.$index) + ' 默认规格'" :value="Boolean(scope.row.is_default_select)" :disabled="!editable" @change="chooseDefault(scope.row, $event)"><span class="sr-only">{{ skuLabel(scope.row, scope.$index) }} 设为默认规格</span></el-checkbox></template></el-table-column>
  </el-table>
</template>
<script>
import { fieldNames } from '../accessibility';
// Ordinary table behavior adapted from template/admin/src/pages/product/productAdd/components/SpecStock.vue.
// The platform request layer, stock input, virtual table, draggable and v-auth are deliberately not imported.
export default {
  mixins: [fieldNames],
  props: { rows: { type: Array, required: true }, editable: Boolean, mediaAllowed: Boolean, stockAllowed: Boolean, preview: { type: Function, required: true } },
  data: () => ({ fields: [{ key: 'price', label: '售价（元）' }, { key: 'cost', label: '成本价（元）' }, { key: 'ot_price', label: '原价（元）' }, { key: 'vip_price', label: '会员价（元）' }, { key: 'weight', label: '重量（kg）' }, { key: 'volume', label: '体积（m³）' }] }),
  methods: { skuLabel(row, index) { return '第' + (index + 1) + '项规格（' + Object.values(row.detail).join(' / ') + '）'; }, chooseDefault(row, selected) { this.rows.forEach((sku) => { sku.is_default_select = selected && sku === row ? 1 : 0; }); } },
};
</script>
