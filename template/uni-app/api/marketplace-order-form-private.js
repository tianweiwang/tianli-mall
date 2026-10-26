import store from '../store';
import Cache from '../utils/cache';
import { LOGIN_STATUS } from '../config/cache';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';

const MAX_FILE_BYTES = 52428800;
const MAX_TICKET_MS = 4294967295999;
const mediaTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function unavailable(message = '表单原图暂未完整取得，请重新进入原订单') { throw new Error(message); }
function stale() { const error = new Error('表单或登录身份已变化，请重新读取'); error.staleIdentity = true; return error; }
function exact(value, names) {
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length !== names.length || names.some(name => !Object.prototype.hasOwnProperty.call(value, name))) unavailable();
  return value;
}
function integer(value, min, max) { if (!Number.isSafeInteger(value) || value < min || value > max) unavailable(); return value; }
function rootId(value) {
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value)) value = Number(value);
  return integer(value, 1, 2147483647);
}
function confirmationKey(value) {
  if (typeof value !== 'string' || !value || Array.from(value).length > 32 || value.includes('\0')) unavailable();
  try { return encodeURIComponent(value); } catch (error) { unavailable(); }
}
// U64 asset identity remains a canonical string throughout transport and display.
export function orderFormAssetId(value) {
  if (typeof value !== 'string' || !/^[1-9][0-9]{0,19}(?![\s\S])/.test(value) || (value.length === 20 && value > '18446744073709551615')) unavailable();
  return value;
}
export function isPrivateOrderFormImage(value) { return typeof value === 'string' && value.startsWith('mp-form:'); }
export function orderFormImageAsset(value) {
  if (!isPrivateOrderFormImage(value)) return null;
  if (!value.startsWith('mp-form:v1:')) unavailable();
  return orderFormAssetId(value.slice('mp-form:v1:'.length));
}
export function orderFormUploadReference(response) {
  exact(response, ['name', 'url', 'attachment_ref']);
  if (typeof response.name !== 'string' || !response.name || typeof response.url !== 'string' || !response.url) unavailable('上传结果未完整取得');
  orderFormImageAsset(response.attachment_ref);
  if (!isPrivateOrderFormImage(response.attachment_ref)) unavailable('上传结果未完整取得');
  return response.attachment_ref;
}
export function isLegacyOrderFormImage(value) { return typeof value === 'string' && value.startsWith('legacy-form:'); }
export function legacyOrderFormAsset(value) {
  if (!isLegacyOrderFormImage(value) || !value.startsWith('legacy-form:v1:')) unavailable();
  return orderFormAssetId(value.slice('legacy-form:v1:'.length));
}
export function legacyOrderFormUploadReference(response) {
  exact(response, ['name', 'url', 'attachment_ref']);
  if (typeof response.name !== 'string' || !response.name || typeof response.url !== 'string' || !response.url) unavailable('上传结果未完整取得');
  legacyOrderFormAsset(response.attachment_ref);
  return response.attachment_ref;
}
export function legacyOrderFormReceipt(response) {
  const value = response && response.data;
  const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join(',') !== keys.join(',') ||
    typeof value.complete !== 'boolean' || typeof value.physical_complete !== 'boolean' || typeof value.phase !== 'string' ||
    !/^[a-z][a-z0-9_:-]{0,63}(?![\s\S])/.test(value.phase) ||
    !['cleanup_complete', 'database_committed', 'physical_changed'].every(name => value[name] === null || typeof value[name] === 'boolean')) return null;
  return Object.freeze(keys.reduce((out, name) => { out[name] = value[name]; return out; }, {}));
}
function ticket(value) {
  exact(value, ['read_ticket', 'issued_at_ms', 'expires_at_ms']);
  if (typeof value.read_ticket !== 'string' || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(value.read_ticket)) unavailable();
  integer(value.issued_at_ms, 1, MAX_TICKET_MS); integer(value.expires_at_ms, value.issued_at_ms + 1, MAX_TICKET_MS);
  if (value.expires_at_ms - value.issued_at_ms > 300000) unavailable();
  return value;
}

