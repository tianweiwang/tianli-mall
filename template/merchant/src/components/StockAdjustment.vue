<template><el-dialog title="调整库存" :visible="visible" width="min(92vw, 520px)" :close-on-click-modal="false" :before-close="close"><p v-if="sku" class="stock-summary">{{ Object.values(sku.detail).join(' / ') }}<br>当前库存：{{ sku.stock }}</p><el-alert v-if="error" :title="error" type="error" :closable="false" show-icon /><el-form @submit.native.prevent="submit"><el-form-item label="增减数量" :for="fieldId('stock-delta')"><el-input v-model="delta" inputmode="numeric" placeholder="增加填写正数，减少填写负数" :id="fieldId('stock-delta')" :label="'库存增减数量'" /></el-form-item><el-form-item label="调整原因" :for="fieldId('stock-reason')"><el-input v-model="reason" type="textarea" maxlength="255" :id="fieldId('stock-reason')" :label="'库存调整原因'" /></el-form-item><div class="actions"><el-button type="primary" native-type="submit" :loading="busy" :disabled="busy">提交库存调整</el-button><el-button :disabled="busy" @click="close">取消</el-button></div></el-form></el-dialog></template>
<script>
import { fieldNames } from '../accessibility';
import { api } from '../api';
export default {
  mixins: [fieldNames],
  props: { visible: Boolean, productId: Number, sku: Object }, data: () => ({ delta: '', reason: '', error: '', busy: false }),
  watch: { visible(value) { if (value) { this.delta = ''; this.reason = ''; this.error = ''; } } },
  methods: {
    close() { if (!this.busy) this.$emit('close'); },
    async submit() {
      if (this.busy) return; this.error = '';
      const delta = /^-?[1-9][0-9]*$/.test(this.delta) ? Number(this.delta) : NaN;
      if (!Number.isSafeInteger(delta) || Math.abs(delta) > 16777215 || !this.reason.trim()) { this.error = '请填写非零整数增减数量和调整原因'; return; }
      this.busy = true;
      try { await api.adjustStock(this.productId, { unique: this.sku.unique, delta, reason: this.reason.trim() }); this.$emit('adjusted'); this.$emit('close'); }
      catch (error) { this.error = error.message; }
      finally { this.busy = false; }
    },
  },
};
</script>
