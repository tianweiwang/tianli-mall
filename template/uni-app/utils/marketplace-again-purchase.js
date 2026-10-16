import { merchantIdentity, publicStore } from './public-store.js';

const unavailable = () => new Error('再次购买信息不完整，请重新选择商品');
const confirmationPath = '/pages/goods/order_confirm/index?new=1&cartId=';

function temporaryReference(value) {
	if (typeof value !== 'string' || !value || value.includes(',') || value.includes('\0')) throw unavailable();
	try {
		// The server's temporary boundary is 50 UTF-8 bytes. Never convert a ref to Number.
		if (encodeURIComponent(value).replace(/%[0-9A-F]{2}/g, '_').length > 50) throw unavailable();
	} catch (error) {
		throw unavailable();
	}
	return value;
}

function merchantString(value) {
	if (typeof value !== 'string' || String(merchantIdentity(value)) !== value) throw unavailable();
	return value;
}

export function againConfirmationUrl(group) {
	const merId = merchantString(group && group.mer_id);
	if (!Array.isArray(group.cart_refs) || !group.cart_refs.length) throw unavailable();
	const refs = group.cart_refs.map(temporaryReference);
	if (new Set(refs).size !== refs.length) throw unavailable();
	return confirmationPath + encodeURIComponent(refs.join(',')) + '&mer_id=' + encodeURIComponent(merId);
}

export function parseAgainPurchase(data) {
	if (!data || typeof data.cateId !== 'string' || !data.cateId) throw unavailable();
	if (!Object.prototype.hasOwnProperty.call(data, 'marketplace_again_version')) {
		return { legacyUrl: confirmationPath + encodeURIComponent(data.cateId), groups: [] };
	}
	if (data.marketplace_again_version !== 1 || data.source_kind !== 'temporary' ||
		!Array.isArray(data.groups) || !data.groups.length) throw unavailable();
	const refs = data.cateId.split(',').map(temporaryReference);
	const positions = new Map(refs.map((ref, index) => [ref, index]));
	if (positions.size !== refs.length) throw unavailable();
	const seenRefs = new Set();
	const seenMerchants = new Set();
	let lastFirstPosition = -1;
	const groups = data.groups.map(raw => {
		const merId = merchantString(raw && raw.mer_id);
		if (!raw.store || merchantString(raw.store.mer_id) !== merId || seenMerchants.has(merId) ||
			!Array.isArray(raw.cart_refs) || !raw.cart_refs.length) throw unavailable();
		const store = publicStore(raw.store, merId);
		if (!store.name.trim()) throw unavailable();
		let firstPosition = refs.length;
		const groupRefs = raw.cart_refs.map(value => {
			const ref = temporaryReference(value);
			if (!positions.has(ref) || seenRefs.has(ref)) throw unavailable();
			seenRefs.add(ref);
			firstPosition = Math.min(firstPosition, positions.get(ref));
			return ref;
		});
		if (firstPosition <= lastFirstPosition) throw unavailable();
		lastFirstPosition = firstPosition;
		seenMerchants.add(merId);
		return { mer_id: merId, store: { ...store, mer_id: merId }, cart_refs: groupRefs };
	});
	if (seenRefs.size !== refs.length) throw unavailable();
	return { legacyUrl: '', groups };
}
