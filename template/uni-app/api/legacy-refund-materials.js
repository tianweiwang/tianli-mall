import store from '../store';
import { createOrderFormPrivateScope } from './marketplace-order-form-private';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';

const types = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const purposes = ['legacy_refund_evidence', 'refund_shipping_proof'];
function unavailable(message = '退款材料未完整读取，请重新读取当前退款') { return new Error(message); }
function stale() { const error = unavailable('登录身份或退款页面已变化，请重新进入'); error.staleIdentity = true; return error; }
function own(value, key) { return Object.prototype.hasOwnProperty.call(value, key); }
function operationReceipt(value) {
  const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join(',') !== keys.join(',')
    || value.complete !== false || typeof value.physical_complete !== 'boolean' || typeof value.phase !== 'string'
    || !/^[a-z][a-z0-9_]{0,63}(?![\s\S])/.test(value.phase)
    || !['cleanup_complete', 'database_committed', 'physical_changed'].every(key => value[key] === null || typeof value[key] === 'boolean')) return null;
  return Object.freeze(keys.reduce((out, key) => { out[key] = value[key]; return out; }, {}));
}
function responseFailure(message, data, unconfirmed = false) {
  const error = unavailable(message); error.resultUnconfirmed = unconfirmed;
  const receipt = operationReceipt(data); if (receipt) error.operationReceipt = receipt; return error;
}
function cartIntent(rows, idKey) {
  if (!Array.isArray(rows) || !rows.length) return null;
  const ids = new Set(), result = [];
  for (const row of rows) {
    if (!row || typeof row !== 'object') return null;
    const value = row[idKey], id = typeof value === 'string' ? value : Number.isSafeInteger(value) ? String(value) : '';
    const quantity = typeof row.cart_num === 'string' && /^[1-9][0-9]*(?![\s\S])/.test(row.cart_num) ? Number(row.cart_num) : row.cart_num;
    if (!/^[1-9][0-9]{0,19}(?![\s\S])/.test(id) || id.length === 20 && id > '18446744073709551615'
      || ids.has(id) || !Number.isSafeInteger(quantity) || quantity < 1) return null;
    ids.add(id); result.push({ cart_id: id, cart_num: quantity });
  }
  return result.sort((a, b) => a.cart_id.length - b.cart_id.length || (a.cart_id < b.cart_id ? -1 : a.cart_id > b.cart_id ? 1 : 0));
}
function applicationIntent(body, source, uploaded) {
  // A zero-image or incomplete legacy intent has no request-specific association; leave its result unknown.
  try {
    if (![1, 2].includes(body.refund_type) || typeof body.refund_reason_wap_img !== 'string') return null;
    const refs = body.refund_reason_wap_img.split(',');
    if (!refs.length || new Set(refs).size !== refs.length || !refs.every(ref => uploaded.some(raw => {
      const row = legacyRefundMaterial(raw); return row.attachment_ref === ref && ref.startsWith('mp-refund:v1:legacy_refund_evidence:');
    }))) return null;
    const available = cartIntent(source, 'id');
    const selected = Array.isArray(body.cart_ids) && body.cart_ids.length ? cartIntent(body.cart_ids, 'cart_id') : available;
    if (!available || !selected || !selected.every(row => available.some(item => item.cart_id === row.cart_id && item.cart_num >= row.cart_num))) return null;
    const quantity = selected.reduce((sum, row) => sum + row.cart_num, 0); if (!Number.isSafeInteger(quantity)) return null;
    return Object.freeze({ refund_type: body.refund_type, refs: Object.freeze(refs), cart: Object.freeze(selected.map(Object.freeze)), quantity });
  } catch (error) { return null; }
}
export function legacyRefundId(value) {
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < 1 || value > 2147483647) throw unavailable(); return String(value);
}
export function legacyRefundMaterial(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw unavailable();
  const keys = ['attachment_ref', 'asset_id', 'media_type', 'byte_size', 'width', 'height'];
  if (!keys.every(key => own(value, key)) || Object.keys(value).some(key => !keys.includes(key) && key !== 'name')) throw unavailable();
  const match = typeof value.attachment_ref === 'string' && /^mp-refund:v1:(legacy_refund_evidence|refund_shipping_proof):([1-9][0-9]{0,19})(?![\s\S])/.exec(value.attachment_ref);
  if (!match || value.asset_id !== match[2] || match[2].length === 20 && match[2] > '18446744073709551615' || !types.includes(value.media_type)
    || !Number.isSafeInteger(value.byte_size) || value.byte_size < 1 || value.byte_size > 52428800
    || !Number.isSafeInteger(value.width) || value.width < 1 || !Number.isSafeInteger(value.height) || value.height < 1) throw unavailable();
  return { attachment_ref: value.attachment_ref, asset_id: value.asset_id, media_type: value.media_type,
    byte_size: value.byte_size, width: value.width, height: value.height };
}
export function legacyRefundGroups(value) {
  if (!value || !value.refund_materials) return { application: [], shipping: [] };
  const groups = value.refund_materials;
  if (groups.schema !== 1 || Object.keys(groups).sort().join(',') !== 'application,schema,shipping'
    || !Array.isArray(groups.application) || !Array.isArray(groups.shipping)) throw unavailable();
  const result = { application: [], shipping: [] }, ids = new Set();
  ['application', 'shipping'].forEach((group, i) => groups[group].forEach(raw => {
    const row = legacyRefundMaterial(raw);
    if (!row.attachment_ref.startsWith('mp-refund:v1:' + purposes[i] + ':') || ids.has(row.asset_id)) throw unavailable();
    ids.add(row.asset_id); result[group].push(row);
  })); return result;
}

