import setting from '@/setting';
import { captureContext, currentContext, closeContext } from '@/api/marketplace-aftersale';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';
const apiBase = setting.apiBaseURL;
const authHeader = 'Authori-zation';
const orderPath = id => 'order/info/' + id;
function canRead(context) { return currentContext(context); }
const subscribeEpoch = subscribePlatformAuthEpoch;
// Same existing platform detail route and pagination; the caller applies the existing saved-root projection validator.
export async function readSavedOrderFormRoot(context, locator) {
  const id = rootId(locator); if (!id) throw unavailable();
  return transfer(context, orderPath(id), 'GET', false, null, { fulfillment_page: 1, fulfillment_limit: 20 });
}
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const imageTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function unavailable() { return new Error('表单图片读取未完成，请重新加载订单'); }
function requireCurrent(context) { if (!canRead(context)) throw unavailable(); }
function rootId(value) {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value > 0 && value <= 2147483647 ? String(value) : '';
  return typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value) && (value.length < 10 || value <= '2147483647') ? value : '';
}
function assetId(ref) {
  if (typeof ref !== 'string' || !ref.startsWith('mp-form:v1:')) throw unavailable();
  const value = ref.slice('mp-form:v1:'.length);
  if (!/^[1-9][0-9]{0,19}(?![\s\S])/.test(value) || value.length === 20 && value > '18446744073709551615') throw unavailable();
  return value;
}
function ticketValue(value) {
  const keys = ['read_ticket', 'issued_at_ms', 'expires_at_ms'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length !== keys.length || keys.some(key => !own(value, key))
    || typeof value.read_ticket !== 'string' || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(value.read_ticket)
    || !Number.isSafeInteger(value.issued_at_ms) || value.issued_at_ms < 1000000000000
    || !Number.isSafeInteger(value.expires_at_ms) || value.expires_at_ms <= value.issued_at_ms
    || value.expires_at_ms - value.issued_at_ms > 300000 || value.expires_at_ms <= Date.now()) throw unavailable();
  return value;
}
function targetURL(path) {
  const base = new URL(apiBase, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw unavailable();
  base.pathname = base.pathname.replace(/\/+$/, '') + '/'; const target = new URL(path, base);
  if (target.origin !== base.origin || target.pathname !== base.pathname + path || target.search || target.hash || target.username || target.password) throw unavailable();
  return target;
}
async function transfer(context, path, method, binary, ticket, query) {
  requireCurrent(context); const target = targetURL(path);
  if (query) Object.keys(query).forEach(key => target.searchParams.set(key, String(query[key])));
  const controller = new AbortController(); const cancel = () => controller.abort(); context.life.cancels.add(cancel);
  const timer = window.setTimeout(cancel, 20000);
  const headers = { [authHeader]: 'Bearer ' + context.token };
  if (method === 'POST') headers['Content-Type'] = 'application/json';
  if (ticket) headers['X-Order-Form-Read-Ticket'] = ticket.read_ticket;
  try {
    const response = await fetch(target.href, { method, headers, body: method === 'POST' ? '{"schema":1}' : undefined,
      mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
    requireCurrent(context);
    if (response.status !== 200 || response.redirected) throw unavailable();
    if (!binary) {
      const envelope = await response.json(); requireCurrent(context);
      if (!envelope || envelope.status !== 200 || typeof envelope.msg !== 'string' || !own(envelope, 'data')) throw unavailable();
      return envelope.data;
    }
    const type = response.headers.get('Content-Type'); const length = response.headers.get('Content-Length');
    if (!imageTypes.includes(type) || typeof length !== 'string' || !/^[1-9][0-9]*(?![\s\S])/.test(length)
      || !Number.isSafeInteger(Number(length)) || response.headers.get('X-Content-Type-Options') !== 'nosniff'
      || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
    const blob = await response.blob(); requireCurrent(context);
    if (!(blob instanceof Blob) || blob.size !== Number(length) || blob.type !== type) throw unavailable();
    return blob;
  } catch (error) { requireCurrent(context); throw unavailable(); }
  finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}
function savedBinding(root, image) {
  if (!root || root.marketplace_trade_version !== 1 && root.marketplace_trade_version !== '1' || !rootId(root.id) || !Array.isArray(root.custom_form)) throw unavailable();
  const group = root.custom_form.find(group => group.product_id === image.productId && group.definition_sha256 === image.definition);
  const field = group && Array.isArray(group.fields) && group.fields.find(field => field.field_index === image.fieldIndex);
  if (!field || field.label !== 'img' || !Array.isArray(field.value) || field.value[image.imageIndex] !== image.ref || assetId(image.ref) !== image.assetId) throw unavailable();
  return rootId(root.id);
}
// The saved root supplies a locator only. Both HTTP endpoints rebuild current server authorization.
export async function privateOrderFormImage(context, root, image) {
  requireCurrent(context); const id = savedBinding(root, image); const path = orderPath(id) + '/form-files/' + image.assetId;
  const ticket = ticketValue(await transfer(context, path + '/read-ticket', 'POST', false));
  requireCurrent(context); if (savedBinding(root, image) !== id) throw unavailable();
  const blob = await transfer(context, path + '/content', 'GET', true, ticket);
  requireCurrent(context); if (savedBinding(root, image) !== id) throw unavailable();
  const url = window.URL.createObjectURL(blob);
  if (!canRead(context)) { window.URL.revokeObjectURL(url); throw unavailable(); }
  return url;
}
export function imageRowsFor(rows, group, field) {
  if (!Array.isArray(field.value)) return [];
  return field.value.map((ref, index) => rows.find(image => image.productId === group.product_id && image.definition === group.definition_sha256
    && image.fieldIndex === field.field_index && image.imageIndex === index && image.ref === ref)).filter(Boolean);
}
function displayRows(groups) {
  const rows = [];
  groups.forEach(group => group.fields.forEach(field => {
    if (field.label !== 'img' || !Array.isArray(field.value)) return;
    field.value.forEach((ref, imageIndex) => {
      const image = { key: String(group.product_id) + ':' + group.definition_sha256 + ':' + field.field_index + ':' + imageIndex,
        productId: group.product_id, definition: group.definition_sha256, fieldIndex: field.field_index, imageIndex, ref,
        assetId: '', private: typeof ref === 'string' && ref.startsWith('mp-form:'), url: '', owned: false, loading: false, loaded: false, error: '' };
      if (image.private) {
        try {
          image.assetId = assetId(ref);
          if (!Number.isSafeInteger(image.productId) || image.productId < 1 || image.productId > 8388607
            || typeof image.definition !== 'string' || !/^[0-9a-f]{64}(?![\s\S])/.test(image.definition)
            || !Number.isInteger(image.fieldIndex) || image.fieldIndex < 0 || image.fieldIndex > 9) throw unavailable();
        } catch (error) { image.error = unavailable().message; }
      } else image.url = ref; // Preserve the original legacy/default URL branch without changing saved values.
      rows.push(image);
    });
  }));
  return rows;
}
export const orderFormImages = {
  data: () => ({ formImageRows: [], formImageContext: null, formImageGeneration: 0, formImageAlive: true,
    formImageSourceValue: null, formImageGroupsValue: null, formImageWire: '', formImageRoute: '', formImageBlocked: false, formImageUnsubscribe: null, formImageEpochUnsubscribe: null }),
  computed: {
    visibleFormImages() {
      if (!this.formImageActive || this.formImageSourceValue !== this.formImageSource || this.formImageGroupsValue !== this.formImageGroups
        || this.formImageWire !== JSON.stringify(this.formImageGroups) || this.formImageRoute !== this.$route.fullPath) return [];
      if (this.formImageContext && !canRead(this.formImageContext)) return [];
      return this.formImageRows;
    }
  },
  created() {
    this.formImageUnsubscribe = this.$store.subscribe(() => this.syncFormImageIdentity());
    window.addEventListener('focus', this.syncFormImageIdentity); window.addEventListener('storage', this.syncFormImageIdentity);
    this.formImageEpochUnsubscribe = subscribeEpoch(this.syncFormImageIdentity); this.resetFormImages();
  },
  watch: {
    formImageActive() { this.resetFormImages(); },
    formImageSource() { this.resetFormImages(); },
    formImageGroups: { handler() { this.resetFormImages(); }, deep: true },
    '$route.fullPath'() { this.resetFormImages(); }
  },
  beforeDestroy() {
    this.formImageAlive = false; if (this.formImageUnsubscribe) this.formImageUnsubscribe(); if (this.formImageEpochUnsubscribe) this.formImageEpochUnsubscribe();
    window.removeEventListener('focus', this.syncFormImageIdentity); window.removeEventListener('storage', this.syncFormImageIdentity); this.clearFormImages();
  },
  methods: {
    formImagesFor(group, field) { return imageRowsFor(this.visibleFormImages, group, field); },
    formImageLoaded(image) { if (this.visibleFormImages.includes(image) && image.url) image.loaded = true; },
    formImageFailed(image) {
      if (!this.visibleFormImages.includes(image)) return;
      if (image.owned && image.url) window.URL.revokeObjectURL(image.url);
      image.url = ''; image.owned = false; image.loaded = false; image.error = '图片未能显示，请重新加载订单';
    },
    clearFormImages() {
      closeContext(this.formImageContext); this.formImageContext = null; this.formImageGeneration += 1;
      this.formImageRows.forEach(image => { if (image.owned && image.url) window.URL.revokeObjectURL(image.url); image.url = ''; image.owned = false; });
      this.formImageRows = []; this.formImageSourceValue = null; this.formImageGroupsValue = null; this.formImageWire = ''; this.formImageRoute = ''; this.formImageBlocked = false;
    },
    syncFormImageIdentity() {
      if (!this.formImageContext || canRead(this.formImageContext)) return;
      closeContext(this.formImageContext); this.formImageContext = null; this.formImageGeneration += 1; this.formImageBlocked = true;
      this.formImageRows.forEach(image => {
        if (image.owned && image.url) window.URL.revokeObjectURL(image.url);
        image.url = ''; image.owned = false; image.loaded = false; image.loading = false; image.error = '身份或权限已变化，请重新加载订单';
      });
    },
    formImagesCurrent(context, generation, source, groups, wire, route) {
      if (this.formImageContext === context && !canRead(context)) { this.syncFormImageIdentity(); return false; }
      return this.formImageAlive && !this.formImageBlocked && this.formImageActive && this.formImageContext === context
        && generation === this.formImageGeneration && source === this.formImageSource && groups === this.formImageGroups
        && wire === JSON.stringify(this.formImageGroups) && route === this.$route.fullPath && canRead(context);
    },
    async resetFormImages() {
      this.clearFormImages();
      if (!this.formImageAlive || !this.formImageActive || !this.formImageSource || !Array.isArray(this.formImageGroups)) return;
      const source = this.formImageSource; const groups = this.formImageGroups; const wire = JSON.stringify(groups); const route = this.$route.fullPath;
      this.formImageSourceValue = source; this.formImageGroupsValue = groups; this.formImageWire = wire; this.formImageRoute = route;
      const rows = displayRows(groups); this.formImageRows = rows;
      const queue = rows.filter(image => image.private && !image.error); if (!queue.length) return;
      const generation = this.formImageGeneration; let context;
      try {
        context = captureContext(this.$store); this.formImageContext = context; requireCurrent(context);
        const current = () => this.formImagesCurrent(context, generation, source, groups, wire, route);
        const root = await this.resolveFormImageRoot(context, source);
        if (!current()) return;
        const worker = async () => {
          while (queue.length && current()) {
            const image = queue.shift(); image.loading = true;
            try {
              const url = await privateOrderFormImage(context, root, image);
              if (!current() || !this.formImageRows.includes(image)) { window.URL.revokeObjectURL(url); return; }
              image.url = url; image.owned = true;
            } catch (error) { if (current() && this.formImageRows.includes(image)) image.error = unavailable().message; }
            finally { if (current() && this.formImageRows.includes(image)) image.loading = false; }
          }
        };
        await Promise.all([worker(), worker(), worker()]);
      } catch (error) {
        if (generation === this.formImageGeneration && source === this.formImageSource && route === this.$route.fullPath) {
          rows.forEach(image => { if (image.private && !image.url) { image.error = unavailable().message; image.loading = false; } });
        }
      }
    }
  }
};
