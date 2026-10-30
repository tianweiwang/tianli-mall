// Import-only fixed private transport. Actual prepare/source/native0 gates belong to the Root caller.
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { isDeepStrictEqual as equal, TextDecoder } from 'node:util';

const demand = ok => { if (!ok) throw new Error('M4UI_PRIVATE_READ_UNPROVED'); };
const fixedPath = '/run/audit-fixtures/ms4-ui/credentials.json';
const metaEntry = 'help/audit-regressions/isolated/ms4-ui-credential-meta.php';
const backendEntry = 'help/audit-regressions/isolated/ms4-ui-backend.php';
const tarExe = 'C:/Windows/System32/tar.exe';
const phpImage = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const maximumFile = 16384;
const immutable = ['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode',
  'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at', 'user', 'stop_signal'];
const writerKeys = ['path', 'bytes', 'sha256', 'mode', 'uid', 'gid', 'dev', 'ino', 'nlink', 'directory_mode'];
const actorSpec = { customer: { uid: 2147483647 }, merchant: { id: 2147483647, mer_id: 4294967295 }, platform: { id: 2147483647 } };
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const hex = (value, size) => typeof value === 'string' && new RegExp('^[a-f0-9]{' + size + '}$').test(value);
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
function keys(value, wanted) { demand(record(value) && equal(Object.keys(value).sort(), [...wanted].sort())); }
function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (record(value)) return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  return value;
}
function json(bytes) {
  let text = null;
  try { text = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes); return JSON.parse(text); }
  catch { throw new Error('M4UI_PRIVATE_READ_UNPROVED'); }
  finally { text = null; } // Immutable JS strings cannot be claimed erased.
}
function validateExpected(expected) {
  keys(expected, ['run_id', 'source_sha', 'freeze_sha256', 'container_id', 'native_created', 'fixture_volume', 'writer', 'actors']);
  const value = canonical(expected); // Snapshot public caller inputs; these inputs are not producer proof.
  demand(typeof value.run_id === 'string' && /^\d{14}[a-f0-9]{6}$/.test(value.run_id)
    && hex(value.source_sha, 40) && hex(value.freeze_sha256, 64) && hex(value.container_id, 64));
  demand(record(value.native_created) && immutable.every(key => Object.hasOwn(value.native_created, key))
    && value.native_created.id === value.container_id && Array.isArray(value.native_created.mounts)
    && value.native_created.mounts.every(record));
  keys(value.fixture_volume, ['name', 'created_at', 'labels']);
  demand(value.fixture_volume.name === 'tianli-audit-' + value.run_id + '_fixture-secrets'
    && typeof value.fixture_volume.created_at === 'string' && value.fixture_volume.created_at.length > 0
    && (value.fixture_volume.labels === null || record(value.fixture_volume.labels)));
  keys(value.writer, writerKeys);
  demand(value.writer.path === fixedPath && Number.isSafeInteger(value.writer.bytes) && value.writer.bytes > 0
    && value.writer.bytes <= maximumFile && hex(value.writer.sha256, 64) && value.writer.mode === '0600'
    && value.writer.directory_mode === '0700' && value.writer.nlink === 1
    && ['uid', 'gid'].every(key => Number.isSafeInteger(value.writer[key]) && value.writer[key] >= 0)
    && ['dev', 'ino'].every(key => Number.isSafeInteger(value.writer[key]) && value.writer[key] > 0));
  keys(value.actors, Object.keys(actorSpec));
  for (const [kind, bounds] of Object.entries(actorSpec)) {
    keys(value.actors[kind], Object.keys(bounds));
    for (const [id, maximum] of Object.entries(bounds)) demand(Number.isSafeInteger(value.actors[kind][id])
      && value.actors[kind][id] > 0 && value.actors[kind][id] <= maximum);
  }
  return value;
}

