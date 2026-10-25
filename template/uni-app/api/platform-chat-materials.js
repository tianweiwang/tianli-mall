import store from '../store';
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '../config/app';

const drafts = new Map();
function problem(message = '聊天图片结果尚未完整确认，请保留本次草稿') { return new Error(message); }
function integer(value, min = 1, max = 4294967295) {
  if (typeof value === 'string' && /^(0|[1-9][0-9]*)(?![\s\S])/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < min || value > max) throw problem('聊天身份或房间尚未确认'); return value;
}
export function isPlatformChatRef(value) { return typeof value === 'string' && value.startsWith('platform-chat:'); }
function assetId(ref) {
  const m = typeof ref === 'string' && /^platform-chat:1:([1-9][0-9]{0,19})(?![\s\S])/.exec(ref);
  if (!m || m[1].length === 20 && m[1] > '18446744073709551615') throw problem(); return m[1];
}
function receipt(value) {
  const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join(',') !== keys.join(',')
    || value.complete !== false || typeof value.physical_complete !== 'boolean' || typeof value.phase !== 'string'
    || !/^[a-z_]{1,64}(?![\s\S])/.test(value.phase)
    || !['cleanup_complete', 'database_committed', 'physical_changed'].every(key => value[key] === null || typeof value[key] === 'boolean')) return null;
  return Object.freeze(keys.reduce((out, key) => { out[key] = value[key]; return out; }, {}));
}
function safeError(message, raw) { const e = problem(message); const value = receipt(raw); if (value) e.operationReceipt = value; return e; }
function currentFrame(getContext, ready = true) {
  const value = getContext(); const token = store.state.app.token, revision = store.state.app.authRevision;
  if (!value || value.kind !== 'api' || typeof token !== 'string' || !token || value.token !== token || !Number.isSafeInteger(revision)
    || value.revision !== revision || ready && value.ready !== true) throw problem('登录身份或聊天室已变化');
  const uid = integer(value.uid), peer = integer(value.peer);
  if (uid === peer || Number(store.state.app.uid) !== uid) throw problem('登录身份或聊天室已变化');
  return Object.freeze({ kind: 'api', uid, peer, tourist: 0, token, revision, route: String(value.route || '') });
}
function same(a, b) { return ['uid', 'peer', 'token', 'revision', 'route'].every(key => a[key] === b[key]); }
function key(frame) { return frame.uid + ':' + frame.peer; }
function scrub(entry) {
  entry.filePath = ''; entry.ref = ''; entry.body = null; entry.frame = Object.freeze({ ...entry.frame, token: '' });
  entry.phase = 'unknown'; entry.busy = false; entry.problem = '此前图片结果尚未确认，请先查看原聊天记录';
}
// Pending payloads are memory-only; logout removes private content/credentials even after page disposal.
store.subscribe(() => drafts.forEach((entry, slot) => {
  if (['confirmed', 'legacy'].includes(entry.phase) && entry.frame.revision !== store.state.app.authRevision) { drafts.delete(slot); return; }
  if (entry.frame.token && (entry.frame.token !== store.state.app.token || entry.frame.revision !== store.state.app.authRevision || entry.frame.uid !== Number(store.state.app.uid))) scrub(entry);
}));
function base(leaf) {
  if (typeof HTTP_REQUEST_URL !== 'string' || !/^https?:\/\//.test(HTTP_REQUEST_URL) || /[?#]/.test(HTTP_REQUEST_URL)) throw problem('聊天接口地址未确认');
  return HTTP_REQUEST_URL.replace(/\/+$/, '') + '/api/user/service/files' + leaf;
}
function headers(frame, json = false, ticket = null) {
  const out = { ...HEADER };
  Object.keys(out).forEach(name => { if (['content-type', 'authorization', TOKENNAME.toLowerCase(), 'x-platform-chat-read-ticket'].includes(name.toLowerCase())) delete out[name]; });
  out[TOKENNAME] = 'Bearer ' + frame.token; if (json) out['Content-Type'] = 'application/json';
  if (ticket) out['X-Platform-Chat-Read-Ticket'] = ticket; return out;
}
function status(entry) {
  if (!entry) return ''; if (entry.problem) return entry.problem;
  return { uploading: '私有图片上传中', staged: '图片已上传，尚未发送', unknown: '图片消息尚未确认，请保留草稿并查看聊天记录',
    legacy: '当前为旧图片模式，私有发布尚未启用', confirmed: '图片消息已保存' }[entry.phase] || '';
}

export function createPlatformChatScope(getContext, changed) {
  let alive = true, generation = 0; const tasks = new Set(), paths = new Set(), cached = new Map(), reading = new Map();
  function current(frame, version) { try { return alive && generation === version && same(frame, currentFrame(getContext)); } catch (e) { return false; } }
  function requireCurrent(frame, version) { if (!current(frame, version)) throw problem('聊天页面、身份或房间已变化'); }
  function emit() {
    let entry = null; try { entry = drafts.get(key(currentFrame(getContext, false))); } catch (e) {}
    if (alive) changed({ notice: status(entry), busy: !!(entry && entry.busy), blocked: !!(entry && ['uploading', 'staged', 'unknown'].includes(entry.phase)) });
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
  function releaseViews() {
    generation += 1; tasks.forEach(task => { if (task && typeof task.abort === 'function') task.abort(); }); tasks.clear(); paths.forEach(release); cached.clear(); reading.clear();
  }
  const unsubscribe = store.subscribe(() => {
    try { currentFrame(getContext, false); } catch (e) { releaseViews(); if (alive) changed({ notice: '登录身份已变化，预览已释放；已派发结果仍需查看原记录', busy: false, blocked: true, invalidated: true }); }
  });
  function json(frame, version, leaf, method, data) {
    requireCurrent(frame, version); return new Promise((resolve, reject) => {
      let task; const finish = (fn, value) => { if (task) tasks.delete(task); if (!current(frame, version)) reject(problem('聊天身份或页面已变化')); else fn(value); };
      task = uni.request({ url: base(leaf), method, data, header: headers(frame, method === 'POST'), timeout: TIMEOUT,
        success(response) { finish(() => { const body = response.data;
          if (response.statusCode !== 200 || !body || body.status !== 200) reject(safeError('聊天操作结果尚未完整确认', body && body.data)); else resolve(body.data);
        }); }, fail() { finish(reject, problem()); }, complete() { if (task) tasks.delete(task); } }); tasks.add(task);
    });
  }
  async function image(frame, version, ref, messageId) {
    const id = assetId(ref), cacheKey = id + ':' + (messageId || 'preview'); if (cached.has(cacheKey)) return cached.get(cacheKey);
    if (reading.has(cacheKey)) return reading.get(cacheKey);
    const pending = (async () => {
      const body = messageId === null ? { mode: 'preview' } : { mode: 'message', message_id: String(integer(messageId, 1, 2147483647)) };
      const ticket = await json(frame, version, '/' + id + '/read-ticket', 'POST', body);
      if (!ticket || Object.keys(ticket).sort().join(',') !== 'expires_in_ms,ticket' || typeof ticket.ticket !== 'string' || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(ticket.ticket)
        || !Number.isSafeInteger(ticket.expires_in_ms) || ticket.expires_in_ms < 1 || ticket.expires_in_ms > 300000) throw problem();
      let path;
      // #ifdef H5
      const target = new URL(base('/' + id + '/content'), window.location.href);
      if (target.origin !== window.location.origin || target.username || target.password || target.search || target.hash) throw problem('私有原图需已核对的同源接口');
      const controller = new AbortController(); tasks.add(controller); const timer = setTimeout(() => controller.abort(), TIMEOUT);
      try {
        const response = await fetch(target.href, { method: 'GET', headers: headers(frame, false, ticket.ticket), mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
        requireCurrent(frame, version); const mime = response.headers.get('Content-Type'), size = Number(response.headers.get('Content-Length'));
        if (response.status !== 200 || response.redirected || !['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(mime)
          || !Number.isSafeInteger(size) || size < 1 || size > 52428800 || response.headers.get('X-Content-Type-Options') !== 'nosniff'
          || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw problem('原图读取未完成');
        const blob = await response.blob(); requireCurrent(frame, version); if (blob.size !== size || blob.type !== mime) throw problem('原图读取未完成'); path = URL.createObjectURL(blob);
      } finally { clearTimeout(timer); tasks.delete(controller); }
      // #endif
      // #ifndef H5
      path = await new Promise((resolve, reject) => {
        let task; task = uni.downloadFile({ url: base('/' + id + '/content'), header: headers(frame, false, ticket.ticket), timeout: TIMEOUT,
          success(response) { const value = response.tempFilePath;
            if (typeof value === 'string' && value) paths.add(value);
            if (!current(frame, version) || response.statusCode !== 200 || typeof value !== 'string' || !value) { if (value) release(value); reject(problem('原图读取未完成')); } else resolve(value);
          }, fail() { reject(problem('原图读取未完成')); }, complete() { if (task) tasks.delete(task); } }); tasks.add(task);
      });
      // #endif
      paths.add(path);
      try {
        // #ifndef H5
        await new Promise((resolve, reject) => uni.getFileInfo({ filePath: path, success(info) { if (!Number.isSafeInteger(info.size) || info.size < 1 || info.size > 52428800) reject(problem()); else resolve(); }, fail() { reject(problem()); } }));
        // #endif
        await new Promise((resolve, reject) => uni.getImageInfo({ src: path, success(info) {
          if (!Number.isSafeInteger(info.width) || !Number.isSafeInteger(info.height) || info.width < 1 || info.height < 1
            || info.width > 4294967295 || info.height > 4294967295) { reject(problem()); return; }
          // H5's fixed SDK returns dimensions/path only; wire MIME/blob length were already strictly checked above.
          // #ifndef H5
          const type = typeof info.type === 'string' ? info.type.toLowerCase().replace(/^image\//, '') : '';
          if (!['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(type)) { reject(problem()); return; }
          // #endif
          resolve();
        }, fail() { reject(problem()); } }));
        requireCurrent(frame, version); cached.set(cacheKey, path); return path;
      } catch (e) { release(path); throw e; }
    })();
    reading.set(cacheKey, pending); try { return await pending; } finally { if (reading.get(cacheKey) === pending) reading.delete(cacheKey); }
  }
  async function hydrate(rows, setURL) {
    if (!rows.some(row => Number(row.msn_type) === 3 && isPlatformChatRef(row.msn))) { emit(); return; }
    const frame = currentFrame(getContext), version = generation;
    for (const row of rows) {
      if (Number(row.msn_type) !== 3 || !isPlatformChatRef(row.msn)) continue;
      const from = integer(row.uid), to = integer(row.to_uid);
      if ((from !== frame.uid && to !== frame.uid) || Number(row.is_tourist) !== 0) throw problem('图片不属于当前聊天参与者');
      const path = await image(frame, version, row.msn, integer(row.id, 1, 2147483647)); requireCurrent(frame, version); setURL(row, path);
      drafts.forEach(entry => {
        if (entry.phase === 'unknown' && entry.messageDispatched === true && entry.ref === row.msn && entry.frame.token === frame.token && entry.frame.uid === from && entry.frame.peer === to) {
          entry.phase = 'confirmed'; entry.filePath = ''; entry.body = null; entry.problem = ''; entry.busy = false;
          entry.frame = Object.freeze({ ...entry.frame, token: '' });
        }
      });
    } emit();
  }
  async function uploadAndSend(filePath, send) {
    const frame = currentFrame(getContext), version = generation, slot = key(frame), prior = drafts.get(slot);
    if (prior && ['uploading', 'staged', 'unknown'].includes(prior.phase)) throw problem('已有图片结果尚未确认，请先查看聊天记录');
    if (typeof filePath !== 'string' || !filePath) throw problem('请选择图片');
    const entry = { frame, filePath, ref: '', body: null, phase: 'uploading', busy: true, problem: '', receipt: null, dispatched: false, messageDispatched: false }; drafts.set(slot, entry); emit();
    try {
      const mode = await json(frame, version, '/capabilities', 'GET');
      if (!mode || Object.keys(mode).sort().join(',') !== 'private_read_supported,private_writes_enabled,protocol' || mode.protocol !== 1
        || typeof mode.private_writes_enabled !== 'boolean' || typeof mode.private_read_supported !== 'boolean') throw problem('聊天素材协议尚未确认');
      if (!mode.private_writes_enabled) throw problem('私有发布模式已变化，所选图片尚未上传');
      if (!mode.private_read_supported) throw problem('私有原图能力尚未确认'); requireCurrent(frame, version); entry.dispatched = true;
      const value = await new Promise((resolve, reject) => {
        let task; task = uni.uploadFile({ url: base(''), filePath, name: 'file', fileType: 'image', formData: { filename: 'file', service_uid: String(frame.peer) }, header: headers(frame), timeout: TIMEOUT,
          success(response) { if (!current(frame, version)) { reject(problem('聊天身份或页面已变化')); return; }
            let body; try { body = JSON.parse(response.data); } catch (e) { reject(problem()); return; }
            if (response.statusCode !== 200 || !body || body.status !== 200) reject(safeError('图片上传结果尚未确认', body && body.data)); else resolve(body.data);
          }, fail() { reject(problem()); }, complete() { if (task) tasks.delete(task); } }); tasks.add(task);
      });
      requireCurrent(frame, version); if (!value || Object.keys(value).sort().join(',') !== 'attachment_ref,name,url' || typeof value.name !== 'string') throw problem();
      const id = assetId(value.attachment_ref); if (value.url !== '/api/user/service/files/' + id + '/content') throw problem();
      entry.ref = value.attachment_ref; entry.phase = 'staged'; emit(); await image(frame, version, entry.ref, null); requireCurrent(frame, version);
      entry.body = Object.freeze({ msn: entry.ref, type: 3, to_uid: frame.peer }); entry.phase = 'unknown'; entry.busy = false; emit();
      entry.messageDispatched = true; await send(entry.ref, 3); return false;
    } catch (e) { entry.phase = entry.dispatched ? 'unknown' : 'selected'; entry.busy = false; entry.receipt = receipt(e && e.operationReceipt);
      entry.problem = entry.dispatched ? '图片操作结果尚未确认，请保留草稿并查看聊天记录' : '所选图片尚未上传，聊天协议或身份仍需确认'; emit(); throw safeError(entry.problem, entry.receipt); }
  }
  async function selectionMode() {
    const frame = currentFrame(getContext), version = generation, slot = key(frame), prior = drafts.get(slot);
    if (prior && (prior.busy || ['staged', 'unknown'].includes(prior.phase))) throw problem('已有图片结果尚未确认，请先查看原聊天记录');
    const entry = { frame, phase: 'choosing', busy: true, filePath: '', ref: '', body: null, problem: '', receipt: null }; drafts.set(slot, entry); emit();
    try {
      const mode = await json(frame, version, '/capabilities', 'GET');
      if (!mode || Object.keys(mode).sort().join(',') !== 'private_read_supported,private_writes_enabled,protocol' || mode.protocol !== 1
        || typeof mode.private_writes_enabled !== 'boolean' || typeof mode.private_read_supported !== 'boolean') throw problem('聊天素材协议尚未确认');
      if (mode.private_writes_enabled && !mode.private_read_supported) throw problem('私有原图能力尚未确认');
      entry.phase = mode.private_writes_enabled ? 'selected' : 'legacy'; entry.busy = false;
      if (!mode.private_writes_enabled) entry.frame = Object.freeze({ ...entry.frame, token: '' }); emit();
      return { private: mode.private_writes_enabled, current() { try { return alive && same(frame, currentFrame(getContext)); } catch (e) { return false; } } };
    } catch (e) { entry.phase = 'selected'; entry.busy = false; entry.problem = '聊天素材协议尚未确认，尚未上传'; emit(); throw problem(entry.problem); }
  }
  function uncertain(raw) { let entry; try { entry = drafts.get(key(currentFrame(getContext, false))); } catch (e) {}
    if (entry && ['uploading', 'staged', 'unknown'].includes(entry.phase)) { entry.phase = 'unknown'; entry.busy = false; entry.receipt = receipt(raw && raw.receipt); entry.problem = '图片消息结果尚未确认，请保留草稿并查看聊天记录'; } emit(); }
  function dispose() { releaseViews(); alive = false; unsubscribe(); }
  function owns(path) { try { currentFrame(getContext); return alive && paths.has(path); } catch (e) { return false; } }
  return { uploadAndSend, selectionMode, hydrate, uncertain, releaseViews, dispose, owns, refresh: emit };
}
