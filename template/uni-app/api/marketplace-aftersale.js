import store from '../store';
import Cache from '../utils/cache';
import { LOGIN_STATUS } from '../config/cache';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';
import { routeId, u64, fileView, readTicket, intentWire, errorText } from '../utils/marketplace-aftersale';

function stale() { const error = new Error('登录身份已变化，请重新进入售后'); error.staleIdentity = true; return error; }
export async function openCustomerAftersale() {
  await store.dispatch('USERINFO', true);
  const state = store.state.app; const token = state.token, revision = state.authRevision, uid = state.verifiedUid;
  let alive = true; const tasks = new Set(); const temporary = new Set();
  function current() {
    try { return alive && typeof token === 'string' && !!token && Number.isSafeInteger(revision) && revision >= 0 && revision < Number.MAX_SAFE_INTEGER && !!uid && store.state.app.authRevision === revision && store.state.app.token === token && store.state.app.verifiedUid === uid && String(store.state.app.uid) === uid && String(store.state.app.userInfo.uid) === uid && Cache.get(LOGIN_STATUS) === token; }
    catch (error) { return false; }
  }
  function requireCurrent() { if (!current()) throw stale(); }
  function header(json = true) { requireCurrent(); const value = { ...HEADER }; Object.keys(value).forEach(name => { if (name.toLowerCase() === 'content-type' || name.toLowerCase() === TOKENNAME.toLowerCase()) delete value[name]; }); value[TOKENNAME] = 'Bearer ' + token; if (json) value['Content-Type'] = 'application/json'; return value; }
  function release(url) {
    temporary.delete(url);
    // H5 downloadFile may return a Blob URL. Native SDK temporary paths are never saved persistently here.
    // #ifdef H5
    if (typeof url === 'string' && url.startsWith('blob:') && typeof URL !== 'undefined' && URL.revokeObjectURL) URL.revokeObjectURL(url);
    // #endif
  }
  function dispose() { if (!alive) return; alive = false; tasks.forEach(task => { if (task && typeof task.abort === 'function') task.abort(); }); tasks.clear(); temporary.forEach(release); temporary.clear(); }
  function json(method, path, data) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task;
      const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try { task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path, method, data: data === undefined ? {} : data, header: header(), timeout: TIMEOUT,
        success(response) { finish(() => { const body = response.data; if (response.statusCode !== 200 || !body || typeof body !== 'object' || Array.isArray(body) || body.status !== 200 || body.data === undefined) reject(new Error(errorText(body))); else resolve(body.data); }); },
        fail() { finish(reject, new Error('请求结果未确认，请保留原提交后重试')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task); }
      catch (error) { finish(reject, error); }
    });
  }
  async function upload(rootId, path, uploadKey) {
    rootId = routeId(rootId); if (typeof path !== 'string' || !path || typeof uploadKey !== 'string' || !/^[!-~]([ -~]{0,126}[!-~])?(?![\s\S])/.test(uploadKey)) throw new Error('原上传文件或上传编号不可用');
    requireCurrent(); const result = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try { task = uni.uploadFile({ url: HTTP_REQUEST_URL + '/api/order/refund/files/' + rootId, filePath: path, name: 'file', fileType: 'image', formData: { schema: '1', upload_key: uploadKey }, header: header(false), timeout: TIMEOUT,
        success(response) { finish(() => { let body; try { body = JSON.parse(response.data); } catch (error) { reject(new Error('上传结果未确认，请重试同一文件')); return; } if (response.statusCode !== 200 || !body || body.status !== 200) reject(new Error(errorText(body))); else resolve(body.data); }); },
        fail() { finish(reject, new Error('上传结果未确认，请重试同一文件')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task); }
      catch (error) { finish(reject, error); }
    }); requireCurrent(); return fileView(result, rootId);
  }
  async function image(rootId, fileId, refundId) {
    rootId = routeId(rootId); fileId = u64(fileId); requireCurrent();
    const ticket = readTicket(await json('POST', 'order/refund/files/' + fileId + '/read-ticket', { schema: 1 }), rootId, fileId, refundId);
    requireCurrent(); let path;
    // #ifdef H5
    const controller = new AbortController(); tasks.add(controller);
    try {
      const response = await fetch(HTTP_REQUEST_URL + '/api/order/refund/files/' + fileId + '/content', {
        method: 'GET', headers: { ...header(false), 'X-Aftersale-Read-Ticket': ticket.read_ticket },
        mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal
      });
      requireCurrent();
      if (response.status !== 200 || response.headers.get('Content-Type') !== ticket.file.media_type) throw new Error('私有凭证字节类型未确认');
      const bytes = await response.blob(); requireCurrent();
      if (bytes.size !== ticket.file.byte_size || bytes.type !== ticket.file.media_type) throw new Error('私有凭证字节长度未确认');
      path = URL.createObjectURL(bytes);
    } finally { tasks.delete(controller); }
    // #endif
    // #ifndef H5
    path = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) { if (typeof value === 'string') release(value); reject(stale()); } else callback(value); };
      try { task = uni.downloadFile({ url: HTTP_REQUEST_URL + '/api/order/refund/files/' + fileId + '/content', header: { ...header(false), 'X-Aftersale-Read-Ticket': ticket.read_ticket }, timeout: TIMEOUT,
        success(response) { const path = response.tempFilePath; if (response.statusCode !== 200 || typeof path !== 'string' || !path) { if (path) release(path); finish(reject, new Error('私有凭证未读回，请重新读取')); return; } finish(resolve, path); },
        fail() { finish(reject, new Error('私有凭证未读回，请重新读取')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task); }
      catch (error) { finish(reject, error); }
    });
    // #endif
    temporary.add(path);
    try {
      await new Promise((resolve, reject) => uni.getImageInfo({ src: path, success(info) { if (!current()) reject(stale()); else if (info.width !== ticket.file.width || info.height !== ticket.file.height) reject(new Error('凭证图片尺寸未确认')); else resolve(); }, fail() { reject(new Error('私有凭证未能解码为图片')); } }));
      requireCurrent(); return { url: path, release: () => release(path) };
    } catch (error) { release(path); throw error; }
  }
  requireCurrent();
  return Object.freeze({ current, dispose, upload, image,
    page: (page, limit) => json('GET', 'order/refund/list', { contract: 2, page, limit }),
    quote: (id, page, limit) => json('GET', 'order/refund/cart_info/' + routeId(id), { contract: 2, fulfillment_page: page, fulfillment_limit: limit }),
    detail: no => json('GET', 'order/refund/detail/' + encodeURIComponent(no), {}),
    submit(intent) {
      const body = intentWire(intent);
      if (intent.action === 'apply') return json('POST', 'order/refund/apply/' + routeId(intent.rootId), body);
      if (intent.action === 'withdraw') return json('POST', 'order/refund/cancel/' + encodeURIComponent(intent.refundNo), body);
      if (intent.action === 'express') return json('POST', 'order/refund/express', body);
      throw new Error('顾客动作不可用');
    }
  });
}