// Actual close status is retained in the internal return. No exit-event substitution, timeout or kill.
async function native(executable, args, input, maximum) {
  return await new Promise((resolve, reject) => {
    let child;
    try { child = spawn(executable, args, { windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] }); }
    catch { reject(new Error('M4UI_PRIVATE_READ_UNPROVED')); return; }
    let total = 0, stderrBytes = 0, oversized = false, inputError = false, spawnError = false, outputError = false;
    const chunks = [];
    child.once('error', () => { spawnError = true; });
    child.stdout?.on('error', () => { outputError = true; });
    child.stderr?.on('error', () => { outputError = true; });
    child.stdin?.on('error', () => { inputError = true; });
    child.stdout?.on('data', chunk => {
      total = Math.min(maximum + 1, total + chunk.length);
      if (!oversized && total <= maximum) chunks.push(chunk);
      else { oversized = true; chunk.fill(0); chunks.forEach(part => part.fill(0)); chunks.length = 0; }
    });
    child.stderr?.on('data', chunk => { stderrBytes = Math.min(1, stderrBytes + chunk.length); chunk.fill(0); });
    child.once('close', (code, signal) => {
      const terminal = { code, signal }; let output = null;
      try {
        demand(terminal.code === 0 && terminal.signal === null && !spawnError && !inputError && !outputError
          && !oversized && stderrBytes === 0 && child.stdin && child.stdout && child.stderr);
        output = Buffer.concat(chunks, total); demand(output.length <= maximum);
        const result = { stdout: output, terminal }; output = null; resolve(result);
      } catch { output?.fill(0); reject(new Error('M4UI_PRIVATE_READ_UNPROVED')); }
      finally { chunks.forEach(part => part.fill(0)); chunks.length = 0; input = null; child = null; }
    });
    if (!child.stdin || !child.stdout || !child.stderr) spawnError = true;
    try { child.stdin?.end(input ?? undefined); }
    catch { inputError = true; child.stdin?.destroy(); }
  });
}
async function container(expected) {
  const pairs = Object.fromEntries(immutable.map(key => [key, {
    id: '.Id', image: '.Image', hostname: '.Config.Hostname', labels: '.Config.Labels', tmpfs: '(index .HostConfig "Tmpfs")',
    read_only: '.HostConfig.ReadonlyRootfs', privileged: '.HostConfig.Privileged', network_mode: '.HostConfig.NetworkMode',
    command: '.Config.Cmd', entrypoint: '.Config.Entrypoint', workdir: '.Config.WorkingDir', port_bindings: '.HostConfig.PortBindings',
    cap_drop: '.HostConfig.CapDrop', security_options: '.HostConfig.SecurityOpt', created_at: '.Created', user: '.Config.User',
    stop_signal: '(index .Config "StopSignal")' }[key]]));
  Object.assign(pairs, { mounts: '.Mounts', status: '.State.Status', pid: '.State.Pid', restart_count: '.RestartCount' });
  const format = '{' + Object.entries(pairs).map(([key, expression]) => JSON.stringify(key) + ':{{json ' + expression + '}}').join(',') + '}';
  const response = await native('docker', ['inspect', '--type', 'container', '--format', format, expected.container_id], null, 131072);
  try {
    const current = json(response.stdout), created = expected.native_created;
    keys(current, [...immutable, 'mounts', 'status', 'pid', 'restart_count']);
    demand(immutable.every(key => equal(created[key], current[key])) && Array.isArray(current.mounts) && current.mounts.every(record)
      && equal(created.mounts.map(row => JSON.stringify(canonical(row))).sort(), current.mounts.map(row => JSON.stringify(canonical(row))).sort()));
    demand(current.id === expected.container_id && current.image === phpImage && current.status === 'running'
      && Number.isSafeInteger(current.pid) && current.pid > 0 && current.restart_count === 0
      && current.labels?.['com.docker.compose.service'] === 'audit-ms4-ui-backend'
      && current.labels?.['tianli.audit.run'] === expected.run_id && current.read_only === true && current.privileged === false
      && current.stop_signal === 'SIGTERM' && equal(current.entrypoint, ['php']) && equal(current.command, [backendEntry, 'serve']));
    const mounts = current.mounts.filter(row => typeof row.Destination === 'string'
      && (row.Destination === '/run/audit-fixtures' || row.Destination.startsWith('/run/audit-fixtures/')));
    demand(mounts.length === 1 && mounts[0].Destination === '/run/audit-fixtures' && mounts[0].Type === 'volume'
      && mounts[0].RW === false && mounts[0].Name === expected.fixture_volume.name);
  } finally { response.stdout.fill(0); response.stdout = null; }
}
async function volume(expected) {
  const format = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}';
  const response = await native('docker', ['volume', 'inspect', '--format', format, expected.fixture_volume.name], null, 16384);
  try { const actual = json(response.stdout); keys(actual, ['name', 'created_at', 'labels']); demand(equal(actual, expected.fixture_volume)); }
  finally { response.stdout.fill(0); response.stdout = null; }
}
async function metadata(expected) {
  const response = await native('docker', ['exec', expected.container_id, 'php', metaEntry, 'metadata'], null, 8192);
  try { const actual = json(response.stdout); keys(actual, writerKeys); demand(equal(actual, expected.writer)); }
  finally { response.stdout.fill(0); response.stdout = null; }
}

