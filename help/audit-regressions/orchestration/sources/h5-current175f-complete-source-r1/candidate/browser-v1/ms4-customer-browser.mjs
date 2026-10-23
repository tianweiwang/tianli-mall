// Import-only CUA driver. Root supplies the one owned real tab and locators from its current DOM.
import { initializeCustomerNetworkObserver, readCustomerNetworkPrivate, awaitCustomerResponsePrivate,
  projectCustomerCapturePublic, customerNetworkSummaryPublic } from './customer-network-observer.mjs';

const demand = (ok, code) => { if (!ok) throw new Error(code); };
const states = new WeakMap();
const scenes = new Set(['login-empty', 'store-a', 'store-b', 'product-a', 'product-b', 'product-c',
  'cart-three-lines', 'confirm-filled', 'order-detail-unpaid']);
const targets = new Set(['account-tab', 'account-input', 'password-input', 'login-agreement', 'login-submit',
  'product-a', 'product-b', 'product-c', 'sku-a', 'sku-b', 'sku-c', 'add-cart', 'quantity-a', 'quantity-b',
  'quantity-c', 'sku-confirm', 'cart-entry', 'checkout-store-a', 'checkout-select-all', 'checkout-submit',
  'address-picker', 'owned-address', 'invoice-picker', 'owned-invoice', 'form-a-text', 'form-a-number',
  'form-b-text', 'form-b-number', 'order-mark', 'create-submit',
  'store-entry-a', 'store-entry-b', 'store-product-a', 'store-product-b', 'store-product-c',
  'store-more-a', 'store-more-b']);
const state = handle => { const value = states.get(handle); demand(value, 'M4CH_BROWSER_OWNED_HANDLE'); return value; };
const finiteId = value => { demand((typeof value === 'string' || Number.isSafeInteger(value))
  && /^[1-9][0-9]*$/.test(String(value)), 'M4CH_BROWSER_REAL_PUBLIC_ID'); return String(value); };
function live(s) {
  demand(!s.released && Math.floor(Date.now() / 1000) < s.runtime.deadline, 'M4CH_BROWSER_ORIGINAL_LIVE_WINDOW');
}
function publicNetwork(s) {
  return s.records.map(row => ({ request_id: row.request.request_id, projection: projectCustomerCapturePublic(row) }));
}
function normalStageCaptures(s, stage) {
  // Raw auth request/response (including its token) never enters K or public evidence.
  return s.records.filter(row => row.path_template !== '/api/login'
    && (stage !== 'B1' || row.path_template === '/api/user'));
}

