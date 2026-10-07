<template>
  <el-dialog
    :title="dialogTitle"
    :visible="visible"
    width="620px"
    append-to-body
    @close="close"
  >
    <el-form label-position="top" class="catalog-editor" @submit.native.prevent>
      <div class="catalog-editor__grid">
        <el-form-item label="名称" required>
          <el-input v-model.trim="form.name" maxlength="40" />
        </el-form-item>
        <el-form-item :label="type === 'service' ? '服务系列' : '商品系列'" required>
          <el-input v-model.trim="form.series" maxlength="20" />
        </el-form-item>

        <template v-if="type === 'service'">
          <el-form-item label="服务时长（分钟）" required>
            <el-input-number v-model="form.durationMinutes" :min="1" :max="300" controls-position="right" />
          </el-form-item>
          <el-form-item label="价格模式" required>
            <el-radio-group v-model="form.priceModel">
              <el-radio label="standard">标准定价</el-radio>
              <el-radio label="quote">到店报价</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item :label="form.priceModel === 'quote' ? '定制起价（元）' : '单次价格（元）'" required>
            <el-input-number v-model="form.unitPriceYuan" :min="0" :precision="2" controls-position="right" />
          </el-form-item>
          <template v-if="form.priceModel === 'standard'">
            <el-form-item label="疗程价格（元）">
              <el-input-number v-model="form.packagePriceYuan" :min="0" :precision="2" controls-position="right" />
            </el-form-item>
            <el-form-item label="疗程数量">
              <el-input-number v-model="form.packageCount" :min="0" :max="100" controls-position="right" />
            </el-form-item>
            <el-form-item label="疗程单位">
              <el-input v-model.trim="form.packageUnit" maxlength="6" />
            </el-form-item>
          </template>
          <el-form-item label="项目说明" class="catalog-editor__wide" required>
            <el-input v-model.trim="form.description" type="textarea" :rows="3" maxlength="160" show-word-limit />
          </el-form-item>
        </template>

        <template v-else>
          <el-form-item label="商品分类" required>
            <el-input v-model.trim="form.category" maxlength="20" />
          </el-form-item>
          <el-form-item label="销售单位" required>
            <el-input v-model.trim="form.unit" maxlength="8" />
          </el-form-item>
          <el-form-item label="统一售价（元）" required>
            <el-input-number v-model="form.priceYuan" :min="0" :precision="2" controls-position="right" />
          </el-form-item>
          <el-form-item label="划线价（元）">
            <el-input-number v-model="form.originalPriceYuan" :min="0" :precision="2" controls-position="right" />
          </el-form-item>
          <el-form-item label="集团参考库存" required>
            <el-input-number v-model="form.stock" :min="0" controls-position="right" />
          </el-form-item>
          <el-form-item label="配送类型">
            <el-select v-model="form.deliveryType">
              <el-option label="普通快递" value="express" />
              <el-option label="大件配送" value="large" />
            </el-select>
          </el-form-item>
          <el-form-item label="销售规格" class="catalog-editor__wide" required>
            <el-input v-model.trim="form.specsText" placeholder="多个规格用逗号分隔" maxlength="100" />
          </el-form-item>
          <el-form-item label="商品图片路径" class="catalog-editor__wide">
            <el-input v-model.trim="form.image" placeholder="留空使用内置示例图" />
          </el-form-item>
        </template>

        <el-form-item label="适用营业门店" class="catalog-editor__wide" required>
          <el-checkbox-group v-model="form.storeIds" class="catalog-editor__stores">
            <el-checkbox v-for="store in stores" :key="store.id" :label="store.id">
              {{ store.name }}
            </el-checkbox>
          </el-checkbox-group>
        </el-form-item>
      </div>
    </el-form>
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" data-testid="catalog-editor-submit" @click="submit">保存</el-button>
    </template>
  </el-dialog>
</template>

