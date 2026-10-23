// Import-only Root caller. No process, credential read or browser action at import.
import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
import { UiWindowObserverBridge, UiTask2ObserverBridge, TASK2_READ_MODES, task2Source } from './ms4-ui-window-native-bridge.mjs';
import { isDeepStrictEqual as equal, TextDecoder } from 'node:util';

const demand = ok => { if (!ok) throw new Error('MS4_UI_PRIVATE_CALLER_UNPROVED'); };
const sha = raw => createHash('sha256').update(raw).digest('hex');
const pin = raw => ({ bytes: raw.length, sha256: sha(raw) });
const readerPin = { bytes: 14107, sha256: '940d2a03103b26eab8766a1c50b38928f02d2eece2be1bd19fd1a6fac449fd3f' };
const artifactNames = ['compose.yml', 'interface-v1.json', 'ms4-host.py', 'ms4-initialize.php',
  'normal-close-ms4-fulfillment.py', 'README.md', 'seed-source-inverse.json', 'source-derivation.json',
  'verify-zero-ms4-fulfillment.py', 'ms4-ui-nginx.conf', 'ms4-ui-public-copy.php', 'ms4-ui-copy.php',
  'ms4-order.png', 'manifests/ui-h5-dist.json', 'manifests/ui-merchant-dist.json',
  'manifests/ui-admin-dist.json', 'manifests/ui-public-copy-profile.json'];
const keys = (value, wanted) => demand(value !== null && typeof value === 'object' && !Array.isArray(value)
  && equal(Object.keys(value).sort(), [...wanted].sort()));
const json = raw => { try { return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(raw)); }
  catch { throw new Error('MS4_UI_PRIVATE_CALLER_UNPROVED'); } };
const hex = (value, count) => typeof value === 'string' && new RegExp('^[a-f0-9]{' + count + '}$').test(value);
const stable = (a, b) => ['dev', 'ino', 'mode', 'nlink', 'size', 'mtimeMs', 'ctimeMs'].every(key => a[key] === b[key]);

async function ordinary(name, maximum = 33554432, empty = false) {
  demand(typeof name === 'string' && path.isAbsolute(name));
  let parent = path.dirname(name);
  while (true) {
    const stat = await fs.lstat(parent); demand(stat.isDirectory() && !stat.isSymbolicLink());
    const next = path.dirname(parent); if (next === parent) break; parent = next;
  }
  const before = await fs.lstat(name);
  demand(before.isFile() && !before.isSymbolicLink() && before.nlink === 1
    && before.size >= (empty ? 0 : 1) && before.size <= maximum);
  const handle = await fs.open(name, 'r'); let raw;
  try {
    demand(stable(before, await handle.stat())); raw = await handle.readFile();
    const extra = Buffer.alloc(1); const end = await handle.read(extra, 0, 1, null);
    demand(end.bytesRead === 0 && raw.length === before.size && stable(before, await handle.stat()));
  } finally { await handle.close(); }
  demand(stable(before, await fs.lstat(name))); return raw;
}

async function create(name, raw) {
  const handle = await fs.open(name, 'wx', 0o600);
  try { await handle.writeFile(raw); await handle.sync(); } finally { await handle.close(); }
  demand((await ordinary(name, raw.length, raw.length === 0)).equals(raw));
}