export async function openOrderFormPrivateScope(realScope) {
  const token = store.state.app.token, revision = store.state.app.authRevision;
  if (typeof realScope !== 'function' || realScope() !== true) throw stale();
  await store.dispatch('USERINFO', true);
  if (realScope() !== true || store.state.app.token !== token || store.state.app.authRevision !== revision) throw stale();
  return createOrderFormPrivateScope(realScope);
}

// This finite client scope is not an authorization grant; every named server read reauthorizes.
export function createOrderFormPrivateScope(realScope) {
  if (typeof realScope !== 'function') unavailable();
  const app = store.state.app, token = app.token, revision = app.authRevision, uid = app.verifiedUid;
  let alive = true; const tasks = new Set(), temporary = new Set(), released = new Set();
  function current() {
    try {
      const now = store.state.app;
      return alive && realScope() === true && typeof token === 'string' && !!token && Number.isSafeInteger(revision) && revision >= 0 && revision < Number.MAX_SAFE_INTEGER &&
        typeof uid === 'string' && /^[1-9][0-9]*(?![\s\S])/.test(uid) && now.authRevision === revision && now.token === token && now.verifiedUid === uid &&
        String(now.uid) === uid && String(now.userInfo.uid) === uid && Cache.get(LOGIN_STATUS) === token;
    } catch (error) { return false; }
  }
  function requireCurrent() { if (!current()) throw stale(); }
  function header(json = false) {
    requireCurrent(); const out = { ...HEADER };
    Object.keys(out).forEach(name => { if (name.toLowerCase() === 'content-type' || name.toLowerCase() === TOKENNAME.toLowerCase()) delete out[name]; });
    out[TOKENNAME] = 'Bearer ' + token; if (json) out['Content-Type'] = 'application/json'; return out;
  }
  function release(path) {
    if (released.has(path)) return; released.add(path);
    temporary.delete(path);
    // #ifdef H5
    if (typeof path === 'string' && path.startsWith('blob:') && typeof URL !== 'undefined' && URL.revokeObjectURL) URL.revokeObjectURL(path);
    // #endif
    // #ifdef MP
    if (typeof path === 'string' && path && typeof uni.getFileSystemManager === 'function') {
      const fs = uni.getFileSystemManager(); if (fs && typeof fs.unlink === 'function') fs.unlink({ filePath: path, fail() {} });
    }
    // #endif
    // #ifdef APP-PLUS
    if (typeof path === 'string' && path && typeof plus !== 'undefined' && plus.io) plus.io.resolveLocalFileSystemURL(path, entry => entry.remove(() => {}, () => {}), () => {});
    // #endif
  }
  function dispose() {
    if (!alive) return; alive = false;
    tasks.forEach(task => { if (task && typeof task.abort === 'function') task.abort(); }); tasks.clear();
    temporary.forEach(release); temporary.clear();
  }
  function trackUpload(task) { if (!current()) { if (task && typeof task.abort === 'function') task.abort(); } else if (task) tasks.add(task); }
  function confirmationUploadOptions(key, productId, fieldIndex) {
    requireCurrent();
    if (typeof productId === 'string' && /^[1-9][0-9]{0,6}(?![\s\S])/.test(productId)) productId = Number(productId);
    integer(productId, 1, 8388607); integer(fieldIndex, 0, 9);
    return { url: 'order/confirm/' + confirmationKey(key) + '/forms/' + productId + '/' + fieldIndex + '/files', name: 'pics', count: 1, onUploadTask: trackUpload };
  }
  function json(path) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task;
      const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try {
        task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path + '/read-ticket', method: 'POST', data: { schema: 1 }, header: header(true), timeout: TIMEOUT,
          success(response) { finish(() => { const body = response.data; if (response.statusCode !== 200 || !body || typeof body !== 'object' || Array.isArray(body) || body.status !== 200) reject(new Error('表单原图票据未完整取得')); else { try { resolve(ticket(body.data)); } catch (error) { reject(error); } } }); },
          fail() { finish(reject, new Error('表单原图票据未取得，请重新读取')); }, complete() { if (task) tasks.delete(task); } });
        if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
  }
  async function image(path, legacy = false) {
    const issued = legacy ? null : await json(path); requireCurrent(); let localPath;
    const readHeaders = () => issued ? { ...header(), 'X-Order-Form-Read-Ticket': issued.read_ticket } : header();
    // #ifdef H5
    const controller = new AbortController(); tasks.add(controller);
    try {
      const response = await fetch(HTTP_REQUEST_URL + '/api/' + path + '/content', { method: 'GET', headers: readHeaders(),
        mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
      requireCurrent(); const type = response.headers.get('Content-Type'), length = response.headers.get('Content-Length');
      if (response.status !== 200 || !(legacy ? ['image/jpg', ...mediaTypes] : mediaTypes).includes(type) || typeof length !== 'string' || !/^[1-9][0-9]{0,8}(?![\s\S])/.test(length)) unavailable();
      if (legacy && (response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store'))) unavailable();
      const expected = integer(Number(length), 1, MAX_FILE_BYTES), bytes = await response.blob(); requireCurrent();
      if (bytes.type !== type || bytes.size !== expected) unavailable();
      localPath = URL.createObjectURL(bytes);
    } finally { tasks.delete(controller); }
    // #endif
    // #ifndef H5
    localPath = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) { if (typeof value === 'string') release(value); reject(stale()); } else callback(value); };
      try {
        task = uni.downloadFile({ url: HTTP_REQUEST_URL + '/api/' + path + '/content', header: readHeaders(), timeout: TIMEOUT,
          success(response) { const downloaded = response.tempFilePath; if (response.statusCode !== 200 || typeof downloaded !== 'string' || !downloaded) { if (downloaded) release(downloaded); finish(reject, new Error('表单原图未读回')); } else finish(resolve, downloaded); },
          fail() { finish(reject, new Error('表单原图未读回')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
    // #endif
    temporary.add(localPath);
    try {
      await new Promise((resolve, reject) => uni.getImageInfo({ src: localPath,
        success(info) { if (!current()) reject(stale()); else if (!info || !Number.isSafeInteger(info.width) || info.width < 1 || !Number.isSafeInteger(info.height) || info.height < 1) reject(new Error('表单原图尺寸未确认')); else resolve(); },
        fail() { reject(new Error('表单原图未能解码为图片')); } }));
      requireCurrent(); return { url: localPath, release: () => release(localPath) };
    } catch (error) { release(localPath); throw error; }
  }
  requireCurrent();
  return Object.freeze({ current, dispose, confirmationUploadOptions,
    legacyConfirmationUploadOptions(key, productId, fieldIndex) {
      requireCurrent();
      if (typeof productId === 'string' && /^[1-9][0-9]{0,6}(?![\s\S])/.test(productId)) productId = Number(productId);
      integer(productId, 1, 8388607); integer(fieldIndex, 0, 9);
      return { url: 'order/form/legacy/confirm/' + confirmationKey(key) + '/products/' + productId + '/fields/' + fieldIndex + '/files', name: 'pics', count: 1, onUploadTask: trackUpload };
    },
    legacyConfirmationImage: (key, assetId) => image('order/form/legacy/confirm/' + confirmationKey(key) + '/files/' + orderFormAssetId(assetId), true),
    legacyCustomerOrderImage: (order, assetId) => image('order/form/legacy/orders/' + rootId(order) + '/files/' + orderFormAssetId(assetId), true),
    legacyMobileOrderImage: (order, assetId) => image('admin/order/form/legacy/orders/' + rootId(order) + '/files/' + orderFormAssetId(assetId), true),
    confirmationImage: (key, assetId) => image('order/confirm/' + confirmationKey(key) + '/form-files/' + orderFormAssetId(assetId)),
    customerOrderImage: (root, assetId) => image('order/' + rootId(root) + '/form-files/' + orderFormAssetId(assetId)),
    mobileOrderImage: (root, assetId) => image('admin/order/detail/' + rootId(root) + '/form-files/' + orderFormAssetId(assetId))
  });
}
