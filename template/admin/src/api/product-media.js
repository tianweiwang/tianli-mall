import Setting from '@/setting';
import { getPlatformToken } from '@/utils/storage';
import { getCookies } from '@/libs/util';
import { platformAuthEpoch } from '@/libs/platform-auth-epoch';

// Finite product-only transport. The server remains the authority for all three grants/owners.
export function mediaId(value, zero = false) {
  const number = typeof value === 'string' && /^(0|[1-9][0-9]{0,9})(?![\s\S])/.test(value) ? Number(value) : value;
  if (!Number.isSafeInteger(number) || number < (zero ? 0 : 1) || number > 2147483647) throw new Error('图片目标参数不正确');
  return number;
}
export function productId(value) {
  const id = mediaId(value, true);
  if (id > 8388607) throw new Error('商品图片范围不正确');
  return id;
}
function scanId(value) {
  if (typeof value !== 'string' || !/^[a-f0-9]{32}(?![\s\S])/.test(value)) throw new Error('扫码记录不正确');
  return value;
}
export function captureProductContext(value) {
  const id = productId(value); const token = getPlatformToken(); const uuid = getCookies('uuid');
  if (!token || typeof uuid !== 'string' || !/^[1-9][0-9]{0,9}(?![\s\S])/.test(uuid)) throw new Error('登录身份未确认，请重新打开商品图片');
  return Object.freeze({ productId: id, token, uuid, epoch: platformAuthEpoch.value, life: { open: true, controllers: new Set(), urls: new Set() } });
}
export function currentProductContext(context) {
  return !!context && context.life.open && context.token === getPlatformToken() && context.uuid === getCookies('uuid') && context.epoch === platformAuthEpoch.value;
}
export function requireProductContext(context) {
  if (!currentProductContext(context)) throw new Error('商品或登录身份已变化，请重新打开图片库');
}
export function releaseProductPreview(context, url) {
  if (!url) return;
  window.URL.revokeObjectURL(url);
  if (context) context.life.urls.delete(url);
}
export function closeProductContext(context) {
  if (!context) return;
  context.life.open = false;
  cancelProductRequests(context);
  context.life.urls.forEach(url => window.URL.revokeObjectURL(url)); context.life.urls.clear();
}
export function cancelProductRequests(context) {
  if (!context) return;
  context.life.controllers.forEach(controller => controller.abort()); context.life.controllers.clear();
}
function target(path, query) {
  const base = new URL(Setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.username || base.password || base.hash || base.search) throw new Error('图片接口地址不正确');
  base.pathname = base.pathname.replace(/\/+$/, '') + '/';
  const url = new URL(path, base);
  if (url.origin !== base.origin || url.pathname !== base.pathname + path || url.username || url.password || url.hash) throw new Error('图片接口目标不正确');
  if (query) Object.keys(query).forEach(key => { if (query[key] !== undefined) url.searchParams.set(key, String(query[key])); });
  return url;
}
function failure(message, state, receipt) {
  const error = new Error(message); error.state = state;
  if (receipt && typeof receipt === 'object') {
    const keys = ['complete', 'phase', 'physical_changed', 'physical_complete', 'database_committed', 'cleanup_complete'];
    if (keys.every(key => Object.prototype.hasOwnProperty.call(receipt, key)) && typeof receipt.complete === 'boolean' && typeof receipt.physical_complete === 'boolean' && typeof receipt.phase === 'string' && /^[a-z_-]{1,64}$/.test(receipt.phase) && ['physical_changed', 'database_committed', 'cleanup_complete'].every(key => [true, false, null].includes(receipt[key]))) error.receipt = keys.reduce((out, key) => { out[key] = receipt[key]; return out; }, {});
  }
  return error;
}
async function transfer(context, method, path, data, query, binary) {
  requireProductContext(context);
  const url = target(path, query);
  const controller = new AbortController(); context.life.controllers.add(controller);
  const timer = window.setTimeout(() => controller.abort(), method === 'GET' ? 20000 : 100000);
  const headers = { 'Authori-zation': 'Bearer ' + context.token };
  const config = { method, headers, signal: controller.signal, redirect: 'error', cache: 'no-store', credentials: 'same-origin', mode: url.origin === window.location.origin ? 'same-origin' : 'cors' };
  if (data instanceof FormData) config.body = data;
  else if (data !== undefined) { headers['Content-Type'] = 'application/json'; config.body = JSON.stringify(data); }
  try {
    const response = await fetch(url.href, config); requireProductContext(context);
    if (binary) {
      if (response.status !== 200 || response.redirected || response.headers.get('Content-Type') !== binary.mime) throw failure('扫码预览未确认，请刷新图库', 'read');
      const blob = await response.blob(); requireProductContext(context);
      if (!(blob instanceof Blob) || blob.type !== binary.mime || blob.size !== binary.size) throw failure('扫码图片内容未确认', 'read');
      return blob;
    }
    const envelope = await response.json(); requireProductContext(context);
    if (response.status !== 200 || !envelope || envelope.status !== 200 || !Object.prototype.hasOwnProperty.call(envelope, 'data')) {
      const status = response.status === 200 ? envelope && envelope.status : response.status;
      const controlled = [401, 403, 404, 410, 422].includes(status);
      const detail = envelope && envelope.data;
      throw failure(controlled ? (status === 401 ? '登录已失效，请重新打开商品图片' : status === 403 ? '当前账号没有这项图片权限' : status === 410 ? '二维码已失效，请重新扫码' : '图片请求未完成，请核对当前资料') : '提交结果尚未确认，请保留本次结果', controlled ? 'rejected' : (method === 'GET' ? 'read' : 'unknown'), detail && (detail.receipt || detail));
    }
    return envelope.data;
  } catch (error) {
    requireProductContext(context);
    if (error.state) throw error;
    throw failure(method === 'GET' ? '图片读取未完成，请刷新当前页面' : '提交结果尚未确认，请保留本次结果', method === 'GET' ? 'read' : 'unknown');
  } finally { window.clearTimeout(timer); context.life.controllers.delete(controller); }
}
function path(context) { requireProductContext(context); return 'product/product/' + productId(context.productId) + '/media'; }
function rows(value) {
  if (!value || !Array.isArray(value.list)) throw new Error('图片响应不完整');
  return value;
}
export function receiptText(receipt) {
  if (!receipt) return '';
  const state = value => value === null ? '未确认' : value ? '是' : '否';
  return '阶段：' + receipt.phase + '；完成：' + state(receipt.complete) + '；文件变化：' + state(receipt.physical_changed) + '；文件处理完成：' + state(receipt.physical_complete) + '；数据已提交：' + state(receipt.database_committed) + '；清理完成：' + state(receipt.cleanup_complete);
}
function selectedIds(ids) {
  if (!Array.isArray(ids) || ids.length < 1 || ids.length > 50) throw new Error('一次请选择1至50张图片');
  return Array.from(new Set(ids.map(id => mediaId(id))));
}
export const mediaList = (context, query) => transfer(context, 'GET', path(context), undefined, query).then(rows);
export const mediaCategories = (context, query = {}) => transfer(context, 'GET', path(context) + '/categories', undefined, query).then(rows);
export const mediaCreateCategory = (context, body) => transfer(context, 'POST', path(context) + '/categories', body);
export const mediaUpdateCategory = (context, id, body) => transfer(context, 'PUT', path(context) + '/categories/' + mediaId(id), body);
export const mediaDeleteCategory = (context, id) => transfer(context, 'DELETE', path(context) + '/categories/' + mediaId(id));
export const mediaRename = (context, id, name) => transfer(context, 'PUT', path(context) + '/' + mediaId(id) + '/name', { real_name: name });
export const mediaMove = (context, ids, pid) => transfer(context, 'PUT', path(context) + '/move', { images: selectedIds(ids).join(','), pid: mediaId(pid, true) });
export const mediaDelete = (context, ids) => transfer(context, 'POST', path(context) + '/delete', { ids: selectedIds(ids).join(',') });
export function mediaUpload(context, file, pid = 0) {
  const body = new FormData(); body.append('file', file); body.append('pid', String(mediaId(pid, true)));
  return transfer(context, 'POST', path(context), body).then(value => {
    if (!value || typeof value.src !== 'string' || !value.src || /^(blob:|data:)/i.test(value.src)) throw failure('图片保存结果未确认', 'unknown');
    return value;
  });
}
export async function mediaOnline(context, images, pid = 0) {
  const value = rows(await transfer(context, 'POST', path(context) + '/online_upload', { images, pid: mediaId(pid, true) }));
  if (value.list.length !== images.length) throw failure('图片保存数量未确认', 'unknown');
  value.list.forEach(item => { mediaId(item.att_id); if (typeof item.src !== 'string' || !item.src || /^(blob:|data:)/i.test(item.src)) throw failure('图片保存结果未确认', 'unknown'); });
  return value;
}
export async function issueScan(context, pid) {
  const value = await transfer(context, 'POST', path(context) + '/scan', { pid: mediaId(pid, true) });
  if (!value || !Number.isSafeInteger(value.expires_at_ms) || typeof value.url !== 'string') throw failure('二维码发行结果未确认', 'unknown');
  scanId(value.lease_id); const url = new URL(value.url, window.location.origin); const cap = new URLSearchParams(url.hash.slice(1)).get('scan_cap');
  if (!/^https?:$/.test(url.protocol) || url.origin !== window.location.origin || url.username || url.password || url.pathname !== '/app/upload' || url.searchParams.get('scene') !== 'product_media' || url.searchParams.get('lease_id') !== value.lease_id || typeof cap !== 'string' || !/^[a-f0-9]{64}(?![\s\S])/.test(cap)) throw failure('二维码返回范围未确认', 'unknown');
  return { ...value, url: url.href };
}
export async function pollScan(context, lease) {
  const value = await transfer(context, 'GET', path(context) + '/scan/' + scanId(lease));
  if (!value || value.lease_id !== lease || typeof value.state !== 'string' || !Number.isSafeInteger(value.expires_at_ms) || !Array.isArray(value.list) || value.list.length > 50) throw new Error('扫码图库响应不完整');
  const seen = new Set();
  value.list.forEach(item => {
    scanId(item.stage_id);
    if (seen.has(item.stage_id) || !Number.isSafeInteger(item.sequence) || item.sequence < 1 || typeof item.name !== 'string' || typeof item.mime !== 'string' || !/^image\/(jpg|jpeg|png|gif|webp)$/.test(item.mime) || !Number.isSafeInteger(item.size) || item.size < 1 || item.size > 52428800) throw new Error('扫码图片资料不完整');
    seen.add(item.stage_id);
  });
  return value;
}
export async function scanPreview(context, lease, item) {
  const blob = await transfer(context, 'GET', path(context) + '/scan/' + scanId(lease) + '/items/' + scanId(item.stage_id) + '/content', undefined, undefined, item);
  requireProductContext(context);
  const url = window.URL.createObjectURL(blob);
  if (!currentProductContext(context)) { window.URL.revokeObjectURL(url); requireProductContext(context); }
  context.life.urls.add(url); return url;
}
export async function claimScan(context, lease, ids, destinationPid) {
  if (!Array.isArray(ids) || ids.length < 1 || ids.length > 50 || new Set(ids).size !== ids.length) throw new Error('请选择本次扫码图片');
  const value = await transfer(context, 'POST', path(context) + '/scan/' + scanId(lease) + '/claim', { stage_ids: ids.map(scanId), destination_pid: mediaId(destinationPid, true) });
  if (!value || !['claimed', 'processing'].includes(value.state) || !Array.isArray(value.list) || (value.state === 'processing' && value.list.length !== 0)) throw failure('认领结果尚未确认，请保留本次结果', 'unknown');
  if (value.state === 'claimed') {
    if (value.list.length !== ids.length) throw failure('图片保存数量未确认', 'unknown');
    value.list.forEach(item => { mediaId(item.att_id); if (typeof item.src !== 'string' || !item.src || /^(blob:|data:)/i.test(item.src)) throw failure('图片保存结果未确认', 'unknown'); });
  }
  return value;
}
export async function closeScan(context, lease) {
  const value = await transfer(context, 'DELETE', path(context) + '/scan/' + scanId(lease));
  if (!value || !['closed', 'pending', 'unknown'].includes(value.state) || (value.state === 'closed' && value.cleanup_complete !== true)) throw failure('扫码关闭结果未确认', 'unknown');
  return value;
}
export function takePhoneScanFragment(route) {
  const raw = window.location.hash; const scene = route.query.scene;
  if (scene !== 'product_media' && !raw.includes('scan_cap=')) return null;
  window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
  const params = new URLSearchParams(raw.slice(1)); const cap = params.get('scan_cap'); const lease = route.query.lease_id;
  if (scene !== 'product_media' || params.getAll('scan_cap').length !== 1 || typeof cap !== 'string' || !/^[a-f0-9]{64}(?![\s\S])/.test(cap)) throw new Error('二维码已失效，请回电脑重新扫码');
  scanId(lease); return { lease, capability: cap };
}
export async function stageProductScan(entry, file, signal) {
  if (!entry || !/^[a-f0-9]{64}(?![\s\S])/.test(entry.capability)) throw new Error('二维码已失效，请重新扫码');
  const url = target('image/product_scan_upload'); const body = new FormData(); body.append('file', file); body.append('lease_id', scanId(entry.lease)); body.append('scan_cap', entry.capability);
  const response = await fetch(url.href, { method: 'POST', body, signal, credentials: 'omit', redirect: 'error', cache: 'no-store', mode: url.origin === window.location.origin ? 'same-origin' : 'cors' });
  const envelope = await response.json();
  if (response.status !== 200 || !envelope || envelope.status !== 200 || !envelope.data || envelope.data.state !== 'ready') throw new Error('图片发送未确认，请查看电脑图库；二维码失效时请重新扫码');
  scanId(envelope.data.stage_id);
  if (!Number.isSafeInteger(envelope.data.sequence) || envelope.data.sequence < 1) throw new Error('图片发送结果未确认，请查看电脑图库');
  return envelope.data;
}
