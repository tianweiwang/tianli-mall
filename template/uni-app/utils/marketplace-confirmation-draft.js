// Local request copies are not authorization, a server quote, or proof that HTTP was sent.
import {initializeMarketplaceForms, marketplaceFormAnswers} from './marketplace-order-form.js';
import {bindMarketplaceSubmission, snapshotMarketplaceSubmission} from './marketplace-order-submit.js';

const PREFIX = 'marketplace-confirmation:v1:';
const FULL_LIMIT = 1024 * 1024, OWNER_LIMIT = 5 * FULL_LIMIT;
const EDIT_MS = 600000, ATTEMPT_MS = 86400000;
let pageSequence = 0;
const fail = message => { throw new Error(message || '结算资料未能安全保存，本次尚未发送'); };
const copy = value => JSON.parse(JSON.stringify(value));
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const integer = value => Number.isSafeInteger(value) && value >= 0;
const freeze = value => {
	if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); }
	return value;
};
export function marketplaceOwnerUid(value) {
	if (typeof value === 'number' && (!Number.isSafeInteger(value) || value < 1)) return '';
	return typeof value === 'string' || typeof value === 'number' ? (/^[1-9][0-9]*$/.test(String(value)) ? String(value) : '') : '';
}
export function marketplacePageInstance() { return String(Date.now()) + ':' + String(++pageSequence); }
export function marketplaceConfirmationUrl(key) { return '/pages/goods/order_confirm/index?orderKey=' + encodeURIComponent(key); }
const keyValid = key => typeof key === 'string' && /^[\x21-\x7e]{1,32}$/.test(key);
function bytes(value) {
	let size = 0;
	for (let i = 0; i < value.length; i++) {
		const code = value.charCodeAt(i);
		if (code < 128) size++;
		else if (code < 2048) size += 2;
		else if (code >= 0xd800 && code <= 0xdbff && i + 1 < value.length && value.charCodeAt(i + 1) >= 0xdc00 && value.charCodeAt(i + 1) <= 0xdfff) { size += 4; i++; }
		else size += 3;
	}
	return size;
}
function bindingValid(binding, key) {
	return binding && binding.key === key && binding.idemKey === 'confirm:' + key && marketplaceOwnerUid(binding.merId) &&
		['persistent', 'temporary'].includes(binding.sourceKind) && Array.isArray(binding.refs) && binding.refs.length > 0 && binding.refs.length <= 500 &&
		binding.refs.every(ref => typeof ref === 'string' && ref && !ref.includes(',') && !ref.includes('\0')) && new Set(binding.refs).size === binding.refs.length;
}
function selectionValid(selection) {
	return selection && ['none', 'id'].includes(selection.mode) && typeof selection.id === 'string' && /^(0|[1-9][0-9]*)$/.test(selection.id) &&
		(selection.mode === 'none' ? selection.id === '0' : selection.id !== '0') && ['none', 'resolved', 'unavailable', 'pending'].includes(selection.status);
}
function attemptValid(attempt, binding) {
	if (!attempt || attempt.key !== binding.key || !integer(attempt.generation) || !attempt.body) return false;
	const body = attempt.body;
	if (['cartId', 'mer_id', 'new', 'idem_key', 'addressId', 'invoice_id', 'payType', 'mark', 'real_name', 'phone', 'gift_mark',
		'shipping_type', 'is_gift', 'store_id', 'couponId', 'useIntegral', 'combinationId', 'pinkId', 'seckill_id', 'bargainId', 'advanceId', 'custom_form'].some(key => !own(body, key))) return false;
	try {
		const normalized = snapshotMarketplaceSubmission(binding, body, body.custom_form, attempt.generation);
		return JSON.stringify(normalized) === JSON.stringify(attempt);
	} catch (error) { return false; }
}
function recordValid(record, owner) {
	if (!record || record.schema !== 1 || record.ownerUid !== owner || !keyValid(record.key) || !integer(record.revision) || record.revision < 1 ||
		!bindingValid(record.binding, record.key) || !integer(record.firstSendIntentAt)) return false;
	if (record.kind === 'unknown') return record.phase === 'uncertain' && record.firstSendIntentAt > 0;
	if (record.kind !== 'full' || !integer(record.startedAt) || record.startedAt < 1 || record.draftExpiresAt !== record.startedAt + EDIT_MS ||
		!integer(record.editGeneration) || !['editing', 'submitting', 'uncertain'].includes(record.phase) || !record.fields ||
		['mark', 'contacts', 'contactsTel', 'gift_mark', 'payType'].some(field => typeof record.fields[field] !== 'string') ||
		Object.keys(record.fields).length !== 5 ||
		!record.selection || !selectionValid(record.selection.address) || !selectionValid(record.selection.invoice) ||
		!Array.isArray(record.attempts) || record.attempts.length > 5 || !record.attempts.every(attempt => attemptValid(attempt, record.binding)) ||
		new Set(record.attempts.map(attempt => attempt.generation)).size !== record.attempts.length ||
		(record.attempts.length > 0) !== (record.firstSendIntentAt > 0) ||
		(record.firstSendIntentAt === 0 && record.phase !== 'editing') ||
		record.attemptExpiresAt !== (record.firstSendIntentAt ? record.firstSendIntentAt + ATTEMPT_MS : 0) ||
		!record.selection.address.snapshot || !record.selection.invoice.snapshot || !record.view || !record.view.store || typeof record.view.store.name !== 'string' ||
		!Array.isArray(record.view.products) || record.view.products.length > 500 || record.view.products.some(product => !product || typeof product.name !== 'string' || typeof product.sku !== 'string')) return false;
	try { initializeMarketplaceForms(record.forms); } catch (error) { return false; }
	return true;
}
function readEnvelope(owner) {
	if (!marketplaceOwnerUid(owner) || owner !== String(owner)) fail('登录身份尚未核实');
	const raw = uni.getStorageSync(PREFIX + owner);
	if (raw === '' || raw === null || raw === undefined) return {schema: 1, ownerUid: owner, revision: 0, records: []};
	if (typeof raw !== 'string' || bytes(raw) > OWNER_LIMIT) fail('本机结算资料无法读取，请仅查询原提交结果');
	let value;
	try { value = JSON.parse(raw); } catch (error) { fail('本机结算资料已损坏，请仅查询原提交结果'); }
	if (!value || value.schema !== 1 || value.ownerUid !== owner || !integer(value.revision) || !Array.isArray(value.records) ||
		value.records.length > 25 || !value.records.every(record => recordValid(record, owner)) ||
		new Set(value.records.map(record => record.key)).size !== value.records.length) fail('本机结算资料不完整，请仅查询原提交结果');
	return value;
}
function writeEnvelope(envelope) {
	if (envelope.records.filter(record => record.kind === 'full').length > 5 ||
		envelope.records.filter(record => record.firstSendIntentAt > 0).length > 20) fail('待确认的结算资料已达保存上限，请先查询原订单');
	for (const record of envelope.records) if (!recordValid(record, envelope.ownerUid) || bytes(JSON.stringify(record)) > FULL_LIMIT) fail();
	envelope.revision++;
	const raw = JSON.stringify(envelope);
	if (bytes(raw) > OWNER_LIMIT) fail('本机结算保存空间不足，本次尚未发送');
	try {
		uni.setStorageSync(PREFIX + envelope.ownerUid, raw);
		if (uni.getStorageSync(PREFIX + envelope.ownerUid) !== raw) fail();
		const actual = readEnvelope(envelope.ownerUid);
		if (JSON.stringify(actual) !== raw) fail();
		return actual;
	} catch (error) { fail('结算资料未能完整写入并读回，本次尚未发送'); }
}
function anchor(record) {
	return {schema: 1, ownerUid: record.ownerUid, key: record.key, kind: 'unknown', revision: record.revision + 1,
		binding: copy(record.binding), phase: 'uncertain', firstSendIntentAt: record.firstSendIntentAt};
}
function expire(envelope, now) {
	let changed = false;
	envelope.records = envelope.records.reduce((rows, record) => {
		if (record.kind === 'full' && (now < record.startedAt || (record.firstSendIntentAt ? now >= record.attemptExpiresAt : now >= record.draftExpiresAt))) {
			changed = true;
			if (record.firstSendIntentAt) rows.push(anchor(record));
		} else rows.push(record);
		return rows;
	}, []);
	return changed;
}
export function readMarketplaceConfirmation(owner, key) {
	if (!keyValid(key)) fail('原确认编号不完整');
	let envelope = readEnvelope(owner);
	if (expire(envelope, Date.now())) envelope = writeEnvelope(envelope);
	const record = envelope.records.find(row => row.key === key);
	return record ? copy(record) : null;
}
export function findMarketplaceUnknown(owner, merId, sourceKind, refs) {
	let envelope = readEnvelope(owner);
	if (expire(envelope, Date.now())) envelope = writeEnvelope(envelope);
	return copy(envelope.records.find(record => record.firstSendIntentAt > 0 && String(record.binding.merId) === String(merId) &&
		record.binding.sourceKind === sourceKind && JSON.stringify(record.binding.refs.slice().sort()) === JSON.stringify(refs.slice().sort())) || null);
}
export function saveMarketplaceConfirmation(owner, context, expectedRevision) {
	let envelope = readEnvelope(owner);
	const now = Date.now();
	if (expire(envelope, now)) envelope = writeEnvelope(envelope);
	if (!context || now < context.startedAt || (context.phase === 'editing' && now >= context.draftExpiresAt)) fail('原确认已过期，请仅查询提交结果');
	// Never overwrite a newer send-intent with an older editing checkpoint.
	const previous = envelope.records.find(record => record.key === context.key);
	if ((previous ? previous.revision : 0) !== expectedRevision || (previous && previous.kind === 'unknown')) fail('结算资料已在其他页面更新，请重新查询原确认');
	const record = copy(context);
	record.schema = 1; record.ownerUid = owner; record.kind = 'full'; record.revision = expectedRevision + 1;
	if (previous) {
		if (JSON.stringify(previous.binding) !== JSON.stringify(record.binding) || previous.startedAt !== record.startedAt ||
			previous.firstSendIntentAt !== record.firstSendIntentAt || JSON.stringify(previous.attempts) !== JSON.stringify(record.attempts)) fail('原提交事实不可覆盖');
	}
	if (!recordValid(record, owner)) fail('结算资料不完整，本次尚未发送');
	if (previous) envelope.records[envelope.records.indexOf(previous)] = record;
	else envelope.records.push(record);
	return copy(writeEnvelope(envelope).records.find(row => row.key === record.key));
}
export function sealMarketplaceConfirmationAttempt(owner, key, expectedRevision, attempt) {
	const envelope = readEnvelope(owner), record = envelope.records.find(row => row.key === key);
	if (!record || record.kind !== 'full' || record.revision !== expectedRevision || !attemptValid(attempt, record.binding) || attempt.generation > record.editGeneration) fail('结算资料已变化，本次尚未发送');
	const now = Date.now();
	const previous = record.attempts.find(row => row.generation === attempt.generation);
	if (previous && JSON.stringify(previous) !== JSON.stringify(attempt)) fail('原提交内容不可更换');
	// A live retry uses the original sealed body, even when later edits have another generation.
	if (!previous && (attempt.generation !== record.editGeneration || String(attempt.body.addressId) !== record.selection.address.id || String(attempt.body.invoice_id) !== record.selection.invoice.id ||
		attempt.body.payType !== record.fields.payType || attempt.body.mark !== record.fields.mark || attempt.body.gift_mark !== record.fields.gift_mark ||
		attempt.body.real_name !== record.fields.contacts || attempt.body.phone !== record.fields.contactsTel ||
		JSON.stringify(attempt.body.custom_form) !== JSON.stringify(marketplaceFormAnswers(record.forms)))) fail('完整提交内容与已保存上下文不一致');
	if (now < record.startedAt || (!previous && now >= record.draftExpiresAt) || (record.firstSendIntentAt && now >= record.attemptExpiresAt)) fail('原确认已过期，请仅查询提交结果');
	if (!previous) {
		if (record.attempts.length >= 5) fail('本次确认已达提交副本上限，请查询原订单');
		record.attempts.push(copy(attempt));
	}
	if (!record.firstSendIntentAt) record.firstSendIntentAt = now;
	record.attemptExpiresAt = record.firstSendIntentAt + ATTEMPT_MS;
	record.phase = 'submitting'; record.revision++;
	const saved = writeEnvelope(envelope).records.find(row => row.key === key);
	const actual = saved.attempts.find(row => row.generation === attempt.generation);
	if (JSON.stringify(actual) !== JSON.stringify(attempt)) fail();
	return {record: copy(saved), attempt: freeze(copy(actual))};
}
// Caller may remove a key only after a real owner lookup/create returned created.
export function removeCreatedMarketplaceConfirmation(owner, key, expectedRevision) {
	const envelope = readEnvelope(owner), record = envelope.records.find(row => row.key === key);
	if (!record) return true;
	if (record.revision !== expectedRevision) fail('结算资料已更新，请再次查询订单');
	envelope.records = envelope.records.filter(row => row.key !== key);
	writeEnvelope(envelope);
	return true;
}
export function pruneMarketplaceConfirmationOwner(ownerUid) {
	try {
		const owner = marketplaceOwnerUid(ownerUid);
		if (!owner) return false;
		const envelope = readEnvelope(owner);
		if (!envelope.records.length) return true;
		envelope.records = envelope.records.filter(record => record.firstSendIntentAt > 0).map(anchor);
		writeEnvelope(envelope);
		return true;
	} catch (error) { return false; }
}
export function marketplaceCurrentIdentity(store) {
	const app = store.state.app;
	if (!integer(app.authRevision) || !marketplaceOwnerUid(app.verifiedUid) || typeof app.verifiedUid !== 'string') fail('登录身份尚未核实');
	return {ownerUid: app.verifiedUid, authRevision: app.authRevision};
}
export function marketplaceIdentityCurrent(store, identity) {
	return !!identity && store.state.app.authRevision === identity.authRevision && store.state.app.verifiedUid === identity.ownerUid;
}
export function verifyMarketplaceOwner(store) {
	const revision = store.state.app.authRevision;
	return store.dispatch('USERINFO', true).then(user => {
		const identity = marketplaceCurrentIdentity(store);
		if (identity.authRevision !== revision || marketplaceOwnerUid(user && user.uid) !== identity.ownerUid) fail('登录身份已变化，请重新打开原确认');
		return identity;
	});
}

