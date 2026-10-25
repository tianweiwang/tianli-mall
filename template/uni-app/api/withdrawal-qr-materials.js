import store from '../store';
import { createOrderFormPrivateScope } from './marketplace-order-form-private';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';

const types = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
// Memory only: no bearer/session credentials, ticket or temporary preview is saved in a draft.
const drafts = new Map(), pending = new Map();
function unavailable(message = '收款码读取未完成，请保留草稿') { return new Error(message); }
function ownerKey() {
  const app = store.state.app, uid = app.verifiedUid;
  if (typeof uid !== 'string' || !/^[1-9][0-9]{0,9}(?![\s\S])/.test(uid) || Number(uid) > 4294967295
    || String(app.uid) !== uid || String(app.userInfo.uid) !== uid || !Number.isSafeInteger(app.authRevision)) throw unavailable('登录身份尚未确认');
  return uid + ':' + app.authRevision;
}
export function withdrawalQrId(reference) {
  const match = typeof reference === 'string' && /^mp-withdrawal:v1:([1-9][0-9]{0,19})(?![\s\S])/.exec(reference);
  if (!match || match[1].length === 20 && match[1] > '18446744073709551615') throw unavailable(); return match[1];
}
function receipt(value) {
  const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join(',') !== keys.join(',')
    || value.complete !== false || typeof value.physical_complete !== 'boolean' || typeof value.phase !== 'string'
    || !/^[a-z][a-z0-9_]{0,63}(?![\s\S])/.test(value.phase)
    || !['cleanup_complete', 'database_committed', 'physical_changed'].every(key => value[key] === null || typeof value[key] === 'boolean')) return null;
  return Object.freeze(keys.reduce((out, key) => { out[key] = value[key]; return out; }, {}));
}
function failure(body) { const error = unavailable(); const safe = receipt(body && body.data); if (safe) error.operationReceipt = safe; return error; }
export function withdrawalQrDrafts() {
  const key = ownerKey(); if (!drafts.has(key)) drafts.set(key, { W: null, Z: null }); return drafts.get(key);
}
export function withdrawalQrPending() { return pending.get(ownerKey()) || null; }

