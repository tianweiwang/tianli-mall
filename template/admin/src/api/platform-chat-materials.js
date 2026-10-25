import Setting from '@/setting';
import Cookies from 'js-cookie';
import { getCookies } from '@/libs/util';

const types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
const drafts = new Map();
const transfers = new Map();
let credentialTimer = null;
function failure(message = '聊天图片操作尚未完整确认，请保留草稿') { return new Error(message); }
function integer(value, min = 1, max = 4294967295) {
  if (typeof value === 'string' && /^(0|-?[1-9][0-9]*)(?![\s\S])/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < min || value > max) throw failure('聊天身份或房间尚未确认'); return value;
}
export function isPlatformChatRef(value) { return typeof value === 'string' && value.startsWith('platform-chat:'); }
function assetId(ref) {
  const m = typeof ref === 'string' && /^platform-chat:1:([1-9][0-9]{0,19})(?![\s\S])/.exec(ref);
  if (!m || m[1].length === 20 && m[1] > '18446744073709551615') throw failure(); return m[1];
}
export function platformChatReceipt(value) {
  const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).sort().join(',') !== keys.join(',')
    || value.complete !== false || typeof value.physical_complete !== 'boolean' || typeof value.phase !== 'string'
    || !/^[a-z_]{1,64}(?![\s\S])/.test(value.phase)
    || !['cleanup_complete', 'database_committed', 'physical_changed'].every(key => value[key] === null || typeof value[key] === 'boolean')) return null;
  return Object.freeze(keys.reduce((out, key) => { out[key] = value[key]; return out; }, {}));
}
function safeError(receipt, message) { const e = failure(message); const value = platformChatReceipt(receipt); if (value) e.operationReceipt = value; return e; }
function credential(frame) {
  try {
  if (frame.tokenSource === 'kefu-cookie') return getCookies('kefu_token') || '';
  if (frame.tokenSource === 'user-cookie') { const value = Cookies.get('auth._token.local1') || ''; return value.startsWith('Bearer ') ? value.slice(7) : ''; }
  if (frame.tokenSource === 'user-storage') return window.localStorage.getItem('LOGIN_STATUS_TOKEN') || '';
  return frame.kind === 'guest' && frame.expiresAt > Math.floor(Date.now() / 1000) ? frame.token : '';
  } catch (e) { return ''; }
}
function frameOf(getContext, requireReady = true) {
  const raw = getContext(); if (!raw || !['api', 'kefu', 'guest'].includes(raw.kind) || typeof raw.token !== 'string' || !raw.token) throw failure('聊天身份尚未确认');
  const uid = integer(raw.uid, -2147483647), peer = integer(raw.peer, -2147483647), tourist = integer(raw.tourist, 0, 1);
  const frame = { kind: raw.kind, uid, peer, tourist, token: raw.token, tokenSource: raw.tokenSource, serviceId: raw.kind === 'kefu' ? integer(raw.serviceId) : null,
    route: String(raw.route || ''), expiresAt: raw.kind === 'guest' ? integer(raw.expiresAt) : null };
  if (!uid || !peer || uid === peer || raw.kind === 'guest' && (uid >= 0 || tourist !== 1)
    || raw.kind !== 'guest' && uid < 1 || raw.kind !== 'kefu' && peer < 1 || credential(frame) !== frame.token
    || requireReady && (raw.ready !== true || raw.active === false)) throw failure('聊天身份或房间已变化，请重新进入');
  return Object.freeze(frame);
}
function same(a, b) { return a && b && ['kind', 'uid', 'peer', 'tourist', 'token', 'tokenSource', 'serviceId', 'route', 'expiresAt'].every(key => a[key] === b[key]); }
function slot(frame) { return [frame.kind, frame.uid, frame.peer, frame.tourist].join(':'); }
function scrub(entry) {
  entry.file = null; entry.ref = ''; entry.body = null; entry.frame = Object.freeze({ ...entry.frame, token: '' });
  entry.phase = 'unknown'; entry.busy = false; entry.problem = '此前图片或转接结果尚未确认，请先查看原聊天记录';
}
function watchCredentials() {
  if (credentialTimer !== null) return;
  credentialTimer = window.setInterval(() => {
    let sensitive = false;
    [drafts, transfers].forEach(map => map.forEach(entry => {
      if (['confirmed', 'legacy'].includes(entry.phase)) { map.delete(slot(entry.frame)); return; }
      if (!entry.frame.token) return;
      if (credential(entry.frame) !== entry.frame.token) scrub(entry); else sensitive = true;
    }));
    if (!sensitive) { window.clearInterval(credentialTimer); credentialTimer = null; }
  }, 1000);
}
function target(frame, leaf) {
  const base = new URL(Setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw failure('私有图片需已核对的同源接口');
  const prefix = base.pathname.replace(/\/adminapi\/?$/, frame.kind === 'api' ? '/api/' : '/kefuapi/');
  if (prefix === base.pathname) throw failure('聊天接口前缀尚未确认');
  const path = (frame.kind === 'api' ? 'user/service/files' : frame.kind === 'kefu' ? 'service/files' : 'tourist/files') + leaf;
  const value = new URL(prefix + path, base.origin);
  if (value.origin !== base.origin || value.search || value.hash || value.username || value.password) throw failure(); return value.href;
}
function headers(frame, json = false, ticket = null) {
  const out = frame.kind === 'guest' ? { 'X-Platform-Chat-Guest-Token': frame.token } : { 'Authori-zation': 'Bearer ' + frame.token };
  if (json) out['Content-Type'] = 'application/json'; if (ticket) out['X-Platform-Chat-Read-Ticket'] = ticket; return out;
}
function legacyTarget(frame) {
  const base = new URL(Setting.apiBaseURL, window.location.origin);
  if (base.origin !== window.location.origin || !/^https?:$/.test(base.protocol) || base.username || base.password || base.search || base.hash
    || !/\/adminapi\/?$/.test(base.pathname) || frame.kind === 'guest') throw failure('旧图片接口身份尚未确认');
  base.pathname = base.pathname.replace(/\/adminapi\/?$/, frame.kind === 'kefu' ? '/kefuapi/upload' : '/kefuapi/tourist/upload'); return base.href;
}
function notice(entry) {
  if (!entry) return '';
  if (entry.problem) return entry.problem;
  return { uploading: '私有图片上传中', staged: '图片已上传，尚未发送', unknown: '图片消息结果尚未确认，请保留草稿并查看聊天记录',
    confirmed: '图片消息已保存', legacy: '当前为旧图片模式，私有发布尚未启用' }[entry.phase] || '';
}

/** Each component supplies the actual socket-confirmed actor and current room, never a platform admin token. */
export function createPlatformChatScope(getContext, changed) {
  let alive = true, generation = 0, identityTimer = null; const tasks = new Set(), urls = new Map(), ownedURLs = new Set(), reads = new Map();
  function current(frame, version) { try { return alive && version === generation && same(frame, frameOf(getContext)); } catch (e) { return false; } }
  function requireCurrent(frame, version) { if (!current(frame, version)) throw failure('聊天页面、身份或房间已变化'); }
  function watchIdentity(frame) {
    if (identityTimer !== null) window.clearInterval(identityTimer);
    identityTimer = window.setInterval(() => {
      if (credential(frame) !== frame.token) {
        releaseViews();
        if (alive) changed({ notice: '登录身份已变化，私有预览已释放；已派发结果仍需查看原记录', busy: false, blocked: true, invalidated: true });
      }
    }, 1000);
  }
  function emit() {
    let entry = null; try { entry = drafts.get(slot(frameOf(getContext, false))); } catch (e) {}
    if (alive) changed({ notice: notice(entry), busy: !!(entry && entry.busy), blocked: !!(entry && ['uploading', 'staged', 'unknown'].includes(entry.phase)) });
  }
  async function request(frame, version, leaf, method, body, binary = false, ticket = null) {
    requireCurrent(frame, version); const controller = new AbortController(); tasks.add(controller);
    const timer = window.setTimeout(() => controller.abort(), 100000);
    try {
      const response = await fetch(target(frame, leaf), { method, headers: headers(frame, body && !(body instanceof FormData), ticket), body,
        credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal });
      requireCurrent(frame, version);
      if (binary) {
        const type = response.headers.get('Content-Type'), size = Number(response.headers.get('Content-Length'));
        if (response.status !== 200 || response.redirected || !types.includes(type) || !Number.isSafeInteger(size) || size < 1 || size > 52428800
          || response.headers.get('X-Content-Type-Options') !== 'nosniff' || !(response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store')) throw failure('原图读取未完成');
        const blob = await response.blob(); requireCurrent(frame, version);
        if (blob.size !== size || blob.type !== type) throw failure('原图读取未完成'); return blob;
      }
      const envelope = await response.json(); requireCurrent(frame, version);
      if (response.status !== 200 || !envelope || envelope.status !== 200) throw safeError(envelope && envelope.data);
      return envelope.data;
    } catch (e) { throw safeError(e && e.operationReceipt, e && e.message || '聊天操作结果尚未确认'); }
    finally { window.clearTimeout(timer); tasks.delete(controller); }
  }
  async function capabilities(frame, version) {
    const value = await request(frame, version, '/capabilities', 'GET');
    if (!value || Object.keys(value).sort().join(',') !== 'private_read_supported,private_writes_enabled,protocol' || value.protocol !== 1
      || typeof value.private_writes_enabled !== 'boolean' || typeof value.private_read_supported !== 'boolean') throw failure('聊天素材协议尚未确认'); return value;
  }
  async function readImage(frame, version, ref, messageId) {
    const id = assetId(ref), key = id + ':' + (messageId || 'preview'); if (urls.has(key)) return urls.get(key);
    if (reads.has(key)) return reads.get(key);
    const pending = (async () => {
      const body = messageId === null ? { mode: 'preview' } : { mode: 'message', message_id: String(integer(messageId, 1, 2147483647)) };
      const ticket = await request(frame, version, '/' + id + '/read-ticket', 'POST', JSON.stringify(body));
      if (!ticket || Object.keys(ticket).sort().join(',') !== 'expires_in_ms,ticket' || typeof ticket.ticket !== 'string'
        || !/^[A-Za-z0-9_-]{43}(?![\s\S])/.test(ticket.ticket) || !Number.isSafeInteger(ticket.expires_in_ms) || ticket.expires_in_ms < 1 || ticket.expires_in_ms > 300000) throw failure();
      const blob = await request(frame, version, '/' + id + '/content', 'GET', undefined, true, ticket.ticket); requireCurrent(frame, version);
      const url = URL.createObjectURL(blob); ownedURLs.add(url);
      try {
        let decode;
        try {
          await new Promise((resolve, reject) => { const image = new Image();
            decode = { abort() { image.onload = null; image.onerror = null; image.src = ''; reject(failure('原图读取已中止')); } }; tasks.add(decode);
            image.onload = () => image.naturalWidth > 0 && image.naturalHeight > 0 ? resolve() : reject(failure()); image.onerror = () => reject(failure()); image.src = url;
          });
        } finally { if (decode) tasks.delete(decode); }
        requireCurrent(frame, version); urls.set(key, url); return url;
      } catch (e) { if (ownedURLs.has(url)) URL.revokeObjectURL(url); ownedURLs.delete(url); throw e; }
    })();
    reads.set(key, pending); try { return await pending; } finally { if (reads.get(key) === pending) reads.delete(key); }
  }
  function rowParticipant(frame, row) {
    if (!row || Number(row.msn_type) !== 3 || !isPlatformChatRef(row.msn)) throw failure(); assetId(row.msn);
    const from = integer(row.uid, -2147483647), to = integer(row.to_uid, -2147483647), tourist = integer(row.is_tourist, 0, 1);
    if (from !== frame.uid && to !== frame.uid || tourist !== frame.tourist) throw failure('图片不属于当前聊天参与者');
    if (frame.kind === 'kefu' && (from === frame.uid ? to : from) !== frame.peer) throw failure('图片不属于当前房间');
    return integer(row.id, 1, 2147483647);
  }
  async function hydrate(rows, setURL) {
    if (!rows.some(row => Number(row.msn_type) === 3 && isPlatformChatRef(row.msn))) { emit(); return; }
    const frame = frameOf(getContext), version = generation; watchIdentity(frame);
    for (const row of rows) {
      if (Number(row.msn_type) !== 3 || !isPlatformChatRef(row.msn)) continue;
      const id = rowParticipant(frame, row), url = await readImage(frame, version, row.msn, id); requireCurrent(frame, version); setURL(row, url);
      drafts.forEach(entry => {
        if (entry.phase === 'unknown' && entry.messageDispatched === true && entry.ref === row.msn && entry.frame.kind === frame.kind && entry.frame.uid === frame.uid && entry.frame.token === frame.token
          && Number(row.uid) === entry.frame.uid && Number(row.to_uid) === entry.frame.peer && Number(row.is_tourist) === entry.frame.tourist) {
          entry.phase = 'confirmed'; entry.file = null; entry.body = null; entry.problem = ''; entry.busy = false;
        }
      });
    } emit();
  }
  async function uploadAndSend(file, send) {
    const frame = frameOf(getContext), version = generation, key = slot(frame); let entry = drafts.get(key);
    if (entry && ['uploading', 'staged', 'unknown'].includes(entry.phase)) throw failure('已有图片结果尚未确认，请先查看聊天记录');
    if (frame.kind === 'guest' || !(file instanceof File) || file.size < 1 || file.size > 52428800) throw failure('请选择现有允许的图片');
    entry = { frame, file, ref: '', body: null, phase: 'uploading', busy: true, problem: '', receipt: null, dispatched: false, messageDispatched: false }; drafts.set(key, entry); watchCredentials(); watchIdentity(frame); emit();
    try {
      const mode = await capabilities(frame, version); requireCurrent(frame, version);
      if (!mode.private_writes_enabled) { entry.phase = 'legacy'; entry.file = null; entry.busy = false; emit(); return true; }
      if (!mode.private_read_supported) throw failure('私有原图能力尚未确认');
      const form = new FormData(); form.append('filename', 'file'); form.append('file', file);
      if (frame.kind === 'api') form.append('service_uid', String(frame.peer)); else { form.append('peer_uid', String(frame.peer)); form.append('is_tourist', String(frame.tourist)); }
      entry.dispatched = true; const value = await request(frame, version, '', 'POST', form); requireCurrent(frame, version);
      if (!value || Object.keys(value).sort().join(',') !== 'attachment_ref,name,url' || typeof value.name !== 'string' || typeof value.url !== 'string') throw failure();
      const id = assetId(value.attachment_ref); if (value.url !== (frame.kind === 'api' ? '/api/user' : '/kefuapi') + '/service/files/' + id + '/content') throw failure();
      entry.ref = value.attachment_ref; entry.phase = 'staged'; emit();
      await readImage(frame, version, entry.ref, null); requireCurrent(frame, version);
      entry.body = Object.freeze({ msn: entry.ref, type: 3, to_uid: frame.peer }); entry.phase = 'unknown'; entry.busy = false; emit();
      // The original socket dispatch is called once; only a real saved row/content read may confirm it.
      entry.messageDispatched = true; await send(entry.ref, 3); emit(); return false;
    } catch (e) { entry.phase = entry.dispatched ? 'unknown' : 'selected'; entry.busy = false;
      entry.problem = entry.dispatched ? '图片操作结果尚未完整确认，请保留草稿并查看聊天记录' : '所选图片尚未上传，聊天协议或身份仍需确认';
      entry.receipt = platformChatReceipt(e && e.operationReceipt); emit(); throw safeError(entry.receipt, entry.problem); }
  }
  function uncertain(raw) {
    let entry; try { entry = drafts.get(slot(frameOf(getContext, false))); } catch (e) {}
    if (entry && ['uploading', 'staged', 'unknown'].includes(entry.phase)) { entry.phase = 'unknown'; entry.busy = false; entry.receipt = platformChatReceipt(raw && raw.receipt); entry.problem = '图片消息结果尚未确认，请保留草稿并查看聊天记录'; } emit();
  }
  async function legacyUpload(file, send) {
    const frame = frameOf(getContext), version = generation, entry = drafts.get(slot(frame));
    if (!entry || entry.phase !== 'legacy' || !(file instanceof File)) throw failure('旧图片模式尚未确认');
    const form = new FormData(); form.append('filename', 'file'); form.append('file', file);
    if (frame.kind === 'api') form.append('token', frame.token);
    const controller = new AbortController(); tasks.add(controller); const timer = window.setTimeout(() => controller.abort(), 100000);
    try {
      const response = await fetch(legacyTarget(frame), { method: 'POST', headers: headers(frame), body: form,
        credentials: 'same-origin', mode: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
      requireCurrent(frame, version); const value = await response.json(); requireCurrent(frame, version);
      if (response.status !== 200 || !value || value.status !== 200 || !value.data || typeof value.data.url !== 'string' || !value.data.url || isPlatformChatRef(value.data.url)) throw failure('旧图片上传结果未确认');
      return send(value.data.url, 3);
    } catch (e) { throw failure('旧图片上传结果未确认，请查看当前聊天'); }
    finally { window.clearTimeout(timer); tasks.delete(controller); }
  }
  function releaseViews() { generation += 1; if (identityTimer !== null) window.clearInterval(identityTimer); identityTimer = null;
    tasks.forEach(task => task.abort()); tasks.clear(); ownedURLs.forEach(url => URL.revokeObjectURL(url)); ownedURLs.clear(); urls.clear(); reads.clear(); }
  function dispose() { releaseViews(); alive = false; }
  return { uploadAndSend, legacyUpload, hydrate, uncertain, releaseViews, dispose, refresh: emit };
}

/** No retry or invented transfer-confirmation endpoint; original HTTP success alone completes this UI action. */
export async function guardedPlatformChatTransfer(getContext, body, dispatch) {
  const frame = frameOf(getContext); if (frame.kind !== 'kefu') throw failure('客服身份尚未确认'); const key = slot(frame);
  const prior = transfers.get(key); if (prior) throw failure('此前转接结果尚未确认，请先查看原聊天记录');
  const input = Object.freeze({ uid: integer(body.uid, -2147483647), kefuToUid: integer(body.kefuToUid) });
  if (input.uid !== frame.peer) throw failure('转接房间已变化');
  const entry = { frame, phase: 'unknown', body: input, file: null, ref: '', busy: true, problem: '', receipt: null }; transfers.set(key, entry); watchCredentials();
  try {
    const response = await dispatch(input, frame);
    if (!same(frame, frameOf(getContext)) || !response || response.status !== 200) throw safeError(response && response.data);
    transfers.delete(key); return response;
  } catch (e) {
    entry.busy = false; entry.receipt = platformChatReceipt(e && (e.operationReceipt || e.data || e.response && e.response.data && e.response.data.data));
    entry.problem = '转接结果尚未确认，请保留当前选择并查看原聊天记录'; throw safeError(entry.receipt, entry.problem);
  }
}
export function platformChatTransferPending(getContext) { try { return transfers.has(slot(frameOf(getContext, false))); } catch (e) { return true; } }

export const platformChatMaterialMixin = {
  data: () => ({ chatMaterialNotice: '', chatMaterialBusy: false, chatMaterialBlocked: false }),
  computed: {
    chatMaterialRows() { return this.platformChatRows(); },
    chatMaterialRoomKey() { try { const f = this.platformChatContext(); return [f.kind, f.uid, f.peer, f.tourist, f.route, f.ready].join(':'); } catch (e) { return ''; } },
  },
  created() {
    this._chatMaterials = createPlatformChatScope(() => this.platformChatContext(), value => {
      this.chatMaterialNotice = value.notice; this.chatMaterialBusy = value.busy; this.chatMaterialBlocked = value.blocked;
      if (value.invalidated) { this.chatMaterialCloseViewers(); this.platformChatRows().forEach(row => this.$set(row, '_platformChatURL', '')); }
    });
  },
  watch: {
    chatMaterialRows() { this.chatMaterialRefresh(); },
    chatMaterialRoomKey() { this.chatMaterialCloseViewers(); if (this._chatMaterials) { this._chatMaterials.releaseViews(); this.platformChatRows().forEach(row => this.$set(row, '_platformChatURL', '')); this._chatMaterials.refresh(); } this.chatMaterialRefresh(); },
    '$route.fullPath'() { this.chatMaterialCloseViewers(); if (this._chatMaterials) this._chatMaterials.releaseViews(); },
  },
  beforeDestroy() { this.chatMaterialCloseViewers(); if (this._chatMaterials) this._chatMaterials.dispose(); },
  activated() { this.chatMaterialRefresh(); },
  deactivated() { this.chatMaterialCloseViewers(); if (this._chatMaterials) this._chatMaterials.releaseViews(); this.platformChatRows().forEach(row => this.$set(row, '_platformChatURL', '')); },
  methods: {
    chatMaterialCloseViewers() {
      if (!this.$el || typeof this.$el.querySelectorAll !== 'function') return;
      this.$el.querySelectorAll('.platform-chat-material-viewer').forEach(node => {
        if (node.$viewer && typeof node.$viewer.hide === 'function') node.$viewer.hide(true);
      });
    },
    chatMaterialImage(row) { return isPlatformChatRef(row.msn) ? row._platformChatURL || '' : row.msn; },
    chatMaterialRefresh() {
      if (!this._chatMaterials) return;
      this._chatMaterials.hydrate(this.platformChatRows(), (row, url) => this.$set(row, '_platformChatURL', url))
        .catch(() => { if (!this._isDestroyed && !this._isBeingDestroyed) this.chatMaterialNotice = '部分私有原图尚未读取，请重新查看当前聊天'; });
    },
    async chatMaterialBeforeUpload(file) {
      try { return await this._chatMaterials.uploadAndSend(file, (ref, type) => this.sendMsg(ref, type)); }
      catch (e) { this.chatMaterialNotice = e.message; return false; }
    },
    async chatMaterialLegacyFile(file) {
      try { return await this._chatMaterials.legacyUpload(file, (url, type) => this.sendMsg(url, type)); }
      catch (e) { this.chatMaterialNotice = e.message; }
    },
    chatMaterialUncertain(value) { if (this._chatMaterials) this._chatMaterials.uncertain(value); },
  },
};
