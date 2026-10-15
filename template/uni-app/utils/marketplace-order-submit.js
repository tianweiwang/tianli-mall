// Wire data only. UID, ownership, prices and durable results are verified by the server.
const unavailable = () => new Error('本店确认信息不完整，请重新确认商品');
const freeze = value => {
	if (value && typeof value === 'object') {
		Object.values(value).forEach(freeze);
		Object.freeze(value);
	}
	return value;
};

export function bindMarketplaceSubmission(dto) {
	if (!dto || dto.marketplace_confirmation_version !== 1 || typeof dto.orderKey !== 'string' ||
		!/^[\x21-\x7e]{1,32}$/.test(dto.orderKey) || !/^[1-9][0-9]*$/.test(String(dto.mer_id)) ||
		!['persistent', 'temporary'].includes(dto.source_kind) || !Array.isArray(dto.cartInfo) || !dto.cartInfo.length) throw unavailable();
	const refs = dto.cartInfo.map(row => {
		if (!row || typeof row.id !== 'string' || !row.id || row.id.includes(',') || row.id.includes('\0')) throw unavailable();
		return row.id;
	});
	if (new Set(refs).size !== refs.length) throw unavailable();
	return freeze({key: dto.orderKey, idemKey: 'confirm:' + dto.orderKey,
		merId: dto.mer_id, sourceKind: dto.source_kind, refs});
}

export function snapshotMarketplaceSubmission(binding, fields, answers, generation) {
	if (!binding || typeof binding.key !== 'string' || binding.idemKey !== 'confirm:' + binding.key || !Array.isArray(answers)) throw unavailable();
	for (const key of ['payType', 'mark', 'real_name', 'phone', 'gift_mark']) if (typeof fields[key] !== 'string') throw unavailable();
	const body = {
		cartId: binding.refs.join(','), mer_id: binding.merId, new: binding.sourceKind === 'temporary' ? 1 : 0,
		idem_key: binding.idemKey, addressId: fields.addressId, invoice_id: fields.invoice_id === '' ? 0 : fields.invoice_id,
		payType: fields.payType, mark: fields.mark, real_name: fields.real_name, phone: fields.phone, gift_mark: fields.gift_mark,
		shipping_type: 1, is_gift: 0, store_id: 0, couponId: 0, useIntegral: false,
		combinationId: 0, pinkId: 0, seckill_id: 0, bargainId: 0, advanceId: 0, custom_form: answers
	};
	return freeze({key: binding.key, generation, body: JSON.parse(JSON.stringify(body))});
}

export function marketplaceConfirmationResult(data, key) {
	if (!data || data.key !== key || !['created', 'processing', 'not_found'].includes(data.state) ||
		(data.state === 'created' && (typeof data.orderId !== 'string' || !data.orderId))) throw unavailable();
	return {state: data.state, key, ...(data.state === 'created' ? {orderId: data.orderId} : {})};
}

export function marketplaceCreateResult(response, key) {
	const data = response && response.data;
	if (!data || data.status !== 'SUCCESS' || !data.result) throw unavailable();
	return marketplaceConfirmationResult({state: 'created', key: data.result.key, orderId: data.result.orderId}, key);
}