async function dom(tab) {
  const result = await tab.playwright.evaluate(() => {
    const root = document.documentElement, body = document.body;
    const visible = element => { const r = element.getBoundingClientRect(), style = getComputedStyle(element);
      return r.width > 0 && r.height > 0 && style.display !== 'none' && style.visibility !== 'hidden'; };
    const box = element => { const r = element.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; };
    const controlSelector = 'button,uni-button,uni-checkbox,input,textarea,select,a,[role="button"],[role="link"],[role="checkbox"],'
      + '.logon,.tips>uni-view,.store-select,.placeOrder,.marketplace-submit,.settlement,.protocol uni-checkbox';
    const controls = [...new Set(document.querySelectorAll(controlSelector))].filter(visible).map(element => ({
      role: element.getAttribute('role') || element.tagName.toLowerCase(),
      name: element.getAttribute('aria-label') || element.getAttribute('placeholder') || element.getAttribute('name') || '',
      text: ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) ? '' : (element.innerText || ''),
      disabled: element.disabled === true || element.hasAttribute('disabled') || element.getAttribute('aria-disabled') === 'true',
      checked: element.checked === true || element.getAttribute('aria-checked') === 'true'
        || element.tagName === 'UNI-CHECKBOX' && !!element.querySelector('.uni-checkbox-input-checked'), box: box(element) }));
    const images = [...document.querySelectorAll('img')].filter(visible).map(element => ({
      src: element.getAttribute('src') || '', current_src: element.currentSrc, complete: element.complete,
      natural_width: element.naturalWidth, natural_height: element.naturalHeight, box: box(element) }));
    const url = new URL(location.href);
    return { url: location.href, width: innerWidth, height: innerHeight,
      scroll_width: Math.max(root.scrollWidth, body ? body.scrollWidth : 0), body_text: body ? body.innerText : '', controls, images,
      password_values_absent: ![...document.querySelectorAll('input[type="password"]')].some(element => Boolean(element.value)),
      credential_query_absent: ![...url.searchParams.keys()].some(key => /token|password|authorization|secret|account/i.test(key)) };
  });
  demand(result && Array.isArray(result.controls) && result.controls.length <= 512 && Array.isArray(result.images)
    && result.images.length <= 512 && typeof result.body_text === 'string' && result.body_text.length <= 262144,
    'M4CH_BROWSER_FULL_BOUNDED_RENDERED_DOM_NO_TRUNCATION');
  demand(result.password_values_absent && result.credential_query_absent, 'M4CH_BROWSER_NO_CREDENTIAL_IN_DOM_EVIDENCE');
  return result;
}
function storefrontStore(s, owner) {
  const matches = s.inputs.ui_intent.stores.filter(store => finiteId(store.mer_id) === finiteId(owner));
  demand(matches.length === 1 && typeof matches[0].name === 'string', 'M4CH_BROWSER_ACTUAL_STORE_FOR_PRODUCT_OWNER');
  return matches[0];
}
function storefrontProducts(s, store) {
  return s.inputs.ui_intent.storefront_products.filter(product => finiteId(product.mer_id) === finiteId(store.mer_id));
}
function sameStorefrontNames(actual, expected) {
  return actual.length === expected.length && JSON.stringify([...actual].sort()) === JSON.stringify([...expected].sort());
}
function containedStorefrontNames(actual, expected) {
  const counts = new Map();
  for (const name of expected) counts.set(name, (counts.get(name) || 0) + 1);
  for (const name of actual) {
    if (!counts.get(name)) return false;
    counts.set(name, counts.get(name) - 1);
  }
  return true;
}
function verifyStorefrontIntent(inputs) {
  const intent = inputs.ui_intent, rows = intent.storefront_products;
  demand(Array.isArray(intent.stores) && intent.stores.length === 2
    && new Set(intent.stores.map(store => finiteId(store.mer_id))).size === 2
    && intent.stores.every(store => typeof store.name === 'string')
    && Array.isArray(rows) && rows.length >= intent.products.length,
    'M4CH_BROWSER_COMPLETE_NATIVE_BEFORE_STOREFRONT_INTENT');
  const ids = new Set();
  for (const row of rows) {
    demand(row && typeof row === 'object' && !Array.isArray(row)
      && Object.keys(row).sort().join(',') === 'mer_id,product_id,store_name'
      && Number.isSafeInteger(row.product_id) && row.product_id >= 1 && row.product_id <= 8388607
      && Number.isSafeInteger(row.mer_id) && row.mer_id >= 1 && row.mer_id <= 4294967295
      && typeof row.store_name === 'string' && !ids.has(finiteId(row.product_id))
      && intent.stores.some(store => finiteId(store.mer_id) === finiteId(row.mer_id)),
      'M4CH_BROWSER_SAFE_EXACT_PUBLIC_STOREFRONT_PRODUCT_ROW');
    ids.add(finiteId(row.product_id));
  }
  demand(intent.products.every(product => rows.some(row => finiteId(row.product_id) === finiteId(product.product_id)
    && finiteId(row.mer_id) === finiteId(product.mer_id) && row.store_name === product.store_name)),
    'M4CH_BROWSER_ORIGINAL_THREE_PRODUCTS_IN_COMPLETE_NATIVE_STOREFRONT_SET');
}
async function storefrontSemanticDom(tab) {
  const proof = await tab.playwright.evaluate(() => {
    const visible = element => { const r = element.getBoundingClientRect(), style = getComputedStyle(element);
      return r.width > 0 && r.height > 0 && style.display !== 'none' && style.visibility !== 'hidden'; };
    const all = (root, selector) => {
      const rows = [...root.querySelectorAll(selector)].filter(visible);
      if (rows.length > 512) throw new Error('M4CH_BROWSER_COMPLETE_STOREFRONT_DOM_BOUND');
      return rows;
    };
    const control = element => ({ tag: element.tagName.toLowerCase(), classes: [...element.classList],
      role: element.getAttribute('role'), aria: element.getAttribute('aria-label'), text: (element.innerText || element.textContent || '').trim(),
      in_store: Boolean(element.closest('.store-products')) });
    return { url: location.href,
      store_names: all(document, '.store-header .store-name').map(element => element.textContent),
      product_stores: all(document, '.marketplace-store-link').map(element => ({
        name: element.querySelector('.store-name')?.textContent ?? null,
        caption: element.querySelector('.store-caption')?.textContent ?? null,
        entries: all(element, '.store-entry').map(control) })),
      rows: all(document, '.store-products .product-row').map(element => ({ ...control(element),
        product_name: element.querySelector('.product-name')?.textContent ?? null })),
      more: all(document, '.store-products .store-action.more').map(control) };
  });
  demand(proof && typeof proof.url === 'string' && ['store_names', 'product_stores', 'rows', 'more']
    .every(key => Array.isArray(proof[key]) && proof[key].length <= 512), 'M4CH_BROWSER_FULL_ACTUAL_STOREFRONT_SEMANTICS');
  return proof;
}
function storefrontButton(control, name, text, aria) {
  return control && ['button', 'uni-button'].includes(control.tag) && control.classes.includes(name)
    && (control.role === null || control.role === 'button') && control.text === text && control.aria === aria;
}
function verifyStorefrontProduct(s, product, observed, proof) {
  const store = storefrontStore(s, product.mer_id), url = new URL(observed.url), owner = proof.product_stores[0];
  demand(url.origin === s.runtime.origin && !url.hash && url.pathname === '/pages/goods_details/index'
    && url.searchParams.get('id') === finiteId(product.product_id) && proof.url === observed.url
    && observed.body_text.includes(product.store_name) && observed.body_text.includes(store.name)
    && observed.body_text.includes('商品所属店铺') && proof.product_stores.length === 1
    && owner.name === store.name && owner.caption === '商品所属店铺' && owner.entries.length === 1
    && storefrontButton(owner.entries[0], 'store-entry', '进入店铺', '进入店铺 ' + store.name)
    && observed.controls.filter(control => control.name === '进入店铺 ' + store.name
      && control.text.trim() === '进入店铺' && !control.disabled).length === 1,
    'M4CH_BROWSER_REAL_PRODUCT_NATIVE_OWNER_STORE_NAME_CAPTION_AND_ENTRY');
}
function verifyStorefrontStore(s, store, observed, proof, complete) {
  const url = new URL(observed.url), expected = storefrontProducts(s, store).map(product => product.store_name);
  demand(url.origin === s.runtime.origin && !url.hash && url.pathname === '/pages/store/index'
    && url.searchParams.get('mer_id') === finiteId(store.mer_id) && proof.url === observed.url
    && proof.store_names.length === 1 && proof.store_names[0] === store.name
    && observed.body_text.includes(store.name) && observed.body_text.includes('本店商品'),
    'M4CH_BROWSER_ACTUAL_STORE_ROUTE_OWNER_HEADER_AND_PRODUCT_SECTION');
  demand(proof.rows.every(row => row.classes.includes('product-row') && row.in_store && row.role === 'link'
    && typeof row.product_name === 'string' && row.aria === '查看商品 ' + row.product_name),
    'M4CH_BROWSER_ACTUAL_PRODUCT_ROW_LINK_CLASS_ARIA_AND_TITLE');
  const names = proof.rows.map(row => row.product_name), captured = observed.controls
    .filter(control => control.role === 'link' && control.name.startsWith('查看商品 ')).map(control => control.name);
  demand(sameStorefrontNames(captured, proof.rows.map(row => row.aria)) && containedStorefrontNames(names, expected),
    'M4CH_BROWSER_CAPTURED_LINKS_MATCH_ACTUAL_ROWS_AND_NATIVE_STORE_SUBSET');
  if (complete) demand(sameStorefrontNames(names, expected) && proof.more.length === 0
    && observed.body_text.includes(expected.length ? '本店商品已加载完' : '本店暂无已发布商品'),
    'M4CH_BROWSER_FULL_NATIVE_PUBLISHED_STORE_MULTISET_AFTER_ACTUAL_PAGINATION');
}
async function storefrontClickProof(s, locator, target) {
  const observed = await dom(s.tab), proof = await storefrontSemanticDom(s.tab);
  const selected = await locator.evaluate(element => ({ tag: element.tagName.toLowerCase(), classes: [...element.classList],
    role: element.getAttribute('role'), aria: element.getAttribute('aria-label'), text: (element.innerText || element.textContent || '').trim(),
    in_store: Boolean(element.closest('.store-products')),
    owner_name: element.closest('.marketplace-store-link')?.querySelector('.store-name')?.textContent ?? null,
    product_name: element.querySelector('.product-name')?.textContent ?? null }));
  if (/^store-entry-[ab]$/.test(target)) {
    const store = s.inputs.ui_intent.stores['ab'.indexOf(target.at(-1))], url = new URL(observed.url);
    const product = s.inputs.ui_intent.products.find(row => finiteId(row.product_id) === url.searchParams.get('id'));
    demand(product && finiteId(product.mer_id) === finiteId(store.mer_id), 'M4CH_BROWSER_REAL_CURRENT_PRODUCT_FOR_ENTRY_OWNER');
    verifyStorefrontProduct(s, product, observed, proof);
    demand(storefrontButton(selected, 'store-entry', '进入店铺', '进入店铺 ' + store.name)
      && selected.owner_name === store.name, 'M4CH_BROWSER_SELECTED_ACTUAL_PRODUCT_STORE_ENTRY');
    return { path: '/pages/store/index', parameter: 'mer_id', value: finiteId(store.mer_id) };
  }
  if (/^store-product-[abc]$/.test(target)) {
    const product = s.inputs.ui_intent.products['abc'.indexOf(target.at(-1))], store = storefrontStore(s, product.mer_id);
    verifyStorefrontStore(s, store, observed, proof, false);
    demand(selected.classes.includes('product-row') && selected.in_store && selected.role === 'link'
      && selected.aria === '查看商品 ' + product.store_name && selected.product_name === product.store_name,
      'M4CH_BROWSER_SELECTED_ACTUAL_OWNED_STOREFRONT_PRODUCT_ROW');
    return { path: '/pages/goods_details/index', parameter: 'id', value: finiteId(product.product_id) };
  }
  demand(/^store-more-[ab]$/.test(target), 'M4CH_BROWSER_ONLY_FINITE_NEW_STOREFRONT_CLICK');
  const store = s.inputs.ui_intent.stores['ab'.indexOf(target.at(-1))];
  verifyStorefrontStore(s, store, observed, proof, false);
  demand(proof.rows.length > 0 && proof.more.length === 1
    && storefrontButton(proof.more[0], 'more', '加载更多商品', null) && proof.more[0].classes.includes('store-action')
    && storefrontButton(selected, 'more', '加载更多商品', null) && selected.classes.includes('store-action') && selected.in_store,
    'M4CH_BROWSER_SELECTED_ACTUAL_CURRENT_STORE_MORE_BUTTON');
  return { path: '/pages/store/index', parameter: 'mer_id', value: finiteId(store.mer_id) };
}
async function awaitStorefrontPage(s, wanted) {
  const deadline = Math.min(s.runtime.deadline * 1000, Date.now() + 10000);
  do {
    live(s);
    const actual = new URL(await s.tab.playwright.evaluate(() => location.href));
    live(s);
    demand(Date.now() <= deadline, 'M4CH_BROWSER_ACTUAL_STOREFRONT_LOCATION_READ_WITHIN_BOUND');
    demand(actual.origin === s.runtime.origin && !actual.hash, 'M4CH_BROWSER_ACTUAL_CLICK_REMAINS_OWN_ORIGIN');
    if (actual.pathname === wanted.path && actual.searchParams.get(wanted.parameter) === wanted.value) return;
    demand(Date.now() < deadline, 'M4CH_BROWSER_ACTUAL_STOREFRONT_CLICK_DESTINATION_WITHIN_ORIGINAL_WINDOW');
    await new Promise(resolve => setTimeout(resolve, Math.min(50, Math.max(1, deadline - Date.now()))));
  } while (Date.now() < deadline);
  demand(false, 'M4CH_BROWSER_ACTUAL_STOREFRONT_CLICK_DESTINATION_OBSERVED');
}

