'use strict';

// Normal T7B01 browser checks for the registered dedicated isolated RUN.
// No token/cookie/storage injection, internal response mocking, trace or HAR.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import os from 'node:os';
import { setTimeout as pause } from 'node:timers/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

const ROOT = '/run/audit-browser-evidence';
const SECRET = '/run/audit-browser-secret/browser-login.json';
const READY = '/run/audit-browser-secret/ready.json';
const ORIGIN = 'http://audit-ui:8080';
let input;
let ready;
let browser;
let stageName = 'desktop';
let httpQueue = Promise.resolve();
let httpFailure;
let fatal;
let submitted = false;
const contexts = [];
const closedContexts = new Set();
const result = {
  status: 'FAIL', contexts_closed: false,
  stages: [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }],
  login_http_success: 0, guest_http_success: 0,
  identity_acks: [], normal_texts: [], room_acks: [], screenshots: [], control_checks: [], action_controls: [],
  message_avatars: [], brand_checks: [],
  external_requests_blocked_count: 0, external_requests_blocked: [],
  client_http_count: 0, console_counts: { external_blocked: 0, application_error: 0, page_error: 0 },
  resource_counts: {},
};
const blocked = new Map();
const internalApiPaths = new Set([
  '/adminapi/get_workerman_url', '/adminapi/custom_admin_js',
  '/kefuapi/config', '/kefuapi/login', '/kefuapi/service/info',
  '/kefuapi/user/record', '/kefuapi/service/list', '/kefuapi/service/transfer_list',
  '/kefuapi/service/cate', '/kefuapi/service/speechcraft',
  '/kefuapi/tourist/user', '/kefuapi/tourist/adv', '/kefuapi/tourist/chat',
]);
const stages = new Map();