<script>
function centsToYuan(value) {
  return Number(value || 0) / 100;
}

function yuanToCents(value) {
  return Math.round(Number(value || 0) * 100);
}

function emptyForm(type) {
  if (type === 'service') {
    return {
      name: '',
      series: '',
      description: '',
      durationMinutes: 45,
      priceModel: 'standard',
      unitPriceYuan: 0,
      packagePriceYuan: 0,
      packageCount: 0,
      packageUnit: '次',
      storeIds: [],
    };
  }
  return {
    name: '',
    series: '维养系列',
    category: '',
    unit: '',
    specsText: '',
    priceYuan: 0,
    originalPriceYuan: 0,
    stock: 0,
    deliveryType: 'express',
    image: '',
    storeIds: [],
  };
}

function hydrateForm(type, value) {
  const form = emptyForm(type);
  if (!value) return form;
  if (type === 'service') {
    return {
      ...form,
      ...value,
      unitPriceYuan: centsToYuan(value.unitPriceCents),
      packagePriceYuan: centsToYuan(value.packagePriceCents),
      storeIds: [...(value.storeIds || [])],
    };
  }
  return {
    ...form,
    ...value,
    specsText: (value.specs || []).join(', '),
    priceYuan: centsToYuan(value.priceCents),
    originalPriceYuan: centsToYuan(value.originalPriceCents),
    storeIds: [...(value.storeIds || [])],
  };
}

export default {
  name: 'CatalogEditorDialog',
  props: {
    visible: { type: Boolean, default: false },
    type: { type: String, required: true },
    value: { type: Object, default: null },
    stores: { type: Array, default: () => [] },
  },
  data() {
    return { form: hydrateForm(this.type, this.value) };
  },
  computed: {
    dialogTitle() {
      const target = this.type === 'service' ? '服务项目' : '商品';
      return `${this.value ? '编辑' : '新增'}${target}`;
    },
  },
  watch: {
    visible(value) {
      if (value) this.form = hydrateForm(this.type, this.value);
    },
    value: {
      deep: true,
      handler() {
        if (this.visible) this.form = hydrateForm(this.type, this.value);
      },
    },
    type() {
      this.form = hydrateForm(this.type, this.value);
    },
  },
  methods: {
    close() {
      this.$emit('update:visible', false);
    },
    submit() {
      if (this.type === 'service') {
        this.$emit('submit', {
          name: this.form.name,
          series: this.form.series,
          description: this.form.description,
          durationMinutes: this.form.durationMinutes,
          priceModel: this.form.priceModel,
          unitPriceCents: yuanToCents(this.form.unitPriceYuan),
          packagePriceCents: yuanToCents(this.form.packagePriceYuan),
          packageCount: this.form.packageCount,
          packageUnit: this.form.packageUnit,
          storeIds: [...this.form.storeIds],
        });
        return;
      }
      this.$emit('submit', {
        name: this.form.name,
        series: this.form.series,
        category: this.form.category,
        unit: this.form.unit,
        specs: [...new Set(this.form.specsText.split(/[,，\n]/).map((item) => item.trim()).filter(Boolean))],
        priceCents: yuanToCents(this.form.priceYuan),
        originalPriceCents: yuanToCents(this.form.originalPriceYuan),
        stock: this.form.stock,
        deliveryType: this.form.deliveryType,
        image: this.form.image,
        storeIds: [...this.form.storeIds],
      });
    },
  },
};
</script>

<style scoped>
.catalog-editor__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 18px;
}

.catalog-editor__wide {
  grid-column: 1 / -1;
}

.catalog-editor__stores {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
}

.catalog-editor :deep(.el-input-number),
.catalog-editor :deep(.el-select) {
  width: 100%;
}

@media (max-width: 680px) {
  .catalog-editor__grid {
    grid-template-columns: 1fr;
  }

  .catalog-editor__wide {
    grid-column: auto;
  }
}
</style>