/** Existing verified UID/page scope is only a client guard; each server operation authorizes again. */
export async function openWithdrawalQrScope(realScope) {
  const token = store.state.app.token, revision = store.state.app.authRevision;
  if (typeof realScope !== 'function' || realScope() !== true) throw unavailable();
  await store.dispatch('USERINFO', true);
  if (realScope() !== true || token !== store.state.app.token || revision !== store.state.app.authRevision) throw unavailable('登录身份或页面已变化');
  const owner = ownerKey(), guard = createOrderFormPrivateScope(realScope), tasks = new Set(), paths = new Set(); let alive = true;
  function current() { try { return alive && guard.current() && ownerKey() === owner; } catch (error) { return false; } }
  function requireCurrent() { if (!current()) throw unavailable('登录身份或页面已变化，请重新进入'); }
  function headers(json = false, ticket = null) {
    requireCurrent(); const out = { ...HEADER };
    Object.keys(out).forEach(key => { if (key.toLowerCase() === 'content-type' || key.toLowerCase() === TOKENNAME.toLowerCase()) delete out[key]; });
    out[TOKENNAME] = 'Bearer ' + token; if (json) out['Content-Type'] = 'application/json';
    if (ticket) out['X-Withdrawal-Qr-Read-Ticket'] = ticket; return out;
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
  function json(path, data) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) reject(unavailable('页面已变化，提交结果仍须核实')); else fn(value); };
      try {
        task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path, method: 'POST', data, header: headers(true), timeout: TIMEOUT,
          success(response) { finish(() => { const body = response.data;
            if (response.statusCode !== 200 || !body || body.status !== 200 || typeof body.msg !== 'string') reject(failure(body)); else resolve(body); }); },
          fail() { finish(reject, unavailable('响应未完整取得，请勿重复提交')); }, complete() { if (task) tasks.delete(task); } });
        if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
  }
  async function upload(type, filePath, originalFile) {
    if (!['weixin', 'alipay'].includes(type) || typeof filePath !== 'string' || !filePath) throw unavailable('请选择图片');
    requireCurrent(); const slot = type === 'weixin' ? 'W' : 'Z', draft = { filePath, originalFile, reference: '', phase: 'uploading', receipt: null };
    withdrawalQrDrafts()[slot] = draft;
    try {
      const raw = await new Promise((resolve, reject) => {
        let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) reject(unavailable()); else fn(value); };
        try {
          task = uni.uploadFile({ url: HTTP_REQUEST_URL + '/api/extract/files/' + type, filePath, name: 'pics', fileType: 'image',
            formData: { filename: 'pics' }, header: headers(), timeout: TIMEOUT,
            success(response) { finish(() => { let body; try { body = JSON.parse(response.data); } catch (error) { reject(unavailable()); return; }
              if (response.statusCode !== 200 || !body || body.status !== 200) reject(failure(body)); else resolve(body.data); }); },
            fail() { finish(reject, unavailable()); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
        } catch (error) { finish(reject, error); }
      });
      requireCurrent(); const id = withdrawalQrId(raw && raw.attachment_ref);
      if (!raw || Object.keys(raw).sort().join(',') !== 'attachment_ref,name,url' || typeof raw.name !== 'string'
        || raw.url !== '/api/extract/files/' + id + '/content') throw unavailable();
      draft.reference = raw.attachment_ref; draft.phase = 'uploaded'; return draft;
    } catch (error) { draft.phase = 'upload_unknown'; draft.receipt = error.operationReceipt || null; throw error; }
  }
  async function image(reference) {
    const path = 'extract/files/' + withdrawalQrId(reference), reply = await json(path + '/read-ticket', { schema: 1 }), ticket = reply.data;
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
      if (response.status !== 200 || response.redirected || !types.includes(type) || !/^[1-9][0-9]{0,8}(?![\s\S])/.test(size || '') || Number(size) > 52428800
        || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
      const blob = await response.blob(); requireCurrent(); if (blob.size !== Number(size) || blob.type !== type) throw unavailable(); localPath = URL.createObjectURL(blob);
    } finally { clearTimeout(timer); tasks.delete(controller); }
    // #endif
    // #ifndef H5
    localPath = await new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current()) { if (typeof value === 'string') { paths.add(value); release(value); } reject(unavailable()); } else fn(value); };
      task = uni.downloadFile({ url: HTTP_REQUEST_URL + '/api/' + path + '/content', header: headers(false, ticket.read_ticket), timeout: TIMEOUT,
        success(response) { const file = response.tempFilePath; if (response.statusCode !== 200 || typeof file !== 'string' || !file) { if (file) { paths.add(file); release(file); } finish(reject, unavailable()); } else finish(resolve, file); },
        fail() { finish(reject, unavailable()); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
    });
    // #endif
    paths.add(localPath);
    try {
      // #ifndef H5
      await new Promise((resolve, reject) => uni.getFileInfo({ filePath: localPath, success(info) { if (!Number.isSafeInteger(info.size) || info.size < 1 || info.size > 52428800) reject(unavailable()); else resolve(); }, fail() { reject(unavailable()); } }));
      // #endif
      await new Promise((resolve, reject) => uni.getImageInfo({ src: localPath, success(info) { if (!Number.isSafeInteger(info.width) || info.width < 1 || !Number.isSafeInteger(info.height) || info.height < 1) reject(unavailable()); else resolve(); }, fail() { reject(unavailable()); } }));
      requireCurrent(); return { url: localPath, release: () => release(localPath) };
    } catch (error) { release(localPath); throw error; }
  }
  async function cash(body) {
    requireCurrent(); if (pending.has(owner)) throw unavailable('已有提交结果待核实，请勿重复提交');
    const copy = JSON.parse(JSON.stringify(body)); withdrawalQrId(copy.qrcode_url);
    if (!['weixin', 'alipay'].includes(copy.extract_type)) throw unavailable();
    const draft = { body: Object.freeze(copy), phase: 'cash_unknown', receipt: null };
    // Store before the actual POST; abort, stale identity, 5xx and original-hook errors cannot prove noncommit.
    pending.set(owner, draft);
    try { const reply = await json('extract/cash', draft.body); requireCurrent(); if (pending.get(owner) !== draft) throw unavailable(); pending.delete(owner); return reply; }
    catch (error) { draft.receipt = error.operationReceipt || null; throw error; }
  }
  requireCurrent(); return Object.freeze({ current, dispose, upload, image, cash });
}

