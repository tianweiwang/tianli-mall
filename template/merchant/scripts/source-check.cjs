'use strict';
// Source-only checks. No HTTP, credentials, storage writes, application mount or business fixtures.
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const files = [];
function walk(directory) { for (const entry of fs.readdirSync(directory, { withFileTypes: true })) { const name = path.join(directory, entry.name); if (entry.isDirectory()) walk(name); else files.push(name); } }
walk(path.join(root, 'src'));
const errors = []; let parsed = 0, imports = 0;
for (const file of files.filter((file) => /\.(js|vue)$/.test(file))) {
  const source = fs.readFileSync(file, 'utf8'); const match = file.endsWith('.vue') ? source.match(/<script>([\s\S]*?)<\/script>/) : null;
  const script = file.endsWith('.vue') ? (match ? match[1] : '') : source;
  if (script) { const result = spawnSync(process.execPath, ['--input-type=module', '--check'], { input: script, encoding: 'utf8' }); if (result.status !== 0) errors.push(path.relative(root, file) + ': ' + result.stderr.trim()); else parsed += 1; }
  const localImports = [...script.matchAll(/(?:\bfrom\s*|\bimport\s*(?:\(\s*)?)['"](\.[^'"\r\n]+)['"]/g)];
  for (const match of localImports) { const base = path.resolve(path.dirname(file), match[1]); const candidates = [base, base + '.js', base + '.vue', path.join(base, 'index.js')]; if (!candidates.some((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isFile())) errors.push('Missing local import: ' + path.relative(root, file) + ' -> ' + match[1]); imports += 1; }
  const executable = script.replace(/^\s*\/\/.*$/gm, '');
  if (/\/adminapi|Authori-zation|localStorage\.|sessionStorage\.clear\(|v-auth|document\.cookie|console\.(log|debug)/.test(executable)) errors.push('Unexpected platform client/storage/logging dependency: ' + path.relative(root, file));
  if (source.includes('\r')) errors.push('Non-LF text: ' + path.relative(root, file));
}
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const expected = { vue: '2.7.16', 'vue-router': '3.6.5', vuex: '3.6.2', axios: '0.18.1', 'element-ui': '2.15.6', 'core-js': '3.43.0' };
for (const [key, version] of Object.entries(expected)) if (pkg.dependencies[key] !== version) errors.push('Unexpected selected dependency: ' + key);
if (pkg.devDependencies['vue-template-compiler'] !== pkg.dependencies.vue) errors.push('Vue compiler mismatch');
const request = fs.readFileSync(path.join(root, 'src/api/request.js'), 'utf8');
if (!request.includes("apiBase !== '/merchantapi'") || !request.includes("headers.Authorization = 'Bearer ' + snapshot.token")) errors.push('Merchant request boundary missing');
const session = fs.readFileSync(path.join(root, 'src/api/session.js'), 'utf8');
if (!session.includes("TOKEN_KEY = 'marketplace_merchant_token'")) errors.push('Independent token key missing');
const config = fs.readFileSync(path.join(root, 'vue.config.js'), 'utf8');
if (!config.includes("publicPath: '/merchant/'")) errors.push('Merchant static prefix missing');
if (errors.length) { process.stderr.write(errors.join('\n') + '\n'); process.exitCode = 1; }
else process.stdout.write(JSON.stringify({ source_checks: 'PASS', syntax_scripts: parsed, local_imports: imports, build_or_browser_or_HTTP_run: false, lock_present: fs.existsSync(path.join(root, 'package-lock.json')) }) + '\n');
