'use strict';

// Normal Vue component initialization and display only; no app, HTTP or identity simulation.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
assert.ok(process.env.PREVIEW_VUE_MODULES, 'Set PREVIEW_VUE_MODULES to the existing node_modules directory containing Vue 2');
const Vue = require(path.join(process.env.PREVIEW_VUE_MODULES, 'vue'));
const page = fs.readFileSync(path.join(root, 'template/uni-app/pages/goods/order_confirm/index.vue'), 'utf8');
const activeMethod = page.match(/orderFormImagesActive\(\) \{[^\n]+\}/);
assert.ok(activeMethod, 'Read the actual confirmation computed method');
const active = new Function('return ({' + activeMethod[0] + '})')().orderFormImagesActive;
const mixinSource = fs.readFileSync(path.join(root, 'template/uni-app/mixins/marketplace-order-form-images.js'), 'utf8');
const sandbox = { module: { exports: {} }, isPrivateOrderFormImage: value => value.startsWith('mp-form:') };
vm.runInNewContext(mixinSource.replace(/^import[^\n]+\n/, '').replace('export default', 'module.exports ='), sandbox);
const mixin = sandbox.module.exports;

async function main() {
  let planChanges = 0;
  const component = new Vue({
    data: () => ({ privateFormImagesVisible: true, marketplaceOwner: null, marketplaceFormsReady: false,
      marketplaceRestoring: false, orderFormImageContext: null, orderFormImageGroups: [], orderFormTemporaryImages: {} }),
    computed: { orderFormImagesActive: active, orderFormImagePlan: mixin.computed.orderFormImagePlan },
    watch: { orderFormImagePlan() { planChanges++; } }
  });
  // Vue installs the watcher before the page's onLoad sets its private lifecycle flag.
  assert.equal(component.orderFormImagesActive, false);
  component._marketplaceAlive = true;
  component.marketplaceOwner = { ready: true };
  component.marketplaceFormsReady = true;
  await Vue.nextTick();
  assert.equal(component.orderFormImagesActive, true, 'Normal onLoad and confirmation hydration activate image reads');
  assert.ok(planChanges > 0, 'The actual image plan observes activation');

  const group = { product_id: 7, definition_sha256: 'a'.repeat(64) };
  const field = { field_index: 1, value: ['mp-form:v1:1'] };
  const key = JSON.stringify(['7', group.definition_sha256, 1, 0, field.value[0]]);
  let observed = '';
  component.$watch(() => mixin.methods.orderFormImageSource.call(component, group, field, 0), value => { observed = value; });
  component._orderFormImageScope = { current: () => true };
  component.$set(component.orderFormTemporaryImages, key, 'blob:synthetic-display-only');
  await Vue.nextTick();
  assert.equal(observed, 'blob:synthetic-display-only', 'The first asynchronous display URL triggers a render dependency');
  component.$destroy();
  console.log(JSON.stringify({ status: 'PASS', scope: 'normal Vue initialization and asynchronous image display', checks: 4,
    application_or_HTTP: false, stopped_samples: false }));
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