// Three existing child pages share the same finite owner/key return contract.
function liveCheckoutParent(page, context, record) {
	if (!context || !page._marketplaceCheckoutChannel || !context.parentInstance) return false;
	const pages = getCurrentPages(), previous = pages[pages.length - 2], parent = previous && (previous.$vm || previous);
	if (!parent || (parent.marketplacePageInstance || parent._marketplaceInstance) !== context.parentInstance) return false;
	return pages.slice(0, -1).some(item => {
		const vm = item.$vm || item, attempt = vm.marketplaceAttempt;
		return vm.marketplacePageInstance && vm.orderKey === record.key && vm.marketplaceOwner && vm.marketplaceOwner.ownerUid === context.ownerUid &&
			vm.marketplaceOwner.authRevision === context.authRevision && vm.marketplaceCanEdit === true && !vm.marketplaceRestoredUnknown &&
			JSON.stringify(vm.marketplaceSubmitBinding) === JSON.stringify(record.binding) &&
			vm.marketplaceEditGeneration === record.editGeneration && attempt && record.editGeneration > attempt.generation &&
			record.attempts.some(saved => JSON.stringify(saved) === JSON.stringify(attempt));
	});
}
export function prepareMarketplaceCheckoutChild(page, options, channel, api) {
	page._marketplaceInstance = marketplacePageInstance();
	page._marketplaceAlive = true;
	page._marketplaceChildEpoch = 0;
	page.marketplaceRevalidating = true;
	page.marketplaceNotice = '';
	page._marketplaceBootstrapAuth = {authRevision: page.$store.state.app.authRevision, token: page.$store.state.app.token};
	const key = options.orderKey;
	const epoch = page._marketplaceChildEpoch, instance = page._marketplaceInstance;
	const active = () => marketplaceChildLifetimeCurrent(page) && page._marketplaceChildEpoch === epoch &&
		page._marketplaceInstance === instance && page.marketplaceOrderKey === key;
	if (!keyValid(key)) return Promise.reject(new Error('原确认编号不完整'));
	let candidate = null;
	if (channel && typeof channel.on === 'function' && typeof channel.emit === 'function') channel.on('marketplaceCheckoutContext', value => { candidate = value; });
	return verifyMarketplaceOwner(page.$store).then(identity => {
		if (!active()) fail('页面或登录身份已变化');
		page._marketplaceIdentity = identity;
		return api.lookup(key).then(response => ({identity, result: response.data}));
	}).then(({identity, result}) => {
		if (!active() || !marketplaceIdentityCurrent(page.$store, identity)) fail('登录身份已变化');
		if (!result || result.key !== key || !['not_found', 'processing', 'created'].includes(result.state)) fail('原订单结果尚未完整取得');
		if (result.state === 'created' || result.state === 'processing') { uni.redirectTo({url: marketplaceConfirmationUrl(key)}); return false; }
		const record = readMarketplaceConfirmation(identity.ownerUid, key);
		if (!record || record.kind !== 'full' || record.phase !== 'editing') { uni.redirectTo({url: marketplaceConfirmationUrl(key)}); return false; }
		const validCandidate = candidate && candidate.ownerUid === identity.ownerUid && candidate.authRevision === identity.authRevision && candidate.orderKey === key &&
			String(candidate.mer_id) === String(record.binding.merId) && candidate.generation === record.editGeneration && typeof candidate.parentInstance === 'string';
		page._marketplaceCheckoutChannel = validCandidate ? channel : null;
		if (record.firstSendIntentAt && !(validCandidate && liveCheckoutParent(page, candidate, record))) { uni.redirectTo({url: marketplaceConfirmationUrl(key)}); return false; }
		return api.restore(key, {addressId: record.selection.address.id, invoice_id: record.selection.invoice.id, payType: record.fields.payType}).then(response => {
			if (!active() || !marketplaceIdentityCurrent(page.$store, identity)) fail('登录身份已变化');
			const data = response.data;
			if (!data || data.key !== key || data.state !== 'not_found' || !data.confirmation || data.confirmation.orderKey !== key) { uni.redirectTo({url: marketplaceConfirmationUrl(key)}); return false; }
			if (JSON.stringify(bindMarketplaceSubmission(data.confirmation)) !== JSON.stringify(record.binding)) fail('原店铺商品选择已变化');
			page.marketplaceCheckout = {version: 1, ownerUid: identity.ownerUid, authRevision: identity.authRevision, orderKey: key,
				mer_id: record.binding.merId, source_kind: record.binding.sourceKind, cartId: record.binding.refs.join(','),
				generation: record.editGeneration, parentInstance: validCandidate ? candidate.parentInstance : ''};
			page._marketplaceCheckoutChannel = validCandidate ? channel : null;
			page._marketplaceBinding = copy(record.binding);
			page.marketplaceRevalidating = false;
			return true;
		});
	}).catch(error => { if (active()) page.marketplaceNotice = error.message || '原确认暂不能恢复，请返回原确认页查询'; return false; });
}
export function marketplaceChildCurrent(page) {
	return !page.marketplaceRevalidating && marketplaceChildLifetimeCurrent(page) && marketplaceIdentityCurrent(page.$store, page._marketplaceIdentity) && !!page.marketplaceCheckout;
}
export function marketplaceChildLifetimeCurrent(page) {
	const scope = page._marketplaceBootstrapAuth;
	return !!page._marketplaceAlive && !!scope && scope.authRevision === page.$store.state.app.authRevision && scope.token === page.$store.state.app.token &&
		(!page._marketplaceIdentity || marketplaceIdentityCurrent(page.$store, page._marketplaceIdentity));
}
export function marketplaceChildGuard(page, kind) {
	if (!page.marketplaceParentRequired) return () => true;
	if (!marketplaceChildCurrent(page)) return () => false;
	if (!page._marketplaceChildRequests) page._marketplaceChildRequests = {};
	const revision = page._marketplaceChildRequests[kind] = (page._marketplaceChildRequests[kind] || 0) + 1;
	const epoch = page._marketplaceChildEpoch, instance = page._marketplaceInstance, context = page.marketplaceCheckout;
	const scope = {key: context.orderKey, ownerUid: context.ownerUid, authRevision: context.authRevision, generation: context.generation,
		merId: context.mer_id, parentInstance: context.parentInstance, binding: JSON.stringify(page._marketplaceBinding)};
	return () => marketplaceChildCurrent(page) && page._marketplaceChildEpoch === epoch && page._marketplaceInstance === instance &&
		page.marketplaceCheckout === context && context.orderKey === scope.key && scope.key === page.marketplaceOrderKey &&
		context.ownerUid === scope.ownerUid && context.authRevision === scope.authRevision && context.generation === scope.generation &&
		context.mer_id === scope.merId && context.parentInstance === scope.parentInstance && JSON.stringify(page._marketplaceBinding) === scope.binding &&
		page._marketplaceChildRequests[kind] === revision;
}
export function hideMarketplaceCheckoutChild(page) {
	if (!page.marketplaceParentRequired) return;
	page._marketplaceChildEpoch++;
	page.marketplaceRevalidating = true;
	page._marketplaceRevalidationTask = null;
}
export function revalidateMarketplaceCheckoutChild(page, api) {
	if (page._marketplaceRevalidationTask) return page._marketplaceRevalidationTask;
	if (!marketplaceChildLifetimeCurrent(page)) return Promise.resolve(false);
	if (!page.marketplaceCheckout) { page.marketplaceNotice = '原确认尚未核实，请返回原确认页查询'; return Promise.resolve(false); }
	const context = page.marketplaceCheckout, epoch = ++page._marketplaceChildEpoch, instance = page._marketplaceInstance;
	const scope = {key: context.orderKey, generation: context.generation, ownerUid: context.ownerUid, authRevision: context.authRevision,
		merId: context.mer_id, parentInstance: context.parentInstance, binding: JSON.stringify(page._marketplaceBinding)};
	const revision = page.$store.state.app.authRevision, token = page.$store.state.app.token;
	const active = () => marketplaceChildLifetimeCurrent(page) && page._marketplaceChildEpoch === epoch && page.marketplaceCheckout === context &&
		page._marketplaceInstance === instance && page.marketplaceOrderKey === scope.key && context.orderKey === scope.key && context.generation === scope.generation &&
		context.ownerUid === scope.ownerUid && context.authRevision === scope.authRevision && context.mer_id === scope.merId && context.parentInstance === scope.parentInstance &&
		JSON.stringify(page._marketplaceBinding) === scope.binding &&
		page.$store.state.app.authRevision === revision && page.$store.state.app.token === token;
	page.marketplaceRevalidating = true;
	page.marketplaceNotice = '';
	const task = verifyMarketplaceOwner(page.$store).then(identity => {
		if (!active() || identity.ownerUid !== context.ownerUid || identity.authRevision !== context.authRevision) fail('原登录身份已变化');
		return api.lookup(context.orderKey).then(response => ({identity, result: response.data}));
	}).then(({identity, result}) => {
		if (!active()) return false;
		if (!result || result.key !== context.orderKey || !['not_found', 'processing', 'created'].includes(result.state)) fail('原订单结果未完整取得');
		if (result.state !== 'not_found') { uni.redirectTo({url: marketplaceConfirmationUrl(context.orderKey)}); return false; }
		const record = readMarketplaceConfirmation(identity.ownerUid, context.orderKey);
		if (!record || record.kind !== 'full' || record.phase !== 'editing' || record.editGeneration !== context.generation ||
			JSON.stringify(record.binding) !== JSON.stringify(page._marketplaceBinding) || (record.firstSendIntentAt && !liveCheckoutParent(page, context, record))) {
			uni.redirectTo({url: marketplaceConfirmationUrl(context.orderKey)}); return false;
		}
		page._marketplaceIdentity = identity;
		page.marketplaceRevalidating = false;
		if (own(page, 'marketplaceReturning')) page.marketplaceReturning = false;
		if (page.marketplaceSaving && !page.marketplaceSaved) page.marketplaceNotice = '保存请求结果尚待核实，请返回原确认页选择已保存的资料';
		return true;
	}).catch(error => {
		if (active()) page.marketplaceNotice = error.message || '原确认暂不能恢复，请返回原确认页查询';
		return false;
	}).finally(() => { if (page._marketplaceRevalidationTask === task) page._marketplaceRevalidationTask = null; });
	page._marketplaceRevalidationTask = task;
	return task;
}
export function returnMarketplaceCheckoutSelection(page, kind, id, api) {
	if (!['address', 'invoice'].includes(kind)) return Promise.reject(new Error('结算选择类型不完整'));
	const context = page.marketplaceCheckout;
	if (!context || !marketplaceChildCurrent(page)) return Promise.reject(new Error('登录或原确认已变化，请返回原确认页查询'));
	const current = marketplaceChildGuard(page, 'return-selection');
	return verifyMarketplaceOwner(page.$store).then(identity => {
		if (!current() || !marketplaceIdentityCurrent(page.$store, identity)) fail('登录身份已变化');
		return api.lookup(context.orderKey).then(response => ({identity, result: response.data}));
	}).then(({identity, result}) => {
		if (!current()) fail('原确认已变化');
		if (!result || result.key !== context.orderKey || !['not_found', 'created', 'processing'].includes(result.state)) fail('原订单结果尚未取得');
		if (result.state !== 'not_found') { uni.redirectTo({url: marketplaceConfirmationUrl(context.orderKey)}); return; }
		const record = readMarketplaceConfirmation(identity.ownerUid, context.orderKey);
		if (!record || record.kind !== 'full' || record.editGeneration !== context.generation ||
			JSON.stringify(record.binding) !== JSON.stringify(page._marketplaceBinding) ||
			(record.firstSendIntentAt && !liveCheckoutParent(page, context, record))) { uni.redirectTo({url: marketplaceConfirmationUrl(context.orderKey)}); return; }
		if (!marketplaceOwnerUid(id)) fail('保存结果未取得真实编号，请返回列表选择');
		return api.detail(id).then(response => {
			if (!current()) fail('登录身份已变化');
			const row = response.data;
			if (!row || String(row.id) !== String(id)) fail('本人选择资料未完整取得');
			record.selection[kind] = {mode: 'id', id: String(id), status: 'pending', snapshot: {}};
			const saved = saveMarketplaceConfirmation(identity.ownerUid, record, record.revision);
			const pages = getCurrentPages(), previous = pages[pages.length - 2], parent = previous && (previous.$vm || previous);
			const parentInstance = parent && (parent.marketplacePageInstance || parent._marketplaceInstance);
			if (page._marketplaceCheckoutChannel && context.parentInstance && parentInstance === context.parentInstance &&
				(parent.orderKey === context.orderKey || (parent.marketplaceCheckout && parent.marketplaceCheckout.orderKey === context.orderKey))) {
				page._marketplaceCheckoutChannel.emit(kind === 'address' ? 'marketplaceAddressSelected' : 'marketplaceInvoiceSelected', {
					...context, addressId: kind === 'address' ? id : undefined, invoiceId: kind === 'invoice' ? id : undefined,
					headerType: kind === 'invoice' ? row.header_type : undefined, savedRevision: saved.revision
				});
				uni.navigateBack({delta: 1});
			} else uni.redirectTo({url: marketplaceConfirmationUrl(context.orderKey)});
		});
	});
}
