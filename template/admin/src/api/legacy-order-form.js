import setting from '@/setting';
import { captureContext, currentContext, closeContext } from '@/api/marketplace-aftersale';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';

const privateRef = value => typeof value === 'string' && value.startsWith('legacy-form:');
function assetId(reference) {
  if (typeof reference !== 'string' || !reference.startsWith('legacy-form:v1:')) throw new Error('图片引用暂不可读取');
  const id = reference.slice('legacy-form:v1:'.length);
  if (!/^[1-9][0-9]{0,19}(?![\s\S])/.test(id) || id.length === 20 && id > '18446744073709551615') throw new Error('图片引用暂不可读取');
  return id;
}
function orderId(value) {
  const id = String(value);
  if (!/^[1-9][0-9]{0,9}(?![\s\S])/.test(id) || Number(id) > 2147483647) throw new Error('订单身份暂未确认');
  return id;
}
async function readImage(context, id, reference) {
  if (!currentContext(context)) throw new Error('登录身份已变化');
  const base = new URL(setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw new Error('图片读取地址不可用');
  base.pathname = base.pathname.replace(/\/+$/, '') + '/order/form/legacy/orders/' + orderId(id) + '/files/' + assetId(reference) + '/content';
  const controller = new AbortController(), cancel = () => controller.abort(); context.life.cancels.add(cancel);
  const timer = window.setTimeout(cancel, 20000);
  try {
    const response = await fetch(base.href, {method: 'GET', headers: {'Authori-zation': 'Bearer ' + context.token}, mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal});
    if (!currentContext(context)) throw new Error('登录身份已变化');
    const type = response.headers.get('Content-Type'), length = response.headers.get('Content-Length');
    if (response.status !== 200 || response.redirected || !['image/jpg','image/jpeg','image/png','image/gif','image/webp'].includes(type) ||
      !/^[1-9][0-9]{0,8}(?![\s\S])/.test(length || '') || Number(length) > 52428800 ||
      response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw new Error('图片未完整取得');
    const blob = await response.blob();
    if (!currentContext(context) || blob.size !== Number(length) || blob.type !== type) throw new Error('图片未完整取得');
    return window.URL.createObjectURL(blob);
  } finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}

export const legacyOrderFormImages = {
  data() { return {legacyFormImageRows: [], legacyFormImageGeneration: 0, legacyFormImageBlocked: false}; },
  computed: {
    legacyFormImagePlan() { return JSON.stringify([this.legacyFormOrder && this.legacyFormOrder.id, this.legacyFormOrder && this.legacyFormOrder.custom_form, this.legacyFormActive, this.legacyFormReadAllowed, this.$route.fullPath]); }
  },
  watch: {legacyFormImagePlan() { this.resetLegacyFormImages(); }},
  created() {
    this._legacyFormAlive = true;
    this._legacyFormStoreStop = this.$store.subscribe(() => this.syncLegacyFormIdentity());
    this._legacyFormEpochStop = subscribePlatformAuthEpoch(() => this.syncLegacyFormIdentity());
  },
  mounted() { this.resetLegacyFormImages(); },
  beforeDestroy() {
    this._legacyFormAlive = false; this.closeLegacyFormImages();
    if (this._legacyFormStoreStop) this._legacyFormStoreStop();
    if (this._legacyFormEpochStop) this._legacyFormEpochStop();
  },
  methods: {
    closeLegacyFormImages() {
      this.legacyFormImageGeneration++;
      if (this._legacyFormContext) closeContext(this._legacyFormContext);
      this._legacyFormContext = null;
      this.legacyFormImageRows.forEach(row => { if (row.owned && row.url) window.URL.revokeObjectURL(row.url); row.url = ''; });
      this.legacyFormImageRows = [];
      this._legacyFormSource = null; this._legacyFormPlan = '';
    },
    syncLegacyFormIdentity() {
      if (this._legacyFormContext && !currentContext(this._legacyFormContext)) { this.closeLegacyFormImages(); this.legacyFormImageBlocked = true; }
    },
    legacyFormImagesFor(field) {
      if (!this.legacyFormActive || this.legacyFormImageBlocked || this._legacyFormContext && !currentContext(this._legacyFormContext)) return [];
      const order = this.legacyFormOrder;
      if (!order || this._legacyFormSource !== order || this._legacyFormPlan !== this.legacyFormImagePlan || !Array.isArray(order.custom_form) || !Array.isArray(field.value)) return [];
      const index = order.custom_form.indexOf(field);
      return this.legacyFormImageRows.filter(row => row.fieldIndex === index && field.value[row.imageIndex] === row.reference);
    },
    legacyFormImageFailed(row) {
      if (!this.legacyFormImageRows.includes(row)) return;
      if (row.owned && row.url) window.URL.revokeObjectURL(row.url);
      row.url = ''; row.owned = false; row.error = '图片未能显示，请重新打开订单';
    },
    resetLegacyFormImages() {
      this.closeLegacyFormImages(); this.legacyFormImageBlocked = false;
      const order = this.legacyFormOrder;
      if (!this._legacyFormAlive || !this.legacyFormActive || !order || !Array.isArray(order.custom_form)) return;
      const generation = this.legacyFormImageGeneration, plan = this.legacyFormImagePlan;
      this._legacyFormSource = order; this._legacyFormPlan = plan;
      order.custom_form.forEach((field, fieldIndex) => {
        if (field && field.label === 'img' && Array.isArray(field.value)) field.value.forEach((reference, imageIndex) => {
          this.legacyFormImageRows.push({key: fieldIndex + ':' + imageIndex, fieldIndex, imageIndex, reference, url: privateRef(reference) ? '' : reference, private: privateRef(reference), owned: false, loading: privateRef(reference), error: ''});
        });
      });
      if (!this.legacyFormImageRows.some(row => row.private)) return;
      if (!this.legacyFormReadAllowed) { this.legacyFormImageRows.forEach(row => { if (row.private) { row.loading = false; row.error = '请从原订单查看图片'; } }); return; }
      let context;
      try { context = captureContext(this.$store); this._legacyFormContext = context; }
      catch (error) { this.legacyFormImageRows.forEach(row => { if (row.private) { row.loading = false; row.error = '身份暂未确认，请重新打开订单'; } }); return; }
      const current = () => this._legacyFormAlive && this.legacyFormActive && this._legacyFormContext === context && currentContext(context) &&
        generation === this.legacyFormImageGeneration && order === this.legacyFormOrder && plan === this.legacyFormImagePlan;
      const read = async () => {
        for (const row of this.legacyFormImageRows) {
          if (!current()) return;
          if (!row.private) continue;
          try {
            const url = await readImage(context, order.id, row.reference);
            if (!current()) { window.URL.revokeObjectURL(url); return; }
            row.url = url; row.owned = true;
          } catch (error) { if (current()) row.error = '图片未读取，请重新打开订单'; }
          finally { if (current()) row.loading = false; }
        }
      };
      read();
    }
  }
};
