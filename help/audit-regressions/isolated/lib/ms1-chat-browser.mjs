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
  identity_acks: [], normal_texts: [], room_acks: [], screenshots: [], control_checks: [],
  external_requests_blocked_count: 0, external_requests_blocked: [],
  client_http_count: 0, console_counts: { external_blocked: 0, application_error: 0, page_error: 0 },
  resource_counts: {},
};
const blocked = new Map();
const internalApiPaths = new Set([
  '/adminapi/get_workerman_url', '/adminapi/custom_admin_js',
  '/kefuapi/config', '/kefuapi/login', '/kefuapi/service/info',
  '/kefuapi/user/record', '/kefuapi/service/list',
  '/kefuapi/tourist/user', '/kefuapi/tourist/adv',
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
  if (httpFailure) fail('HTTP_REGISTRATION_FAILED');
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
      let businessStatus = null, meta;
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
            stage.guestUid = uid; meta = 'guest';
          } else if (pathname === '/kefuapi/service/list') {
            check(url.searchParams.get('is_tourist') === '1' &&
              Number(url.searchParams.get('uid')) === stage.guestUid, 'HISTORY_SELECTED_GUEST');
          }
        }
      } else check(response.status() >= 200 && response.status() < 400, 'NORMAL_STATIC_UNSUCCESSFUL');
      await command('client_http', { stage: name, method, path: pathname, http: response.status(), business_status: businessStatus });
      result.client_http_count++;
      if (meta === 'login') { result.login_http_success++; stage.loginHttp = true; }
      if (meta === 'guest') { result.guest_http_success++; stage.guestHttp = true; }
    }).catch(() => { httpFailure = true; });
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
            is_tourist: 1, message_sha256: sha(text) });
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
async function textVisible(page, text, code) {
  await until(async () => await page.getByText(text, { exact: true }).count() > 0 &&
    await page.getByText(text, { exact: true }).first().isVisible(), code);
}
async function avatar(page, name, kind) {
  if (kind === 'guest' && name === 'mobile') {
    await until(async () => await page.locator('.chat-box img').evaluateAll((images, expected) =>
      images.some((img) => img.src === expected && img.complete && img.naturalWidth > 0),
    input.staff_avatar), 'STAFF_REPLY_AVATAR_VISIBLE');
    result.control_checks.push({ stage: name, kind, check: 'actual_staff_reply_avatar',
      complete: true, natural_width_positive: true });
    return;
  }
  const selector = kind === 'kefu' ?
    (name === 'desktop' ? '.base-header .user_info img' : '.chat-list .left-wrappers img') :
    (name === 'desktop' ? '.head .image img' : '.chat-box .head-box');
  await until(async () => await page.locator(selector).first().evaluate((img, expected) =>
    img.src === expected && img.complete && img.naturalWidth > 0, input.staff_avatar), 'STAFF_AVATAR_VISIBLE');
  result.control_checks.push({ stage: name, kind, check: 'actual_staff_avatar', complete: true, natural_width_positive: true });
}
async function stage(name) {
  stageName = name;
  const state = { guestUid: null, loginHttp: false, guestHttp: false };
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
  await avatar(staff, name, 'kefu');
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
    await guest.locator('.input-box .iconfasong').click();
  }
  await until(() => result.normal_texts.some((t) => t.stage === name && t.sender_uid === state.guestUid), 'GUEST_TEXT_RECEIVED');
  await textVisible(guest, guestText, 'GUEST_ECHO_VISIBLE');
  const visitorName = '游客' + Math.abs(state.guestUid);
  const itemSelector = name === 'desktop' ? '.chatList .chat-item' : '.list-box .item';
  const item = staff.locator(itemSelector).filter({ hasText: visitorName });
  await until(async () => await item.count() === 1 && await item.isVisible(), 'OWN_VISITOR_VISIBLE');
  await controls(staff, name);
  const beforeRooms = staffActor.roomCount;
  await item.click();
  await until(() => staffActor.roomCount > beforeRooms, 'SELECTED_ROOM_ACK');
  await drain();
  await textVisible(staff, guestText, 'STAFF_RECEIVED_TEXT_VISIBLE');
  if (name === 'desktop') {
    await staff.getByPlaceholder('请输入文字内容', { exact: true }).fill(replyText);
    await staff.getByRole('button', { name: '发送', exact: true }).click();
  } else {
    check(new URL(staff.url()).pathname === '/kefu/mobile_chat', 'MOBILE_CHAT_ROUTE');
    await staff.getByPlaceholder('请输入内容', { exact: true }).fill(replyText);
    await staff.locator('.input-box .iconfasong').click();
  }
  await until(() => result.normal_texts.some((t) => t.stage === name && t.sender_uid === input.staff_uid), 'STAFF_TEXT_RECEIVED');
  await textVisible(staff, replyText, 'STAFF_ECHO_VISIBLE');
  await textVisible(guest, replyText, 'GUEST_REPLY_VISIBLE');
  await drain();
  await avatar(guest, name, 'guest');
  result.screenshots.push(await shot(staff, name, 'kefu'), await shot(guest, name, 'guest'));
  const leaveBefore = result.room_acks.filter((a) => a.stage === name && a.uid === 0).length;
  if (name === 'desktop') {
    await staff.getByRole('button', { name: '用户列表', exact: true }).click();
  } else {
    await staff.locator('.chat-box .head-box .back').click();
  }
  await until(() => result.room_acks.filter((a) => a.stage === name && a.uid === 0).length > leaveBefore, 'ORDINARY_LEAVE_ACK');
  await drain();
  if (name === 'mobile') {
    check(new URL(staff.url()).pathname === '/kefu/mobile_list', 'MOBILE_BACK_ROUTE');
    await staff.getByRole('button', { name: '游客列表', exact: true }).click();
    await until(async () => await staff.locator('.list-box .item').filter({ hasText: visitorName }).count() === 1, 'MOBILE_VISITOR_LIST_VISIBLE');
    await textVisible(staff, replyText, 'MOBILE_REPLY_LIST_VISIBLE');
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
    result.control_checks.filter((c) => c.buttons).length === 3, 'NORMAL_TOTALS');
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