function fail(code) { const e = new Error(); e.publicCode = code; throw e; }
function check(value, code) { if (!value) fail(code); }
function sha(text) { return crypto.createHash('sha256').update(text).digest('hex'); }
function readJson(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }
function atomic(file, value) {
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(value) + '\n', { mode: 0o600 });
  fs.renameSync(tmp, file);
}
function healthy() {
  if (httpFailure) fail(httpFailure);
  if (fatal) fail(fatal);
  check(Date.now() < ready.normal_deadline * 1000, 'NORMAL_DEADLINE_REACHED');
}
async function until(fn, code, timeout = 60000) {
  const deadline = Math.min(Date.now() + timeout, ready.normal_deadline * 1000);
  while (Date.now() < deadline) {
    healthy();
    if (await fn()) return;
    await pause(25);
  }
  fail(code);
}
async function command(action, event) {
  if (observerTerminal()) fail('OBSERVER_TERMINATED');
  const revision = crypto.randomBytes(8).toString('hex');
  atomic(path.join(ROOT, 'control.json'), { run_id: input.run_id, revision, action, ...(event ? { event } : {}) });
  const deadline = Math.min(Date.now() + 30000, ready.normal_deadline * 1000);
  while (Date.now() < deadline) {
    let reply;
    try { reply = readJson(path.join(ROOT, 'status.json')); } catch {}
    if (reply && reply.run_id === input.run_id && reply.revision === revision) {
      check(reply.status === action && (action !== 'client_http' ||
        reply.registered_current_source === true), 'CONTROL_ACK_UNAVAILABLE');
      return reply;
    }
    if (reply && reply.run_id === input.run_id && ['abort', 'finish'].includes(reply.status)) {
      fail('OBSERVER_TERMINATED');
    }
    await pause(25);
  }
  fail('CONTROL_ACK_TIMEOUT');
}
async function drain() { await httpQueue; healthy(); }
async function permission() {
  const waiting = { state: 'waiting', node_pid: process.pid, hostname: os.hostname(),
    uid: process.getuid(), gid: process.getgid() };
  if (typeof process.env.AUDIT_RUN_ID === 'string') {
    waiting.run_id = process.env.AUDIT_RUN_ID; result.run_id = waiting.run_id;
  }
  if (/^[a-f0-9]{40}$/.test(process.env.AUDIT_SOURCE_SHA || '')) {
    waiting.source_sha = process.env.AUDIT_SOURCE_SHA; result.source_sha = waiting.source_sha;
  }
  check(waiting.uid === 0 && waiting.gid === 0, 'RUNNER_IDENTITY_MISMATCH');
  const deadline = Date.now() + 900000;
  let wroteStartup = false;
  while (Date.now() < deadline) {
    if (!wroteStartup) {
      try {
        fs.mkdirSync(ROOT, { recursive: true });
        atomic(path.join(ROOT, 'startup.json'), waiting);
        wroteStartup = true;
      } catch (error) {
        if (error.code === 'EACCES') fail('PREPARATION_OUTPUT_UNWRITABLE');
      }
    }
    try {
      const terminal = readJson(path.join(ROOT, 'status.json'));
      if (terminal.run_id === waiting.run_id && ['finish', 'abort'].includes(terminal.status)) {
        fail('PREPARATION_TERMINATED');
      }
    } catch (error) {
      if (error.publicCode) throw error;
    }
    // Pure files only: no Chromium import/launch, credential use, HTTP or WS.
    if (fs.existsSync(SECRET) && fs.existsSync(READY)) {
      try {
        const permit = readJson(READY);
        if (permit && typeof permit.generation === 'string' && permit.generation.length > 0) return;
      } catch (error) {
        if (error.code === 'EACCES') fail('PREPARATION_INPUT_UNREADABLE');
      }
    }
    await pause(250);
  }
  fail('PREPARATION_PERMISSION_TIMEOUT');
}
function finishMetadata() {
  result.external_requests_blocked = Array.from(blocked, ([key, count]) => {
    const split = key.lastIndexOf('|');
    return { origin: key.slice(0, split), category: key.slice(split + 1), count };
  });
}
function observerTerminal() {
  try {
    const last = readJson(path.join(ROOT, 'status.json'));
    return last.run_id === input.run_id && ['finish', 'abort'].includes(last.status);
  } catch { return false; }
}
function coordinationFailure(code, reply) {
  const receipt = { run_id: input.run_id, source_sha: input.source_sha, status: 'FAIL',
    stage: 'coordination', failure_code: code, contexts_closed: result.contexts_closed,
    php_result: reply && ['PASS', 'FAIL'].includes(reply.result) ? reply.result : 'UNAVAILABLE',
    ordinary_cleanup_complete: Boolean(reply && reply.ordinary_cleanup_complete === true) };
  try { atomic(path.join(ROOT, 'coordination-result.json'), receipt); } catch {}
  console.log(JSON.stringify(receipt));
  process.exitCode = 1;
}
function readonlySecretMount() {
  const lines = fs.readFileSync('/proc/self/mountinfo', 'utf8').split('\n');
  return lines.some((line) => {
    const fields = line.split(' ');
    return [SECRET, '/run/audit-browser-secret'].includes(fields[4]) && fields[5].split(',').includes('ro');
  });
}
function scopeInput() {
  check(readonlySecretMount(), 'PRIVATE_INPUT_NOT_READONLY');
  check(!fs.existsSync('/var/run/docker.sock'), 'UNEXPECTED_DOCKER_SOCKET');
  check(fs.statSync(SECRET).size <= 16384, 'PRIVATE_INPUT_TOO_LARGE');
  input = readJson(SECRET); ready = readJson(READY);
  check(typeof input.run_id === 'string' && input.run_id.length > 0 &&
    typeof input.source_sha === 'string' && /^[a-f0-9]{40}$/.test(input.source_sha) &&
    input.url === ORIGIN && /^[a-zA-Z0-9]{4,30}$/.test(input.account) &&
    typeof input.password === 'string' && input.password.length > 0 && input.password.length <= 1024 &&
    Number.isSafeInteger(input.staff_uid) && input.staff_uid > 0, 'PRIVATE_INPUT_CONTRACT');
  check(ready.run_id === input.run_id && ready.source_sha === input.source_sha &&
    ready.staff_uid === input.staff_uid && ready.guard_mode === 'read-only' &&
    typeof ready.generation === 'string' && ready.generation.length > 0 &&
    typeof ready.app_generation === 'string' && ready.app_generation.length > 0 &&
    Number.isSafeInteger(ready.normal_deadline) && ready.normal_deadline * 1000 > Date.now(), 'READY_CONTRACT');
  check(ready.browser_identity && /^[a-f0-9]{64}$/.test(ready.browser_identity.id) &&
    ready.browser_identity.image_id === 'sha256:12f8a18ee81e6496442101b53ed2e9b2f68eef56cbb63ed4393b5a81cf9e1dbe' &&
    os.hostname() === ready.browser_identity.id.slice(0, 12), 'BROWSER_CONTAINER_IDENTITY');
  check(typeof input.staff_avatar === 'string' && new URL(input.staff_avatar).origin === ORIGIN,
    'STAFF_AVATAR_SOURCE');
  const fallback = input.default_avatar;
  check(fallback && ['inline', 'file'].includes(fallback.representation) && fallback.jpeg === true &&
    /^[a-f0-9]{64}$/.test(fallback.sha256) && /^[a-f0-9]{64}$/.test(fallback.source_sha256) &&
    Number.isSafeInteger(fallback.bytes) && fallback.bytes > 0 &&
    (fallback.representation === 'inline' ? fallback.path === null :
      /^\/admin\/system_static\/img\/[a-zA-Z0-9_.-]+\.jpe?g$/.test(fallback.path)), 'DEFAULT_AVATAR_SOURCE');
  check(typeof input.guest_avatar_site_url === 'string', 'GUEST_AVATAR_SITE_SOURCE');
  const names = ['desktop_guest', 'desktop_kefu', 'mobile_guest', 'mobile_kefu'];
  check(input.texts && names.every((n) => typeof input.texts[n] === 'string' &&
    input.texts[n].length > 0 && input.texts[n].length <= 2000) &&
    new Set(names.map((n) => input.texts[n])).size === 4, 'NORMAL_TEXT_CONTRACT');
  result.run_id = input.run_id; result.source_sha = input.source_sha;
  result.guard_mode = ready.guard_mode;
  result.node_pid = process.pid;
  result.browser_container = { id: ready.browser_identity.id, image_id: ready.browser_identity.image_id };
}
async function contextFor(name) {
  const options = name === 'desktop' ? { viewport: { width: 1440, height: 900 } } : {
    viewport: { width: 390, height: 844 }, screen: { width: 390, height: 844 },
    isMobile: true, hasTouch: true, deviceScaleFactor: 1,
    userAgent: 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Mobile Safari/537.36',
  };
  const context = await browser.newContext(options);
  contexts.push(context);
  context.on('close', () => closedContexts.add(context));
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === ORIGIN || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
    const category = route.request().resourceType();
    const key = url.origin + '|' + category;
    blocked.set(key, (blocked.get(key) || 0) + 1);
    result.external_requests_blocked_count++;
    await route.abort('blockedbyclient');
  });
  return context;
}
function observe(page, name, kind) {
  const stage = stages.get(name);
  const actor = { kind, uid: kind === 'kefu' ? input.staff_uid : null, pendingRooms: [], roomCount: 0, closing: false };
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    if (message.text().includes('ERR_BLOCKED_BY_CLIENT')) result.console_counts.external_blocked++;
    else { result.console_counts.application_error++; fatal = 'APPLICATION_CONSOLE_ERROR'; }
  });
  page.on('pageerror', () => { result.console_counts.page_error++; fatal = 'PAGE_ERROR'; });
  page.on('requestfailed', (request) => {
    if (actor.closing) return;
    try { if (new URL(request.url()).origin === ORIGIN) fatal = 'INTERNAL_RESOURCE_FAILED'; }
    catch { fatal = 'INTERNAL_RESOURCE_URL'; }
  });
  page.on('response', (response) => {
    const responseUrl = new URL(response.url());
    if (responseUrl.origin !== ORIGIN) return;
    const captureListPreview = name === 'mobile' && kind === 'kefu' && stage.expectListPreview === true;
    if (!responseUrl.pathname.startsWith('/adminapi/') && !responseUrl.pathname.startsWith('/kefuapi/') &&
      !responseUrl.pathname.startsWith('/api/')) {
      const category = response.request().resourceType() + ':' + response.status();
      result.resource_counts[category] = (result.resource_counts[category] || 0) + 1;
      if (response.status() < 200 || response.status() >= 400) fatal = 'NORMAL_STATIC_UNSUCCESSFUL';
      return;
    }
    // Only API response metadata enters the serialized PHP handshake.
    httpQueue = httpQueue.then(async () => {
      const url = new URL(response.url());
      if (url.origin !== ORIGIN) return;
      const method = response.request().method(), pathname = url.pathname;
      let businessStatus = null, meta, listPreview;
      if (pathname.startsWith('/adminapi/') || pathname.startsWith('/kefuapi/') || pathname.startsWith('/api/')) {
        check(internalApiPaths.has(pathname), 'UNEXPECTED_INTERNAL_API_PRODUCER');
        if (pathname !== '/adminapi/custom_admin_js') {
          const envelope = await response.json();
          businessStatus = Number.isInteger(envelope.status) ? envelope.status : null;
          check(response.status() === 200 && businessStatus === 200, 'NORMAL_HTTP_UNSUCCESSFUL');
          if (pathname === '/kefuapi/login' && method === 'POST') {
            check(kind === 'kefu' && Number(envelope.data.kefuInfo.uid) === input.staff_uid, 'LOGIN_SUBJECT');
            meta = 'login';
          } else if (pathname === '/kefuapi/tourist/user') {
            const uid = Number(envelope.data.tourist_uid);
            check(kind === 'guest' && Number(envelope.data.uid) === input.staff_uid &&
              Number.isSafeInteger(uid) && uid < 0 && Number.isSafeInteger(envelope.data.guest_expires_at), 'GUEST_ISSUER_SUBJECT');
            check(typeof envelope.data.tourist_avatar === 'string', 'GUEST_AVATAR_ISSUER_FIELD');
            stage.guestAvatar = envelope.data.tourist_avatar;
            stage.guestUid = uid; meta = 'guest';
          } else if (pathname === '/kefuapi/tourist/chat') {
            check(kind === 'guest' && Number.isSafeInteger(stage.guestUid) && stage.guestUid < 0 &&
              Number(url.searchParams.get('uid')) === input.staff_uid && Array.isArray(envelope.data),
            'GUEST_HISTORY_SUBJECT');
          } else if (pathname === '/kefuapi/user/record' && captureListPreview &&
            method === 'GET' && url.searchParams.get('is_tourist') === '1') {
            check(Number.isSafeInteger(stage.guestUid) && stage.guestUid < 0 && Array.isArray(envelope.data),
            'LIST_PREVIEW_SUBJECT');
            const rows = envelope.data.filter((row) => row && typeof row === 'object' &&
              row.user_id === input.staff_uid && row.to_uid === stage.guestUid && row.is_tourist === 1);
            check(rows.length === 1, 'LIST_PREVIEW_ROW_COUNT');
            const row = rows[0], preview = Array.from(input.texts[name + '_kefu']).slice(0, 10).join('');
            check(Number.isSafeInteger(row.id) && row.id > 0 && row.message_type === 1 &&
              typeof row.message === 'string' && row.message === preview, 'LAST_REPLY_PREVIEW_BINDING');
            // Only this normal own-row projection survives the response callback.
            listPreview = { stage: name, record_id: row.id, user_id: row.user_id, to_uid: row.to_uid,
              is_tourist: 1, message_type: 1, preview_sha256: sha(preview), received_via: 'http', path: pathname };
          } else if (pathname === '/kefuapi/service/list') {
            check(url.searchParams.get('is_tourist') === '1' &&
              Number(url.searchParams.get('uid')) === stage.guestUid, 'HISTORY_SELECTED_GUEST');
            check(kind === 'kefu' && stage.guestSelectionRequested && Array.isArray(envelope.data),
              'HISTORY_STAFF_SELECTION');
            const text = input.texts[name + '_guest'];
            const rows = envelope.data.filter((row) => row && typeof row === 'object' && row.msn === text);
            check(rows.length === 1 || (rows.length === 0 && stage.guestHistory), 'GUEST_HISTORY_TEXT_COUNT');
            if (rows.length === 1) {
              const row = rows[0];
              check(row.uid === stage.guestUid && row.to_uid === input.staff_uid &&
                row.is_tourist === 1 && row.msn_type === 1, 'NORMAL_TEXT_BINDING');
              // Keep only metadata from the actual selected staff history response.
              // The guest's own echo cannot establish staff receipt. Later empty
              // pagination cannot replace the already verified history receipt.
              stage.guestHistory = { stage: name, sender_uid: row.uid, recipient_uid: row.to_uid,
                is_tourist: 1, message_sha256: sha(text), received_via: 'history' };
              rememberAvatarMessage(stage, kind, row);
            }
          }
        }
      } else check(response.status() >= 200 && response.status() < 400, 'NORMAL_STATIC_UNSUCCESSFUL');
      await command('client_http', { stage: name, method, path: pathname, http: response.status(), business_status: businessStatus });
      result.client_http_count++;
      if (listPreview) {
        stage.listPreview = { ...listPreview, client_http_sequence: result.client_http_count };
        result.list_preview = stage.listPreview;
      }
      if (meta === 'login') { result.login_http_success++; stage.loginHttp = true; }
      if (meta === 'guest') { result.guest_http_success++; stage.guestHttp = true; }
    }).catch((error) => {
      const code = error?.publicCode;
      httpFailure ||= typeof code === 'string' && /^[A-Z0-9_]+$/.test(code) ? code : 'HTTP_REGISTRATION_FAILED';
      result.http_failure_code = httpFailure;
    });
  });
  page.on('websocket', (socket) => {
    try { const url = new URL(socket.url()); check(url.origin === ORIGIN.replace('http:', 'ws:') && url.pathname === '/msg', 'UNEXPECTED_WEBSOCKET'); }
    catch { fatal = 'UNEXPECTED_WEBSOCKET'; }
    socket.on('framesent', ({ payload }) => {
      try {
        const frame = JSON.parse(String(payload));
        // Authentication data is deliberately not accessed or retained.
        if (frame.type === 'to_chat') actor.pendingRooms.push(Number(frame.data.id));
      } catch { fatal = 'CLIENT_FRAME_PARSE'; }
    });
    socket.on('framereceived', ({ payload }) => {
      try {
        const frame = JSON.parse(String(payload)), data = frame.data;
        if (!data || typeof data !== 'object') return;
        if (['chat', 'reply'].includes(frame.type)) rememberAvatarMessage(stage, kind, data);
        if (frame.type === 'success' && ['kefu', 'guest'].includes(data.kind)) {
          check(data.kind === kind && Number.isSafeInteger(data.uid) &&
            (kind === 'kefu' ? data.uid === input.staff_uid : data.uid < 0), 'IDENTITY_ACK_SUBJECT');
          const keys = Object.keys(data).sort().join(',');
          check(keys === (kind === 'guest' ? 'expires_at,kind,uid' : 'kind,uid'), 'IDENTITY_ACK_FIELDS');
          if (kind === 'guest') check(Number.isSafeInteger(data.expires_at) &&
            data.expires_at * 1000 > Date.now(), 'GUEST_ACK_EXPIRY');
          check(!result.identity_acks.some((a) => a.stage === name && a.kind === kind), 'DUPLICATE_IDENTITY_ACK');
          actor.uid = data.uid;
          result.identity_acks.push({ stage: name, kind, uid: data.uid, ...(kind === 'guest' ? { expires_at: data.expires_at } : {}) });
        } else if (frame.type === 'mssage_num' && actor.pendingRooms.length &&
          data.uid === actor.pendingRooms[0] && data.num === 0) {
          actor.pendingRooms.shift(); actor.roomCount++;
          result.room_acks.push({ stage: name, uid: data.uid, num: 0 });
        } else if (kind === 'guest' && frame.type === 'chat' && data.msn === input.texts[name + '_guest']) {
          check(data.uid === stage.guestUid && data.to_uid === input.staff_uid &&
            data.is_tourist === 1 && data.msn_type === 1, 'NORMAL_TEXT_BINDING');
          check(!stage.guestEcho, 'DUPLICATE_GUEST_ECHO');
          stage.guestEcho = true;
        } else if (['chat', 'reply'].includes(frame.type)) {
          const senderKind = kind === 'kefu' ? 'guest' : 'kefu';
          const text = input.texts[name + '_' + senderKind];
          if (data.msn !== text) return;
          const senderUid = senderKind === 'kefu' ? input.staff_uid : stage.guestUid;
          const recipientUid = kind === 'kefu' ? input.staff_uid : stage.guestUid;
          check(data.uid === senderUid && data.to_uid === recipientUid &&
            data.is_tourist === 1 && data.msn_type === 1, 'NORMAL_TEXT_BINDING');
          check(!result.normal_texts.some((t) => t.stage === name && t.sender_uid === senderUid), 'DUPLICATE_NORMAL_TEXT');
          result.normal_texts.push({ stage: name, sender_uid: senderUid, recipient_uid: recipientUid,
            is_tourist: 1, message_sha256: sha(text), received_via: 'websocket' });
        } else if (['err_tip', 'error'].includes(frame.type)) fatal = 'CHAT_UNAVAILABLE';
      } catch (error) { fatal = error.publicCode || 'SERVER_FRAME_CONTRACT'; }
    });
    socket.on('socketerror', () => { fatal = 'SOCKET_ERROR'; });
  });
  return actor;
}
async function shot(page, name, kind) {
  const filename = name + '-' + (kind === 'kefu_list' ? 'kefu-list' : kind) + '.png';
  await page.screenshot({ path: path.join(ROOT, filename), fullPage: false, animations: 'disabled' });
  return { stage: name, kind, path: filename };
}
async function controls(page, name) {
  const selector = name === 'desktop' ? '.chatList .tab-head' : '.chat-list .tab-box';
  const metrics = await page.locator(selector).evaluate((parent) => {
    const p = parent.getBoundingClientRect(), pcs = getComputedStyle(parent);
    const left = p.left + parseFloat(pcs.borderLeftWidth) + parseFloat(pcs.paddingLeft);
    const right = p.right - parseFloat(pcs.borderRightWidth) - parseFloat(pcs.paddingRight);
    return { parent: { left, right, top: p.top, bottom: p.bottom }, buttons: Array.from(parent.querySelectorAll('button')).map((button) => {
      const b = button.getBoundingClientRect(), cs = getComputedStyle(button),
        indicator = getComputedStyle(button, '::after');
      const tree = document.createTreeWalker(button, NodeFilter.SHOW_TEXT); let node, rect;
      while ((node = tree.nextNode())) if (node.nodeValue.trim()) {
        const text = node.nodeValue, trimmed = text.trim(), start = text.indexOf(trimmed), range = document.createRange();
        range.setStart(node, start); range.setEnd(node, start + trimmed.length); rect = range.getBoundingClientRect(); break;
      }
      return { label: button.textContent.trim(), type: button.type, pressed: button.getAttribute('aria-pressed'),
        bounds: { left: b.left, right: b.right, top: b.top, bottom: b.bottom, width: b.width, height: b.height },
        label_bounds: rect ? { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom } : null,
        display: cs.display, align_items: cs.alignItems, justify_content: cs.justifyContent,
        text_align: cs.textAlign, font_family: cs.fontFamily, font_size: cs.fontSize, font_weight: cs.fontWeight,
        box_shadow: cs.boxShadow, indicator_display: indicator.display,
        indicator_height: indicator.height, indicator_width: indicator.width };
    }) };
  });
  check(metrics.buttons.length === 2 && metrics.buttons.some((b) => b.label === '游客列表' && b.pressed === 'true'), 'TAB_SELECTED');
  for (const b of metrics.buttons) {
    check(b.type === 'button' && b.display === 'flex' && b.align_items === 'center' &&
      b.justify_content === 'center' && b.label_bounds && b.bounds.width > 0 && b.bounds.height > 0, 'TAB_CONTROL_SEMANTICS');
    check(b.bounds.left >= metrics.parent.left - 1 && b.bounds.right <= metrics.parent.right + 1 &&
      b.bounds.top >= metrics.parent.top - 1 && b.bounds.bottom <= metrics.parent.bottom + 1, 'TAB_CONTROL_OVERFLOW');
    const tolerance = Math.max(2, parseFloat(b.font_size) * 0.2);
    check(Math.abs((b.label_bounds.left + b.label_bounds.right - b.bounds.left - b.bounds.right) / 2) <= tolerance &&
      Math.abs((b.label_bounds.top + b.label_bounds.bottom - b.bounds.top - b.bounds.bottom) / 2) <= tolerance, 'TAB_LABEL_ALIGNMENT');
    if (b.pressed === 'true') check(Number(b.font_weight) >= 600 &&
      (b.box_shadow !== 'none' || b.indicator_display !== 'none' && parseFloat(b.indicator_height) > 0),
    'TAB_SELECTED_NONCOLOR_FEEDBACK');
  }
  result.control_checks.push({ stage: name, viewport: name === 'desktop' ? { width: 1440, height: 900 } :
    { width: 390, height: 844 }, ...metrics, manual_screenshot_review: 'PENDING ROOT' });
}
async function actionControl(page, label, kind) {
  const button = page.getByRole('button', { name: label, exact: true });
  await until(async () => await button.count() === 1 && await button.isVisible() && await button.isEnabled(),
    'NORMAL_ACTION_CONTROL_READY');
  const metrics = await button.evaluate((node) => {
    const rect = (element) => {
      const box = element.getBoundingClientRect();
      return { left: box.left, right: box.right, top: box.top, bottom: box.bottom,
        width: box.width, height: box.height };
    };
    const style = getComputedStyle(node), svg = node.querySelector('svg');
    return { type: node.type, label: node.getAttribute('aria-label'), disabled: node.disabled,
      display: style.display, align_items: style.alignItems, justify_content: style.justifyContent,
      bounds: rect(node), parent: rect(node.parentElement), icon: svg ? rect(svg) : null,
      symbol: svg?.querySelector('use')?.getAttribute('href') || svg?.querySelector('use')?.getAttribute('xlink:href') };
  });
  const b = metrics.bounds, p = metrics.parent, i = metrics.icon;
  check(metrics.type === 'button' && metrics.label === label && metrics.disabled === false &&
    metrics.display === 'flex' && metrics.align_items === 'center' && metrics.justify_content === 'center' &&
    i && i.width > 0 && i.height > 0 && metrics.symbol === (label === '发送' ? '#iconfasong' : '#iconfanhui'),
  'NORMAL_ACTION_SEMANTICS');
  check(b.width >= 44 && b.height >= 44 && b.left >= p.left - 1 && b.right <= p.right + 1 &&
    b.top >= p.top - 1 && b.bottom <= p.bottom + 1, 'NORMAL_ACTION_BOUNDS');
  check(Math.abs((i.left + i.right - b.left - b.right) / 2) <= 2 &&
    Math.abs((i.top + i.bottom - b.top - b.bottom) / 2) <= 2, 'NORMAL_ACTION_ICON_ALIGNMENT');
  result.action_controls.push({ stage: 'mobile', kind, ...metrics });
  return button;
}
async function textVisible(page, text, code, selector) {
  const scope = selector ? page.locator(selector) : page;
  await until(async () => await scope.getByText(text, { exact: true }).count() > 0 &&
    await scope.getByText(text, { exact: true }).first().isVisible(), code);
}
async function brandingAvatar(page, name, kind) {
  const selector = kind === 'kefu' ?
    (name === 'desktop' ? '.base-header .user_info img' : '.chat-list .left-wrappers img') :
    '.head .image img';
  await until(async () => await page.locator(selector).first().evaluate((img, expected) =>
    img.src === expected && img.complete && img.naturalWidth > 0, input.staff_avatar), 'STAFF_AVATAR_VISIBLE');
  result.brand_checks.push({ stage: name, kind, check: 'header_branding', complete: true, natural_width_positive: true });
}
function rememberAvatarMessage(state, viewKind, data) {
  const senderKind = data.msn === input.texts[state.name + '_guest'] ? 'guest' :
    (data.msn === input.texts[state.name + '_kefu'] ? 'kefu' : null);
  if (!senderKind) return;
  const sender = senderKind === 'kefu' ? input.staff_uid : state.guestUid;
  const recipient = senderKind === 'kefu' ? state.guestUid : input.staff_uid;
  const id = Number(data.id);
  check(Number.isSafeInteger(id) && id > 0 && data.uid === sender && data.to_uid === recipient &&
    data.is_tourist === 1 && data.msn_type === 1 && typeof data.avatar === 'string', 'MESSAGE_AVATAR_ACTOR');
  const previous = state.avatarMessages[viewKind][senderKind];
  check(!previous || (previous.log_id === id && previous.avatar === data.avatar), 'MESSAGE_AVATAR_SOURCE_CHANGED');
  state.avatarMessages[viewKind][senderKind] = { log_id: id, sender_uid: sender, recipient_uid: recipient,
    message_sha256: sha(input.texts[state.name + '_' + senderKind]), avatar: data.avatar };
}
function expectedGuestAvatar(state, pageUrl) {
  const source = state.guestAvatar;
  check(typeof source === 'string' && source.length > 0, 'GUEST_CONFIG_AVATAR_SOURCE');
  const produced = source.startsWith('http') || source.startsWith('//') ? source :
    input.guest_avatar_site_url + source.replaceAll('\\', '/');
  const url = new URL(produced, pageUrl);
  check(url.origin === ORIGIN && !url.search && !url.hash, 'GUEST_CONFIG_AVATAR_INTERNAL');
  return url.href;
}
async function messageAvatars(page, name, kind) {
  const state = stages.get(name);
  const rowSelector = name === 'desktop' ?
    (kind === 'kefu' ? '#chat_scroll .chat-item' : '#chat_scroll li') : '#chatBox > [id^="chat_"]';
  for (const senderKind of ['guest', 'kefu']) {
    await until(() => state.avatarMessages[kind][senderKind], 'MESSAGE_AVATAR_NATIVE_SOURCE');
    const native = state.avatarMessages[kind][senderKind];
    const text = input.texts[name + '_' + senderKind];
    const row = page.locator(rowSelector).filter({ has: page.getByText(text, { exact: true }) });
    await until(async () => await row.count() === 1 && await row.isVisible(), 'MESSAGE_AVATAR_ROW_VISIBLE');
    await textVisible(row, text, 'MESSAGE_AVATAR_FULL_TEXT_VISIBLE');
    const sourceKind = senderKind === 'kefu' ? 'staff' : (state.guestAvatar === '' ? 'guest_default' : 'guest_config');
    const expected = sourceKind === 'staff' ? input.staff_avatar :
      (sourceKind === 'guest_config' ? expectedGuestAvatar(state, page.url()) : null);
    check(sourceKind === 'guest_default' ? native.avatar === '' :
      new URL(native.avatar, page.url()).href === expected, 'MESSAGE_AVATAR_NATIVE_BINDING');
    let metrics;
    await until(async () => {
      metrics = await row.evaluate((element) => {
        const image = element.querySelector('img.avatar, .avatar img');
        if (!image) return null;
        const rect = (r) => ({ left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height });
        return { id: element.id, src: image.currentSrc || image.src, complete: image.complete,
          natural_width: image.naturalWidth, natural_height: image.naturalHeight,
          lazy_state: image.getAttribute('lazy') || 'native', bounds: rect(image.getBoundingClientRect()),
          row_bounds: rect(element.getBoundingClientRect()), width: innerWidth, height: innerHeight };
      });
      if (!metrics || !metrics.complete || metrics.natural_width <= 0 || metrics.natural_height <= 0 ||
        metrics.lazy_state !== (name === 'mobile' && kind === 'guest' ? 'native' : 'loaded')) return false;
      if (sourceKind !== 'guest_default') return metrics.src === expected;
      const fallback = input.default_avatar;
      if (fallback.representation === 'file') return metrics.src === ORIGIN + fallback.path;
      if (!metrics.src.startsWith('data:image/jpeg;base64,')) return false;
      const bytes = Buffer.from(metrics.src.slice('data:image/jpeg;base64,'.length), 'base64');
      return bytes.length === fallback.bytes && sha(bytes) === fallback.sha256;
    }, 'MESSAGE_AVATAR_LOADED_SOURCE');
    const b = metrics.bounds, r = metrics.row_bounds;
    check(metrics.id === 'chat_' + native.log_id && b.width > 0 && b.height > 0 &&
      b.left >= -1 && b.top >= -1 && b.right <= metrics.width + 1 && b.bottom <= metrics.height + 1 &&
      b.left >= r.left - 1 && b.right <= r.right + 1 && b.top >= r.top - 1 && b.bottom <= r.bottom + 1,
    'MESSAGE_AVATAR_RENDER_BOUNDS');
    const sourceRef = sourceKind === 'guest_default' && input.default_avatar.representation === 'inline' ?
      'inline:image/jpeg:' + input.default_avatar.sha256 : new URL(metrics.src).pathname;
    result.message_avatars.push({ stage: name, kind, sender_uid: native.sender_uid, recipient_uid: native.recipient_uid,
      log_id: native.log_id, message_sha256: native.message_sha256, source_kind: sourceKind,
      source_ref_sha256: sha(sourceRef), complete: metrics.complete, natural_width: metrics.natural_width,
      natural_height: metrics.natural_height, lazy_state: metrics.lazy_state, bounds: b,
      screenshot_path: name + '-' + kind + '.png' });
    if (senderKind === 'kefu') result.control_checks.push({ stage: name, kind,
      check: 'actual_staff_message_avatar', complete: true, natural_width_positive: true });
  }
}
async function stage(name) {
  stageName = name;
  const state = { name, guestUid: null, guestAvatar: null, avatarMessages: { kefu: {}, guest: {} }, loginHttp: false, guestHttp: false,
    guestEcho: false, guestSelectionRequested: false, guestHistory: null,
    expectListPreview: false, listPreview: null };
  stages.set(name, state);
  const staffContext = await contextFor(name), guestContext = await contextFor(name);
  const staff = await staffContext.newPage(), guest = await guestContext.newPage();
  const staffActor = observe(staff, name, 'kefu'), guestActor = observe(guest, name, 'guest');
  await staff.goto(ORIGIN + '/kefu', { waitUntil: 'domcontentloaded' });
  await staff.getByPlaceholder('请输入用户名', { exact: true }).fill(input.account);
  await staff.getByPlaceholder('请输入密码', { exact: true }).fill(input.password);
  await staff.getByRole('button', { name: '登录', exact: true }).click();
  await until(() => state.loginHttp && staffActor.uid === input.staff_uid &&
    result.identity_acks.some((a) => a.stage === name && a.kind === 'kefu'), 'LOGIN_AND_ACK');
  await drain();
  const expectedStaffPath = name === 'desktop' ? '/kefu/pc_list' : '/kefu/mobile_list';
  check(new URL(staff.url()).pathname === expectedStaffPath, 'STAFF_ROUTE');
  await brandingAvatar(staff, name, 'kefu');
  await staff.getByRole('button', { name: '游客列表', exact: true }).click();
  await drain();
  await guest.goto(ORIGIN + (name === 'desktop' ? '/kefu/appChat' : '/kefu/mobile_user_chat'),
    { waitUntil: 'domcontentloaded' });
  await until(() => state.guestHttp && guestActor.uid === state.guestUid && guestActor.roomCount >= 1, 'GUEST_AND_ROOM_ACK');
  await drain();
  const guestText = input.texts[name + '_guest'], replyText = input.texts[name + '_kefu'];
  if (name === 'desktop') {
    await guest.getByPlaceholder('请输入文字内容', { exact: true }).fill(guestText);
    await guest.getByRole('button', { name: '发送', exact: true }).click();
  } else {
    await guest.getByPlaceholder('请输入内容', { exact: true }).fill(guestText);
    await (await actionControl(guest, '发送', 'guest')).click();
  }
  await until(() => state.guestEcho, 'GUEST_TEXT_ACKNOWLEDGED');
  await textVisible(guest, guestText, 'GUEST_ECHO_VISIBLE');
  const visitorName = '游客' + Math.abs(state.guestUid);
  const itemSelector = name === 'desktop' ? '.chatList .chat-item' : '.list-box .item';
  const item = staff.locator(itemSelector).filter({ hasText: visitorName });
  await until(async () => await item.count() === 1 && await item.isVisible(), 'OWN_VISITOR_VISIBLE');
  await controls(staff, name);
  const beforeRooms = staffActor.roomCount;
  state.guestSelectionRequested = true;
  await item.click();
  await until(() => staffActor.roomCount > beforeRooms, 'SELECTED_ROOM_ACK');
  await until(() => state.guestHistory && state.guestHistory.received_via === 'history', 'GUEST_HISTORY_RECEIVED');
  await drain();
  const staffChatSelector = name === 'desktop' ? '#chat_scroll' : '#chatBox';
  await textVisible(staff, guestText, 'STAFF_RECEIVED_TEXT_VISIBLE', staffChatSelector);
  check(!result.normal_texts.some((t) => t.stage === name && t.sender_uid === state.guestUid), 'DUPLICATE_NORMAL_TEXT');
  result.normal_texts.push(state.guestHistory);
  await until(() => result.normal_texts.some((t) => t.stage === name && t.sender_uid === state.guestUid), 'GUEST_TEXT_RECEIVED');
  if (name === 'desktop') {
    await staff.getByPlaceholder('请输入文字内容', { exact: true }).fill(replyText);
    await staff.getByRole('button', { name: '发送', exact: true }).click();
  } else {
    check(new URL(staff.url()).pathname === '/kefu/mobile_chat', 'MOBILE_CHAT_ROUTE');
    await staff.getByPlaceholder('请输入内容', { exact: true }).fill(replyText);
    await (await actionControl(staff, '发送', 'kefu')).click();
  }
  await until(() => result.normal_texts.some((t) => t.stage === name && t.sender_uid === input.staff_uid), 'STAFF_TEXT_RECEIVED');
  await textVisible(staff, replyText, 'STAFF_ECHO_VISIBLE', staffChatSelector);
  await textVisible(guest, replyText, 'GUEST_REPLY_VISIBLE');
  await drain();
  await messageAvatars(staff, name, 'kefu');
  await messageAvatars(guest, name, 'guest');
  if (name === 'desktop') await brandingAvatar(guest, name, 'guest');
  result.screenshots.push(await shot(staff, name, 'kefu'), await shot(guest, name, 'guest'));
  const leaveBefore = result.room_acks.filter((a) => a.stage === name && a.uid === 0).length;
  if (name === 'desktop') {
    await staff.getByRole('button', { name: '用户列表', exact: true }).click();
  } else {
    state.expectListPreview = true;
    await (await actionControl(staff, '返回会话列表', 'kefu')).click();
  }
  await until(() => result.room_acks.filter((a) => a.stage === name && a.uid === 0).length > leaveBefore, 'ORDINARY_LEAVE_ACK');
  await drain();
  if (name === 'mobile') {
    check(new URL(staff.url()).pathname === '/kefu/mobile_list', 'MOBILE_BACK_ROUTE');
    await staff.getByRole('button', { name: '游客列表', exact: true }).click();
    const visitor = staff.locator('.list-box .item').filter({ hasText: visitorName });
    await until(async () => await visitor.count() === 1 && await visitor.isVisible(), 'MOBILE_VISITOR_LIST_VISIBLE');
    await until(() => state.listPreview && state.listPreview.to_uid === state.guestUid &&
      state.listPreview.preview_sha256 === sha(Array.from(replyText).slice(0, 10).join('')), 'MOBILE_LIST_PREVIEW_RESPONSE');
    await textVisible(visitor.locator('.msg'), Array.from(replyText).slice(0, 10).join(''), 'MOBILE_REPLY_LIST_VISIBLE');
    await drain(); await controls(staff, name);
    result.control_screenshot = await shot(staff, name, 'kefu_list');
  }
  await staff.waitForLoadState('networkidle'); await guest.waitForLoadState('networkidle'); await drain();
  staffActor.closing = true; guestActor.closing = true;
  await staffContext.close(); await guestContext.close();
  await drain();
}
async function main() {
  await permission();
  scopeInput(); fs.mkdirSync(ROOT, { recursive: true });
  delete process.env.DEBUG; delete process.env.PWDEBUG;
  const { chromium } = require('/opt/playwright-core');
  browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true });
  await stage('desktop'); await stage('mobile');
  check(result.login_http_success === 2 && result.guest_http_success === 2 &&
    result.identity_acks.length === 4 && result.normal_texts.length === 4 &&
    result.screenshots.length === 4 && result.control_screenshot &&
    result.control_checks.filter((c) => c.buttons).length === 3 && result.message_avatars.length === 8 &&
    result.control_checks.filter((c) => c.check === 'actual_staff_message_avatar').length === 4, 'NORMAL_TOTALS');
  await browser.close(); browser = null; result.contexts_closed = closedContexts.size === contexts.length;
  check(result.contexts_closed, 'CONTEXTS_NOT_CLOSED');
  await drain();
  finishMetadata();
  result.status = 'PASS';
  atomic(path.join(ROOT, 'browser.json'), result);
  submitted = true;
  atomic(path.join(ROOT, 'closed.json'), { run_id: input.run_id, contexts_closed: result.contexts_closed });
  let reply;
  try { reply = await command('finish'); }
  catch { coordinationFailure('FINISH_ACK_UNAVAILABLE'); return; }
  if (reply.result !== 'PASS' || reply.ordinary_cleanup_complete !== true) {
    coordinationFailure('PHP_FINISH_UNSUCCESSFUL', reply); return;
  }
  console.log(JSON.stringify({ status: 'PASS', run_id: input.run_id, source_sha: input.source_sha,
    screenshots: 5, normal_texts: 4, contexts_closed: true, manual_review: 'PENDING ROOT' }));
}
main().catch(async (error) => {
  // Never print Playwright messages or call logs: filling passwords can enter them.
  for (const context of contexts) { try { await context.close(); } catch {} }
  if (browser) { try { await browser.close(); } catch {} }
  result.contexts_closed = closedContexts.size === contexts.length && (!browser || !browser.isConnected());
  if (submitted) { coordinationFailure('SUBMITTED_COORDINATION_ERROR'); return; }
  result.status = 'FAIL'; result.stage = stageName;
  result.failure_code = typeof error.publicCode === 'string' && /^[A-Z0-9_]{1,64}$/.test(error.publicCode) ?
    error.publicCode : 'UNEXPECTED_RUNTIME_ERROR';
  try { atomic(path.join(ROOT, 'closed.json'), { run_id: input ? input.run_id : result.run_id,
    contexts_closed: result.contexts_closed }); } catch {}
  try { await httpQueue; } catch {}
  finishMetadata();
  try {
    atomic(path.join(ROOT, 'browser.json'), result);
    submitted = true;
    if (input && ready && !observerTerminal()) await command('finish');
  } catch {}
  console.log(JSON.stringify({ status: 'FAIL', failure_code: result.failure_code, contexts_closed: result.contexts_closed }));
  process.exitCode = 1;
});
