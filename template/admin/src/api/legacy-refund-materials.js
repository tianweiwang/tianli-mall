import setting from '@/setting';
import { captureContext, currentContext, closeContext } from '@/api/marketplace-aftersale';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';

const imageTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function unavailable() { return new Error('退款材料读取未完成，请重新加载当前退款'); }
function refundId(value) {
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < 1 || value > 2147483647) throw unavailable(); return String(value);
}
function descriptors(source) {
  if (!source || !source.refund_materials) return [];
  refundId(source.id); const groups = source.refund_materials, rows = [], seen = new Set();
  if (Object.keys(groups).sort().join(',') !== 'application,schema,shipping' || groups.schema !== 1) throw unavailable();
  ['application', 'shipping'].forEach((group, index) => {
    if (!Array.isArray(groups[group])) throw unavailable();
    groups[group].forEach(value => {
      if (!value || Object.keys(value).sort().join(',') !== 'asset_id,attachment_ref,byte_size,height,media_type,width') throw unavailable();
      const purpose = index === 0 ? 'legacy_refund_evidence' : 'refund_shipping_proof';
      if (typeof value.asset_id !== 'string' || !/^[1-9][0-9]{0,19}(?![\s\S])/.test(value.asset_id) || value.asset_id.length === 20 && value.asset_id > '18446744073709551615'
        || value.attachment_ref !== 'mp-refund:v1:' + purpose + ':' + value.asset_id || seen.has(value.asset_id) || !imageTypes.includes(value.media_type)
        || !Number.isSafeInteger(value.byte_size) || value.byte_size < 1 || value.byte_size > 52428800 || !Number.isSafeInteger(value.width) || value.width < 1
        || !Number.isSafeInteger(value.height) || value.height < 1) throw unavailable();
      seen.add(value.asset_id); rows.push({ group, source, descriptor: value, key: refundId(source.id) + ':' + value.asset_id,
        url: '', loading: false, error: '' });
    });
  }); return rows;
}
function targetURL(path) {
  const base = new URL(setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw unavailable();
  base.pathname = base.pathname.replace(/\/+$/, '') + '/'; const target = new URL(path, base);
  if (target.origin !== base.origin || target.pathname !== base.pathname + path || target.search || target.hash || target.username || target.password) throw unavailable(); return target;
}
async function transfer(context, path, method, descriptor, ticket) {
  if (!currentContext(context)) throw unavailable(); const target = targetURL(path), controller = new AbortController(), cancel = () => controller.abort();
  context.life.cancels.add(cancel); const timer = window.setTimeout(cancel, 20000);
  const headers = { 'Authori-zation': 'Bearer ' + context.token };
  if (method === 'POST') headers['Content-Type'] = 'application/json'; if (ticket) headers['X-Legacy-Refund-Read-Ticket'] = ticket;
  try {
    const response = await fetch(target.href, { method, headers, body: method === 'POST' ? '{"schema":1}' : undefined,
      mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
    if (!currentContext(context) || response.status !== 200 || response.redirected) throw unavailable();
    if (method === 'POST') {
      const envelope = await response.json(), value = envelope && envelope.data;
      if (!currentContext(context) || envelope.status !== 200 || !value || Object.keys(value).sort().join(',') !== 'expires_at_ms,issued_at_ms,read_ticket'
        || typeof value.read_ticket !== 'string' || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(value.read_ticket)
        || !Number.isSafeInteger(value.issued_at_ms) || !Number.isSafeInteger(value.expires_at_ms) || value.expires_at_ms - value.issued_at_ms !== 300000) throw unavailable();
      return value.read_ticket;
    }
    if (response.headers.get('Content-Type') !== descriptor.media_type || response.headers.get('Content-Length') !== String(descriptor.byte_size)
      || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
    const bytes = await response.blob();
    if (!currentContext(context) || !(bytes instanceof Blob) || bytes.size !== descriptor.byte_size || bytes.type !== descriptor.media_type) throw unavailable(); return bytes;
  } finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}

/** Actual original list/detail components supply saved sources and their fixed reader kind. */
export const legacyRefundMaterialImages = {
  data: () => ({ legacyPlatformRows: [], legacyPlatformProblem: '', legacyPlatformGeneration: 0 }),
  created() {
    this._legacyPlatformAlive = true;
    this._legacyPlatformUnsubscribe = this.$store.subscribe(() => this.syncLegacyMaterialIdentity());
    this._legacyPlatformEpochUnsubscribe = subscribePlatformAuthEpoch(this.syncLegacyMaterialIdentity);
    window.addEventListener('focus', this.syncLegacyMaterialIdentity); window.addEventListener('storage', this.syncLegacyMaterialIdentity);
    this.resetLegacyMaterialImages();
  },
  beforeDestroy() {
    this._legacyPlatformAlive = false; this.closeLegacyMaterialImages();
    if (this._legacyPlatformUnsubscribe) this._legacyPlatformUnsubscribe(); if (this._legacyPlatformEpochUnsubscribe) this._legacyPlatformEpochUnsubscribe();
    window.removeEventListener('focus', this.syncLegacyMaterialIdentity); window.removeEventListener('storage', this.syncLegacyMaterialIdentity);
  },
  watch: {
    legacyRefundSources() { this.resetLegacyMaterialImages(); },
    legacyRefundActive() { this.resetLegacyMaterialImages(); },
    '$route.fullPath'() { this.closeLegacyMaterialImages(); }
  },
  methods: {
    closeLegacyMaterialImages() {
      this.legacyPlatformGeneration++; this.legacyPlatformRows.forEach(row => { if (row.url) URL.revokeObjectURL(row.url); row.url = ''; });
      this.legacyPlatformRows = []; if (this._legacyPlatformContext) closeContext(this._legacyPlatformContext); this._legacyPlatformContext = null;
    },
    syncLegacyMaterialIdentity() { if (this._legacyPlatformContext && !currentContext(this._legacyPlatformContext)) { this.closeLegacyMaterialImages(); this.legacyPlatformProblem = '登录身份已变化，请重新读取退款列表或详情'; } },
    resetLegacyMaterialImages() {
      if (this.legacyMaterialCurrent()) return;
      this.closeLegacyMaterialImages(); this.legacyPlatformProblem = '';
      if (!this._legacyPlatformAlive || !this.legacyRefundActive) return;
      try {
        if (!['list', 'detail'].includes(this.legacyRefundReader) || !Array.isArray(this.legacyRefundSources)) throw unavailable();
        this._legacyPlatformContext = captureContext(this.$store); if (!currentContext(this._legacyPlatformContext)) throw unavailable();
        this._legacyPlatformRoute = this.$route.fullPath; this._legacyPlatformReader = this.legacyRefundReader;
        this._legacyPlatformSources = this.legacyRefundSources.slice(); this._legacyPlatformWire = JSON.stringify(this._legacyPlatformSources.map(source => source.refund_materials));
        this.legacyPlatformRows = this._legacyPlatformSources.flatMap(descriptors); this.legacyPlatformRows.forEach(row => this.loadLegacyMaterialImage(row));
      } catch (error) { this.closeLegacyMaterialImages(); this.legacyPlatformProblem = error.message || '退款材料格式未完整取得'; }
    },
    legacyMaterialCurrent() {
      try { return this._legacyPlatformAlive && this.legacyRefundActive && this._legacyPlatformContext && currentContext(this._legacyPlatformContext)
        && this._legacyPlatformReader === this.legacyRefundReader
        && this._legacyPlatformRoute === this.$route.fullPath && JSON.stringify(this.legacyRefundSources.map(source => source.refund_materials)) === this._legacyPlatformWire
        && this.legacyRefundSources.length === this._legacyPlatformSources.length && this.legacyRefundSources.every((source, index) => source === this._legacyPlatformSources[index]); }
      catch (error) { return false; }
    },
    legacyMaterialImagesFor(source, group) { return this.legacyMaterialCurrent() ? this.legacyPlatformRows.filter(row => row.source === source && row.group === group) : []; },
    legacyMaterialImageFailed(row) { if (row.url) URL.revokeObjectURL(row.url); row.url = ''; row.error = '原图未能显示，请重新读取'; },
    async loadLegacyMaterialImage(row) {
      if (row.loading || !this.legacyMaterialCurrent() || !this.legacyPlatformRows.includes(row)) return;
      const generation = this.legacyPlatformGeneration, context = this._legacyPlatformContext, reader = this.legacyRefundReader;
      const current = () => this.legacyMaterialCurrent() && this.legacyPlatformGeneration === generation && this._legacyPlatformContext === context
        && this.legacyRefundReader === reader && this.legacyPlatformRows.includes(row);
      row.loading = true; row.error = '';
      try {
        const matches = descriptors(row.source).filter(value => value.key === row.key && value.descriptor === row.descriptor);
        if (matches.length !== 1) throw unavailable();
        const path = 'refund/' + reader + '/' + refundId(row.source.id) + '/materials/' + row.descriptor.asset_id;
        const ticket = await transfer(context, path + '/read-ticket', 'POST'); if (!current()) throw unavailable();
        const blob = await transfer(context, path + '/content', 'GET', row.descriptor, ticket); if (!current()) throw unavailable();
        const url = URL.createObjectURL(blob); if (!current()) { URL.revokeObjectURL(url); throw unavailable(); }
        if (row.url) URL.revokeObjectURL(row.url); row.url = url;
      } catch (error) { if (current()) row.error = error.message || '原图读取未完成'; }
      finally { if (current()) row.loading = false; }
    }
  }
};
