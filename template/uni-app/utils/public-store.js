export function merchantIdentity(value) {
  if (typeof value === 'string' && /^[1-9][0-9]{0,9}$/.test(value)) value = Number(value);
  if (!Number.isSafeInteger(value) || value < 1 || value > 4294967295) throw new Error('店铺信息暂未完整取得');
  return value;
}
export function publicStore(value, expectedId) {
  const owner = merchantIdentity(expectedId);
  if (!value || merchantIdentity(value.mer_id) !== owner) throw new Error('店铺信息暂未完整取得');
  const result = { mer_id: owner };
  ['name', 'logo', 'after_sale_contact', 'shipping_note'].forEach((key) => {
    if (typeof value[key] !== 'string') throw new Error('店铺信息暂未完整取得');
    result[key] = value[key];
  });
  return result;
}
export function storefrontMessage(error) {
  if (typeof error === 'string') return error;
  return error && typeof error.msg === 'string' ? error.msg : error && typeof error.message === 'string' ? error.message : '暂未加载完成，请重试';
}
