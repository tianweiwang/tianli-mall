'use strict';

// Ordinary wire/component state samples only. No authentication actors, application or HTTP.
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const vm = require('node:vm'), crypto = require('node:crypto'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const modules = process.env.UNI_CHECK_MODULES || 'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/uniapp-cli/node_modules';
const compiler = require(modules + '/vue-template-compiler'), Vue = require(modules + '/vue');
const parser = require(modules + '/@babel/parser');
const preprocess = require(modules + '/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess');
const contexts = require(modules + '/@dcloudio/uni-cli-shared/lib/preprocess');
const sass = require(process.env.UNI_SASS_MODULES || 'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/compile-dart-sass/node_modules/sass');
const quickCartFiles = ['pages/goods_cate/goods_cate2.vue', 'pages/goods_cate/goods_cate3.vue'];
const vueFiles = ['pages/goods/order_confirm/index.vue', 'pages/goods/order_details/index.vue', ...quickCartFiles];
const jsFiles = ['utils/marketplace-order-submit.js', 'api/order.js', 'utils/util.js'];
const fingerprint = (file, bytes) => ({path: file, bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex')});
let checks = 0;
const equal = (actual, expected, label) => {assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, label); checks++;};
const inspectSyntax = script => {
  const run = cp.spawnSync(process.execPath, ['--check', '--input-type=module'], {input: script, encoding: 'utf8'});
  assert.equal(run.status, 0, run.stderr);
};
const compiled = [], fingerprints = [];
const components = {};
for (const relative of vueFiles) {
  const file = 'template/uni-app/' + relative, raw = fs.readFileSync(path.join(root, file), 'utf8');
  assert.equal(raw.includes('\r'), false); fingerprints.push(fingerprint(file, Buffer.from(raw)));
  for (const platform of ['h5', 'mp-weixin', 'app-plus']) {
    const context = contexts(platform, ['h5', 'app-plus', 'mp-weixin']).vueContext;
    const sfc = compiler.parseComponent(raw);
    const template = preprocess.preprocess(sfc.template.content, context, {type: 'html'});
    const script = preprocess.preprocess(sfc.script.content, context, {type: 'js'});
    const result = compiler.compile(template, {outputSourceRange: true});
    assert.deepEqual(result.errors, []); assert.deepEqual(result.tips, []); inspectSyntax(script);
    const ast = parser.parse(script, {sourceType: 'module'});
    const exported = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration');
    if (relative === 'pages/goods/order_details/index.vue') {
      const onShow = exported.declaration.properties.find(node => node.key.name === 'onShow');
      const shown = script.slice(onShow.start, onShow.end);
      assert.equal(shown.includes('wx.getEnterOptionsSync()'), platform === 'mp-weixin');
      for (const method of ['getOrderInfo', 'getUserInfo', 'getCustomerType'])
        assert.equal(shown.includes('this.' + method + '();'), true);
    }
    const methods = exported.declaration.properties.find(node => node.key.name === 'methods').value.properties;
    const names = methods.map(node => node.key.name || node.key.value);
    assert.equal(new Set(names).size, names.length);
    let cssBytes = 0;
    for (const style of sfc.styles) cssBytes += sass.renderSync({data: preprocess.preprocess(style.content, context, {type: 'css'}),
      includePaths: [path.dirname(path.join(root, file)), path.join(root, 'template/uni-app')],
      importer(url) {return url.startsWith('@/') ? {file: path.join(root, 'template/uni-app', url.slice(2))} : null;}}).css.length;
    compiled.push({file, platform, templateErrors: result.errors, templateTips: result.tips, jsExit: 0, cssBytes, methods: names.length});
    if (platform === 'h5') components[relative] = {script, ast, exported};
  }
}
for (const relative of jsFiles) {
  const file = 'template/uni-app/' + relative, raw = fs.readFileSync(path.join(root, file), 'utf8');
  assert.equal(raw.includes('\r'), false); fingerprints.push(fingerprint(file, Buffer.from(raw)));
  for (const platform of ['h5', 'mp-weixin', 'app-plus']) {
    inspectSyntax(preprocess.preprocess(raw, contexts(platform, ['h5', 'app-plus', 'mp-weixin']).vueContext, {type: 'js'}));
  }
}

async function main() {
  const load = async relative => import('data:text/javascript;base64,' + Buffer.from(fs.readFileSync(path.join(root, 'template/uni-app', relative))).toString('base64'));
  const submit = await load('utils/marketplace-order-submit.js'), forms = await load('utils/marketplace-order-form.js');
  // These are codec DTO examples, never runtime confirmations, order records or identity actors.
  const dto = {marketplace_confirmation_version: 1, marketplace_create_enabled: true, orderKey: '9007199254740993',
    mer_id: 7, source_kind: 'persistent', store: {mer_id: 7, name: '通用店铺'},
    cartInfo: [{id: '9007199254740995'}, {id: '9007199254740997'}]};
  const definitions = [{product_id: 17, product_name: '通用商品', cart_refs: dto.cartInfo.map(row => row.id),
    definition_sha256: 'a'.repeat(64), fields: [{field_index: 0, title: '资料', label: 'number', status: true,
      value: '900719925474099312345.123456789'}, {field_index: 1, title: '图片', label: 'img', status: false, value: []}]}];
  const binding = submit.bindMarketplaceSubmission(dto);
  const fields = {addressId: '31', invoice_id: '41', payType: '', mark: '原备注', gift_mark: '', real_name: '', phone: ''};
  const answers = forms.marketplaceFormAnswers(forms.initializeMarketplaceForms(definitions));
  const attempt = submit.snapshotMarketplaceSubmission(binding, fields, answers, 0);
  equal(attempt.body.cartId, '9007199254740995,9007199254740997', 'All confirmation references retain their bytes');
  equal(attempt.body.idem_key, 'confirm:9007199254740993', 'Idem derives from the original key without Number conversion');
  equal([attempt.body.mer_id, attempt.body.new], [7, 0], 'Merchant and source come from the confirmed DTO');
  equal(['uid', 'source_kind', 'orderKey'].some(key => Object.hasOwn(attempt.body, key)), false, 'No unregistered identity/metadata body fields');
  equal(Object.keys(attempt.body.custom_form[0]), ['product_id', 'definition_sha256', 'values'], 'Grouped answers exclude client labels');
  equal(attempt.body.custom_form[0].values[0].value, '900719925474099312345.123456789', 'Numeric answers retain exact text');
  equal(Object.isFrozen(attempt.body) && Object.isFrozen(attempt.body.custom_form[0].values), true, 'Snapshot is deeply immutable');
  equal(submit.snapshotMarketplaceSubmission(binding, fields, answers, 0).body, attempt.body, 'Ordinary repeated payload is byte-equivalent');
  const temporary = submit.bindMarketplaceSubmission({...dto, source_kind: 'temporary', cartInfo: [{id: 'opaque/原引用'}]});
  equal(submit.snapshotMarketplaceSubmission(temporary, fields, answers, 0).body.new, 1, 'Temporary source is encoded by new only');
  let navigation, posts = [], computedCreated = null, computedRequest;
  const sandbox = {uni: {reLaunch(value) {navigation = value.url;}, switchTab(value) {navigation = value.url;}, hideLoading() {},
    getWindowInfo() {return {windowHeight: 800, statusBarHeight: 0, windowTop: 0};},
    getSystemInfo() {return {titleBarHeight: 0};}}, mapGetters: () => ({})};
  const component = relative => {
    const {script: raw, ast, exported} = components[relative]; let script = raw;
    const edits = [];
    for (const node of ast.program.body) if (node.type === 'ImportDeclaration') {
      for (const spec of node.specifiers) sandbox[spec.local.name] = node.source.value === '@/utils/marketplace-order-submit.js' ? submit[spec.imported.name] :
        node.source.value === '@/utils/marketplace-order-form.js' ? forms[spec.imported.name] : () => {throw new Error('No application API is called');};
      edits.push({start: node.start, end: node.end, value: ''});
    }
    sandbox.mapGetters = () => ({});
    sandbox.orderCreate = (key, body) => {posts.push({key, body}); return Promise.resolve({status: 200, data: {status: 'SUCCESS', result: {key, orderId: 'ordinary-codec-order'}}});};
    sandbox.orderConfirmation = key => Promise.resolve({data: {state: 'created', key, orderId: 'ordinary-codec-order'}});
    sandbox.postOrderComputed = (key, body) => {
      computedRequest = {key, body};
      return Promise.resolve({data: {result: computedCreated || {...dto, pay_price: '20.00', pay_postage: '0.00',
        storePostageDiscount: '0.00', deduction_price: '0.00', coupon_price: '0.00'}}});
    };
    sandbox.getAddressDetail = id => Promise.resolve({data: {id, is_default: '0', real_name: '普通地址展示样例'}});
    edits.push({start: exported.start, end: exported.declaration.start, value: 'globalThis.component = '});
    edits.sort((a, b) => b.start - a.start).forEach(row => {script = script.slice(0, row.start) + row.value + script.slice(row.end);});
    new vm.Script(script).runInContext(sandbox); return sandbox.component;
  };
  vm.createContext(sandbox);
  const source = component(vueFiles[0]);
  let uploadSuccess, uploadComplete;
  const page = () => new Vue({beforeCreate() {this.$t = value => value; this.$util = {
    Tips() {throw new Error('No refusal sample is run');}, uploadImageChange(url, success, error, size, complete) {uploadSuccess = success; uploadComplete = complete;}};},
    data: source.data, computed: source.computed, methods: source.methods});
  const editor = page();
  editor.marketplaceConfirmation = {...dto}; editor.marketplaceSubmitBinding = binding; editor.orderKey = dto.orderKey;
  editor.applyMarketplaceForms(definitions); editor.marketplaceQuoteReady = true; editor.addressId = '31'; editor.invChecked = '41';
  equal(editor.marketplaceCanSubmit, true, 'Ready ordinary wire state can freeze a submission');
  await editor.OnChangeAddress('32');
  equal([editor.addressId, editor.addressInfo.id, editor.marketplaceQuoteReady, editor.marketplacePendingEdits], ['32', '32', true, 0],
    'Ordinary address selection awaits its actual component quote chain');
  editor.uploadMarketplaceFormImage(17, 'a'.repeat(64), 1);
  equal([editor.marketplaceUploadPending, editor.marketplaceCanSubmit], [true, false], 'Submit waits for ordinary upload completion');
  uploadSuccess({data: {url: '/uploads/ordinary.png'}}); uploadComplete();
  equal([editor.marketplaceUploadPending, editor.marketplaceCanSubmit], [false, true], 'Normal completed upload releases pending state');
  equal(editor.marketplaceFormGroups[0].fields[1].value, ['/uploads/ordinary.png'], 'Completed upload retains its grouped answer');
  await editor.submitMarketplaceOrder();
  equal(editor.marketplaceSubmitState, 'done', 'A valid ordinary creation response completes the component state');
  equal(posts.length, 1, 'First ordinary submission sends one frozen request');
  equal(posts[0].body.addressId, '32', 'Submission uses the selected address after quote completion');
  equal(posts[0].body.custom_form[0].values[1].value, ['/uploads/ordinary.png'], 'Submission uses completed image answers');
  equal(navigation, '/pages/goods/order_details/index?order_id=ordinary-codec-order', 'Valid ordinary V1 completion opens the supplied business order');
  await editor.submitMarketplaceOrder();
  equal(posts.length, 1, 'Completed state prevents another ordinary click from creating a new request');
  equal(await editor.queryMarketplaceOrder(), {state: 'created', key: dto.orderKey, orderId: 'ordinary-codec-order'}, 'Valid lookup result retains the actual supplied key');
  equal(editor.marketplaceLookupPending, false, 'Ordinary lookup completion clears pending state');
  // This is one ordinary same-binding DTO response, not a saved order or runtime HTTP sample.
  const saved = page();
  saved.marketplaceConfirmation = {...dto}; saved.marketplaceSubmitBinding = binding; saved.orderKey = binding.key;
  const savedMetadata = saved.marketplaceConfirmation;
  saved.addressId = '32'; saved.totalPrice = '20.00';
  computedCreated = {state: 'created', key: binding.key, orderId: 'ordinary-codec-saved-order'};
  await saved.computedPrice();
  equal([computedRequest.key, computedRequest.body.mer_id], [binding.key, binding.merId], 'Ordinary computed request retains the original binding');
  equal(saved.marketplaceSubmitBinding === binding, true, 'Created DTO consumption preserves the original binding object');
  equal(saved.marketplaceConfirmation === savedMetadata, true, 'Created DTO is not reapplied as confirmation metadata');
  equal([saved.marketplaceSubmitState, saved.marketplaceQuoteReady, saved.marketplaceComputing], ['done', false, false],
    'Valid created DTO completes through the real result parser without becoming a quote');
  equal(navigation, '/pages/goods/order_details/index?order_id=ordinary-codec-saved-order', 'Valid computed completion opens the supplied business order');
  equal(saved.totalPrice, '20.00', 'Created DTO does not replace price fields with absent quote amounts');
  equal(posts.length, 1, 'Reading a valid computed-created DTO does not submit another order');
  computedCreated = null;
  for (const file of quickCartFiles) {
    const quick = component(file);
    quick.methods.subOrder.call({cartData: {cartList: [{id: '9007199254740995'}], iScart: true}});
    equal(navigation, '/pages/order_addcart/order_addcart', 'Shortcut uses the real grouped cart tab');
  }
  // Exercise the real API wrappers with a local transport observer, not an HTTP client.
  const api = fs.readFileSync(path.join(root, 'template/uni-app/api/order.js'), 'utf8');
  const apiAst = parser.parse(api, {sourceType: 'module'});
  const wrappers = apiAst.program.body.filter(node => node.type === 'ExportNamedDeclaration' && node.declaration &&
    ['orderCreate', 'orderConfirmation'].includes(node.declaration.id.name)).map(node => api.slice(node.declaration.start, node.declaration.end)).join('\n');
  const requests = [];
  const apiSandbox = {request: {post(route, body) {requests.push({route, body}); return Promise.resolve({status: 200});}}};
  vm.createContext(apiSandbox); new vm.Script(wrappers).runInContext(apiSandbox);
  await apiSandbox.orderCreate(binding.key, attempt.body); await apiSandbox.orderCreate(binding.key, attempt.body);
  equal(requests[0], requests[1], 'Ordinary repeat wrapper calls keep the same route and exact payload');
  await apiSandbox.orderConfirmation(binding.key);
  equal(requests[2], {route: 'order/confirmation/' + binding.key, body: {}}, 'Lookup sends only the route key and an empty body');
  // Run the existing upload method's ordinary direct/compressed completion with local native observers.
  // No image, credentials, application, transport or native canvas is opened by this harness.
  const util = preprocess.preprocess(fs.readFileSync(path.join(root, 'template/uni-app/utils/util.js'), 'utf8'),
    contexts('h5', ['h5', 'app-plus', 'mp-weixin']).vueContext, {type: 'js'});
  const utilAst = parser.parse(util, {sourceType: 'module'});
  const utilExport = utilAst.program.body.find(node => node.type === 'ExportDefaultDeclaration');
  const upload = utilExport.declaration.properties.find(node => node.key.name === 'uploadImageChange');
  const uploadMethod = util.slice(upload.start, upload.end);
  const ordinaryUpload = async (size, completion) => {
    let successes = 0, finishes = 0, chosenPath, dimensions;
    const local = {setTimeout, TOKENNAME: 'local-observer', HTTP_REQUEST_URL: '', store: {state: {app: {token: ''}}},
      i18n: {t: value => value}, uni: {
        chooseImage(options) {options.success({tempFilePaths: ['/local-codec-image'], tempFiles: [{size}]});},
        getImageInfo(options) {options.success({path: options.src, width: 1000, height: 500});},
        showLoading() {}, hideLoading() {},
        createCanvasContext() {return {clearRect() {}, drawImage() {}, save() {}, draw(reserve, callback) {callback();}};},
        canvasToTempFilePath(options) {options.success({tempFilePath: '/local-codec-compressed'});},
        uploadFile(options) {chosenPath = options.filePath; options.success({statusCode: 200,
          data: JSON.stringify({status: 200, data: {url: '/uploads/ordinary.png'}})}); options.complete();}
      }};
    vm.createContext(local); new vm.Script('globalThis.upload = ({' + uploadMethod + '}).uploadImageChange').runInContext(local);
    await new Promise(resolve => local.upload.call({Tips() {throw new Error('No refusal upload sample is run');}}, 'upload/image',
      () => {successes++; if (!completion) resolve();}, undefined, value => {dimensions = value;},
      completion ? () => {finishes++; resolve();} : undefined));
    return {successes, finishes, chosenPath, ...(dimensions ? {dimensions} : {})};
  };
  equal(await ordinaryUpload(1024, true), {successes: 1, finishes: 1, chosenPath: '/local-codec-image'},
    'Ordinary direct upload producer finishes its optional fifth callback');
  equal(await ordinaryUpload(3000000, true), {successes: 1, finishes: 1, chosenPath: '/local-codec-compressed', dimensions: {w: 750, h: 375}},
    'Ordinary compressed upload keeps the original canvas pipeline and finishes once');
  equal(await ordinaryUpload(1024, false), {successes: 1, finishes: 0, chosenPath: '/local-codec-image'},
    'Legacy four-argument upload still returns the original success callback');
  const result = {scope: 'ORDINARY_SUBMIT_UI_SOURCE_STATE_ONLY', node: process.version, files: fingerprints, compiled,
    ordinaryAssertions: checks, actualVueStateMethods: true, nativeHTTPExecuted: false, applicationStarted: false,
    sqlExecuted: false, authenticationActorsCreated: false, DOMCreated: false, browserVisualAcceptance: false,
    failureLateResponseConflictRecoveryInjectionRun: false, unknownAdjustmentRuntimeVerified: false,
    hardRefreshOrRebuyVerified: false, creationGateChanged: false};
  if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(result, null, 2) + '\n', {flag: 'wx'});
  console.log(JSON.stringify(result));
}
main().catch(error => {console.error(error.message); process.exitCode = 1;});