async function sceneMatches(s, scene, observed) {
  const url = new URL(observed.url);
  demand(url.origin === s.runtime.origin && !url.hash, 'M4CH_BROWSER_SAME_ORIGIN_REAL_HISTORY_PAGE');
  if (scene === 'login-empty') {
    demand(url.pathname === '/pages/users/login/index' && !s.credentialsEntered
      && observed.body_text.includes('多商户商城'), 'M4CH_BROWSER_REAL_GENERIC_EMPTY_LOGIN'); return;
  }
  if (scene === 'store-a' || scene === 'store-b') {
    const store = s.inputs.ui_intent.stores[scene === 'store-a' ? 0 : 1];
    demand(url.pathname === '/pages/store/index' && url.searchParams.get('mer_id') === finiteId(store.mer_id)
      && observed.body_text.includes(store.name) && observed.body_text.includes('本店商品'), 'M4CH_BROWSER_REAL_BOUND_STORE_DOM');
    verifyStorefrontStore(s, store, observed, await storefrontSemanticDom(s.tab), true); return;
  }
  if (scene.startsWith('product-')) {
    const product = s.inputs.ui_intent.products[['product-a', 'product-b', 'product-c'].indexOf(scene)];
    demand(url.pathname === '/pages/goods_details/index' && url.searchParams.get('id') === finiteId(product.product_id)
      && observed.body_text.includes(product.store_name), 'M4CH_BROWSER_REAL_BOUND_PRODUCT_DOM');
    verifyStorefrontProduct(s, product, observed, await storefrontSemanticDom(s.tab)); return;
  }
  if (scene === 'cart-three-lines') {
    demand(url.pathname === '/pages/order_addcart/order_addcart'
      && s.inputs.ui_intent.stores.every(store => observed.body_text.includes(store.name))
      && s.inputs.ui_intent.products.every(product => observed.body_text.includes(product.store_name)),
      'M4CH_BROWSER_REAL_TWO_STORE_THREE_PRODUCT_CART_DOM'); return;
  }
  if (scene === 'confirm-filled') {
    demand(url.pathname === '/pages/goods/order_confirm/index' && observed.body_text.includes('提交订单')
      && observed.body_text.includes(s.inputs.ui_intent.address.real_name)
      && s.inputs.ui_intent.products.filter(product => product.selected).every(product => observed.body_text.includes(product.store_name))
      && observed.controls.some(control => control.text.includes('提交订单') && !control.disabled),
      'M4CH_BROWSER_REAL_EDITABLE_CONFIRM_DOM'); return;
  }
  demand(scene === 'order-detail-unpaid' && s.order && url.pathname === '/pages/goods/order_details/index'
    && url.searchParams.get('order_id') === s.order && /待付款|待支付|未支付/.test(observed.body_text),
    'M4CH_BROWSER_REAL_RETURNED_UNPAID_ORDER_DOM');
}

