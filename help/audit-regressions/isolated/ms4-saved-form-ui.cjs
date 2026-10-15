'use strict';

// Ordinary saved-snapshot component state only. No app, authentication, HTTP or native clipboard.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const modules = process.env.UNI_CHECK_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/uniapp-cli/node_modules';
const sassModule = process.env.UNI_SASS_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/compile-dart-sass/node_modules/sass';
const compiler = require(path.join(modules, 'vue-template-compiler'));
const Vue = require(path.join(modules, 'vue'));
const parser = require(path.join(modules, '@babel/parser'));
const preprocess = require(path.join(modules, '@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess'));
const contexts = require(path.join(modules, '@dcloudio/uni-cli-shared/lib/preprocess'));
const sass = require(sassModule);
const file = path.join(root, 'template/uni-app/pages/goods/order_details/index.vue');
const raw = fs.readFileSync(file, 'utf8');
assert.equal(raw.includes('\r'), false);
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const compiled = [];
let h5Script, h5Ast, h5Export;
for (const platform of ['h5', 'mp-weixin', 'app-plus']) {
  const context = contexts(platform, ['h5', 'app-plus', 'mp-weixin']).vueContext;
  const sfc = compiler.parseComponent(raw);
  const template = preprocess.preprocess(sfc.template.content, context, {type: 'html'});
  const script = preprocess.preprocess(sfc.script.content, context, {type: 'js'});
  const result = compiler.compile(template, {outputSourceRange: true});
  assert.deepEqual(result.errors, []);
  assert.deepEqual(result.tips, []);
  const syntax = cp.spawnSync(process.execPath, ['--check', '--input-type=module'], {input: script, encoding: 'utf8'});
  assert.equal(syntax.status, 0, syntax.stderr);
  const ast = parser.parse(script, {sourceType: 'module'});
  const exported = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration');
  const methods = exported.declaration.properties.find(node => node.key.name === 'methods').value.properties;
  const names = methods.map(node => node.key.name || node.key.value);
  assert.equal(new Set(names).size, names.length);
  let cssBytes = 0;
  for (const style of sfc.styles) {
    cssBytes += sass.renderSync({data: preprocess.preprocess(style.content, context, {type: 'css'})}).css.length;
  }
  compiled.push({platform, templateErrors: result.errors, templateTips: result.tips,
    jsExit: syntax.status, cssBytes, methods: names.length, duplicateMethods: 0});
  if (platform === 'h5') {h5Script = script; h5Ast = ast; h5Export = exported;}
}
const sandbox = {uni: {setClipboardData(value) {sandbox.clipboard = value.data;}}, mapGetters: () => ({})};
const replacements = [];
for (const statement of h5Ast.program.body) {
  if (statement.type !== 'ImportDeclaration') continue;
  for (const specifier of statement.specifiers) sandbox[specifier.local.name] = () => {
    throw new Error('API/import execution outside saved UI source-state scope');
  };
  replacements.push({start: statement.start, end: statement.end, value: ''});
}
sandbox.mapGetters = () => ({});
sandbox.HTTP_REQUEST_URL = '';
replacements.push({start: h5Export.start, end: h5Export.declaration.start, value: 'globalThis.component = '});
let script = h5Script;
replacements.sort((a, b) => b.start - a.start).forEach(row => {script = script.slice(0, row.start) + row.value + script.slice(row.end);});
vm.createContext(sandbox);
new vm.Script(script, {filename: file}).runInContext(sandbox);
const page = new Vue({beforeCreate() {this.$t = value => value;},
  data: sandbox.component.data, computed: sandbox.component.computed, methods: sandbox.component.methods});
let checks = 0;
const ordinary = (actual, expected, label) => {
  assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, label);
  checks++;
};
const field = (field_index, label, value, status = true) => ({field_index, title: '同名资料', label, value, status});
const decimal = '9007199254740993123456789.1234567890123456789';
// IDs/hashes are saved DTO encoding samples, never authentication actors or editable definitions.
const saved = {marketplace_trade_version: 1, custom_form: [
  {product_id: 17, product_name: '已保存商品甲', definition_sha256: 'a'.repeat(64), fields: [
    field(0, 'text', '原订单文字\n第二行'), field(1, 'number', decimal), field(2, 'email', 'form@example.test'),
    field(3, 'data', '2026-10-15'), field(4, 'time', '09:30'), field(5, 'id', '001234567890123456'),
    field(6, 'phone', '13800138000'), field(7, 'img', ['/uploads/saved-one.png', '/uploads/saved-two.png']),
    field(8, 'number', '0', false), field(9, 'text', '', false)]},
  {product_id: 19, product_name: '已保存商品乙', definition_sha256: 'b'.repeat(64), fields: [
    field(0, 'text', '', false), field(1, 'text', '另一个同名答案'), field(2, 'img', [], false)]}
]};
const original = JSON.stringify(saved);
page.orderInfo = saved;
page.applySavedCustomForm(saved);
ordinary(page.savedMarketplaceFormsV1, true, 'Saved integer version selects the grouped read-only branch');
ordinary(page.customForm, [], 'Saved V1 never flows into flat legacy fields');
ordinary(page.savedMarketplaceFormGroups.map(group => group.product_name), ['已保存商品甲', '已保存商品乙'], 'Product names remain from the saved snapshot');
ordinary(page.savedMarketplaceFormGroups[0].fields.map(row => row.label),
  ['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img', 'number'], 'All eight saved field labels retain their display values');
