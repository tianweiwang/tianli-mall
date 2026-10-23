// Import-only Root transport. Importing starts no Docker process, browser, App or business request.
import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { isDeepStrictEqual as equal } from 'node:util';

const demand = (ok, code) => { if (!ok) throw new Error(code); };
const sha = value => createHash('sha256').update(value).digest('hex');
const hex = (value, size) => typeof value === 'string' && new RegExp('^[a-f0-9]{' + size + '}$').test(value);
const I8 = ['run_id', 'source_sha', 'container_id', 'freeze_sha256', 'epoch', 'step_nonce', 'not_before', 'deadline'];
const P4 = ['run_id', 'source_sha', 'container_id', 'freeze_sha256'];
const entry = 'help/audit-regressions/isolated/ms4-customer-h5.php';
const actorPath = '/run/audit-http-browser/customer-actors.json';
const controlRoot = '/run/audit-http-control/';
const tarExe = 'C:/Windows/System32/tar.exe';
const phpImage = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const prep = fileURLToPath(new URL('../', import.meta.url));
const stages = ['B0', 'B1', 'B2', 'B3', 'B4'];
const scenes = ['login-empty', 'store-a', 'store-b', 'product-a', 'product-b', 'product-c',
  'cart-three-lines', 'confirm-filled', 'order-detail-unpaid'];
const volumeNames = ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data',
  'redis-secrets', 'http-runtime', 'http-control', 'http-browser-secrets', 'http-public'];
const serveBindingKeys = ['schema', 'scope', ...P4, 'native_created_sha256', 'requested_repo_source',
  'requested_run_source', 'requested_prep_source', 'entry_sha256', 'source_files', 'h5_source_files',
  'artifact_files', 'h5_artifact_files', 'h5_materials', 'public_copy', 'prepare_result', 'files_result',
  'registration_result', 'environment', 'network_receipts', 'stop_signal', 'manifest', 'registration_inputs',
  'prerequisites_sha256', 'canonical_paid'];
const identity18 = ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'native_created_sha256',
  'source_binding_sha256', 'public_copy_sha256', 'public_source_sha256', 'runtime_origin', 'input_bindings',
  'environment', 'self_operated_mer_id', 'base_fixture_sha256', 'base_prepare_receipt_sha256',
  'base_prepare_container_id', 'base_prepare_native_created_sha256', 'base_prepare_source_binding_sha256'];
const immutable = ['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode',
  'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at', 'user', 'stop_signal'];
function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  return value;
}
function sameCreated(created, actual) {
  demand(immutable.every(key => equal(created[key], actual[key])) && Array.isArray(created.mounts)
    && Array.isArray(actual.mounts) && equal(created.mounts.map(row => JSON.stringify(canonical(row))).sort(),
      actual.mounts.map(row => JSON.stringify(canonical(row))).sort()), 'M4CH_BRIDGE_COMPLETE_NATIVE_IMMUTABLE_AND_MOUNT_MULTISET');
}

function keys(value, expected) {
  demand(value && typeof value === 'object' && !Array.isArray(value)
    && equal(Object.keys(value).sort(), [...expected].sort()), 'M4CH_BRIDGE_EXACT_OBJECT');
}
function json(bytes) {
  try { return JSON.parse(Buffer.from(bytes).toString('utf8')); }
  catch { throw new Error('M4CH_BRIDGE_ACTUAL_JSON_REQUIRED'); }
}
async function ordinaryFile(name, maximum) {
  const before = await fs.lstat(name);
  demand(before.isFile() && !before.isSymbolicLink() && before.size > 0 && before.size <= maximum,
    'M4CH_BRIDGE_ORDINARY_BOUNDED_FILE');
  const bytes = await fs.readFile(name), after = await fs.lstat(name);
  demand(bytes.length === before.size && ['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtimeMs', 'ctimeMs']
    .every(key => before[key] === after[key]), 'M4CH_BRIDGE_ACTUAL_FILE_IDENTITY_UNCHANGED');
  return bytes;
}
async function directory(name) {
  const stat = await fs.lstat(name);
  demand(stat.isDirectory() && !stat.isSymbolicLink(), 'M4CH_BRIDGE_ACTUAL_DIRECTORY');
  return fs.realpath(name);
}
async function publish(name, bytes) {
  const handle = await fs.open(name, 'wx', 0o600);
  try { await handle.writeFile(bytes); await handle.sync(); }
  finally { await handle.close(); }
  demand((await ordinaryFile(name, bytes.length)).equals(bytes), 'M4CH_BRIDGE_CREATE_ONLY_READBACK');
}
function jpegPixels(bytes) {
  demand(bytes.length >= 12 && bytes[0] === 0xff && bytes[1] === 0xd8
    && bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9, 'M4CH_BRIDGE_ACTUAL_JPEG_HEADER');
  const sof = new Set([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf]);
  let position = 2;
  while (position + 3 < bytes.length) {
    demand(bytes[position++] === 0xff, 'M4CH_BRIDGE_ACTUAL_JPEG_MARKER');
    while (position < bytes.length && bytes[position] === 0xff) position++;
    const marker = bytes[position++];
    demand(marker !== 0 && marker !== 0xda && marker !== 0xd9, 'M4CH_BRIDGE_JPEG_DIMENSIONS_BEFORE_SCAN');
    if (marker === 0x01 || marker >= 0xd0 && marker <= 0xd7) continue;
    demand(position + 2 <= bytes.length, 'M4CH_BRIDGE_JPEG_SEGMENT_LENGTH');
    const length = bytes.readUInt16BE(position);
    demand(length >= 2 && position + length <= bytes.length, 'M4CH_BRIDGE_JPEG_ACTUAL_SEGMENT');
    if (sof.has(marker)) {
      demand(length >= 8, 'M4CH_BRIDGE_JPEG_ACTUAL_FRAME');
      const pixels = { width: bytes.readUInt16BE(position + 5), height: bytes.readUInt16BE(position + 3) };
      demand(pixels.width > 0 && pixels.width <= 10000 && pixels.height > 0 && pixels.height <= 10000,
        'M4CH_BRIDGE_ACTUAL_JPEG_PIXEL_DIMENSIONS');
      return pixels;
    }
    position += length;
  }
  throw new Error('M4CH_BRIDGE_JPEG_DIMENSIONS_REQUIRED');
}
// Deliberately separate from H/I8: these public files are derived projections, never private-byte copies.
function publicIdentity(value) {
  return { schema: 1, ...Object.fromEntries(P4.map(key => [key, value[key]])),
    epoch_prefix: value.epoch.slice(0, 12), step_nonce_prefix: value.step_nonce.slice(0, 12),
    not_before: value.not_before, deadline: value.deadline };
}