export async function initializeCustomerBrowser(tab, runtime, bridge) {
  demand(tab && typeof tab.id === 'string' && runtime && bridge && typeof bridge.privateBrowserInputs === 'function',
    'M4CH_BROWSER_REAL_OWNED_TAB_AND_BRIDGE');
  const inputs = bridge.privateBrowserInputs();
  demand(inputs.ui_intent.target_order_state === 'unpaid' && inputs.ui_intent.products.length === 3,
    'M4CH_BROWSER_SEALED_NORMAL_INTENT');
  verifyStorefrontIntent(inputs);
  const observer = await initializeCustomerNetworkObserver(tab, runtime, inputs.public_ids,
    { sha256: bytes => bridge.sha256(Uint8Array.from(bytes)) });
  const handle = Object.freeze({ scope: 'PRIVATE_CUSTOMER_BROWSER_DRIVER_HANDLE' });
  states.set(handle, { tab, runtime, bridge, inputs, observer, records: [], actions: [], credentialsEntered: false,
    createAttempted: false, stages: [], order: null, captures: new Map(), released: false });
  return handle;
}

// PRIVATE return: Root chooses an actual observed request_id, never a guessed endpoint response or fixed count.
export async function readCustomerRequestsPrivate(handle) {
  const s = state(handle); live(s); return readCustomerNetworkPrivate(s.observer);
}
export async function captureCustomerResponsePrivate(handle, requestId) {
  const s = state(handle); live(s);
  const record = await awaitCustomerResponsePrivate(s.observer, requestId);
  if (!s.records.some(item => item.request.request_id === record.request.request_id)) s.records.push(record);
  if (record.path_template === '/api/order/create/:confirmation') {
    demand(s.createAttempted && record.response.envelope.data.status === 'SUCCESS', 'M4CH_BROWSER_CREATE_FROM_ACTUAL_SINGLE_CLICK');
    s.order = record.response.envelope.data.result.orderId;
  }
  return record; // Includes raw wire body; never print, publish, or give it to another agent.
}