/** Reuses the existing verified customer/page identity guard; server endpoints reauthorize every operation. */
export async function openLegacyRefundScope(realScope) {
  const token = store.state.app.token, revision = store.state.app.authRevision;
  if (typeof realScope !== 'function' || realScope() !== true) throw stale();
  await store.dispatch('USERINFO', true);
  if (realScope() !== true || token !== store.state.app.token || revision !== store.state.app.authRevision) throw stale();
  const guard = createOrderFormPrivateScope(realScope), tasks = new Set(), paths = new Set(); let alive = true;
  function current() { return alive && guard.current(); }
  function requireCurrent() { if (!current()) throw stale(); }
  function headers(json = false, ticket = null) {
    requireCurrent(); const out = { ...HEADER };
    Object.keys(out).forEach(key => { if (key.toLowerCase() === 'content-type' || key.toLowerCase() === TOKENNAME.toLowerCase()) delete out[key]; });
    out[TOKENNAME] = 'Bearer ' + token; if (json) out['Content-Type'] = 'application/json';
    if (ticket) out['X-Legacy-Refund-Read-Ticket'] = ticket; return out;
  }
  function release(path) {
    if (!paths.has(path)) return; paths.delete(path);
    // #ifdef H5
    if (typeof path === 'string' && path.startsWith('blob:')) URL.revokeObjectURL(path);
    // #endif
    // #ifdef MP
    if (typeof uni.getFileSystemManager === 'function') uni.getFileSystemManager().unlink({ filePath: path, fail() {} });
    // #endif
    // #ifdef APP-PLUS
    if (typeof plus !== 'undefined' && plus.io) plus.io.resolveLocalFileSystemURL(path, entry => entry.remove(() => {}, () => {}), () => {});
    // #endif
  }
  function dispose() { if (!alive) return; alive = false; guard.dispose(); tasks.forEach(task => { if (task && task.abort) task.abort(); }); tasks.clear(); paths.forEach(release); }
  function json(method, path, data) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else fn(value); };
      const failure = (message, data) => responseFailure(message, data, method === 'POST');
      try {
        task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path, method, data: data || {}, header: headers(true), timeout: TIMEOUT,
          success(response) { finish(() => {
            const body = response.data;
            if (response.statusCode !== 200 || !body || body.status !== 200 || typeof body.msg !== 'string') reject(failure(body && body.msg || '保存结果未确认，请先重新读取', body && body.data));
            else resolve(body);
          }); }, fail() { finish(reject, failure('保存结果未确认，请先重新读取')); }, complete() { if (task) tasks.delete(task); } });
        if (task) tasks.add(task);
      } catch (error) { finish(reject, failure('操作结果未确认，请先重新读取')); }
    });
  }
  async function upload(kind, id, filePath) {
    id = legacyRefundId(id); if (!['application', 'shipping'].includes(kind) || typeof filePath !== 'string' || !filePath) throw unavailable('请选择图片');
    const path = kind === 'application' ? 'order/refund/legacy/orders/' + id + '/materials' : 'order/refund/legacy/' + id + '/shipping-materials';
    requireCurrent(); const raw = await new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else fn(value); };
      try {
        task = uni.uploadFile({ url: HTTP_REQUEST_URL + '/api/' + path, filePath, name: 'pics', fileType: 'image', formData: { filename: 'pics' }, header: headers(), timeout: TIMEOUT,
          success(response) { finish(() => { let body; try { body = JSON.parse(response.data); } catch (error) { reject(unavailable('上传结果未确认，请保留草稿')); return; }
            if (response.statusCode !== 200 || !body || body.status !== 200) reject(responseFailure(body && body.msg || '图片上传未完成', body && body.data, true)); else resolve(body.data); }); },
          fail() { finish(reject, unavailable('图片上传未完成，请保留草稿')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
    requireCurrent(); const row = legacyRefundMaterial(raw);
    if (!row.attachment_ref.startsWith('mp-refund:v1:' + purposes[kind === 'application' ? 0 : 1] + ':')) throw unavailable(); return row;
  }
  async function image(kind, context, descriptor) {
    const id = legacyRefundId(context), row = legacyRefundMaterial(descriptor); requireCurrent();
    let base;
    if (kind === 'application') base = 'order/refund/legacy/orders/' + id + '/materials/';
    else if (kind === 'customer') base = 'order/refund/legacy/' + id + '/materials/';
    else if (kind === 'mobile') base = 'admin/refund_order/detail/' + id + '/materials/';
    else throw unavailable();
    const path = base + row.asset_id, envelope = await json('POST', path + '/read-ticket', { schema: 1 }), ticket = envelope.data;
    if (!ticket || Object.keys(ticket).sort().join(',') !== 'expires_at_ms,issued_at_ms,read_ticket' || typeof ticket.read_ticket !== 'string'
      || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(ticket.read_ticket) || !Number.isSafeInteger(ticket.issued_at_ms)
      || !Number.isSafeInteger(ticket.expires_at_ms) || ticket.expires_at_ms - ticket.issued_at_ms !== 300000) throw unavailable();
    requireCurrent(); let localPath;
    // #ifdef H5
    const controller = new AbortController(); tasks.add(controller); const timer = setTimeout(() => controller.abort(), TIMEOUT);
    try {
      const target = new URL(HTTP_REQUEST_URL + '/api/' + path + '/content', window.location.href);
      if (target.origin !== window.location.origin || target.username || target.password || target.search || target.hash) throw unavailable();
      const response = await fetch(target.href, { method: 'GET', headers: headers(false, ticket.read_ticket), mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
      requireCurrent(); const type = response.headers.get('Content-Type'), size = response.headers.get('Content-Length');
      if (response.status !== 200 || response.redirected || type !== row.media_type || size !== String(row.byte_size)
        || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
      const blob = await response.blob(); requireCurrent(); if (blob.size !== row.byte_size || blob.type !== row.media_type) throw unavailable(); localPath = URL.createObjectURL(blob);
    } finally { clearTimeout(timer); tasks.delete(controller); }
    // #endif
    // #ifndef H5
    localPath = await new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) { if (typeof value === 'string') { paths.add(value); release(value); } reject(stale()); } else fn(value); };
      task = uni.downloadFile({ url: HTTP_REQUEST_URL + '/api/' + path + '/content', header: headers(false, ticket.read_ticket), timeout: TIMEOUT,
        success(response) { const file = response.tempFilePath; if (response.statusCode !== 200 || typeof file !== 'string' || !file) { if (file) { paths.add(file); release(file); } finish(reject, unavailable()); } else finish(resolve, file); },
        fail() { finish(reject, unavailable()); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
    });
    // #endif
    paths.add(localPath);
    try {
      // #ifndef H5
      await new Promise((resolve, reject) => uni.getFileInfo({ filePath: localPath, success(info) { if (info.size !== row.byte_size) reject(unavailable()); else resolve(); }, fail() { reject(unavailable()); } }));
      // #endif
      await new Promise((resolve, reject) => uni.getImageInfo({ src: localPath, success(info) { if (info.width !== row.width || info.height !== row.height) reject(unavailable()); else resolve(); }, fail() { reject(unavailable()); } }));
      requireCurrent(); return { url: localPath, release: () => release(localPath) };
    } catch (error) { release(localPath); throw error; }
  }
  function matchesApplication(row, id, body, intent) {
    if (!intent || !row || legacyRefundId(row.store_order_id) !== id || Number(row.refund_type) !== intent.refund_type
      || ![1, 2].includes(Number(row.refund_type)) || Number(row.is_cancel) !== 0 || Number(row.is_del) !== 0
      || row.refund_reason !== body.text || row.refund_explain !== body.refund_reason_wap_explain
      || Number(row.refund_num) !== intent.quantity || JSON.stringify(cartIntent(row.cart_info, 'id')) !== JSON.stringify(intent.cart)) return false;
    const groups = legacyRefundGroups(row), actual = groups.application.map(item => item.attachment_ref);
    return JSON.stringify(actual) === JSON.stringify(intent.refs) && groups.shipping.length === 0 && Array.isArray(row.refund_img) && row.refund_img.length === 0;
  }
  async function findApplication(orderId, body, intent) {
    const id = legacyRefundId(orderId), matches = []; let count = null, observed = 0;
    // Existing authenticated legacy list only. An absent/changed page is not proof that a write did not commit.
    for (let page = 1; ; page++) {
      const reply = await json('GET', 'order/refund/list', { page, limit: 20 }), data = reply.data;
      if (!data || !Array.isArray(data.list) || !Number.isSafeInteger(data.count) || data.count < 0 || count !== null && data.count !== count) throw unavailable('退款记录正在变化，请稍后再次读取');
      count = data.count; observed += data.list.length;
      data.list.forEach(row => { if (matchesApplication(row, id, body, intent)) matches.push(row); });
      if (observed >= count) break;
      if (!data.list.length) throw unavailable('退款记录尚未完整取得，请再次读取');
    }
    if (observed !== count || matches.length !== 1) return null;
    const row = matches[0]; if (typeof row.order_id !== 'string' || !row.order_id) throw unavailable();
    const detail = (await json('GET', 'order/refund/detail/' + encodeURIComponent(row.order_id))).data;
    if (!matchesApplication(detail, id, body, intent)) throw unavailable('已保存退款发生变化，请从退款列表查看'); return detail;
  }
  requireCurrent(); return Object.freeze({ current, dispose, upload, image,
    saveApplication: (id, body) => json('POST', 'order/refund/apply/' + legacyRefundId(id), body),
    saveShipping: body => json('POST', 'order/refund/express', body),
    readRefund: no => { if (typeof no !== 'string' || !no || no.length > 50) throw unavailable(); return json('GET', 'order/refund/detail/' + encodeURIComponent(no)).then(reply => reply.data); },
    findApplication
  });
}

/** Existing pages supply their actual saved source, reader and visibility; no new business identity flag. */
export const legacyRefundImages = {
  data: () => ({ legacyMaterialPageVisible: true, legacyMaterialBlocked: false, legacyMaterialEpoch: 0 }),
  computed: {
    legacyMaterialProblem() { try { legacyRefundGroups(this.legacyRefundSource); return ''; } catch (error) { return error.message; } },
    legacyMaterialGroups() { return this.legacyMaterialProblem ? { application: [], shipping: [] } : legacyRefundGroups(this.legacyRefundSource); },
    legacyApplicationIds() { return this.legacyMaterialGroups.application.map(row => row.asset_id); },
    legacyShippingIds() { return this.legacyMaterialGroups.shipping.map(row => row.asset_id); },
    legacyMaterialsActive() { return this.legacyMaterialPageVisible && !this.legacyMaterialBlocked && this.legacyRefundVisible === true && !!this.legacyRefundSource && !this.legacyMaterialProblem; }
  },
  watch: {
    legacyRefundSource() { this.closeLegacyMaterials(); this.legacyMaterialBlocked = false; },
    legacyRefundVisible(value) { if (!value) this.closeLegacyMaterials(); },
    '$store.state.app.token'() { this.legacyMaterialBlocked = true; this.closeLegacyMaterials(); },
    '$store.state.app.authRevision'() { this.legacyMaterialBlocked = true; this.closeLegacyMaterials(); },
    '$store.state.app.verifiedUid'() { if (this._legacyMaterialScope && !this._legacyMaterialScope.current()) { this.legacyMaterialBlocked = true; this.closeLegacyMaterials(); } }
  },
  onShow() { this.legacyMaterialPageVisible = true; this.legacyMaterialEpoch++; },
  onHide() { this.legacyMaterialPageVisible = false; this.closeLegacyMaterials(); },
  onUnload() { this.legacyMaterialPageVisible = false; this.closeLegacyMaterials(); },
  beforeDestroy() { this.closeLegacyMaterials(); },
  methods: {
    closeLegacyMaterials() { this.legacyMaterialEpoch++; if (this._legacyMaterialScope) this._legacyMaterialScope.dispose(); this._legacyMaterialScope = null; },
    async readLegacyMaterial(assetId) {
      const source = this.legacyRefundSource, reader = this.legacyRefundReader, wire = JSON.stringify(source && source.refund_materials), epoch = this.legacyMaterialEpoch;
      const valid = () => this.legacyMaterialsActive && this.legacyRefundSource === source && this.legacyRefundReader === reader &&
        this.legacyMaterialEpoch === epoch && JSON.stringify(source.refund_materials) === wire;
      if (!valid() || !['customer', 'mobile'].includes(reader)) throw stale();
      const rows = legacyRefundGroups(source), matches = rows.application.concat(rows.shipping).filter(row => row.asset_id === assetId);
      if (matches.length !== 1) throw unavailable();
      let scope = this._legacyMaterialScope;
      if (!scope || !scope.current()) {
        if (scope) scope.dispose(); scope = await openLegacyRefundScope(valid);
        if (!valid()) { scope.dispose(); throw stale(); }
        // A concurrent first image may have opened the same page scope while USERINFO was pending.
        if (this._legacyMaterialScope && this._legacyMaterialScope.current()) { scope.dispose(); scope = this._legacyMaterialScope; }
        else this._legacyMaterialScope = scope;
      }
      const image = await scope.image(reader, source.id, matches[0]);
      if (!valid() || !scope.current() || this._legacyMaterialScope !== scope) { image.release(); throw stale(); } return image;
    }
  }
};

/** Two existing legacy upload forms share draft/temporary-file lifecycle, with fixed application/shipping modes. */
export const legacyRefundDraft = {
  data: () => ({ legacyDraftRows: [], legacyDraftPreviews: {}, legacyDraftBusy: false, legacyDraftError: '',
    legacyDraftUnknown: null, legacyDraftIntent: null, legacyDraftReceipt: null, legacyDraftConfirmed: false, legacyDraftActive: true, legacyDraftBlocked: false, legacyDraftEpoch: 0 }),
  computed: {
    legacyDraftMissingPreviews() { return this.legacyDraftRows.some(row => !this.legacyDraftPreviews[row.attachment_ref]); },
    legacyDraftReceiptLines() {
      const receipt = this.legacyDraftReceipt; if (!receipt) return [];
      const tri = value => value === true ? '已确认' : value === false ? '未完成' : '未知';
      const phases = { preflight: '操作准备', physical_write: '图片保存', physical_cleanup: '图片清理', metadata_insert: '材料记录保存', commit_outcome_unknown: '保存结果核对', quota_update: '上传额度记录', complete: '操作完成' };
      return ['操作回执：' + (phases[receipt.phase] || '结果核对'), '整体完成：' + tri(receipt.complete),
        '数据保存：' + tri(receipt.database_committed), '图片是否发生变更：' + (receipt.physical_changed === null ? '未知' : receipt.physical_changed ? '是' : '否'),
        '图片保存完成：' + tri(receipt.physical_complete), '清理完成：' + tri(receipt.cleanup_complete)];
    }
  },
  onShow() { this.legacyDraftActive = true; if (this.legacyDraftRows.length && !this.legacyDraftBlocked) this.reloadLegacyDraftPreviews(); },
  onHide() { this.legacyDraftActive = false; this.closeLegacyDraft(); },
  onUnload() { this.legacyDraftActive = false; this.closeLegacyDraft(); },
  beforeDestroy() { this.closeLegacyDraft(); },
  watch: {
    '$store.state.app.token'() { this.blockLegacyDraftIdentity(); },
    '$store.state.app.authRevision'() { this.blockLegacyDraftIdentity(); },
    legacyDraftId() { this.closeLegacyDraft(); }
  },
  methods: {
    closeLegacyDraft() {
      this.legacyDraftEpoch++; if (this._legacyDraftScope) this._legacyDraftScope.dispose(); this._legacyDraftScope = null;
      this._legacyDraftImages = {}; this.legacyDraftPreviews = {};
    },
    blockLegacyDraftIdentity() {
      this.closeLegacyDraft(); this.legacyDraftBlocked = true; this.legacyDraftRows = []; this.refund_reason_wap_img = [];
      this.legacyDraftUnknown = null; this.legacyDraftIntent = null; this.legacyDraftReceipt = null; this.legacyDraftConfirmed = false; this.legacyDraftError = '登录身份已变化，请重新进入当前退款';
      if (own(this, 'applicationExplain')) this.applicationExplain = '';
      if (this.refundInfo && own(this.refundInfo, 'refund_shipping_note')) this.refundInfo.refund_shipping_note = '';
    },
    async legacyDraftScope() {
      if (this.legacyDraftBlocked) throw stale();
      const id = legacyRefundId(this.legacyDraftId), kind = this.legacyDraftKind, epoch = this.legacyDraftEpoch;
      const valid = () => this.legacyDraftActive && !this.legacyDraftBlocked && this.legacyDraftEpoch === epoch &&
        legacyRefundId(this.legacyDraftId) === id && this.legacyDraftKind === kind;
      if (!valid() || !['application', 'shipping'].includes(kind)) throw stale();
      let scope = this._legacyDraftScope;
      if (!scope || !scope.current()) {
        if (scope) scope.dispose(); scope = await openLegacyRefundScope(valid);
        if (!valid()) { scope.dispose(); throw stale(); }
        if (this._legacyDraftScope && this._legacyDraftScope.current()) { scope.dispose(); scope = this._legacyDraftScope; }
        else this._legacyDraftScope = scope;
      } return scope;
    },
    async loadLegacyDraftPreview(row) {
      const scope = await this.legacyDraftScope(), epoch = this.legacyDraftEpoch;
      const image = await scope.image(this.legacyDraftKind === 'application' ? 'application' : 'customer', this.legacyDraftId, row);
      if (!scope.current() || this.legacyDraftEpoch !== epoch || !this.refund_reason_wap_img.includes(row.attachment_ref)) { image.release(); throw stale(); }
      if (!this._legacyDraftImages) this._legacyDraftImages = {};
      if (this._legacyDraftImages[row.attachment_ref]) this._legacyDraftImages[row.attachment_ref].release();
      this._legacyDraftImages[row.attachment_ref] = image; this.$set(this.legacyDraftPreviews, row.attachment_ref, image.url);
    },
    async reloadLegacyDraftPreviews() {
      this.legacyDraftError = '';
      for (const row of this.legacyDraftRows.slice()) {
        try { await this.loadLegacyDraftPreview(row); }
        catch (error) { this.legacyDraftError = error.message || '原图读取未完成，请重新读取'; }
      }
    },
    async uploadLegacyDraft() {
      if (this.legacyDraftBusy || this.legacyDraftUnknown || this.legacyDraftConfirmed || this.legacyDraftRows.length >= 3) return;
      this.legacyDraftBusy = true; this.legacyDraftError = ''; this.legacyDraftReceipt = null;
      try {
        const scope = await this.legacyDraftScope();
        const chosen = await new Promise((resolve, reject) => uni.chooseImage({ count: 1, success: resolve, fail: reject }));
        if (!scope.current() || !chosen || !Array.isArray(chosen.tempFilePaths) || chosen.tempFilePaths.length !== 1) throw stale();
        const row = await scope.upload(this.legacyDraftKind, this.legacyDraftId, chosen.tempFilePaths[0]);
        if (!scope.current()) throw stale();
        this.legacyDraftRows.push(row); this.refund_reason_wap_img.push(row.attachment_ref);
        await this.loadLegacyDraftPreview(row);
      } catch (error) {
        if (!this.legacyDraftBlocked && error.operationReceipt) this.legacyDraftReceipt = error.operationReceipt;
        if (!(error && typeof error.errMsg === 'string' && error.errMsg.includes('cancel'))) this.legacyDraftError = error.message || error.errMsg || '图片未完成，请保留草稿';
      }
      finally { this.legacyDraftBusy = false; }
    },
    removeLegacyDraft(index) {
      if (this.legacyDraftBusy || this.legacyDraftUnknown || this.legacyDraftConfirmed) return;
      const ref = this.refund_reason_wap_img[index];
      if (this._legacyDraftImages && this._legacyDraftImages[ref]) { this._legacyDraftImages[ref].release(); delete this._legacyDraftImages[ref]; }
      this.$delete(this.legacyDraftPreviews, ref); this.refund_reason_wap_img.splice(index, 1);
      this.legacyDraftRows = this.legacyDraftRows.filter(row => row.attachment_ref !== ref);
      // The unselected server STAGED object is retained; no request deletes original or bound material.
    },
    async submitLegacyDraft(body) {
      if (this.legacyDraftUnknown || this.legacyDraftConfirmed || this.legacyDraftBusy) throw unavailable('请先读取本次已保存结果');
      this.legacyDraftBusy = true; let pending = null;
      try {
        pending = JSON.parse(JSON.stringify(body));
        if (Array.isArray(pending.cart_ids)) { pending.cart_ids.forEach(Object.freeze); Object.freeze(pending.cart_ids); } Object.freeze(pending);
        const intent = this.legacyDraftKind === 'application' ? applicationIntent(pending, this.refundCartInfo, this.legacyDraftRows) : null;
        const scope = await this.legacyDraftScope();
        // The lock precedes USERINFO; the immutable intent is kept only once a real POST is about to be dispatched.
        this.legacyDraftUnknown = pending; this.legacyDraftIntent = intent; this.legacyDraftReceipt = null;
        const reply = await (this.legacyDraftKind === 'application' ? scope.saveApplication(this.legacyDraftId, pending) : scope.saveShipping(pending));
        if (!scope.current() || this.legacyDraftUnknown !== pending) throw stale();
        this.legacyDraftUnknown = null; this.legacyDraftIntent = null; this.legacyDraftConfirmed = true; return reply;
      }
      catch (error) {
        if (pending && this.legacyDraftUnknown === pending) {
          if (error.operationReceipt) this.legacyDraftReceipt = error.operationReceipt;
          this.legacyDraftError = '提交结果未确认，草稿已保留。请先读取已保存结果，不要重复提交。';
        }
        throw error;
      }
      finally { this.legacyDraftBusy = false; }
    },
    async readLegacyDraftResult() {
      if (!this.legacyDraftUnknown || this.legacyDraftBusy) return;
      this.legacyDraftBusy = true;
      try {
        const scope = await this.legacyDraftScope(), body = this.legacyDraftUnknown; let saved;
        if (this.legacyDraftKind === 'application') saved = await scope.findApplication(this.legacyDraftId, body, this.legacyDraftIntent);
        else {
          saved = await scope.readRefund(this.orderInfo.order_id); const groups = legacyRefundGroups(saved);
          const refs = body.refund_img ? body.refund_img.split(',') : [];
          if (legacyRefundId(saved.id) !== legacyRefundId(body.id) || Number(saved.refund_type) !== 5 || saved.refund_express !== body.refund_express ||
            saved.refund_phone !== body.refund_phone || saved.refund_express_name !== body.refund_express_name || saved.refund_shipping_note !== body.refund_shipping_note ||
            JSON.stringify(groups.shipping.map(row => row.attachment_ref)) !== JSON.stringify(refs)) saved = null;
        }
        if (!scope.current() || this.legacyDraftUnknown !== body) throw stale();
        if (!saved) { this.legacyDraftError = '尚未完整确认已保存结果。请保留草稿，稍后读取或从退款记录查看。'; return; }
        this.legacyDraftConfirmed = true; this.legacyDraftError = '已读取到匹配的已保存退款记录。';
        this.$util.Tips({ title: '已确认保存结果', icon: 'success' }, { tab: 5, url: '/pages/users/user_return_list/index?isT=1' });
      } catch (error) { this.legacyDraftError = error.message || '保存结果仍未确认，请稍后读取'; }
      finally { this.legacyDraftBusy = false; }
    }
  }
};