ordinary(page.savedMarketplaceFormGroups[0].fields[1].value, decimal, 'Saved decimal answers never pass through JS Number');
ordinary(page.savedMarketplaceFormGroups[0].fields[5].value, '001234567890123456', 'Identifier-like answers retain leading zeroes');
ordinary(page.savedMarketplaceFormGroups[0].fields[8].value, '0', 'Optional zero text is displayed rather than removed by loose equality');
ordinary(page.savedMarketplaceFormGroups[1].fields.map(row => row.field_index), [1], 'Filtering empty optional answers retains original field indexes');
ordinary(page.savedMarketplaceFormGroups[0].fields.map(row => row.title), Array(9).fill('同名资料'), 'Duplicate titles remain independent saved fields');
ordinary(page.savedMarketplaceFormGroups.map(group => group.definition_sha256), ['a'.repeat(64), 'b'.repeat(64)], 'Original saved definition hashes are not recomputed from answers');
ordinary(Object.prototype.hasOwnProperty.call(page.savedMarketplaceFormGroups[0], 'cart_refs'), false, 'Public saved groups need no internal cart references');
ordinary(page.savedMarketplaceFormGroups[0].fields[7].value, ['/uploads/saved-one.png', '/uploads/saved-two.png'], 'Saved image arrays preserve URL order');
ordinary(page.savedMarketplaceFormGroups[0].fields[7].value === saved.custom_form[0].fields[7].value, false, 'Read-only UI state does not alias saved image arrays');
const expected = '已保存商品甲\n1. 同名资料：原订单文字\n第二行\n2. 同名资料：' + decimal +
  '\n3. 同名资料：form@example.test\n4. 同名资料：2026-10-15\n5. 同名资料：09:30\n6. 同名资料：001234567890123456' +
  '\n7. 同名资料：13800138000\n8. 同名资料：/uploads/saved-one.png\n/uploads/saved-two.png\n9. 同名资料：0\n\n已保存商品乙\n2. 同名资料：另一个同名答案';
ordinary(page.savedMarketplaceFormText(), expected, 'Copied content has product sections, original positions, exact answers and images');
page.copyText();
ordinary(sandbox.clipboard, expected, 'The actual copy action uses grouped saved text');
ordinary(sandbox.clipboard.includes('undefined'), false, 'Grouped copy never concatenates an absent flat title/value');
ordinary(JSON.stringify(saved), original, 'Projection/display/copy leave saved DTO bytes unchanged');
page.orderInfo = {...saved, marketplace_trade_version: '1'};
page.applySavedCustomForm(page.orderInfo);
ordinary(page.savedMarketplaceFormsV1, true, 'The saved string version remains compatible');
ordinary(page.savedMarketplaceFormText(), expected, 'Integer/string version serialization produces the same saved content');
const flat = [{title: '历史标题', label: 'text', value: '历史内容'},
  {title: '空项', label: 'text', value: ''}, {title: '历史图片', label: 'img', value: ['/uploads/legacy.png']}];
for (const version of [undefined, null, 0, '0']) {
  page.orderInfo = {marketplace_trade_version: version, custom_form: flat};
  page.applySavedCustomForm(page.orderInfo);
  ordinary(page.savedMarketplaceFormsV1, false, 'Legacy saved version retains flat rendering');
  ordinary(page.savedMarketplaceFormGroups, [], 'Switching to a legacy order clears prior grouped state');
  ordinary(page.customForm.map(row => row.title), ['历史标题', '历史图片'], 'Historical nonempty display rule stays intact');
  page.copyText();
  ordinary(sandbox.clipboard, '历史标题历史内容', 'Historical flat copy behavior stays intact');
}
page.copyText('原单独备注');
ordinary(sandbox.clipboard, '原单独备注', 'Explicit remark copy remains intact');
page.orderInfo = {marketplace_trade_version: 1, custom_form: []};
page.applySavedCustomForm(page.orderInfo);
ordinary(page.savedMarketplaceFormGroups, [], 'Saved V1 orders without forms have no empty product groups');
const result = {schema: 1, scope: 'ORDINARY_SAVED_UI_SOURCE_STATE_ONLY', node: process.version,
  file: {path: path.relative(root, file), bytes: Buffer.byteLength(raw), sha256: sha(raw)},
  vueCompiler: require(path.join(modules, 'vue-template-compiler/package.json')).version,
  uniCompiler: require(path.join(modules, '@dcloudio/uni-cli-shared/package.json')).version,
  checks: compiled, ordinaryAssertions: checks, actualVueStateMethods: true,
  savedSnapshotHashRecomputed: false, currentProductQueried: false, editableFormHelperUsed: false,
  DOMCreated: false, applicationStarted: false, sqlExecuted: false, authenticationActorsCreated: false,
  HTTPExecuted: false, nativeClipboardExecuted: false, browserVisualAcceptance: false,
  actualDetailRefundAPIVerified: false, platformAdminConsumersVerified: false,
  negativeConflictConcurrencyFaultRecoverySamplesRun: false};
if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(result, null, 2) + '\n', {flag: 'wx'});
console.log(JSON.stringify(result));
