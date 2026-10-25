import setting from '@/setting';
import { captureContext, currentContext, closeContext } from '@/api/marketplace-aftersale';
import { subscribePlatformAuthEpoch } from '@/libs/platform-auth-epoch';

const imageTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function unavailable() { return new Error('收款码未完整读取，请重新加载当前提现列表'); }
export function isPrivateWithdrawalQr(value) { return typeof value === 'string' && value.startsWith('mp-withdrawal:'); }
function id(value) {
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < 1 || value > 4294967295) throw unavailable(); return String(value);
}
function descriptor(source) {
  if (!source || !isPrivateWithdrawalQr(source.qrcode_url) || !['weixin', 'alipay'].includes(source.extract_type)) throw unavailable();
  const match = /^mp-withdrawal:v1:([1-9][0-9]{0,19})(?![\s\S])/.exec(source.qrcode_url);
  if (!match || match[1].length === 20 && match[1] > '18446744073709551615') throw unavailable();
  return { extract: id(source.id), uid: id(source.uid), asset: match[1], reference: source.qrcode_url, type: source.extract_type };
}
function targetURL(path) {
  const base = new URL(setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw unavailable();
  base.pathname = base.pathname.replace(/\/+$/, '') + '/'; const target = new URL(path, base);
  if (target.origin !== base.origin || target.pathname !== base.pathname + path || target.search || target.hash || target.username || target.password) throw unavailable(); return target;
}
async function transfer(context, path, method, ticket) {
  if (!currentContext(context)) throw unavailable(); const target = targetURL(path), controller = new AbortController(), cancel = () => controller.abort();
  context.life.cancels.add(cancel); const timer = window.setTimeout(cancel, 20000);
  const headers = { 'Authori-zation': 'Bearer ' + context.token };
  if (method === 'POST') headers['Content-Type'] = 'application/json'; if (ticket) headers['X-Withdrawal-Qr-Read-Ticket'] = ticket;
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
    const type = response.headers.get('Content-Type'), size = response.headers.get('Content-Length');
    if (!imageTypes.includes(type) || !/^[1-9][0-9]{0,8}(?![\s\S])/.test(size || '') || Number(size) > 52428800
      || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
    const bytes = await response.blob();
    if (!currentContext(context) || !(bytes instanceof Blob) || bytes.size !== Number(size) || bytes.type !== type) throw unavailable(); return bytes;
  } finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}

/** Existing finance list supplies real persisted row id/uid/reference. An asset id alone grants no read. */
export const withdrawalQrImages = {
  data: () => ({ withdrawalQrRows: [], withdrawalQrProblem: '', withdrawalQrGeneration: 0 }),
  created() {
    this._withdrawalQrAlive = true;
    this._withdrawalQrUnsubscribe = this.$store.subscribe(() => this.syncWithdrawalQrIdentity());
    this._withdrawalQrEpochUnsubscribe = subscribePlatformAuthEpoch(this.syncWithdrawalQrIdentity);
    window.addEventListener('focus', this.syncWithdrawalQrIdentity); window.addEventListener('storage', this.syncWithdrawalQrIdentity);
  },
  activated() { this._withdrawalQrAlive = true; this.resetWithdrawalQrImages(); },
  deactivated() { this._withdrawalQrAlive = false; this.closeWithdrawalQrImages(); },
  beforeDestroy() {
    this._withdrawalQrAlive = false; this.closeWithdrawalQrImages();
    if (this._withdrawalQrUnsubscribe) this._withdrawalQrUnsubscribe(); if (this._withdrawalQrEpochUnsubscribe) this._withdrawalQrEpochUnsubscribe();
    window.removeEventListener('focus', this.syncWithdrawalQrIdentity); window.removeEventListener('storage', this.syncWithdrawalQrIdentity);
  },
  watch: {
    withdrawalQrSources() { this.resetWithdrawalQrImages(); },
    '$route.fullPath'() { this.closeWithdrawalQrImages(); }
  },
  methods: {
    isPrivateWithdrawalQr,
    closeWithdrawalQrImages() {
      this.withdrawalQrGeneration++; this.withdrawalQrRows.forEach(row => { if (row.url) URL.revokeObjectURL(row.url); row.url = ''; });
      this.withdrawalQrRows = []; if (this._withdrawalQrContext) closeContext(this._withdrawalQrContext); this._withdrawalQrContext = null;
    },
    syncWithdrawalQrIdentity() {
      if (this._withdrawalQrContext && !currentContext(this._withdrawalQrContext)) { this.closeWithdrawalQrImages(); this.withdrawalQrProblem = '登录身份已变化，请重新读取提现列表'; }
    },
    resetWithdrawalQrImages() {
      this.closeWithdrawalQrImages(); this.withdrawalQrProblem = '';
      if (!this._withdrawalQrAlive) return;
      try {
        if (!Array.isArray(this.withdrawalQrSources)) throw unavailable();
        const selected = this.withdrawalQrSources.filter(source => isPrivateWithdrawalQr(source.qrcode_url)); if (!selected.length) return;
        this._withdrawalQrContext = captureContext(this.$store); this._withdrawalQrRoute = this.$route.fullPath;
        this._withdrawalQrSources = this.withdrawalQrSources;
        const seen = new Set();
        this.withdrawalQrRows = selected.map(source => {
          const value = descriptor(source); if (seen.has(value.extract)) throw unavailable(); seen.add(value.extract);
          return { source, descriptor: value, wire: JSON.stringify(value), url: '', error: '', loading: false };
        }); this.withdrawalQrRows.forEach(row => this.loadWithdrawalQrImage(row));
      } catch (error) { this.closeWithdrawalQrImages(); this.withdrawalQrProblem = error.message || '收款码格式未完整取得'; }
    },
    withdrawalQrCurrent(row) {
      try { return this._withdrawalQrAlive && this._withdrawalQrContext && currentContext(this._withdrawalQrContext)
        && this.$route.fullPath === this._withdrawalQrRoute && this.withdrawalQrSources === this._withdrawalQrSources
        && this.withdrawalQrSources.includes(row.source) && this.withdrawalQrRows.includes(row) && JSON.stringify(descriptor(row.source)) === row.wire; }
      catch (error) { return false; }
    },
    withdrawalQrImageFor(source) { return this.withdrawalQrRows.find(row => row.source === source && this.withdrawalQrCurrent(row)) || null; },
    withdrawalQrImageFailed(row) { if (!row) return; if (row.url) URL.revokeObjectURL(row.url); row.url = ''; row.error = '原图未能显示，请重新加载提现列表'; },
    async loadWithdrawalQrImage(row) {
      if (row.loading || !this.withdrawalQrCurrent(row)) return;
      const generation = this.withdrawalQrGeneration, context = this._withdrawalQrContext;
      const current = () => this.withdrawalQrGeneration === generation && this._withdrawalQrContext === context && this.withdrawalQrCurrent(row);
      row.loading = true; row.error = '';
      try {
        const path = 'finance/extract/' + row.descriptor.extract + '/qr/' + row.descriptor.asset;
        const ticket = await transfer(context, path + '/read-ticket', 'POST'); if (!current()) throw unavailable();
        const blob = await transfer(context, path + '/content', 'GET', ticket); if (!current()) throw unavailable();
        const url = URL.createObjectURL(blob); if (!current()) { URL.revokeObjectURL(url); throw unavailable(); }
        if (row.url) URL.revokeObjectURL(row.url); row.url = url;
      } catch (error) { if (current()) row.error = error.message || '收款码读取未完成'; }
      finally { if (current()) row.loading = false; }
    }
  }
};