export const withdrawalQrDraft = {
  data: () => ({ qrReferences: { W: '', Z: '' }, qrBusy: false, qrProblem: '', qrCashPending: null, qrReceipt: null, qrVisible: true, qrEpoch: 0 }),
  computed: {
    qrReceiptLines() {
      const value = this.qrReceipt; if (!value) return [];
      const tri = item => item === true ? '已确认' : item === false ? '未完成' : '未知';
      return ['材料操作完成：' + tri(value.complete), '材料数据保存：' + tri(value.database_committed),
        '图片发生变更：' + tri(value.physical_changed), '图片保存完成：' + tri(value.physical_complete), '清理完成：' + tri(value.cleanup_complete)];
    }
  },
  watch: {
    '$store.state.app.token'() { this.closeWithdrawalQr(); this.qrReferences = { W: '', Z: '' }; this.qrCashPending = null; this.qrReceipt = null; this.qrProblem = '登录身份已变化，请重新进入'; },
    '$store.state.app.authRevision'() { this.closeWithdrawalQr(); this.qrReferences = { W: '', Z: '' }; this.qrCashPending = null; this.qrReceipt = null; this.qrProblem = '登录身份已变化，请重新进入'; }
  },
  onShow() { this.qrVisible = true; this.restoreWithdrawalQr(); },
  onHide() { this.qrVisible = false; this.closeWithdrawalQr(); },
  onUnload() { this.qrVisible = false; this.closeWithdrawalQr(); },
  beforeDestroy() { this.qrVisible = false; this.closeWithdrawalQr(); },
  methods: {
    closeWithdrawalQr() {
      this.qrEpoch++; if (this._withdrawalQrScope) this._withdrawalQrScope.dispose(); this._withdrawalQrScope = null;
      this._withdrawalQrImages = {};
      if (this.qrReferences.W) this.qrcodeUrlW = ''; if (this.qrReferences.Z) this.qrcodeUrlZ = '';
    },
    async withdrawalQrScope() {
      const epoch = this.qrEpoch, pages = getCurrentPages(), page = pages[pages.length - 1];
      const valid = () => this.qrVisible && this.qrEpoch === epoch && getCurrentPages().slice(-1)[0] === page;
      if (!valid()) throw unavailable();
      let scope = this._withdrawalQrScope;
      if (!scope || !scope.current()) {
        if (scope) scope.dispose(); scope = await openWithdrawalQrScope(valid);
        if (!valid()) { scope.dispose(); throw unavailable(); }
        if (this._withdrawalQrScope && this._withdrawalQrScope.current()) { scope.dispose(); scope = this._withdrawalQrScope; }
        else this._withdrawalQrScope = scope;
      } return scope;
    },
    async restoreWithdrawalQr() {
      if (this.qrBusy || !this.isLogin) return;
      this.qrBusy = true;
      try {
        const scope = await this.withdrawalQrScope(), rows = withdrawalQrDrafts(); this.qrCashPending = withdrawalQrPending();
        if (this.qrCashPending) { this.qrProblem = '提交结果尚未确认，草稿已保留。请先通过原财务流程核实，勿重复提交。'; this.qrReceipt = this.qrCashPending.receipt; }
        for (const slot of ['W', 'Z']) {
          const row = rows[slot]; if (!row) continue;
          if (row.phase === 'upload_unknown' && !this.qrCashPending) { this.qrProblem = '图片上传结果尚未确认，原图已保留。'; this.qrReceipt = row.receipt; }
          if (row.reference) { this.$set(this.qrReferences, slot, row.reference); await this.loadWithdrawalQrPreview(slot, scope); }
        }
      } catch (error) { if (this.qrVisible) this.qrProblem = error.message || '收款码读取未完成'; }
      finally { this.qrBusy = false; }
    },
    async loadWithdrawalQrPreview(slot, scope) {
      const ref = this.qrReferences[slot], image = await scope.image(ref);
      if (!scope.current() || this.qrReferences[slot] !== ref) { image.release(); throw unavailable(); }
      if (!this._withdrawalQrImages) this._withdrawalQrImages = {};
      if (this._withdrawalQrImages[slot]) this._withdrawalQrImages[slot].release();
      this._withdrawalQrImages[slot] = image; this[slot === 'W' ? 'qrcodeUrlW' : 'qrcodeUrlZ'] = image.url;
    },
    async uploadWithdrawalQr(slot) {
      if (!['W', 'Z'].includes(slot) || this.qrBusy || this.qrCashPending || this.prevent) return;
      this.qrBusy = true; this.qrProblem = ''; this.qrReceipt = null;
      try {
        const scope = await this.withdrawalQrScope();
        const chosen = await new Promise((resolve, reject) => uni.chooseImage({ count: 1, success: resolve, fail: reject }));
        if (!scope.current() || !chosen || !Array.isArray(chosen.tempFilePaths) || chosen.tempFilePaths.length !== 1) throw unavailable();
        const row = await scope.upload(slot === 'W' ? 'weixin' : 'alipay', chosen.tempFilePaths[0], chosen.tempFiles && chosen.tempFiles[0]);
        this.$set(this.qrReferences, slot, row.reference); await this.loadWithdrawalQrPreview(slot, scope);
      } catch (error) {
        if (error.operationReceipt) this.qrReceipt = error.operationReceipt;
        if (!(error && typeof error.errMsg === 'string' && error.errMsg.includes('cancel'))) this.qrProblem = error.message || '图片未完成，原图已保留';
      } finally { this.qrBusy = false; }
    },
    removeWithdrawalQr(slot) {
      if (!['W', 'Z'].includes(slot) || this.qrBusy || this.qrCashPending || this.prevent) return;
      if (this._withdrawalQrImages && this._withdrawalQrImages[slot]) { this._withdrawalQrImages[slot].release(); delete this._withdrawalQrImages[slot]; }
      try { withdrawalQrDrafts()[slot] = null; } catch (error) { this.qrProblem = error.message; return; }
      this.$set(this.qrReferences, slot, ''); this[slot === 'W' ? 'qrcodeUrlW' : 'qrcodeUrlZ'] = '';
      // Only remove the local selection. No STAGED or BOUND server object is deleted.
    },
    async submitWithdrawalQrCash(body) {
      if (this.qrBusy || this.qrCashPending) throw unavailable('已有操作待核实，请勿重复提交');
      this.qrBusy = true; this.qrProblem = ''; this.qrReceipt = null;
      try {
        const scope = await this.withdrawalQrScope();
        const work = scope.cash(body); this.qrCashPending = withdrawalQrPending();
        const reply = await work; this.qrCashPending = null; return reply;
      } catch (error) {
        try { this.qrCashPending = withdrawalQrPending(); } catch (ignored) { /* Never attach an old identity's draft to the new page. */ }
        if (this.qrCashPending) { this.qrReceipt = this.qrCashPending.receipt; this.qrProblem = '提交结果尚未确认，草稿已保留。请先通过原财务流程核实，勿重复提交。'; }
        else this.qrProblem = error.message || '操作未完成'; throw error;
      } finally { this.qrBusy = false; }
    }
  }
};
