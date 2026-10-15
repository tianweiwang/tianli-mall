// V1 public confirmation definitions and answers only; no transport or identity state.
const formChanged = () => new Error('商品表单已变化，请重新确认');
const labels = ['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'];
const copyValue = value => Array.isArray(value) ? value.slice() : value;
const sameProduct = (left, right) => String(left) === String(right);

export function marketplaceFormIdentity(group, field) {
	return String(group.product_id) + ':' + group.definition_sha256 + ':' + String(field.field_index);
}

export function initializeMarketplaceForms(definitions) {
	if (!Array.isArray(definitions) || definitions.length > 500) throw formChanged();
	const products = new Set();
	return definitions.map(group => {
		if (!group || !/^[1-9][0-9]*$/.test(String(group.product_id)) ||
			products.has(String(group.product_id)) || typeof group.product_name !== 'string' ||
			!Array.isArray(group.cart_refs) || !group.cart_refs.length ||
			group.cart_refs.some(ref => typeof ref !== 'string') ||
			typeof group.definition_sha256 !== 'string' || !/^[0-9a-f]{64}$/.test(group.definition_sha256) ||
			!Array.isArray(group.fields) || !group.fields.length || group.fields.length > 10) throw formChanged();
		products.add(String(group.product_id));
		return {
			product_id: group.product_id,
			product_name: group.product_name,
			cart_refs: group.cart_refs.slice(),
			definition_sha256: group.definition_sha256,
			fields: group.fields.map((field, index) => {
				if (!field || field.field_index !== index || typeof field.title !== 'string' ||
					!labels.includes(field.label) || typeof field.status !== 'boolean' ||
					(field.label === 'img' ? !Array.isArray(field.value) || field.value.length > 8 ||
						field.value.some(value => typeof value !== 'string') : typeof field.value !== 'string')) throw formChanged();
				return {field_index: field.field_index, title: field.title, label: field.label,
					status: field.status, value: copyValue(field.value)};
			})
		};
	});
}

export function findMarketplaceFormField(groups, productId, definitionHash, fieldIndex) {
	const group = groups.find(row => sameProduct(row.product_id, productId) && row.definition_sha256 === definitionHash);
	return group ? group.fields.find(field => field.field_index === fieldIndex) || null : null;
}

// Repricing may refresh server labels/defaults, but must not reset in-progress answers.
export function retainMarketplaceForms(definitions, edited) {
	const groups = initializeMarketplaceForms(definitions);
	if (groups.length !== edited.length) throw formChanged();
	return groups.map(group => {
		const previous = edited.find(row => sameProduct(row.product_id, group.product_id) &&
			row.definition_sha256 === group.definition_sha256);
		if (!previous || previous.fields.length !== group.fields.length ||
			previous.cart_refs.length !== group.cart_refs.length ||
			previous.cart_refs.some((ref, index) => ref !== group.cart_refs[index])) throw formChanged();
		group.fields.forEach(field => {
			const answer = findMarketplaceFormField(edited, group.product_id, group.definition_sha256, field.field_index);
			if (!answer || answer.label !== field.label || answer.status !== field.status || answer.title !== field.title) throw formChanged();
			field.value = copyValue(answer.value);
		});
		return group;
	});
}

export function marketplaceFormAnswers(groups) {
	return groups.map(group => ({
		product_id: group.product_id,
		definition_sha256: group.definition_sha256,
		values: group.fields.map(field => ({field_index: field.field_index, value: copyValue(field.value)}))
	}));
}

export function firstMissingMarketplaceAnswer(groups) {
	for (const group of groups) {
		for (const field of group.fields) {
			if (!field.status) continue;
			const value = field.value;
			let valid;
			if (field.label === 'img') valid = Array.isArray(value) && value.length > 0;
			else if (typeof value !== 'string') valid = false;
			else if (field.label === 'number') valid = /^\s*\+?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)\s*$/u.test(value) && /[1-9]/.test(value);
			else if (field.label === 'email') valid = /^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(value);
			else if (field.label === 'phone') valid = /^1(3|4|5|7|8|9|6)\d{9}$/.test(value);
			else valid = !!value.trim();
			if (!valid) return {product_name: group.product_name, title: field.title, label: field.label};
		}
	}
	return null;
}