// A single ordinary USTAR member only. Extended/link/extra headers are not hidden by tar's listing.
function ordinaryTar(archive, writer) {
  demand(Buffer.isBuffer(archive) && archive.length % 512 === 0 && archive.length >= 1536);
  const header = archive.subarray(0, 512);
  const zeros = bytes => bytes.every(byte => byte === 0);
  const octal = bytes => {
    let text = ''; let ended = false;
    for (const byte of bytes) {
      if (byte === 0 || byte === 32) { ended = true; continue; }
      demand(!ended && byte >= 48 && byte <= 55); text += String.fromCharCode(byte);
    }
    demand(text.length > 0); const value = Number.parseInt(text, 8); demand(Number.isSafeInteger(value) && value >= 0); return value;
  };
  const name = Buffer.from('credentials.json', 'ascii');
  demand(header.subarray(0, name.length).equals(name) && zeros(header.subarray(name.length, 100))
    && zeros(header.subarray(157, 257)) && zeros(header.subarray(345, 500))
    && (header[156] === 48 || header[156] === 0));
  demand(header.subarray(257, 263).equals(Buffer.from('ustar\0', 'ascii'))
    || header.subarray(257, 263).equals(Buffer.from('ustar ', 'ascii')));
  let checksum = 0;
  for (let index = 0; index < 512; ++index) checksum += index >= 148 && index < 156 ? 32 : header[index];
  demand(octal(header.subarray(148, 156)) === checksum && octal(header.subarray(124, 136)) === writer.bytes);
  const dataEnd = 512 + Math.ceil(writer.bytes / 512) * 512;
  demand(dataEnd + 1024 <= archive.length && zeros(archive.subarray(512 + writer.bytes, dataEnd)) && zeros(archive.subarray(dataEnd)));
}
function credentials(value, expectedActors) {
  keys(value, Object.keys(actorSpec));
  for (const [kind, ids] of Object.entries(actorSpec)) {
    keys(value[kind], ['account', 'password', ...Object.keys(ids)]);
    for (const [field, maximum] of Object.entries(ids)) demand(Number.isSafeInteger(value[kind][field])
      && value[kind][field] > 0 && value[kind][field] <= maximum && value[kind][field] === expectedActors[kind][field]);
    for (const [field, maximum] of [['account', 191], ['password', 72]]) demand(typeof value[kind][field] === 'string'
      && Buffer.byteLength(value[kind][field], 'utf8') > 0 && Buffer.byteLength(value[kind][field], 'utf8') <= maximum
      && !value[kind][field].includes('\0'));
  }
  return value;
}

/** Caller must first prove genuine paid/prepare/source/owner/full-oracle/native0 bindings separately. */
export async function readUiCredentials(expected) {
  let selected = null, archive = null, listing = null, raw = null, parsed = null, answer = null;
  let transferAttempted = false, failed = false;
  try {
    selected = validateExpected(expected);
    await container(selected); await volume(selected); await metadata(selected);
    try {
      transferAttempted = true;
      const copied = await native('docker', ['cp', selected.container_id + ':' + fixedPath, '-'], null, maximumFile + 262144);
      archive = copied.stdout; copied.stdout = null;
      ordinaryTar(archive, selected.writer);
      const listed = await native(tarExe, ['-tf', '-'], archive, 4096);
      listing = listed.stdout; listed.stdout = null;
      let names = null;
      try { names = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(listing);
        demand(names === 'credentials.json\n' || names === 'credentials.json\r\n'); }
      finally { names = null; }
      const extracted = await native(tarExe, ['-xOf', '-', 'credentials.json'], archive, maximumFile);
      raw = extracted.stdout; extracted.stdout = null;
      demand(raw.length === selected.writer.bytes && sha(raw) === selected.writer.sha256
        && raw.equals(archive.subarray(512, 512 + selected.writer.bytes)));
      parsed = json(raw); answer = credentials(parsed, selected.actors);
    } catch { failed = true; }
    finally {
      // Attempt every after-gate even if transfer validation failed; no failed gate is waived.
      if (transferAttempted) {
        try { await metadata(selected); } catch { failed = true; }
        try { await container(selected); } catch { failed = true; }
        try { await volume(selected); } catch { failed = true; }
      }
    }
    demand(!failed && answer !== null);
    const actualCredentials = answer; answer = null; parsed = null; return actualCredentials;
  } catch { throw new Error('M4UI_PRIVATE_READ_UNPROVED'); }
  finally {
    archive?.fill(0); listing?.fill(0); raw?.fill(0);
    archive = null; listing = null; raw = null; parsed = null; answer = null; selected = null;
    // Returned account/password strings are private caller-owned references, not securely erased JS strings.
  }
}
