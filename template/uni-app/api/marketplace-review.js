import store from '../store';
import Cache from '../utils/cache';
import { LOGIN_STATUS } from '../config/cache';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';
import { quoteView } from '../utils/marketplace-aftersale';
import { routeId, pictureRef, pictureURL, intentWire, errorText } from '../utils/marketplace-review';
function stale() { const error = new Error('登录身份或页面已变化，请重新进入评价'); error.staleIdentity = true; return error; }
export async function openCustomerReviews() {
  await store.dispatch('USERINFO', true);
  const state = store.state.app, token = state.token, revision = state.authRevision, uid = state.verifiedUid;
  let alive = true; const tasks = new Set();
  function current() { try { return alive && typeof token === 'string' && !!token && Number.isSafeInteger(revision) && revision >= 0 && revision < Number.MAX_SAFE_INTEGER && !!uid && store.state.app.authRevision === revision && store.state.app.token === token && store.state.app.verifiedUid === uid && String(store.state.app.uid) === uid && String(store.state.app.userInfo.uid) === uid && Cache.get(LOGIN_STATUS) === token; } catch (error) { return false; } }
  function requireCurrent() { if (!current()) throw stale(); }
  function headers(json) { requireCurrent(); const out = { ...HEADER }; Object.keys(out).forEach(key => { if (key.toLowerCase() === 'content-type' || key.toLowerCase() === TOKENNAME.toLowerCase()) delete out[key]; }); out[TOKENNAME] = 'Bearer ' + token; if (json) out['Content-Type'] = 'application/json'; return out; }
  function dispose() { alive = false; tasks.forEach(task => { if (task && typeof task.abort === 'function') task.abort(); }); tasks.clear(); }
  function json(method, path, data) {
    requireCurrent(); return new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try { task = uni.request({ url: HTTP_REQUEST_URL + '/api/' + path, method, data: data === undefined ? {} : data, header: headers(true), timeout: TIMEOUT,
        success(response) { finish(() => { const body = response.data; if (response.statusCode !== 200 || !body || typeof body !== 'object' || Array.isArray(body) || body.status !== 200 || !Object.prototype.hasOwnProperty.call(body, 'data')) reject(new Error(errorText(body))); else resolve(body.data); }); },
        fail() { finish(reject, new Error('请求结果尚未确认，请保留原内容并重新查询')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task); }
      catch (error) { finish(reject, error); }
    });
  }
  async function upload(path) {
    requireCurrent(); if (typeof path !== 'string' || !path) throw new Error('所选图片不可用');
    const data = await new Promise((resolve, reject) => {
      let task; const finish = (callback, value) => { if (task) tasks.delete(task); if (!current()) reject(stale()); else callback(value); };
      try { task = uni.uploadFile({ url: HTTP_REQUEST_URL + '/api/upload/image', filePath: path, fileType: 'image', name: 'file', formData: { filename: 'file' }, header: headers(false), timeout: TIMEOUT,
        success(response) { finish(() => { let body; try { body = JSON.parse(response.data); } catch (error) { reject(new Error('图片上传结果未确认')); return; } if (response.statusCode !== 200 || !body || body.status !== 200) reject(new Error(errorText(body))); else resolve(body.data); }); },
        fail() { finish(reject, new Error('图片上传结果未确认')); }, complete() { if (task) tasks.delete(task); } }); if (task) tasks.add(task); }
      catch (error) { finish(reject, error); }
    }); requireCurrent();
    if (!data || typeof data !== 'object' || Array.isArray(data) || typeof data.name !== 'string' || typeof data.url !== 'string') throw new Error('公开图片上传资料不完整');
    const reference = pictureRef(data.attachment_ref); pictureURL(data.url);
    return Object.freeze({ reference, preview: data.url });
  }
  requireCurrent();
  return Object.freeze({ current, dispose, upload,
    async sourceItems(rootId) { rootId = routeId(rootId); return quoteView(await json('GET', 'order/refund/cart_info/' + rootId, { contract: 2, fulfillment_page: 1, fulfillment_limit: 20 }), rootId, 1, 20); },
    quote: (rootId, itemId) => json('GET', 'order/reviews/' + routeId(rootId) + '/' + routeId(itemId), {}),
    create(intent) { return json('POST', 'order/reviews/' + routeId(intent.rootId) + '/' + routeId(intent.itemId), intentWire(intent)); }
  });
}