<template><el-cascader v-control-label="{ id: controlId || fieldId('region'), label: controlLabel }" :value="paths" :options="options" :props="{ multiple: true }" filterable clearable collapse-tags :disabled="disabled" placeholder="选择省、市" @change="update" /></template>
<script>
import { fieldNames } from '../accessibility';
import { cityOptions, toPaths, fromPaths } from '../domain/freight';
export default {
  mixins: [fieldNames], props: { controlId: String, controlLabel: { type: String, default: '选择省、市' }, value: { type: Array, required: true }, cities: { type: Array, required: true }, disabled: Boolean }, computed: { paths() { return toPaths(this.value); }, options() { return cityOptions(this.cities); } }, methods: { update(paths) { this.$emit('input', fromPaths(paths, this.cities)); } } };
</script>