export class CustomerPrivateBridge {
  #expected; #run = null; #freeze = null; #binding = null; #nativeBusy = false;
  #runtime = null; #actors = null; #actorAttempted = false; #uiIntent = null; #publicIds = null;
  #control = null; #start = null; #startAttempted = false; #stop = null; #stageIndex = 0; #stageAttempted = new Set();
  #captures = new Map(); #screenshots = new Map(); #reviews = new Set(); #finish = null; #finishAttempted = false; #closed = false;
  constructor(expected) {
    keys(expected, ['run_id', 'source_sha', 'http_container_id', 'bootstrap_container_id', 'freeze_sha256', 'host_run_path']);
    demand(/^\d{14}[a-f0-9]{6}$/.test(expected.run_id) && hex(expected.source_sha, 40)
      && hex(expected.http_container_id, 64) && hex(expected.bootstrap_container_id, 64)
      && expected.http_container_id !== expected.bootstrap_container_id && hex(expected.freeze_sha256, 64)
      && typeof expected.host_run_path === 'string' && path.isAbsolute(expected.host_run_path),
      'M4CH_BRIDGE_ACTUAL_EXPECTED_IDENTITY');
    this.#expected = { ...expected };
  }
  async initialize() {
    demand(this.#run === null, 'M4CH_BRIDGE_INITIALIZE_ONCE');
    const run = await directory(this.#expected.host_run_path);
    demand(path.basename(run) === this.#expected.run_id
      && path.dirname(await directory(path.join(run, 'evidence'))) === run, 'M4CH_BRIDGE_REAL_RUN_EVIDENCE');
    const bytes = await ordinaryFile(path.join(run, 'preparation-source-binding.json'), 16777216);
    demand(sha(bytes) === this.#expected.freeze_sha256, 'M4CH_BRIDGE_ACTUAL_FROZEN_SOURCE');
    const freeze = json(bytes);
    demand(freeze.status === 'CLEAN_SOURCE_FROZEN_ONLY' && freeze.source_sha === this.#expected.source_sha
      && Array.isArray(freeze.h5_artifact_files) && freeze.h5_artifact_files.length === 20,
      'M4CH_BRIDGE_COMPLETE_FINAL_H5_FREEZE');
    for (const name of ['browser-v1/customer-network-observer.mjs', 'browser-v1/ms4-customer-private-bridge.mjs',
      'browser-v1/ms4-customer-browser.mjs']) {
      const rows = freeze.h5_artifact_files.filter(row => row.path === name);
      demand(rows.length === 1, 'M4CH_BRIDGE_FROZEN_TRANSPORT_DESCRIPTOR');
      keys(rows[0], ['path', 'bytes', 'sha256']);
      const raw = await ordinaryFile(path.join(prep, ...name.split('/')), 1048576);
      demand(Number.isSafeInteger(rows[0].bytes) && raw.length === rows[0].bytes && sha(raw) === rows[0].sha256,
        'M4CH_BRIDGE_ACTUAL_TRANSPORT_BYTES');
    }
    this.#run = run; this.#freeze = freeze;
    const bindingBytes = await this.#evidence('ms4-customer-h5-serve-source-binding.json');
    const binding = json(bindingBytes); keys(binding, serveBindingKeys);
    demand(binding.schema === 1 && binding.scope === 'MS4_CUSTOMER_H5_HTTP_MS5_SOURCE'
      && binding.run_id === this.#expected.run_id && binding.source_sha === this.#expected.source_sha
      && binding.container_id === this.#expected.http_container_id && binding.freeze_sha256 === this.#expected.freeze_sha256
      && equal(binding.source_files, freeze.customer_source_files) && equal(binding.h5_source_files, freeze.h5_source_files)
      && equal(binding.artifact_files, freeze.artifact_files) && equal(binding.h5_artifact_files, freeze.h5_artifact_files)
      && equal(binding.h5_materials, freeze.h5_materials) && await directory(binding.requested_run_source) === run
      && await directory(binding.requested_prep_source) === await directory(prep)
      && binding.requested_repo_source === freeze.requested_repo_source
      && freeze.h5_source_files.filter(row => row.path === entry && row.sha256 === binding.entry_sha256).length === 1
      && binding.native_created_sha256 === sha(await this.#evidence('customer-h5-serve-created.json')),
      'M4CH_BRIDGE_ACTUAL_SERVE_SOURCE_BINDING');
    this.#binding = binding;
    await this.#sameTenVolumes();
    await this.#liveServe();
    return { initialized: true, run_id: this.#expected.run_id, source_sha: this.#expected.source_sha };
  }
  async #native(executable, args, input, maximum = 2097152) {
    demand(this.#run !== null && !this.#nativeBusy, 'M4CH_BRIDGE_ONE_NATIVE_OPERATION');
    this.#nativeBusy = true;
    try {
      return await new Promise((resolve, reject) => {
        let child;
        try { child = spawn(executable, args, { cwd: this.#run, windowsHide: true, shell: false,
          stdio: ['pipe', 'pipe', 'pipe'] }); }
        catch { reject(new Error('M4CH_BRIDGE_NATIVE_SPAWN')); return; }
        let total = 0, stderrBytes = 0, oversized = false, inputError = false;
        const chunks = [];
        child.stdout.on('data', chunk => { total += chunk.length;
          if (total <= maximum) chunks.push(chunk); else { oversized = true; chunk.fill(0); } });
        child.stderr.on('data', chunk => { stderrBytes += chunk.length; chunk.fill(0); });
        child.stdin.on('error', () => { inputError = true; });
        child.on('error', () => { chunks.forEach(chunk => chunk.fill(0)); reject(new Error('M4CH_BRIDGE_NATIVE_SPAWN')); });
        child.on('close', (code, signal) => {
          if (code !== 0 || signal !== null || oversized || stderrBytes !== 0 || inputError) {
            chunks.forEach(chunk => chunk.fill(0)); reject(new Error('M4CH_BRIDGE_NATIVE_TERMINAL_CHECK')); return;
          }
          const result = Buffer.concat(chunks); chunks.forEach(chunk => chunk.fill(0)); resolve(result);
        });
        child.stdin.end(input ?? undefined);
      });
    } finally { this.#nativeBusy = false; }
  }
  async #evidence(name) {
    demand(this.#run && /^[a-z0-9-]+\.json$/.test(name), 'M4CH_BRIDGE_FIXED_EVIDENCE_NAME');
    return ordinaryFile(path.join(this.#run, 'evidence', name), 16777216);
  }
  async #publish(name, value) {
    demand(/^[a-z0-9-]+\.json$/.test(name), 'M4CH_BRIDGE_FIXED_PUBLIC_RECEIPT');
    await publish(path.join(this.#run, 'evidence', name), Buffer.from(JSON.stringify(value) + '\n'));
  }
  async #ingress(mode, value) {
    demand(['runtime', 'actor-meta', 'supervisor', 'write', 'stage', 'finish', 'audit'].includes(mode),
      'M4CH_BRIDGE_FINITE_INGRESS_MODE');
    const input = value === undefined ? null : Buffer.from(JSON.stringify(value) + '\n');
    let bytes;
    try {
      demand(input === null || input.length <= 2097152, 'M4CH_BRIDGE_BOUNDED_INGRESS');
      bytes = await this.#native('docker', ['exec', '-i', this.#expected.http_container_id, 'php', entry, 'ingress', mode], input);
      if (mode === 'write') {
        demand(bytes.toString('utf8') === 'MS4_PRIVATE_WRITE_OK\n', 'M4CH_BRIDGE_ACTUAL_PRIVATE_WRITE_ACK');
        return { private_write_completed: true };
      }
      return json(bytes);
    } finally { input?.fill(0); bytes?.fill(0); }
  }
  async #copyPrivateFile(fixedPath, basename, maximum) {
    demand((fixedPath === actorPath && basename === 'customer-actors.json')
      || (fixedPath === controlRoot + 'source-ready.json' && basename === 'source-ready.json')
      || (fixedPath === controlRoot + 'runtime.json' && basename === 'runtime.json')
      || (fixedPath === controlRoot + 'sql-audit.json' && basename === 'sql-audit.json'),
      'M4CH_BRIDGE_FINITE_FIXED_PRIVATE_COPY_PATHS');
    let archive, listing, bytes;
    try {
      archive = await this.#native('docker', ['cp', this.#expected.http_container_id + ':' + fixedPath, '-'], null, maximum + 262144);
      listing = await this.#native(tarExe, ['-tf', '-'], archive, 4096);
      demand(listing.toString('utf8').trim() === basename, 'M4CH_BRIDGE_SINGLE_PRIVATE_TAR_ENTRY');
      bytes = await this.#native(tarExe, ['-xOf', '-', basename], archive, maximum);
      demand(bytes.length > 0, 'M4CH_BRIDGE_NONEMPTY_PRIVATE_FILE');
      return bytes;
    } catch (error) { bytes?.fill(0); throw error; }
    finally { archive?.fill(0); listing?.fill(0); }
  }
  async #sameTenVolumes() {
    const context = json(await ordinaryFile(path.join(this.#run, 'actual-run-context.json'), 1048576));
    const additional = json(await this.#evidence('customer-h5-additional-volume-identities.json'));
    demand(context.run_id === this.#expected.run_id && context.source_sha === this.#expected.source_sha
      && context.freeze_sha256 === this.#expected.freeze_sha256 && context.project === 'tianli-audit-' + context.run_id
      && additional.original_context_sha256 === sha(await ordinaryFile(path.join(this.#run, 'actual-run-context.json'), 1048576))
      && additional.run_id === context.run_id && additional.source_sha === context.source_sha
      && additional.freeze_sha256 === context.freeze_sha256, 'M4CH_BRIDGE_NATIVE_VOLUME_PRODUCER_BINDING');
    const rows = [...context.initial_volumes, ...additional.additional_volumes].sort((a, b) => a.name.localeCompare(b.name));
    demand(equal(rows.map(row => row.name), volumeNames.map(name => context.project + '_' + name).sort())
      && equal(additional.all_volume_names, rows.map(row => row.name)), 'M4CH_BRIDGE_TEN_ORIGINAL_VOLUME_IDENTITIES');
    const format = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}';
    for (const row of rows) {
      keys(row, ['name', 'created_at', 'labels']);
      const bytes = await this.#native('docker', ['volume', 'inspect', '--format', format, row.name], null, 16384);
      demand(equal(json(bytes), row), 'M4CH_BRIDGE_SAME_NATIVE_VOLUME_NAME_CREATEDAT_LABELS'); bytes.fill(0);
    }
  }
  async #currentPrerequisitesAndPaid() {
    const names = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json',
      'initializer-result.json', 'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
      'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json',
      'files-bootstrap-created.json', 'files-bootstrap-terminal.json', 'files-bootstrap-result.json', 'files-bootstrap-shutdown.json',
      'customer-prepare-created.json', 'customer-prepare-terminal.json', 'ms4-customer-prepare.json',
      'customer-h5-additional-volume-identities.json', 'customer-h5-public-copy-created.json',
      'customer-h5-public-copy-terminal.json', 'customer-h5-public-copy.json', 'customer-h5-prepare-created.json',
      'customer-h5-prepare-terminal.json', 'customer-h5-prepare-result.json', 'customer-h5-files-created.json',
      'customer-h5-files-terminal.json', 'customer-h5-files-result.json', 'register-customer-h5-serve-created.json',
      'register-customer-h5-serve-terminal.json', 'register-customer-h5-serve-result.json'];
    keys(this.#binding.prerequisites_sha256, names);
    for (const name of names) demand(hex(this.#binding.prerequisites_sha256[name], 64)
      && sha(await this.#evidence(name)) === this.#binding.prerequisites_sha256[name], 'M4CH_BRIDGE_FULL_CURRENT_PHASE_ORIGINAL_HASH');
    const desc = this.#binding.canonical_paid, leaf = 'customer-h5-serve-paid-unstarted.json'; keys(desc, ['path', 'bytes', 'sha256']);
    const sealed = await this.#evidence(leaf), original = json(await this.#evidence('paid-producer-created.json'));
    demand(desc.path === '/run/audit/evidence/' + leaf && Number.isSafeInteger(desc.bytes) && desc.bytes > 0
      && desc.bytes === sealed.length && hex(desc.sha256, 64) && sha(sealed) === desc.sha256, 'M4CH_BRIDGE_PHASE_NATIVE_ORIGINAL_BYTES');
    const fields = [...immutable, 'mounts', 'status', 'pid', 'restart_count', 'exit_code', 'networks', 'ports', 'started_at', 'finished_at'];
    keys(original, [...fields, 'run_id', 'source_sha', 'proof_name']); const frozen = json(sealed); keys(frozen, fields);
    demand(original.run_id === this.#expected.run_id && original.source_sha === this.#expected.source_sha
      && original.proof_name === 'paid-producer' && hex(original.id, 64) && original.image === phpImage
      && original.labels?.['tianli.audit'] === '1' && original.labels?.['tianli.audit.run'] === this.#expected.run_id
      && original.labels?.['com.docker.compose.service'] === 'audit-ms5-paid-producer'
      && original.labels?.['com.docker.compose.project'] === 'tianli-audit-' + this.#expected.run_id
      && original.status === 'created' && original.pid === 0 && original.restart_count === 0 && original.exit_code === 0
      && ['started_at', 'finished_at'].every(key => /^0001-01-01T00:00:00(?:\.0{1,9})?Z$/.test(original[key]))
      && equal(original.command, ['help/audit-regressions/isolated/ms5-paid-consumer-normal.php', 'produce'])
      && equal(original.entrypoint, ['php']) && original.read_only === true && original.privileged === false,
      'M4CH_BRIDGE_REAL_CANONICAL_NEVER_STARTED_OWNER');
    const expressions = { id: '.Id', image: '.Image', hostname: '.Config.Hostname', labels: '.Config.Labels',
      mounts: '.Mounts', tmpfs: '(index .HostConfig "Tmpfs")', read_only: '.HostConfig.ReadonlyRootfs',
      privileged: '.HostConfig.Privileged', network_mode: '.HostConfig.NetworkMode', command: '.Config.Cmd',
      entrypoint: '.Config.Entrypoint', workdir: '.Config.WorkingDir', port_bindings: '.HostConfig.PortBindings',
      cap_drop: '.HostConfig.CapDrop', security_options: '.HostConfig.SecurityOpt', created_at: '.Created',
      user: '.Config.User', stop_signal: '(index .Config "StopSignal")', status: '.State.Status', pid: '.State.Pid',
      restart_count: '.RestartCount', exit_code: '.State.ExitCode', networks: '.NetworkSettings.Networks',
      ports: '.NetworkSettings.Ports', started_at: '.State.StartedAt', finished_at: '.State.FinishedAt' };
    const format = '{' + fields.map(key => JSON.stringify(key) + ':{{json ' + expressions[key] + '}}').join(',') + '}';
    const bytes = await this.#native('docker', ['inspect', '--type', 'container', '--format', format, original.id], null, 131072);
    try {
      const actual = json(bytes); keys(actual, fields);
      for (const observed of [frozen, actual]) {
        sameCreated(original, observed);
        demand(fields.filter(key => key !== 'mounts').every(key => equal(observed[key], original[key])),
          'M4CH_BRIDGE_FULL_ORIGINAL_PHASE_AND_FRESH_NATIVE26_UNCHANGED');
      }
    } finally { bytes.fill(0); sealed.fill(0); }
  }
  async #liveServe() {
    await this.#currentPrerequisitesAndPaid();
    const pairs = Object.fromEntries(immutable.map(key => [key, {
      id: '.Id', image: '.Image', hostname: '.Config.Hostname', labels: '.Config.Labels', tmpfs: '(index .HostConfig "Tmpfs")',
      read_only: '.HostConfig.ReadonlyRootfs', privileged: '.HostConfig.Privileged', network_mode: '.HostConfig.NetworkMode',
      command: '.Config.Cmd', entrypoint: '.Config.Entrypoint', workdir: '.Config.WorkingDir', port_bindings: '.HostConfig.PortBindings',
      cap_drop: '.HostConfig.CapDrop', security_options: '.HostConfig.SecurityOpt', created_at: '.Created', user: '.Config.User',
      stop_signal: '(index .Config "StopSignal")' }[key]]));
    Object.assign(pairs, { mounts: '.Mounts', status: '.State.Status', pid: '.State.Pid', restart_count: '.RestartCount' });
    const format = '{' + Object.entries(pairs).map(([key, expression]) => JSON.stringify(key) + ':{{json ' + expression + '}}').join(',') + '}';
    const bytes = await this.#native('docker', ['inspect', '--type', 'container', '--format', format, this.#expected.http_container_id], null, 131072);
    try {
      const current = json(bytes), created = json(await this.#evidence('customer-h5-serve-created.json'));
      sameCreated(created, current);
      demand(current.id === this.#expected.http_container_id && current.image === phpImage && current.status === 'running'
        && Number.isInteger(current.pid) && current.pid > 0 && current.restart_count === 0
        && current.labels?.['com.docker.compose.service'] === 'audit-ms4-customer-h5'
        && current.labels?.['tianli.audit.run'] === this.#expected.run_id && current.read_only === true
        && current.privileged === false && current.stop_signal === 'SIGTERM'
        && equal(current.entrypoint, ['php']) && equal(current.command, [entry, 'serve']), 'M4CH_BRIDGE_SAME_LIVE_SERVE_CID');
      const actorMounts = current.mounts.filter(row => row.Destination === '/run/audit-http-browser');
      demand(actorMounts.length === 1 && actorMounts[0].Type === 'volume' && actorMounts[0].RW === false
        && actorMounts[0].Name === 'tianli-audit-' + this.#expected.run_id + '_http-browser-secrets',
        'M4CH_BRIDGE_ORIGINAL_READONLY_ACTOR_VOLUME');
    } finally { bytes.fill(0); }
  }
  #identity(value) {
    demand(this.#runtime && value.schema === 1, 'M4CH_BRIDGE_CURRENT_RUNTIME_REQUIRED');
    for (const key of [...P4, 'epoch', 'deadline']) demand(value[key] === this.#runtime[key], 'M4CH_BRIDGE_FULL_PRIVATE_IDENTITY');
    const now = Math.floor(Date.now() / 1000);
    demand(hex(value.step_nonce, 48) && Number.isInteger(value.not_before)
      && value.not_before >= this.#runtime.not_before && value.not_before <= now && now < value.deadline,
      'M4CH_BRIDGE_LIVE_ORIGINAL_RUNTIME_WINDOW');
  }
  newFrame(scope) {
    demand(typeof scope === 'string' && ['MS4_CUSTOMER_H5_STAGE_REQUEST', 'MS4_CUSTOMER_H5_UI_CAPTURE']
      .includes(scope), 'M4CH_BRIDGE_FINITE_CALLER_FRAME_SCOPE');
    return this.#newFrame(scope);
  }
  #newFrame(scope) {
    demand(this.#runtime && Math.floor(Date.now() / 1000) < this.#runtime.deadline, 'M4CH_BRIDGE_CURRENT_RUNTIME_REQUIRED');
    return { schema: 1, scope, ...Object.fromEntries(I8.map(key => [key, this.#runtime[key]])),
      step_nonce: randomBytes(24).toString('hex'), not_before: Math.floor(Date.now() / 1000) };
  }
  async runtime() {
    demand(this.#runtime === null, 'M4CH_BRIDGE_RUNTIME_READ_ONCE');
    const value = await this.#ingress('runtime'); keys(value, ['schema', 'scope', ...I8, 'origin']);
    const now = Math.floor(Date.now() / 1000);
    demand(value.schema === 1 && value.scope === 'MS4_CUSTOMER_H5_HTTP_RUNTIME'
      && P4.every(key => value[key] === (key === 'container_id' ? this.#expected.http_container_id : this.#expected[key]))
      && value.origin === this.#freeze.h5_materials.runtime_origin && hex(value.epoch, 64) && hex(value.step_nonce, 48)
      && Number.isInteger(value.not_before) && Number.isInteger(value.deadline)
      && value.deadline - value.not_before === 3600 && value.not_before <= now && now < value.deadline,
      'M4CH_BRIDGE_GENUINE_SERVE_RUNTIME');
    this.#runtime = value; return value; // PRIVATE: never render.
  }
  async publishSourceReady() {
    demand(this.#runtime && !this.#start, 'M4CH_BRIDGE_READY_BEFORE_BUSINESS_CHILD');
    const bytes = await this.#copyPrivateFile(controlRoot + 'source-ready.json', 'source-ready.json', 32768);
    let runtimeBytes;
    try {
      const ready = json(bytes); keys(ready, ['schema', 'scope', ...I8, 'source_binding_sha256',
        'native_created_sha256', 'public_receipt_sha256', 'runtime_sha256', 'supervisor', 'full_source_verified']);
      runtimeBytes = await this.#copyPrivateFile(controlRoot + 'runtime.json', 'runtime.json', 32768);
      this.#identity(ready);
      demand(ready.scope === 'MS4_CUSTOMER_H5_HTTP_SOURCE_READY' && I8.every(key => ready[key] === this.#runtime[key])
        && ready.source_binding_sha256 === sha(await this.#evidence('ms4-customer-h5-serve-source-binding.json'))
        && ready.native_created_sha256 === this.#binding.native_created_sha256
        && ready.public_receipt_sha256 === sha(await this.#evidence('customer-h5-public-copy.json'))
        && ready.runtime_sha256 === sha(runtimeBytes) && equal(json(runtimeBytes), this.#runtime)
        && ready.full_source_verified === true, 'M4CH_BRIDGE_ACTUAL_PRIVATE_SOURCE_READY');
      keys(ready.supervisor, ['pid', 'ppid', 'pgid', 'sid', 'starttime', 'state']);
      const value = { ...publicIdentity(ready), scope: ready.scope, source_binding_sha256: ready.source_binding_sha256,
        native_created_sha256: ready.native_created_sha256, public_receipt_sha256: ready.public_receipt_sha256,
        runtime_sha256_prefix: ready.runtime_sha256.slice(0, 12), private_ready_sha256_prefix: sha(bytes).slice(0, 12),
        private_ready_bytes: bytes.length, supervisor: ready.supervisor, full_source_verified: true,
        public_projection_only: true };
      await this.#publish('http-source-ready.json', value); return { source_ready_projection_published: true };
    } finally { bytes.fill(0); runtimeBytes?.fill(0); }
  }
  async copyActorsFromLiveServe() {
    demand(this.#runtime && !this.#actorAttempted && !this.#start, 'M4CH_BRIDGE_PRIVATE_HANDOFF_ONCE_BEFORE_START');
    this.#actorAttempted = true;
    const before = await this.#ingress('actor-meta'); this.#identity(before);
    keys(before, ['schema', 'scope', ...I8, 'actor_descriptor', 'actual_meta', 'directory_meta',
      'producer_binding', 'public_ids', 'ui_intent']);
    demand(before.scope === 'MS4_CUSTOMER_H5_ACTOR_META', 'M4CH_BRIDGE_ACTUAL_ACTOR_METADATA_SCOPE');
    const raw = await this.#copyPrivateFile(actorPath, 'customer-actors.json', 16384);
    try {
      const after = await this.#ingress('actor-meta'); this.#identity(after);
      for (const key of ['actor_descriptor', 'actual_meta', 'directory_meta', 'producer_binding', 'public_ids', 'ui_intent'])
        demand(equal(before[key], after[key]), 'M4CH_BRIDGE_PRIVATE_HANDOFF_METADATA_UNCHANGED');
      keys(before.actor_descriptor, ['path', 'sha256', 'bytes', 'owner_uid', 'mode']);
      keys(before.actual_meta, ['bytes', 'sha256', 'dev', 'ino', 'mode', 'uid']);
      keys(before.directory_meta, ['dev', 'ino', 'mode', 'uid', 'gid']);
      demand(before.actor_descriptor.path === actorPath && before.actor_descriptor.sha256 === sha(raw)
        && before.actor_descriptor.bytes === raw.length && before.actor_descriptor.mode === '0600'
        && before.actual_meta.bytes === raw.length && before.actual_meta.sha256 === sha(raw)
        && before.actual_meta.mode === 0o100600 && before.actual_meta.uid === before.actor_descriptor.owner_uid
        && before.directory_meta.mode === 0o40700 && before.directory_meta.uid === before.actual_meta.uid,
        'M4CH_BRIDGE_ACTUAL_PRIVATE_BYTES_MODE_OWNER');
      const actors = json(raw); keys(actors, [...identity18, 'scope', 'actors']);
      const producer = before.producer_binding;
      keys(producer, ['container_id', 'native_created_sha256', 'source_binding_sha256', 'prepare_result_sha256']);
      const createdBytes = await this.#evidence('customer-h5-prepare-created.json');
      const bindingBytes = await this.#evidence('ms4-customer-h5-prepare-source-binding.json');
      const resultBytes = await this.#evidence('customer-h5-prepare-result.json');
      const created = json(createdBytes), terminal = json(await this.#evidence('customer-h5-prepare-terminal.json'));
      const result = json(resultBytes), binding = json(bindingBytes);
      sameCreated(created, terminal);
      demand(producer.container_id === this.#expected.bootstrap_container_id && producer.container_id === created.id
        && producer.native_created_sha256 === sha(createdBytes) && producer.source_binding_sha256 === sha(bindingBytes)
        && producer.prepare_result_sha256 === sha(resultBytes) && created.image === phpImage
        && created.labels?.['com.docker.compose.service'] === 'audit-ms4-customer-h5-prepare'
        && equal(created.command, [entry, 'prepare']) && terminal.id === created.id
        && terminal.status === 'exited' && terminal.pid === 0 && terminal.exit_code === 0 && terminal.restart_count === 0
        && result.status === 'VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY' && result.native_exit === 0
        && result.full_source_unchanged === true && result.container_id === created.id
        && result.source_binding_sha256 === sha(bindingBytes) && result.native_created_sha256 === sha(createdBytes)
        && actors.schema === 1 && actors.scope === 'NORMAL_CUSTOMER_H5_PRIVATE_ACTORS'
        && ['run_id', 'source_sha', 'freeze_sha256'].every(key => actors[key] === this.#expected[key]
          && binding[key] === this.#expected[key] && result[key] === this.#expected[key])
        && actors.container_id === created.id && actors.native_created_sha256 === sha(createdBytes)
        && actors.source_binding_sha256 === sha(bindingBytes) && actors.runtime_origin === this.#runtime.origin
        && actors.public_copy_sha256 === this.#binding.public_copy.sha256
        && actors.public_source_sha256 === this.#freeze.h5_materials.public_source.sha256,
        'M4CH_BRIDGE_GENUINE_TERMINAL_ACTOR_PRODUCER');
      keys(actors.actors, ['customer']); const customer = actors.actors.customer;
      keys(customer, ['uid', 'account', 'password']);
      demand(Number.isSafeInteger(customer.uid) && customer.uid > 0 && /^[a-f0-9]{10}$/.test(customer.account)
        && typeof customer.password === 'string' && customer.password.length === 24 && /^[\x21-\x7e]+$/.test(customer.password),
        'M4CH_BRIDGE_ACTUAL_CUSTOMER_ACCOUNT_FIELDS');
      keys(before.public_ids, ['merchantIds', 'productIds', 'skuPairs', 'addressIds', 'invoiceIds']);
      demand(before.public_ids.merchantIds.length === 2 && before.public_ids.productIds.length === 3
        && before.public_ids.skuPairs.length === 4 && before.public_ids.addressIds.length === 1
        && before.public_ids.invoiceIds.length === 1 && before.ui_intent && typeof before.ui_intent === 'object',
        'M4CH_BRIDGE_SEALED_MINIMUM_BROWSER_INTENT');
      const filesBytes = await this.#evidence('customer-h5-files-result.json');
      demand(this.#binding.prepare_result.bytes === resultBytes.length && this.#binding.prepare_result.sha256 === sha(resultBytes)
        && this.#binding.files_result.bytes === filesBytes.length && this.#binding.files_result.sha256 === sha(filesBytes),
        'M4CH_BRIDGE_SERVE_BOUND_ORIGINAL_PREPARE_FILES_RESULTS');
      await this.#sameTenVolumes();
      await this.#liveServe();
      this.#actors = actors; this.#publicIds = before.public_ids; this.#uiIntent = before.ui_intent;
      const receipt = { schema: 1, scope: 'MS4_CUSTOMER_H5_PRIVATE_HANDOFF', status: 'VERIFIED_PRIVATE_ACTOR_COPY_ONLY',
        ...Object.fromEntries(P4.map(key => [key, key === 'container_id' ? this.#expected.http_container_id : this.#expected[key]])),
        bootstrap_container_id: created.id, prepare_receipt_sha256: sha(resultBytes), source_binding_sha256: sha(bindingBytes),
        native_created_sha256: sha(createdBytes), files_receipt_sha256: sha(filesBytes),
        public_receipt_sha256: this.#binding.public_copy.sha256,
        bridge_source_sha256: sha(await ordinaryFile(fileURLToPath(import.meta.url), 1048576)),
        browser_source_sha256: sha(await ordinaryFile(path.join(prep, 'browser-v1/ms4-customer-browser.mjs'), 1048576)),
        private_actor_count: 1, private_actor_file_retained_on_host: false, actor_values_emitted: false,
        docker_cp_exit: 0, tar_list_exit: 0, tar_extract_exit: 0, bootstrap_native_exit: terminal.exit_code,
        bootstrap_terminal_observed: true, HTTP_DOM_acceptance: 'NOT RUN' };
      await this.#publish('customer-h5-private-handoff.json', receipt); return receipt;
    } finally { raw.fill(0); }
  }
  privateBrowserInputs() {
    demand(this.#actors && this.#publicIds && this.#uiIntent, 'M4CH_BRIDGE_ACTUAL_PRIVATE_INPUTS');
    return { actor: this.#actors.actors.customer, public_ids: this.#publicIds, ui_intent: this.#uiIntent };
  }
  sha256(bytes) { demand(bytes instanceof Uint8Array, 'M4CH_BRIDGE_ACTUAL_BYTE_INPUT'); return sha(bytes); }
  async requestSupervisor(action) {
    demand(['start', 'observe', 'stop'].includes(action) && (!this.#control || this.#control.completed),
      'M4CH_BRIDGE_FINITE_SEQUENTIAL_SUPERVISOR');
    demand(action !== 'start' || !this.#startAttempted, 'M4CH_BRIDGE_SINGLE_BUSINESS_START');
    const frame = { ...this.#newFrame('MS4_CUSTOMER_H5_HTTP_CONTROL'), action };
    if (action === 'start') this.#startAttempted = true;
    this.#control = { frame, completed: false };
    await this.#ingress('write', { kind: 'supervisor', payload: frame });
    return { action, private_request_written: true };
  }
  async observeSupervisor() {
    demand(this.#control && !this.#control.completed, 'M4CH_BRIDGE_SAME_CURRENT_SUPERVISOR_HANDLE');
    const reply = await this.#ingress('supervisor');
    keys(reply, ['schema', 'scope', ...I8, 'state', 'response']); this.#identity(reply);
    demand(reply.scope === 'MS4_CUSTOMER_H5_HTTP_SUPERVISOR_REPLY'
      && I8.every(key => reply[key] === this.#control.frame[key]), 'M4CH_BRIDGE_ACTUAL_SUPERVISOR_REPLY');
    if (reply.state === 'not_ready' || reply.response?.status === 'pending')
      return { completed: false, action: this.#control.frame.action, state: reply.state };
    const body = reply.response;
    keys(body, ['schema', 'scope', ...I8, 'action', 'status', 'generation', 'supervisor', 'group', 'members',
      'remaining_members', 'listener_open', 'stop_signal', 'term_attempted', 'native_child', 'native_proc_close', 'normal_only', 'code']);
    this.#identity(body); const action = this.#control.frame.action;
    demand(reply.state === 'response' && body.scope === 'MS4_CUSTOMER_H5_HTTP_STATUS'
      && I8.every(key => body[key] === this.#control.frame[key]) && body.action === action
      && body.status === action && body.normal_only === true, 'M4CH_BRIDGE_GENUINE_SUPERVISOR_RESULT');
    keys(body.supervisor, ['pid', 'ppid', 'pgid', 'sid', 'starttime', 'state']);
    if (action === 'start') {
      demand(body.generation === body.step_nonce && Array.isArray(body.members) && body.members.length > 0
        && body.listener_open === true && body.group, 'M4CH_BRIDGE_ACTUAL_HTTP_CHILD_STARTED');
      this.#start = body;
    } else if (action === 'stop') {
      demand(this.#start && body.generation === this.#start.generation && equal(body.group, this.#start.group)
        && ['pid', 'pgid', 'sid', 'starttime'].every(key => body.supervisor[key] === this.#start.supervisor[key])
        && equal(body.remaining_members, []) && body.listener_open === false && body.stop_signal === 15
        && body.term_attempted === true && body.native_child?.running === false
        && body.native_child?.termsig !== 9 && Number.isInteger(body.native_proc_close),
        'M4CH_BRIDGE_ACTUAL_SINGLE_TERM_CHILD_STOP');
      this.#stop = body;
    }
    if (action !== 'observe') {
      const projection = { ...publicIdentity(body), scope: body.scope, action, status: body.status,
        generation_prefix: body.generation.slice(0, 12), supervisor: body.supervisor, group: body.group,
        members: body.members, remaining_members: body.remaining_members, listener_open: body.listener_open,
        stop_signal: body.stop_signal, term_attempted: body.term_attempted, native_child: body.native_child,
        native_proc_close: body.native_proc_close, normal_only: body.normal_only, code: body.code,
        public_projection_only: true };
      await this.#publish('http-supervisor-' + action + '.json', projection);
    }
    this.#control.completed = true; return { completed: true, action, actual_native_state_verified: true };
  }
  async stage(stage, network, captures) {
    demand(stages[this.#stageIndex] === stage && !this.#stageAttempted.has(stage) && Array.isArray(network)
      && Array.isArray(captures) && (stage !== 'B4' || this.#stop), 'M4CH_BRIDGE_SEQUENTIAL_ACTUAL_STAGE');
    // A natural failed ingress is retained; callers cannot retry this stage under a different nonce.
    this.#stageAttempted.add(stage);
    const frame = { ...this.#newFrame('MS4_CUSTOMER_H5_STAGE_REQUEST'), stage, network, captures };
    const result = await this.#ingress('stage', frame);
    keys(result, ['schema', 'scope', ...I8, 'stage', 'business_table_count', 'auxiliary_table_count', 'private_stage_sha256_prefix']);
    this.#identity(result);
    demand(result.scope === 'MS4_CUSTOMER_H5_SQL_STAGE_RESULT' && I8.every(key => result[key] === frame[key])
      && result.stage === stage && result.business_table_count === 27 && result.auxiliary_table_count === 7
      && hex(result.private_stage_sha256_prefix, 12), 'M4CH_BRIDGE_ACTUAL_STAGE_NATIVE_SUCCESS');
    this.#stageIndex++; return { stage, actual_stage_verified: true, business_table_count: 27, auxiliary_table_count: 7 };
  }
  async recordUiCapture(payload) {
    keys(payload, ['schema', 'scope', ...I8, 'stage', 'scene', 'viewport', 'actions', 'dom', 'network']);
    this.#identity(payload);
    demand(payload.scope === 'MS4_CUSTOMER_H5_UI_CAPTURE' && stages.includes(payload.stage) && scenes.includes(payload.scene)
      && [320, 390, 1440].includes(payload.viewport?.width) && Number.isInteger(payload.viewport?.height)
      && payload.viewport.height >= 600 && payload.viewport.height <= 1200 && !this.#captures.has(payload.step_nonce),
      'M4CH_BRIDGE_ACTUAL_UI_CAPTURE_IDENTITY');
    await this.#ingress('write', { kind: 'ui-capture', payload });
    this.#captures.set(payload.step_nonce, structuredClone(payload));
    return { ui_capture_written: true, scene: payload.scene, width: payload.viewport.width };
  }
  async saveScreenshot(capture, input) {
    demand(this.#captures.has(capture.step_nonce) && equal(this.#captures.get(capture.step_nonce), structuredClone(capture))
      && !this.#screenshots.has(capture.step_nonce), 'M4CH_BRIDGE_SCREENSHOT_FOLLOWS_ACTUAL_CAPTURE');
    this.#identity(capture);
    // Explicitly normalize the CUA bytes into the current realm before this method is called.
    demand(input instanceof Uint8Array, 'M4CH_BRIDGE_CURRENT_REALM_SCREENSHOT_BYTES');
    const bytes = Buffer.from(input); demand(bytes.length <= 16777216, 'M4CH_BRIDGE_BOUNDED_ORIGINAL_JPEG');
    const pixels = jpegPixels(bytes), parent = path.join(this.#run, 'evidence', 'browser-screenshots');
    try { await fs.mkdir(parent, { mode: 0o700 }); }
    catch (error) { if (error.code !== 'EEXIST') throw new Error('M4CH_BRIDGE_SCREENSHOT_DIRECTORY'); }
    demand(await directory(parent) === parent, 'M4CH_BRIDGE_SCREENSHOT_DIRECTORY_WITHIN_RUN');
    const sequence = String(this.#screenshots.size + 1).padStart(4, '0');
    demand(sequence.length === 4, 'M4CH_BRIDGE_BOUNDED_PUBLIC_SCREENSHOT_SEQUENCE');
    const relative = 'evidence/browser-screenshots/' + sequence + '-' + capture.scene + '.jpg';
    await publish(path.join(this.#run, ...relative.split('/')), bytes);
    const payload = { schema: 1, ...Object.fromEntries(I8.map(key => [key, capture[key]])), scene: capture.scene,
      path: relative, bytes: bytes.length, sha256: sha(bytes), viewport: capture.viewport, pixels, dom: capture.dom };
    await this.#ingress('write', { kind: 'ui-screenshot', payload });
    this.#screenshots.set(capture.step_nonce, payload);
    return { scene: capture.scene, path: relative, bytes: bytes.length, sha256: sha(bytes), pixels };
  }
  async publishVisualReviewAfterRootInspection(capture) {
    demand(this.#screenshots.has(capture.step_nonce) && !this.#reviews.has(capture.step_nonce),
      'M4CH_BRIDGE_ORIGINAL_IMAGE_BEFORE_REVIEW');
    const item = this.#screenshots.get(capture.step_nonce);
    const bytes = await ordinaryFile(path.join(this.#run, ...item.path.split('/')), 16777216);
    demand(bytes.length === item.bytes && sha(bytes) === item.sha256 && equal(jpegPixels(bytes), item.pixels),
      'M4CH_BRIDGE_REVIEW_ORIGINAL_IMAGE_READBACK');
    // Root calls only after view_image actually displayed this saved image. This method cannot infer human inspection.
    await this.#ingress('write', { kind: 'ui-review', payload: { schema: 1, scope: 'MS4_CUSTOMER_H5_UI_VISUAL_REVIEW',
      ...Object.fromEntries(I8.map(key => [key, item[key]])), scene: item.scene, sha256: item.sha256,
      reviewed: true, issues: [], review_method: 'actual_image_inspection' } });
    this.#reviews.add(capture.step_nonce); return { scene: item.scene, actual_image_review_written: true };
  }
  async requestFinish() {
    demand(this.#stageIndex === 5 && this.#stop && this.#actors && !this.#finishAttempted, 'M4CH_BRIDGE_B4_AND_STOP_BEFORE_FINISH');
    for (const scene of scenes) for (const width of [320, 390, 1440])
      demand([...this.#captures].some(([nonce, value]) => value.scene === scene && value.viewport.width === width
        && this.#screenshots.has(nonce) && this.#reviews.has(nonce)), 'M4CH_BRIDGE_ALL_SCENES_WIDTHS_ACTUALLY_REVIEWED');
    const frame = { ...this.#newFrame('MS4_CUSTOMER_H5_BROWSER_FINISH'), action: 'close-owned-tabs' };
    this.#finishAttempted = true;
    await this.#ingress('write', { kind: 'finish', payload: frame }); this.#finish = frame;
    demand(equal(await this.#ingress('finish'), frame), 'M4CH_BRIDGE_ACTUAL_FINISH_GETTER_READBACK');
    return { finish_requested: true };
  }
  async confirmOwnedTabClosed(tabId, viewportReset) {
    demand(this.#finish && !this.#closed && typeof tabId === 'string' && tabId.length > 0 && viewportReset === true,
      'M4CH_BRIDGE_ACTUAL_SINGLE_OWNED_TAB_CLOSED');
    const value = { ...this.#finish, scope: 'MS4_CUSTOMER_H5_BROWSER_CLOSED', closed_owned_tab_ids: [tabId], viewport_reset: true };
    delete value.action; await this.#ingress('write', { kind: 'closed', payload: value }); this.#closed = true;
    return { owned_tab_closed_recorded: true, viewport_reset: true };
  }
  async publishFinalAudit() {
    demand(this.#closed && this.#finish && this.#stageIndex === 5 && this.#stop && this.#actors,
      'M4CH_BRIDGE_REAL_BROWSER_COMPLETION_BEFORE_AUDIT');
    const proof = await this.#ingress('audit');
    keys(proof, ['schema', 'scope', ...I8, 'verified', 'business_table_count', 'auxiliary_table_count',
      'private_snapshot_sha256_prefix', 'full_source_unchanged', 'scope_limit']);
    this.#identity(proof);
    demand(proof.scope === 'MS4_CUSTOMER_H5_SQL_AUDIT' && proof.verified === true && proof.full_source_unchanged === true
      && proof.business_table_count === 27 && proof.auxiliary_table_count === 7
      && proof.scope_limit === 'OBSERVED_27_BUSINESS_PLUS_7_AUXILIARY_TABLES_ONLY'
      && hex(proof.private_snapshot_sha256_prefix, 12) && I8.every(key => proof[key] === this.#finish[key]),
      'M4CH_BRIDGE_ACTUAL_FINAL_SQL_AUDIT');
    const sqlBytes = await this.#copyPrivateFile(controlRoot + 'sql-audit.json', 'sql-audit.json', 32768);
    let sqlHashPrefix;
    try { demand(equal(json(sqlBytes), proof), 'M4CH_BRIDGE_ACTUAL_SQL_FILE_EQUALS_PRIVATE_GETTER');
      sqlHashPrefix = sha(sqlBytes).slice(0, 12); }
    finally { sqlBytes.fill(0); }
    // Hash the original fixed private file, rather than a reserialized getter or a public DTO.
    const value = { ...publicIdentity(proof), scope: 'MS4_CUSTOMER_H5_HTTP_DOM_AUDIT',
      status: 'VERIFIED_NORMAL_CUSTOMER_H5_HTTP_DOM_ONLY', source_binding_sha256:
        sha(await this.#evidence('ms4-customer-h5-serve-source-binding.json')),
      native_created_sha256: this.#binding.native_created_sha256, public_receipt_sha256: this.#binding.public_copy.sha256,
      sql_audit_sha256_prefix: sqlHashPrefix,
      ui_capture_count: this.#captures.size, screenshot_count: this.#screenshots.size, manual_review_count: this.#reviews.size,
      business_table_count: 27, auxiliary_table_count: 7, full_source_unchanged: true,
      browser_owned_tabs_closed: 1, viewport_reset: true, scope_limit: proof.scope_limit,
      stopped_samples: 'NOT RUN', external_payment_shipping_aftersale: 'NOT RUN', statistics_acceptance: 'NOT RUN',
      again_paid_UI: 'NOT RUN', public_projection_only: true };
    await this.#publish('customer-h5-browser-audit.json', value);
    return { status: value.status, ui_capture_count: value.ui_capture_count, screenshot_count: value.screenshot_count,
      manual_review_count: value.manual_review_count };
  }
  async forgetActors() {
    demand(this.#runtime && this.#actors, 'M4CH_BRIDGE_ACTUAL_ACTORS_BEFORE_FORGET');
    this.#actors = null; this.#publicIds = null; this.#uiIntent = null;
    const value = { ...publicIdentity(this.#runtime), scope: 'MS4_CUSTOMER_H5_PRIVATE_FORGOTTEN',
      private_actors_forgotten: true, public_projection_only: true };
    await this.#publish('customer-h5-private-forgotten.json', value);
    return { private_actors_forgotten: true };
  }
}