export async function navigateCustomerPage(handle, page) {
  const s = state(handle); live(s);
  demand(['cart', 'store-a', 'store-b'].includes(page), 'M4CH_BROWSER_FINITE_NORMAL_PAGE_NAVIGATION');
  let relative = '/pages/order_addcart/order_addcart';
  if (page !== 'cart') relative = '/pages/store/index?mer_id=' + finiteId(s.inputs.ui_intent.stores[page === 'store-a' ? 0 : 1].mer_id);
  demand(await s.tab.url() !== s.runtime.origin + relative, 'M4CH_BROWSER_NO_ACCIDENTAL_SAME_PAGE_RELOAD');
  await s.tab.goto(s.runtime.origin + relative);
  const observed = await s.tab.playwright.evaluate(() => ({ origin: location.origin, path: location.pathname }));
  demand(observed.origin === s.runtime.origin, 'M4CH_BROWSER_NAVIGATION_STAYS_SAME_ORIGIN');
  s.actions.push({ action: 'navigate', target: page, completed: true });
  return { action_completed: true, target: page }; // Root reads the fresh actual DOM before its next choice.
}
async function actualLocator(locator) {
  demand(locator && await locator.count() === 1 && await locator.isVisible() && await locator.isEnabled(),
    'M4CH_BROWSER_CURRENT_UNIQUE_VISIBLE_ENABLED_LOCATOR');
  demand(await locator.evaluate(element => element.disabled !== true && !element.hasAttribute('disabled')
    && element.getAttribute('aria-disabled') !== 'true' && getComputedStyle(element).pointerEvents !== 'none'),
    'M4CH_BROWSER_ACTUAL_CUSTOM_ELEMENT_ENABLED');
}
export async function clickCustomerControl(handle, locator, target) {
  const s = state(handle); live(s); demand(targets.has(target), 'M4CH_BROWSER_FINITE_NORMAL_CONTROL');
  await actualLocator(locator);
  const storefront = /^store-(?:entry-[ab]|product-[abc]|more-[ab])$/.test(target)
    ? await storefrontClickProof(s, locator, target) : null;
  if (target === 'create-submit') {
    demand(!s.createAttempted && s.stages.join(',') === 'B0,B1,B2,B3', 'M4CH_BROWSER_B3_SEALED_BEFORE_SINGLE_CREATE_CLICK');
    s.createAttempted = true; // Mark before native action; no retry on an observation timeout.
  }
  await locator.click({ timeoutMs: 10000 });
  await s.tab.playwright.evaluate(() => ({ path: location.pathname, ready: document.readyState }));
  if (storefront) await awaitStorefrontPage(s, storefront);
  s.actions.push({ action: 'click', target, completed: true }); return { action_completed: true, target };
}
export async function fillCustomerControl(handle, locator, target) {
  const s = state(handle); live(s); demand(targets.has(target), 'M4CH_BROWSER_FINITE_NORMAL_INPUT');
  let value;
  if (target === 'account-input' || target === 'password-input') {
    demand(!s.createAttempted && await s.tab.url() === s.runtime.origin + '/pages/users/login/index',
      'M4CH_BROWSER_CREDENTIALS_ONLY_REAL_LOCAL_LOGIN');
    value = s.inputs.actor[target === 'account-input' ? 'account' : 'password']; s.credentialsEntered = true;
  } else if (/^quantity-[abc]$/.test(target)) {
    value = String(s.inputs.ui_intent.products['abc'.indexOf(target.at(-1))].qty);
  } else if (/^form-[ab]-(text|number)$/.test(target)) {
    const label = target.endsWith('number') ? 'number' : 'text';
    const product = s.inputs.ui_intent.products[target[5] === 'a' ? 0 : 1];
    const group = s.inputs.ui_intent.custom_form_inputs.find(item => String(item.product_id) === String(product.product_id));
    const fields = group?.fields.filter(field => field.label === label);
    demand(fields?.length === 1, 'M4CH_BROWSER_ACTUAL_UNIQUE_SEALED_FORM_INPUT'); value = fields[0].value;
  } else if (target === 'order-mark') value = s.inputs.ui_intent.mark;
  else throw new Error('M4CH_BROWSER_TARGET_IS_NOT_A_FILL_INPUT');
  demand(typeof value === 'string', 'M4CH_BROWSER_ACTUAL_STRING_INPUT');
  await actualLocator(locator); await locator.fill(value, { timeoutMs: 10000 });
  await s.tab.playwright.evaluate(() => ({ path: location.pathname, ready: document.readyState }));
  s.actions.push({ action: 'fill', target, completed: true }); return { action_completed: true, target };
}

