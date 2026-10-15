'use strict';

// Ordinary source/component state checks. No DOM, application, authentication or HTTP.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const modules = process.env.UNI_CHECK_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/uniapp-cli/node_modules';
const sassModules = process.env.UNI_SASS_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/compile-dart-sass/node_modules/sass';
const compiler = require(path.join(modules, 'vue-template-compiler'));
const Vue = require(path.join(modules, 'vue'));
const parser = require(path.join(modules, '@babel/parser'));
const preprocess = require(path.join(modules, '@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess'));
const contexts = require(path.join(modules, '@dcloudio/uni-cli-shared/lib/preprocess'));
const sass = require(sassModules);
const vuePath = path.join(root, 'template/uni-app/pages/goods/order_confirm/index.vue');
const helperPath = path.join(root, 'template/uni-app/utils/marketplace-order-form.js');
const raw = fs.readFileSync(vuePath, 'utf8');
const helperSource = fs.readFileSync(helperPath, 'utf8');
const sha = text => crypto.createHash('sha256').update(text).digest('hex');
let checks = 0;
const ordinary = (actual, expected, label) => {
  assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, label);
  checks++;
};

async function main() {
  assert.equal(raw.includes('\r'), false);
  assert.equal(helperSource.includes('\r'), false);
  const helperSyntax = cp.spawnSync(process.execPath, ['--check', '--input-type=module'], {input: helperSource, encoding: 'utf8'});
  assert.equal(helperSyntax.status, 0, helperSyntax.stderr);
  const form = await import('data:text/javascript;base64,' + Buffer.from(helperSource).toString('base64'));
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
      const value = preprocess.preprocess(style.content, context, {type: 'css'});
      cssBytes += sass.renderSync({data: value}).css.length;
    }
    compiled.push({platform, templateErrors: result.errors, templateTips: result.tips,
      jsExit: syntax.status, cssBytes, methods: names.length, duplicateMethods: 0});
    if (platform === 'h5') {h5Script = script; h5Ast = ast; h5Export = exported;}
  }
  // Numeric identifiers below are DTO encoding samples, never authentication actors.
  const hash = 'a'.repeat(64), otherHash = 'b'.repeat(64);
  const field = (field_index, label, value, status = true) => ({field_index, title: '同名资料', label, value, status});
  const definitions = [
    {product_id: 17, product_name: '商品甲', cart_refs: ['9007199254740993', '9007199254740995'], definition_sha256: hash,
      fields: [field(0, 'text', '默认'), field(1, 'number', ''), field(2, 'email', ''), field(3, 'data', ''),
        field(4, 'time', ''), field(5, 'id', ''), field(6, 'phone', ''), field(7, 'img', []), field(8, 'text', '', false)]},
    {product_id: 19, product_name: '商品乙', cart_refs: ['9007199254740997'], definition_sha256: otherHash,
      fields: [field(0, 'text', '默认乙')]}
  ];
  const original = JSON.stringify(definitions);
  const groups = form.initializeMarketplaceForms(definitions);
  ordinary(groups.length, 2, 'Same product with two SKU/cart references remains one group');
  ordinary(groups[0].cart_refs, definitions[0].cart_refs, 'BIGINT references retain their bytes');
  ordinary(groups[0].fields.map(row => row.field_index), [0, 1, 2, 3, 4, 5, 6, 7, 8], 'Repeated titles retain every definition index');
  ordinary(form.initializeMarketplaceForms([]), [], 'Products without fields have no empty UI group');
  const largeDecimal = '9007199254740993123456789.1234567890123456789';
  const values = ['甲的独立答案', largeDecimal, 'form@example.test', '2026-10-15', '09:30', '110101199001011234', '13800138000',
    ['/uploads/form-one.png'], ''];
  groups[0].fields.forEach((row, index) => {row.value = Array.isArray(values[index]) ? values[index].slice() : values[index];});
  groups[1].fields[0].value = '乙的独立答案';
  ordinary(form.firstMissingMarketplaceAnswer(groups), null, 'All eight established field types accept normal answers');
  ordinary(JSON.stringify(definitions), original, 'Editing does not mutate the server definition DTO');
  const retained = form.retainMarketplaceForms(definitions, groups);
  ordinary(retained[0].fields.map(row => row.value), values, 'Repricing retains every answer instead of default values');
  ordinary(retained[1].fields[0].value, '乙的独立答案', 'Same field title/index across products remains independent');
  retained[0].fields[7].value.push('/uploads/form-two.png');
  ordinary(groups[0].fields[7].value, ['/uploads/form-one.png'], 'Retained image answers do not alias the old state');
  const answers = form.marketplaceFormAnswers(retained);
  ordinary(Object.keys(answers[0]), ['product_id', 'definition_sha256', 'values'], 'Create sends only the grouped answer contract');
  ordinary(Object.keys(answers[0].values[0]), ['field_index', 'value'], 'Create excludes client labels and required flags');
  ordinary(answers[0].values[1].value, largeDecimal, 'Decimal answers never pass through JS Number');
  ordinary(answers[0].values[7].value, ['/uploads/form-one.png', '/uploads/form-two.png'], 'Image arrays retain list order');

  // Run the actual component state methods with actual Vue reactivity; transport is unavailable.
  const sandbox = {uni: {showToast() {throw new Error('No refusal sample is run');}}, mapGetters: () => ({})};
  const replacements = [];
  for (const statement of h5Ast.program.body) {
    if (statement.type !== 'ImportDeclaration') continue;
    for (const specifier of statement.specifiers) {
      sandbox[specifier.local.name] = statement.source.value === '@/utils/marketplace-order-form.js' ? form[specifier.imported.name] :
        () => {throw new Error('API/import execution outside source-state scope');};
    }
    replacements.push({start: statement.start, end: statement.end, value: ''});
  }
  sandbox.mapGetters = () => ({});
  replacements.push({start: h5Export.start, end: h5Export.declaration.start, value: 'globalThis.component = '});
  let script = h5Script;
  replacements.sort((a, b) => b.start - a.start).forEach(row => {script = script.slice(0, row.start) + row.value + script.slice(row.end);});
  vm.createContext(sandbox);
  new vm.Script(script, {filename: vuePath}).runInContext(sandbox);
  let uploadComplete, uploadEnd;
  const page = new Vue({
    beforeCreate() {
      this.$t = value => value;
      this.$util = {uploadImageChange(route, complete, end) {assert.equal(route, 'upload/image'); uploadComplete = complete; uploadEnd = end;}};
    },
    data: sandbox.component.data, computed: sandbox.component.computed, methods: sandbox.component.methods
  });
  page.marketplaceConfirmation = {marketplace_confirmation_version: 1, marketplace_create_enabled: false};
  page.applyMarketplaceForms(definitions);
  ordinary(page.marketplaceFormsReady, true, 'Valid confirmation initializes editable grouped forms');
  ordinary(page.marketplaceCanCreate, false, 'The existing closed create gate remains closed');
  page.setMarketplaceFormValue({detail: {value: '甲改值'}}, 17, hash, 0);
  page.setMarketplaceFormValue({detail: {value: '甲同名字段改值'}}, 17, hash, 8);
  page.setMarketplaceFormValue({detail: {value: '乙改值'}}, 19, otherHash, 0);
  page.setMarketplaceFormValue({detail: {value: largeDecimal}}, 17, hash, 1);
  page.setMarketplaceFormValue({detail: {value: '2026-10-16'}}, 17, hash, 3);
  page.setMarketplaceFormValue({detail: {value: '10:45'}}, 17, hash, 4);
  ordinary(page.marketplaceFormGroups[0].fields[0].value, '甲改值', 'Input callbacks use the product/hash/index identity');
  ordinary(page.marketplaceFormGroups[0].fields[8].value, '甲同名字段改值', 'Repeated titles within one product remain independent');
  ordinary(page.marketplaceFormGroups[1].fields[0].value, '乙改值', 'The second product is edited independently');
  ordinary(page.marketplaceFormGroups[0].fields[1].value, largeDecimal, 'The actual component preserves numeric text');
  ordinary(page.marketplaceFormGroups[0].fields.slice(3, 5).map(row => row.value), ['2026-10-16', '10:45'], 'Picker callbacks locate their stable field identities');
  page.uploadMarketplaceFormImage(17, hash, 7);
  page.applyMarketplaceForms(definitions, true);
  uploadComplete({data: {url: '/uploads/ordinary-form.png'}});
  uploadEnd();
  ordinary(page.marketplaceFormGroups[0].fields[7].value, ['/uploads/ordinary-form.png'], 'A normal delayed upload resolves the current retained field');
  ordinary(page.canvasStatus, false, 'Normal upload completion clears the existing canvas state');
  const beforeInvoice = JSON.stringify(form.marketplaceFormAnswers(page.marketplaceFormGroups));
  page.invList = [{id: '23', header_type: 1, type: 1}];
  page.invChange('23');
  page.invCancel();
  ordinary(JSON.stringify(form.marketplaceFormAnswers(page.marketplaceFormGroups)), beforeInvoice, 'Actual invoice selection/cancel preserves current answers');
  page.applyMarketplaceForms(definitions, true);
  ordinary(page.marketplaceFormGroups[0].fields[0].value, '甲改值', 'An address/postage repricing definition refresh preserves edited text');
  page.deleteMarketplaceFormImage(17, hash, 7, 0);
  ordinary(page.marketplaceFormGroups[0].fields[7].value, [], 'Image removal targets the selected product field');
  ordinary(definitions[0].cart_refs.length, 2, 'UI state leaves the original selection references unchanged');
  page.confirm = [{label: 'data', value: ''}, {label: 'time', value: ''}, {label: 'img', value: ['/uploads/old.png']}];
  page.bindDateChange({target: {value: '2026-10-15'}}, 0);
  page.bindTimeChange({target: {value: '09:00'}}, 1);
  page.DelPic(2, 0);
  ordinary(page.confirm.map(row => row.value), ['2026-10-15', '09:00', []], 'Legacy flat callback behavior remains intact');
  const result = {schema: 1, scope: 'ORDINARY_UI_SOURCE_STATE_ONLY', node: process.version,
    files: [{path: path.relative(root, vuePath), bytes: Buffer.byteLength(raw), sha256: sha(raw)},
      {path: path.relative(root, helperPath), bytes: Buffer.byteLength(helperSource), sha256: sha(helperSource)}],
    vueCompiler: require(path.join(modules, 'vue-template-compiler/package.json')).version,
    uniCompiler: require(path.join(modules, '@dcloudio/uni-cli-shared/package.json')).version,
    helperJsExit: helperSyntax.status, checks: compiled, ordinaryAssertions: checks, actualVueStateMethods: true,
    DOMCreated: false, applicationStarted: false, sqlExecuted: false, authenticationActorsCreated: false,
    HTTPExecuted: false, browserVisualAcceptance: false, realAddressInvoiceRoundTripVerified: false,
    actualOrderPersistenceVerified: false, negativeConflictConcurrencyFaultRecoverySamplesRun: false};
  if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(result, null, 2) + '\n', {flag: 'wx'});
  console.log(JSON.stringify(result));
}

main().catch(error => {console.error(error.message); process.exitCode = 1;});
