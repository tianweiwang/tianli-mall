import { openOrderFormPrivateScope, isPrivateOrderFormImage, orderFormImageAsset } from '@/api/marketplace-order-form-private.js';

function imageKey(group, field, index, reference) {
  const raw = JSON.stringify([String(group.product_id), group.definition_sha256, field.field_index, index, reference]);
  // #ifdef MP
  let key = 'k';
  for (let i = 0; i < raw.length; i++) key += raw.charCodeAt(i).toString(16).padStart(4, '0');
  return key;
  // #endif
  // #ifndef MP
  return raw;
  // #endif
}
function signature(vm) { return JSON.stringify([vm.orderFormImageContext, vm.orderFormImageGroups, vm.orderFormImagesActive]); }

// Only transient display paths live here. Original field.value arrays remain the saved answers.
export default {
  data() { return { orderFormTemporaryImages: {} }; },
  computed: {
    orderFormImageSession() { const app = this.$store.state.app; return JSON.stringify([app.verifiedUid, app.authRevision, app.token]); },
    orderFormImagePlan() { return signature(this); }
  },
  watch: {
    orderFormImageSession() { this.refreshOrderFormImages(); },
    orderFormImagePlan() { this.refreshOrderFormImages(); }
  },
  mounted() { this.refreshOrderFormImages(); },
  beforeDestroy() { this.closeOrderFormImages(); },
  methods: {
    closeOrderFormImages() {
      this._orderFormImageGeneration = (this._orderFormImageGeneration || 0) + 1;
      if (this._orderFormImageScope) this._orderFormImageScope.dispose();
      if (this._orderFormUploadScope) { this._orderFormUploadScope.dispose(); uni.hideLoading(); }
      this._orderFormImageScope = null; this._orderFormUploadScope = null;
      this.orderFormTemporaryImages = {};
      if (this.marketplaceUploadPending !== undefined) { this.marketplaceUploadPending = false; this.canvasStatus = false; }
    },
    refreshOrderFormImages() {
      this.closeOrderFormImages();
      if (!this.orderFormImagesActive || !this.orderFormImageContext || !Array.isArray(this.orderFormImageGroups)) return;
      const context = this.orderFormImageContext, expected = this.orderFormImagePlan, generation = this._orderFormImageGeneration;
      let scope;
      const samePlan = () => this.orderFormImagesActive === true && generation === this._orderFormImageGeneration && expected === this.orderFormImagePlan;
      const current = () => samePlan() && (!scope || this._orderFormImageScope === scope);
      const entries = [];
      this.orderFormImageGroups.forEach(group => group.fields.forEach(field => {
        if (field.label === 'img' && Array.isArray(field.value)) field.value.forEach((reference, index) => {
          if (isPrivateOrderFormImage(reference)) entries.push({ group, field, index, reference });
        });
      }));
      if (!entries.length) return;
      const read = async () => {
        let reported = false;
        try {
          scope = await openOrderFormPrivateScope(current);
          if (!samePlan()) { scope.dispose(); return; }
          this._orderFormImageScope = scope;
        } catch (error) {
          if (current() && this.$util && typeof this.$util.Tips === 'function') this.$util.Tips({ title: error && error.message || '表单读取身份暂未核实' });
          return;
        }
        for (const entry of entries) {
          if (!scope.current()) return;
          try {
            const id = orderFormImageAsset(entry.reference); let result;
            if (context.kind === 'confirmation') result = await scope.confirmationImage(context.key, id);
            else if (context.kind === 'customer_order') result = await scope.customerOrderImage(context.rootId, id);
            else if (context.kind === 'mobile_order') result = await scope.mobileOrderImage(context.rootId, id);
            else throw new Error('表单原图读取上下文未取得');
            if (!scope.current()) { result.release(); return; }
            this.$set(this.orderFormTemporaryImages, imageKey(entry.group, entry.field, entry.index, entry.reference), result.url);
          } catch (error) {
            if (!scope.current()) return;
            if (!reported && this.$util && typeof this.$util.Tips === 'function') { reported = true; this.$util.Tips({ title: error && error.message || '表单原图暂未取得' }); }
          }
        }
      };
      read();
    },
    orderFormImageSource(group, field, index) {
      const reference = field.value[index];
      if (!isPrivateOrderFormImage(reference)) return reference;
      const source = this.orderFormTemporaryImages[imageKey(group, field, index, reference)] || '';
      const scope = this._orderFormImageScope;
      return scope && scope.current() ? source : '';
    },
    previewOrderFormImages(group, field, index) {
      const current = this.orderFormImageSource(group, field, index);
      if (!current) return;
      const urls = field.value.map((reference, position) => this.orderFormImageSource(group, field, position)).filter(Boolean);
      if (urls.length) uni.previewImage({ urls, current });
    }
  }
};