/** Root supplies only explicit paths; actual Host native0 and frozen bytes produce the public inputs. */
export class UiPrivateCaller {
  #config; #phase = 'NEW'; #context; #freeze; #before; #gate; #gates = {};
  #expected; #receipt; #credentials = null; #readState = null; #reader = null; #busy = false;
  #filled = new Set(); #fillAttempted = new Set(); #forgotten = false;
  #task2Scenes = []; #task2Failure = false;
  constructor(config) {
    keys(config, ['context_path', 'python_path', 'reader_path']);
    demand(Object.values(config).every(value => typeof value === 'string' && path.isAbsolute(value)));
    this.#config = { ...config };
  }
  async #snapshot() {
    const contextRaw = await ordinary(this.#config.context_path), context = json(contextRaw);
    demand(/^\d{14}[a-f0-9]{6}$/.test(context.run_id) && hex(context.source_sha, 40)
      && hex(context.freeze_sha256, 64) && path.isAbsolute(context.run_dir) && path.isAbsolute(context.prep_dir)
      && path.basename(path.resolve(context.run_dir)) === context.run_id
      && path.resolve(this.#config.context_path) === path.resolve(context.run_dir, 'actual-run-context.json'));
    const frozenRaw = await ordinary(path.join(context.run_dir, 'preparation-source-binding.json'));
    const frozen = json(frozenRaw);
    demand(sha(frozenRaw) === context.freeze_sha256 && frozen.status === 'CLEAN_SOURCE_FROZEN_ONLY'
      && frozen.source_sha === context.source_sha && Array.isArray(frozen.artifact_files)
      && equal(frozen.artifact_files.map(row => row.path), artifactNames)
      && Number.isSafeInteger(frozen.ui_web_port) && frozen.ui_web_port > 1023 && frozen.ui_web_port <= 65535
      && frozen.ui_web_origin === 'http://127.0.0.1:' + frozen.ui_web_port);
    const artifacts = {};
    for (const row of frozen.artifact_files) {
      keys(row, ['path', 'bytes', 'sha256']);
      const raw = await ordinary(path.join(context.prep_dir, ...row.path.split('/')));
      demand(equal(pin(raw), { bytes: row.bytes, sha256: row.sha256 })); artifacts[row.path] = pin(raw);
    }
    const readerRaw = await ordinary(this.#config.reader_path);
    demand(equal(pin(readerRaw), readerPin));
    const ownRaw = await ordinary(fileURLToPath(import.meta.url));
    return { context, frozen, signature: { context: pin(contextRaw), freeze: pin(frozenRaw), artifacts,
      reader: pin(readerRaw), caller: pin(ownRaw) } };
  }
  async begin() {
    demand(this.#phase === 'NEW'); this.#phase = 'INITIALIZING';
    try {
      const snapshot = await this.#snapshot(); this.#context = snapshot.context;
      this.#freeze = snapshot.frozen; this.#before = snapshot.signature;
      this.#reader = await import(pathToFileURL(this.#config.reader_path).href);
      demand(typeof this.#reader.readUiCredentials === 'function');
      demand(equal((await this.#snapshot()).signature, this.#before));
      this.#startGate('before'); return this.status();
    } catch { this.#phase = 'FAILED'; throw new Error('MS4_UI_PRIVATE_CALLER_UNPROVED'); }
  }
  #startGate(kind) {
    demand(['before', 'after'].includes(kind) && !this.#gate && !this.#gates[kind]);
    const argv = ['-B', '-X', 'utf8', path.join(this.#context.prep_dir, 'ms4-host.py'),
      'ui-web-private-inputs', '--context', this.#config.context_path];
    const selected = { kind, argv, done: false, failed: false, stdout: [], stderr: [],
      stdoutBytes: 0, stderrBytes: 0, stdoutHash: createHash('sha256'), stderrHash: createHash('sha256') };
    this.#gate = selected; this.#phase = kind === 'before' ? 'HOST_GATE_BEFORE' : 'HOST_GATE_AFTER';
    let child;
    try { child = spawn(this.#config.python_path, argv, { windowsHide: true, shell: false,
      stdio: ['ignore', 'pipe', 'pipe'] }); }
    catch { selected.failed = true; selected.done = true; return; }
    selected.pid = child.pid;
    child.once('error', () => { selected.failed = true; });
    for (const stream of ['stdout', 'stderr']) {
      child[stream]?.on('error', () => { selected.failed = true; });
      child[stream]?.on('data', part => {
        selected[stream + 'Bytes'] += part.length; selected[stream + 'Hash'].update(part);
        if (selected[stream + 'Bytes'] <= 2097152) selected[stream].push(part);
        else { selected.failed = true; part.fill(0); }
      });
    }
    child.once('close', (code, signal) => { selected.code = code; selected.signal = signal;
      selected.done = true; selected.child = null; }); selected.child = child;
  }
  async #finishGate() {
    const gate = this.#gate; demand(gate && gate.done);
    const streams = {};
    for (const stream of ['stdout', 'stderr']) {
      const raw = Buffer.concat(gate[stream]);
      streams[stream] = { bytes: gate[stream + 'Bytes'], sha256: gate[stream + 'Hash'].digest('hex'),
        stored_bytes: raw.length, stored_sha256: sha(raw), complete: raw.length === gate[stream + 'Bytes'] };
      if (raw.length !== streams[stream].bytes) gate.failed = true;
      // These are actual public Host output streams, never reader output or credentials.
      await create(path.join(this.#context.run_dir, 'evidence', 'ui-private-caller-gate-' + gate.kind + '.' + stream), raw);
      if (stream === 'stdout') gate.raw = raw; else raw.fill(0);
      gate[stream].forEach(part => part.fill(0)); gate[stream] = [];
    }
    const native = { schema: 1, scope: 'MS4_UI_PRIVATE_CALLER_HOST_GATE_NATIVE',
      stage: gate.kind, executable: this.#config.python_path, argv: gate.argv,
      pid: gate.pid ?? null, close_code: gate.code ?? null, close_signal: gate.signal ?? null, ...streams };
    await create(path.join(this.#context.run_dir, 'evidence', 'ui-private-caller-gate-' + gate.kind + '.native.json'),
      Buffer.from(JSON.stringify(native) + '\n'));
    demand(!gate.failed && Number.isSafeInteger(gate.pid) && gate.pid > 0
      && gate.code === 0 && gate.signal === null && streams.stderr.bytes === 0);
    let output;
    try { output = json(gate.raw); } finally { gate.raw?.fill(0); gate.raw = null; }
    keys(output, ['status', 'descriptor', 'ready', 'reader_module', 'private_caller_execution', 'form_login_business_DOM']);
    demand(output.status === 'UI_BACKEND_REAL_PRIVATE_READER_INPUTS_ONLY'
      && output.private_caller_execution === 'NOT IMPLEMENTED_OR_PROVEN_BY_HOST'
      && output.form_login_business_DOM === 'NOT RUN'
      && equal(output.reader_module, { path: 'browser-v1/ms4-ui-private-reader.mjs', ...readerPin }));
    const values = {};
    for (const [field, name] of [['descriptor', 'ui-web-private-inputs.json'],
      ['ready', 'ui-web-control-ui-backend-ready.json']]) {
      const descriptor = output[field]; keys(descriptor, ['path', 'bytes', 'sha256']);
      const raw = await ordinary(path.join(this.#context.run_dir, 'evidence', name));
      demand(descriptor.path === '/run/audit/evidence/' + name
        && equal(pin(raw), { bytes: descriptor.bytes, sha256: descriptor.sha256 })); values[field] = json(raw);
    }
    const inputs = values.descriptor;
    keys(inputs, ['run_id', 'source_sha', 'freeze_sha256', 'container_id', 'native_created', 'fixture_volume', 'writer', 'actors']);
    demand(['run_id', 'source_sha', 'freeze_sha256'].every(key => inputs[key] === this.#context[key])
      && inputs.container_id === values.ready.container_id && inputs.writer && equal(inputs.writer, values.ready.writer));
    demand(equal((await this.#snapshot()).signature, this.#before));
    this.#gates[gate.kind] = native; this.#gate = null;
    return { output, inputs };
  }
  async poll() {
    demand(!this.#busy && !this.#forgotten); this.#busy = true;
    try {
      if (this.#gate && this.#gate.done) {
        const kind = this.#gate.kind, result = await this.#finishGate();
        if (kind === 'before') {
          this.#expected = result.inputs; this.#receipt = result.output; this.#phase = 'PRIVATE_READ_RUNNING';
          this.#readState = 'RUNNING';
          this.#reader.readUiCredentials(this.#expected).then(value => {
            this.#credentials = value; this.#readState = 'DONE';
          }, () => { this.#readState = 'FAILED'; });
        } else {
          demand(equal(result.inputs, this.#expected) && equal(result.output, this.#receipt));
          this.#phase = 'PRIVATE_READY';
          const handoff = { schema: 1, scope: 'MS4_UI_PRIVATE_CALLER_HANDOFF_ONLY',
            run_id: this.#context.run_id, source_sha: this.#context.source_sha,
            freeze_sha256: this.#context.freeze_sha256, container_id: this.#expected.container_id,
            source_closure: this.#before, reader_inputs: this.#receipt.descriptor, ready: this.#receipt.ready,
            host_gates: this.#gates, private_reader_returned_three_roles: true,
            form_login_business_DOM: 'NOT RUN', full_acceptance: 'INCOMPLETE' };
          await create(path.join(this.#context.run_dir, 'evidence', 'ui-private-caller-handoff.json'),
            Buffer.from(JSON.stringify(handoff) + '\n'));
        }
      }
      if (this.#phase === 'PRIVATE_READ_RUNNING' && this.#readState !== 'RUNNING') {
        demand(this.#readState === 'DONE'); keys(this.#credentials, ['customer', 'merchant', 'platform']);
        this.#startGate('after');
      }
      return this.status();
    } catch { this.#phase = 'FAILED'; throw new Error('MS4_UI_PRIVATE_CALLER_UNPROVED'); }
    finally { this.#busy = false; }
  }
  status() {
    return { phase: this.#phase, live_host_pid: this.#gate?.done === false ? this.#gate.pid ?? null : null,
      private_read: this.#phase === 'PRIVATE_READY' ? 'COMPLETED_BOTH_HOST_GATES' : 'NOT_PROVEN',
      roles_filled: [...(this.#task2Scenes.at(-1)?.filled ?? this.#filled)].sort(), authentication: 'NOT_PROVEN', business_DOM: 'NOT RUN',
      private_references_released: this.#forgotten };
  }
  publicBinding() {
    demand(this.#phase === 'PRIVATE_READY' && !this.#forgotten && !this.#busy
      && this.#context && this.#freeze && this.#before && this.#expected && this.#receipt);
    // Only the actual instance's already verified public identity; no credentials or session values.
    return JSON.parse(JSON.stringify({
      run_id: this.#context.run_id, source_sha: this.#context.source_sha,
      freeze_sha256: this.#context.freeze_sha256, context_sha256: this.#before.context.sha256,
      backend_cid: this.#expected.container_id, ready_sha256: this.#receipt.ready.sha256,
      origin: this.#freeze.ui_web_origin, source_closure: this.#before,
    }));
  }
  activateTask2LoginScene(config) {
    keys(config, ['bridge', 'predecessor', 'owned_tab_sha256']);
    demand(this.#phase === 'PRIVATE_READY' && !this.#forgotten && !this.#busy && !this.#task2Failure
      && this.#credentials !== null && Object.hasOwn(this.#freeze, 'task2_observer_source')
      && Object.hasOwn(this.#freeze, 'task2_browser_source') && this.#task2Scenes.length < 4);
    const { bridge, predecessor, owned_tab_sha256: tabs } = config;
    keys(tabs, ['customer', 'merchant', 'platform']);
    demand(Object.values(tabs).every(value => hex(value, 64)) && new Set(Object.values(tabs)).size === 3);
    const task2 = Object.getPrototypeOf(bridge) === UiTask2ObserverBridge.prototype;
    demand(task2 || Object.getPrototypeOf(bridge) === UiWindowObserverBridge.prototype);
    const api = task2 ? UiTask2ObserverBridge.prototype : UiWindowObserverBridge.prototype;
    const source = api.sourceBinding.call(bridge), state = api.poll.call(bridge);
    const order = [...Object.keys(TASK2_READ_MODES), 'ORIGINAL_MS4_UI20'];
    const mode = task2 ? Object.keys(TASK2_READ_MODES).find(name => TASK2_READ_MODES[name].scenario === source.scenario)
      : 'ORIGINAL_MS4_UI20';
    demand(mode === order[this.#task2Scenes.length] && state.phase === 'BEGIN_ACK'
      && state.verified_steps === 0 && state.required_steps === (task2 ? 9 : 20) && state.diagnostic === null);
    const fullBinding = source.binding, reduced = JSON.parse(JSON.stringify(fullBinding));
    delete reduced.gateway_cid; delete reduced.source_closure.browser_close;
    demand(equal(reduced, this.publicBinding()));
    task2Source(this.#freeze.task2_observer_source, fullBinding);
    if (task2) demand(equal(source.observer_source, this.#freeze.task2_observer_source));
    const begin = api.response.call(bridge, 0);
    demand(begin.command === 'BEGIN' && begin.sequence === 0 && begin.step === 0
      && equal(begin.receipt.baseline.binding, fullBinding));
    if (this.#task2Scenes.length === 0) {
      demand(predecessor === null && this.#fillAttempted.size === 0 && this.#filled.size === 0);
    } else {
      const previous = this.#task2Scenes.at(-1), roles = ['customer', 'merchant', 'platform'];
      demand(predecessor === previous.bridge && Object.getPrototypeOf(predecessor) === UiTask2ObserverBridge.prototype
        && equal(previous.tabs, tabs) && previous.attempted.size === 3 && previous.filled.size === 3
        && roles.every(role => previous.attempted.has(role) && previous.filled.has(role)));
      const previousSource = UiTask2ObserverBridge.prototype.sourceBinding.call(predecessor);
      demand(equal(previousSource.binding, fullBinding)
        && equal(previousSource.observer_source, this.#freeze.task2_observer_source));
      const final = UiTask2ObserverBridge.prototype.completedLedger.call(predecessor, fullBinding, mode);
      demand(equal(final.binding, begin.receipt.baseline.binding)
        && equal(final.database, begin.receipt.baseline.database)
        && equal(final.redis.keys, begin.receipt.baseline.redis.keys) && equal(final.files, begin.receipt.baseline.files));
      const tail = UiTask2ObserverBridge.prototype.response.call(predecessor, 18);
      demand(tail.command === 'STEP_VERIFY' && tail.step === 9 && tail.sequence === 18);
      const logouts = tail.receipt.expected_delta.independent.auxiliary_reads.filter(value => value.kind === 'normal-logout');
      demand(logouts.length === 3 && new Set(logouts.map(value => value.application)).size === 3);
      for (const [index, role] of roles.entries()) {
        const login = UiTask2ObserverBridge.prototype.response.call(predecessor, 2 * (index + 1));
        const fact = login.receipt.expected_delta.independent.login;
        const app = { customer: 'api', merchant: 'merchantapi', platform: 'adminapi' }[role];
        const subject = role === 'customer' ? this.#expected.actors.customer.uid : this.#expected.actors[role].id;
        demand(login.command === 'STEP_VERIFY' && login.step === index + 1 && fact.application === app
          && fact.subject_id === subject && fact.native_session_verified === true
          && fact.signed_claims_verified === true && fact.browser_token_join === true);
        const logout = logouts.find(value => value.application === app);
        demand(logout && logout.subject_id === subject && logout.native_session_removed === true
          && logout.issued_login_token_join === true && logout.subsequent_requests_anonymous === true);
      }
    }
    this.#task2Scenes.push({ mode, bridge, tabs: { ...tabs }, attempted: new Set(), filled: new Set() });
    return this.status();
  }
  async fillRoleForm(tab, role, selectors) {
    const task2 = this.#freeze && Object.hasOwn(this.#freeze, 'task2_observer_source');
    const scene = task2 ? this.#task2Scenes.at(-1) : null;
    demand(!task2 || (scene !== undefined && !this.#task2Failure));
    const attempted = scene === null ? this.#fillAttempted : scene.attempted;
    demand(this.#phase === 'PRIVATE_READY' && !this.#forgotten && ['customer', 'merchant', 'platform'].includes(role)
      && !attempted.has(role));
    if (scene !== null) {
      demand(typeof tab.id === 'string' && sha(Buffer.from(tab.id)) === scene.tabs[role]);
      attempted.add(role); // Reserve before the first await; a scene name cannot reopen this attempt.
    }
    try {
    keys(selectors, ['account', 'password']);
    for (const selector of Object.values(selectors)) {
      keys(selector, ['by', 'text']); demand(['label', 'placeholder'].includes(selector.by)
        && typeof selector.text === 'string' && selector.text.length > 0);
    }
    const actualUrl = await tab.url(), url = new URL(actualUrl);
    demand(url.origin === this.#freeze.ui_web_origin && (role === 'merchant' ? url.pathname.startsWith('/merchant/')
      : role === 'platform' ? url.pathname.startsWith('/admin/')
      : !url.pathname.startsWith('/merchant/') && !url.pathname.startsWith('/admin/')));
    const locate = selector => selector.by === 'label'
      ? tab.playwright.getByLabel(selector.text, { exact: true })
      : tab.playwright.getByPlaceholder(selector.text, { exact: true });
    const account = locate(selectors.account), password = locate(selectors.password);
    demand(await account.count() === 1 && await password.count() === 1
      && await account.isVisible() && await password.isVisible()
      && await account.isEnabled() && await password.isEnabled()
      && ['text', 'email', 'tel', 'number', null].includes(await account.getAttribute('type', {}))
      && !equal(selectors.account, selectors.password)
      && await password.getAttribute('type', {}) === 'password');
    if (scene === null) attempted.add(role); // Preserve the original default UI20 attempt boundary.
    try {
      await account.fill(this.#credentials[role].account, {});
      demand(await tab.url() === actualUrl && await password.isVisible());
      await password.fill(this.#credentials[role].password, {});
      demand(await tab.url() === actualUrl && await password.getAttribute('type', {}) === 'password');
      this.#filled.add(role); if (scene !== null) scene.filled.add(role);
      return { role, form_fields_filled: true, authentication: 'NOT_PROVEN' };
    } catch {
      throw new Error('MS4_UI_PRIVATE_FORM_OUTCOME_UNKNOWN');
    }
    } catch (error) {
      if (scene !== null) this.#task2Failure = true;
      throw error;
    }
  }
  forget() {
    demand((!this.#gate || this.#gate.done) && this.#readState !== 'RUNNING');
    this.#credentials = null; this.#expected = null; this.#reader = null; this.#forgotten = true;
    this.#task2Scenes = []; this.#task2Failure = true;
    return { private_references_released: true, immutable_JS_strings_securely_erased: false,
      browser_close: 'NOT PROVEN', business_DOM: 'NOT RUN' };
  }
}
