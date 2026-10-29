<template>
  <section class="shipment-form">
    <h2 class="section-heading">分批发货</h2>
    <p class="field-note">选择本次实际寄出的保存商品数量。提交时会重新核对待发货数量与权限。</p>
    <el-form label-position="top" @submit.native.prevent="submit">
      <div v-for="item in snapshot.root.items" :key="item.id" class="shipment-item">
        <img :src="item.image" class="table-image" alt="商品保存图片">
        <div class="shipment-copy"><div>{{ item.product_name }}</div><p class="field-note">{{ item.sku_name }}</p>
          <p class="field-note">保存单价 ￥{{ item.unit_price }} · 原数量 {{ item.quantity }} · 已发 {{ fact(item.id).shipped_quantity }} · 当前可发 {{ fact(item.id).to_ship }}</p>
          <el-form-item label="本次发货数量">
            <!-- Element UI initializes disabled/max ARIA only on mount; keep them in sync after shipment updates. -->
            <el-input-number :key="item.id + ':' + (disabled || fact(item.id).to_ship === 0) + ':' + fact(item.id).to_ship"
              v-model="quantities[item.id]" :min="0" :max="fact(item.id).to_ship" :step="1" :precision="0" :disabled="disabled || fact(item.id).to_ship === 0" :controls="false" />
          </el-form-item>
        </div>
      </div>
      <div class="shipment-delivery">
        <el-form-item label="承运编码"><el-input v-model="delivery.carrier_code" :disabled="disabled" maxlength="50" placeholder="填写本次承运编码" /></el-form-item>
        <el-form-item label="承运名称"><el-input v-model="delivery.carrier_name" :disabled="disabled" maxlength="64" placeholder="填写承运公司名称" /></el-form-item>
        <el-form-item label="物流单号"><el-input v-model="delivery.tracking_no" :disabled="disabled" maxlength="64" placeholder="填写实际物流单号" /></el-form-item>
      </div>
      <div class="shipment-actions"><el-button class="shipment-control" type="primary" :disabled="disabled" @click="submit">核对并提交发货</el-button></div>
    </el-form>
  </section>
</template>
<script>
export default {
  name: 'ShipmentForm',
  props: { snapshot: { type: Object, required: true }, disabled: { type: Boolean, default: false } },
  data() { return { quantities: {}, delivery: { type: 'express', carrier_code: '', carrier_name: '', tracking_no: '' } }; },
  watch: { snapshot: { immediate: true, handler(value) {
    const quantities = {}; value.root.items.forEach(item => { quantities[item.id] = 0; }); this.quantities = quantities;
    this.delivery = { type: 'express', carrier_code: '', carrier_name: '', tracking_no: '' };
  } } },
  methods: {
    fact(id) { return this.snapshot.shipping_items.find(item => item.item_id === id); },
    submit() {
      if (this.disabled) return;
      this.$emit('submit', { items: this.snapshot.root.items.filter(item => this.quantities[item.id] !== 0)
        .map(item => ({ item_id: item.id, quantity: this.quantities[item.id] })), delivery: { ...this.delivery } });
    }
  }
};
</script>
<style scoped>
.shipment-form { min-width: 0; padding-top: 20px; }
.shipment-item { display: flex; gap: 12px; padding: 16px 0; border-bottom: 1px solid #ebeef5; min-width: 0; }
.shipment-item .table-image { flex: 0 0 52px; }
.shipment-copy { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.shipment-copy .el-input-number { box-sizing: border-box; max-width: 100%; min-height: 44px; }
.shipment-form ::v-deep .el-input__inner { box-sizing: border-box; min-height: 44px; }
.shipment-delivery { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 16px; padding-top: 20px; min-width: 0; }
.shipment-delivery .el-form-item { min-width: 0; }
.shipment-actions { display: flex; justify-content: center; align-items: stretch; flex-wrap: wrap; min-width: 0; gap: 12px; }
.shipment-control { box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; min-width: 0; max-width: 100%; min-height: 44px; margin: 0; height: auto; white-space: normal; line-height: 1.4; text-align: center; }
@media(max-width:760px){ .shipment-delivery { grid-template-columns: minmax(0,1fr); } }
</style>
