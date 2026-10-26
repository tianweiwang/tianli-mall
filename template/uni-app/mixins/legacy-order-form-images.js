import { openOrderFormPrivateScope, isLegacyOrderFormImage, legacyOrderFormAsset } from '@/api/marketplace-order-form-private.js';

// Saved values stay references. Only this page-scoped map owns temporary display paths.
function key(fields, field, index) { return JSON.stringify([fields.indexOf(field), index, field.value[index]]); }
export default {
  data() { return { legacyFormImages: {}, legacyFormImageFeedback: {} }; },
  computed: {
    legacyFormImageSession() { const app = this.$store.state.app; return JSON.stringify([app.token, app.authRevision, app.verifiedUid]); },
    legacyFormImagePlan() { return JSON.stringify([this.legacyFormContext, this.legacyFormFields, this.legacyFormActive]); }
  },
  watch: {
    legacyFormImageSession() { this.refreshLegacyFormImages(); },
    legacyFormImagePlan() { this.refreshLegacyFormImages(); }
  },
  mounted() { this.refreshLegacyFormImages(); },
  onHide() { this.closeLegacyFormImages(); },
  onUnload() { this.closeLegacyFormImages(); },
  beforeDestroy() { this.closeLegacyFormImages(); },
  methods: {
    closeLegacyFormImages() {
      this._legacyFormImageGeneration = (this._legacyFormImageGeneration || 0) + 1;
      if (this._legacyFormImageScope) this._legacyFormImageScope.dispose();
      this._legacyFormImageScope = null; this.legacyFormImages = {}; this.legacyFormImageFeedback = {};
    },
    refreshLegacyFormImages() {
      this.closeLegacyFormImages();
      if (!this.legacyFormActive || !this.legacyFormContext || !Array.isArray(this.legacyFormFields)) return;
      const fields = this.legacyFormFields, context = this.legacyFormContext, plan = this.legacyFormImagePlan;
      const generation = this._legacyFormImageGeneration, entries = [];
      fields.forEach(field => {
        if (field && field.label === 'img' && Array.isArray(field.value)) field.value.forEach((reference, index) => {
          if (isLegacyOrderFormImage(reference)) entries.push({ field, index, reference, key: key(fields, field, index) });
        });
      });
      if (!entries.length) return;
      entries.forEach(entry => this.$set(this.legacyFormImageFeedback, entry.key, '正在读取图片'));
      let scope;
      const samePlan = () => this.legacyFormActive === true && generation === this._legacyFormImageGeneration && plan === this.legacyFormImagePlan;
      const current = () => samePlan() && (!scope || this._legacyFormImageScope === scope);
      const read = async () => {
        try {
          scope = await openOrderFormPrivateScope(current);
          if (!samePlan()) { scope.dispose(); return; }
          this._legacyFormImageScope = scope;
        } catch (error) {
          if (current()) entries.forEach(entry => this.$set(this.legacyFormImageFeedback, entry.key, '图片身份暂未确认，请重新进入订单'));
          return;
        }
        for (const entry of entries) {
          if (!scope.current()) return;
          try {
            const id = legacyOrderFormAsset(entry.reference); let result;
            if (context.kind === 'confirmation') result = await scope.legacyConfirmationImage(context.key, id);
            else if (context.kind === 'customer') result = await scope.legacyCustomerOrderImage(context.orderId, id);
            else if (context.kind === 'mobile') result = await scope.legacyMobileOrderImage(context.orderId, id);
            else throw new Error('表单图片上下文未取得');
            if (!scope.current()) { result.release(); return; }
            this.$set(this.legacyFormImages, entry.key, result);
            this.$set(this.legacyFormImageFeedback, entry.key, '');
          } catch (error) {
            if (scope.current()) this.$set(this.legacyFormImageFeedback, entry.key, '图片未读取，请重新进入订单');
          }
        }
      };
      read();
    },
    legacyFormImageSource(field, index) {
      const reference = field.value[index];
      if (!isLegacyOrderFormImage(reference)) return reference;
      const result = this.legacyFormImages[key(this.legacyFormFields || [], field, index)], scope = this._legacyFormImageScope;
      return this.legacyFormActive && scope && scope.current() && result ? result.url : '';
    },
    legacyFormImageMessage(field, index) {
      if (!isLegacyOrderFormImage(field.value[index])) return '';
      return this.legacyFormImageFeedback[key(this.legacyFormFields || [], field, index)] || '图片待读取';
    },
    legacyFormImageFailed(field, index) {
      if (!isLegacyOrderFormImage(field.value[index])) return;
      const id = key(this.legacyFormFields || [], field, index), result = this.legacyFormImages[id];
      if (result) result.release();
      this.$delete(this.legacyFormImages, id); this.$set(this.legacyFormImageFeedback, id, '图片未能显示，请重新进入订单');
    },
    previewLegacyFormImages(field, index) {
      const current = this.legacyFormImageSource(field, index);
      if (!current) return;
      const urls = field.value.map((value, position) => this.legacyFormImageSource(field, position)).filter(Boolean);
      if (urls.length) uni.previewImage({ urls, current });
    }
  }
};
