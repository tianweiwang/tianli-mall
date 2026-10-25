import store from '../store';
import Cache from '../utils/cache';
import { LOGIN_STATUS } from '../config/cache';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';

const prefix = 'mp-licence:v1:';
const mediaTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
function stale() { const error = new Error('登录身份或页面已变化，请重新进入申请'); error.staleIdentity = true; return error; }
function unavailable(message = '执照图片未完整取得，请重新读取') { return new Error(message); }
export function isPrivateAgentLicence(ref) { return typeof ref === 'string' && ref.startsWith('mp-licence:'); }
export function agentLicenceAssetId(ref) {
  if (typeof ref !== 'string' || !ref.startsWith(prefix)) throw unavailable();
  const id = ref.slice(prefix.length);
  if (!/^[1-9][0-9]{0,19}(?![\s\S])/.test(id) || id.length === 20 && id > '18446744073709551615') throw unavailable();
  return id;
}
function applicationId(value) {
  if (Number.isSafeInteger(value) && value >= 0 && value <= 2147483647) return String(value);
  if (typeof value === 'string' && /^(0|[1-9][0-9]{0,9})(?![\s\S])/.test(value) && (value.length < 10 || value <= '2147483647')) return value;
  throw unavailable('申请记录未确认，请重新读取');
}
export async function openAgentLicenceScope(realScope) {
  const token = store.state.app.token, revision = store.state.app.authRevision;
  if (typeof realScope !== 'function' || realScope() !== true) throw stale();
  await store.dispatch('USERINFO', true);
  if (realScope() !== true || store.state.app.token !== token || store.state.app.authRevision !== revision) throw stale();
  const app = store.state.app, uid = app.verifiedUid;
  let alive = true; const tasks = new Set(), temporary = new Set(), released = new Set();
  // This identity proof remains usable after dispose; the page uses it to retain only the same user's draft.
  function sameIdentity() {
    try {
      const now = store.state.app;
      return typeof token === 'string' && !!token && Number.isSafeInteger(revision) && revision >= 0 && revision < Number.MAX_SAFE_INTEGER &&
        typeof uid === 'string' && /^[1-9][0-9]*(?![\s\S])/.test(uid) && now.authRevision === revision && now.token === token && now.verifiedUid === uid &&
        String(now.uid) === uid && String(now.userInfo.uid) === uid && Cache.get(LOGIN_STATUS) === token;
    } catch (error) { return false; }
  }
  function current() {
    try { return alive && realScope() === true && sameIdentity(); }
    catch (error) { return false; }
  }
  function requireCurrent() { if (!current()) throw stale(); }
  function header(json = false) {
    requireCurrent(); const out = { ...HEADER };
    Object.keys(out).forEach(name => { if (name.toLowerCase() === 'content-type' || name.toLowerCase() === TOKENNAME.toLowerCase()) delete out[name]; });
    out[TOKENNAME] = 'Bearer ' + token; if (json) out['Content-Type'] = 'application/json'; return out;
  }
  function release(path) {
    if (released.has(path)) return; released.add(path); temporary.delete(path);
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
  function json(method, path, data, saveAcknowledgement = false) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task;
      const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try {
        task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path, method, data: data || {}, header: header(true), timeout: TIMEOUT,
          success(response) { finish(() => {
            const body = response.data;
            if (response.statusCode !== 200 || !body || typeof body !== 'object' || Array.isArray(body) || body.status !== 200 ||
              (!Object.prototype.hasOwnProperty.call(body, 'data') && !(saveAcknowledgement === true && method === 'POST' && typeof body.msg === 'string'))) {
              const error = unavailable(body && typeof body.msg === 'string' ? body.msg : '申请结果未确认，请重新读取');
              const receipt = body && typeof body === 'object' && !Array.isArray(body) && body.data;
              const fields = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
              const knownNotCommitted = receipt && typeof receipt === 'object' && !Array.isArray(receipt) &&
                Object.keys(receipt).sort().join(',') === fields.join(',') && receipt.complete === false &&
                typeof receipt.phase === 'string' && !!receipt.phase && typeof receipt.physical_complete === 'boolean' &&
                [null, false, true].includes(receipt.physical_changed) && [null, false, true].includes(receipt.cleanup_complete) && receipt.database_committed === false;
              // Only an explicit not-committed receipt permits another submission without first reading saved state.
              error.resultUnconfirmed = method === 'POST' && !knownNotCommitted;
              reject(error);
            } else resolve(body.data);
          }); },
          fail() { const error = unavailable('申请结果未确认，请先重新读取申请'); error.resultUnconfirmed = method === 'POST'; finish(reject, error); },
          complete() { if (task) tasks.delete(task); } });
        if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
  }
  async function upload(id, filePath) {
    id = applicationId(id); requireCurrent(); if (typeof filePath !== 'string' || !filePath) throw unavailable('请选择执照图片');
    const result = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try {
        task = uni.uploadFile({ url: HTTP_REQUEST_URL + '/api/agent/apply/' + id + '/files', filePath, name: 'pics', fileType: 'image', formData: { filename: 'pics' }, header: header(), timeout: TIMEOUT,
          success(response) { finish(() => { let body; try { body = JSON.parse(response.data); } catch (error) { reject(unavailable('上传结果未确认，请重新选择图片')); return; } if (response.statusCode !== 200 || !body || body.status !== 200) reject(unavailable(body && typeof body.msg === 'string' ? body.msg : '执照图片上传未完成')); else resolve(body.data); }); },
          fail() { finish(reject, unavailable('执照图片上传未完成，请重新选择图片')); }, complete() { if (task) tasks.delete(task); } });
        if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
    requireCurrent();
    if (!result || typeof result !== 'object' || Array.isArray(result) || Object.keys(result).length !== 3 || typeof result.name !== 'string' || !result.name || typeof result.url !== 'string' || !result.url) throw unavailable('上传结果未完整取得');
    agentLicenceAssetId(result.attachment_ref); return result.attachment_ref;
  }
  async function image(ref) {
    const path = 'agent/licence/' + agentLicenceAssetId(ref) + '/content'; requireCurrent(); let localPath;
    // #ifdef H5
    const controller = new AbortController(); tasks.add(controller); const timer = setTimeout(() => controller.abort(), TIMEOUT);
    try {
      const response = await fetch(HTTP_REQUEST_URL + '/api/' + path, { method: 'GET', headers: header(), mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
      requireCurrent(); const type = response.headers.get('Content-Type'), length = response.headers.get('Content-Length');
      if (response.status !== 200 || response.redirected || !mediaTypes.includes(type) || typeof length !== 'string' || !/^[1-9][0-9]{0,8}(?![\s\S])/.test(length) || Number(length) > 52428800 || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw unavailable();
      const bytes = await response.blob(); requireCurrent(); if (bytes.type !== type || bytes.size !== Number(length)) throw unavailable(); localPath = URL.createObjectURL(bytes);
    } finally { clearTimeout(timer); tasks.delete(controller); }
    // #endif
    // #ifndef H5
    localPath = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) { if (typeof value === 'string') release(value); reject(stale()); } else callback(value); };
      try {
        task = uni.downloadFile({ url: HTTP_REQUEST_URL + '/api/' + path, header: header(), timeout: TIMEOUT,
          success(response) { const downloaded = response.tempFilePath; if (response.statusCode !== 200 || typeof downloaded !== 'string' || !downloaded) { if (downloaded) release(downloaded); finish(reject, unavailable()); } else finish(resolve, downloaded); },
          fail() { finish(reject, unavailable()); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task);
      } catch (error) { finish(reject, error); }
    });
    // #endif
    temporary.add(localPath);
    try {
      await new Promise((resolve, reject) => uni.getImageInfo({ src: localPath, success(info) { if (!current()) reject(stale()); else if (!info || !Number.isSafeInteger(info.width) || info.width < 1 || !Number.isSafeInteger(info.height) || info.height < 1) reject(unavailable()); else resolve(); }, fail() { reject(unavailable()); } }));
      requireCurrent(); return { url: localPath, release: () => release(localPath) };
    } catch (error) { release(localPath); throw error; }
  }
  requireCurrent();
  return Object.freeze({ current, sameIdentity, dispose, upload, image,
    history: () => json('GET', 'agent/apply/info'),
    save: (id, body) => json('POST', 'agent/apply/' + applicationId(id), body, true)
  });
}