export async function verifyCustomerSqlStage(handle, stage) {
  const s = state(handle); live(s);
  demand(['B0', 'B1', 'B2', 'B3', 'B4'][s.stages.length] === stage, 'M4CH_BROWSER_REAL_SQL_STAGE_ORDER');
  const network = stage === 'B0' ? [] : publicNetwork(s), captures = stage === 'B0' ? [] : normalStageCaptures(s, stage);
  const result = await s.bridge.stage(stage, network, captures);
  s.stages.push(stage); s.records = []; return result;
}

export async function captureCustomerScene(handle, scene, stage) {
  const s = state(handle); live(s); demand(scenes.has(scene) && ['B0', 'B1', 'B2', 'B3', 'B4'].includes(stage),
    'M4CH_BROWSER_FINITE_SCENE_STAGE');
  const observed = await dom(s.tab); await sceneMatches(s, scene, observed);
  demand([320, 390, 1440].includes(observed.width) && observed.height >= 600 && observed.height <= 1200
    && observed.scroll_width <= observed.width, 'M4CH_BROWSER_REAL_VIEWPORT_NO_PAGE_OVERFLOW');
  const capture = { ...s.bridge.newFrame('MS4_CUSTOMER_H5_UI_CAPTURE'), stage, scene,
    viewport: { width: observed.width, height: observed.height }, actions: s.actions, dom: observed, network: publicNetwork(s) };
  const screenshot = await s.tab.screenshot({ fullPage: true });
  await s.bridge.recordUiCapture(capture);
  const result = await s.bridge.saveScreenshot(capture, Uint8Array.from(screenshot));
  s.captures.set(result.path, capture); s.actions = [];
  return result; // Public path contains sequence/scene only. Root must inspect the actual image before review.
}
export async function reviewCustomerSceneAfterRootInspection(handle, screenshotPath) {
  const s = state(handle); live(s);
  demand(s.captures.has(screenshotPath), 'M4CH_BROWSER_REVIEW_ACTUAL_OWNED_SCREENSHOT');
  return s.bridge.publishVisualReviewAfterRootInspection(s.captures.get(screenshotPath));
}
export function customerBrowserProgressPublic(handle) {
  const s = state(handle);
  return { scope: 'NORMAL_CUSTOMER_BROWSER_PROGRESS_ONLY_NOT_ACCEPTANCE', sql_stages_verified: [...s.stages],
    create_click_attempted: s.createAttempted, saved_scene_count: s.captures.size,
    network: customerNetworkSummaryPublic(s.observer), HTTP_DOM_acceptance: 'NOT RUN' };
}
export function releaseCustomerBrowserPrivateInputs(handle) {
  const s = state(handle);
  s.inputs = null; s.records = []; s.observer = null; s.captures.clear(); s.released = true;
  states.delete(handle); return { driver_private_references_released: true };
}
